<?php

declare(strict_types=1);

namespace Marko\DevServer\Process;

use Marko\Core\Command\Output;
use Marko\DevServer\Exceptions\DevServerException;
use ValueError;

class ProcessManager
{
    private const int SIGTERM = 15;

    private const int SIGKILL = 9;

    /** How long to wait for SIGKILL to take effect before failing loudly. */
    private const float KILL_TIMEOUT_SECONDS = 2.0;

    private const int POLL_INTERVAL_MICROS = 10_000;

    /** @var array<string, array{resource: resource, pipes: array<int, resource>}> */
    private array $processes = [];

    /** @var array<string, int> */
    private array $pids = [];

    /**
     * @param float $stopTimeoutSeconds Grace period stop() gives a process group to exit after SIGTERM before sending SIGKILL
     * @param float $startProbeSeconds How long start() watches a new process for an immediate "not found"/"not executable" exit
     */
    public function __construct(
        private readonly Output $output,
        private readonly float $stopTimeoutSeconds = 3.0,
        private readonly float $startProbeSeconds = 0.15,
    ) {}

    /**
     * Start a named process.
     *
     * @throws DevServerException If the process fails to start
     */
    public function start(
        string $name,
        string $command,
    ): int {
        $descriptors = [
            0 => ['pipe', 'r'],  // stdin
            1 => ['pipe', 'w'],  // stdout
            2 => ['pipe', 'w'],  // stderr
        ];

        $wrappedCommand = $this->wrapWithNewProcessGroup($command);
        $process = proc_open($wrappedCommand, $descriptors, $pipes);

        if (!is_resource($process)) {
            throw DevServerException::processFailedToStart($name, $command);
        }

        // Make stdout/stderr non-blocking
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $status = proc_get_status($process);
        $pid = $status['pid'];

        $this->processes[$name] = ['resource' => $process, 'pipes' => $pipes];
        $this->pids[$name] = $pid;

        // Watch the process for the probe window, returning early once it exits,
        // and fail if it exited because the command was not found or not executable
        $deadline = microtime(true) + $this->startProbeSeconds;
        while (($status = proc_get_status($process))['running'] && microtime(true) < $deadline) {
            usleep(self::POLL_INTERVAL_MICROS);
        }

        if (!$status['running'] && in_array($status['exitcode'], [126, 127], true)) {
            $this->stop($name);
            throw DevServerException::processFailedToStart($name, $command);
        }

        return $pid;
    }

    /**
     * Start a named process in the background, fully detached from PHP.
     *
     * Uses shell exec with output redirection instead of proc_open,
     * so the process survives PHP exit. Returns the PID.
     */
    public function startDetached(
        string $name,
        string $command,
    ): int {
        $wrappedCommand = $this->wrapWithNewProcessGroup($command);

        // Start fully detached: pipe stdin from tail to keep it open (some tools
        // like tailwind --watch exit when stdin closes), redirect output to
        // /dev/null, and background the process.
        $pid = (int) trim((string) shell_exec(
            "tail -f /dev/null | $wrappedCommand > /dev/null 2>&1 & echo $!",
        ));

        if ($pid <= 0) {
            throw DevServerException::processFailedToStart($name, $command);
        }

        // Brief check to see if process died immediately
        // (e.g. command not found, port in use)
        usleep(150000); // 150ms
        if (!$this->isDetachedRunning($pid)) {
            throw DevServerException::processFailedToStart($name, $command);
        }

        $this->pids[$name] = $pid;

        return $pid;
    }

    /**
     * Check if a detached process (or its process group) is still running.
     */
    private function isDetachedRunning(int $pid): bool
    {
        if (!function_exists('posix_kill')) {
            return false;
        }

        try {
            return @posix_kill(-$pid, 0) || posix_kill($pid, 0);
        } catch (ValueError) {
            return false;
        }
    }

    /**
     * Stop a named process and every process in its process group.
     *
     * Sends SIGTERM to the process and its group, waits up to the stop timeout for
     * all of them to exit, then escalates to SIGKILL. When this returns, the
     * process and its group are gone.
     *
     * @throws DevServerException If the process group survives SIGKILL
     */
    public function stop(string $name): void
    {
        if (!isset($this->processes[$name])) {
            return;
        }

        $this->terminate([$name]);
    }

    /**
     * Terminate the named processes and their process groups, then release them.
     *
     * All groups share one SIGTERM grace period, so stopping several services
     * takes as long as the slowest one rather than the sum of all of them.
     *
     * @param list<string> $names
     * @throws DevServerException If a process group survives SIGKILL
     */
    private function terminate(array $names): void
    {
        $remaining = $this->waitForExit($names, 0.0);
        $this->signalAll($remaining, self::SIGTERM);

        $remaining = $this->waitForExit($remaining, $this->stopTimeoutSeconds);
        $this->signalAll($remaining, self::SIGKILL);

        $remaining = $this->waitForExit($remaining, self::KILL_TIMEOUT_SECONDS);

        foreach ($names as $name) {
            if (in_array($name, $remaining, true)) {
                continue;
            }

            // Close pipes only once the group is gone, so nothing gets SIGPIPE while shutting down
            foreach ($this->processes[$name]['pipes'] as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }

            proc_close($this->processes[$name]['resource']);

            unset($this->processes[$name], $this->pids[$name]);
        }

        if ($remaining !== []) {
            throw DevServerException::processFailedToStop($remaining[0], $this->pids[$remaining[0]]);
        }
    }

    /**
     * @param list<string> $names
     */
    private function signalAll(
        array $names,
        int $signal,
    ): void {
        foreach ($names as $name) {
            $this->signal($this->processes[$name]['resource'], $this->pids[$name], $signal);
        }
    }

    /**
     * Send a signal to a process and to its process group.
     *
     * Both are signalled because the process may not have become a group
     * leader yet (it is killed before posix_setsid() runs), and because
     * children in the group outlive a leader that exits on its own.
     *
     * @param resource $process
     */
    private function signal(
        mixed $process,
        int $pid,
        int $signal,
    ): void {
        if (function_exists('posix_kill')) {
            @posix_kill(-$pid, $signal);
        }

        // Once reaped, the PID may be reused by an unrelated process — only signal a live child
        if (proc_get_status($process)['running']) {
            proc_terminate($process, $signal);
        }
    }

    /**
     * Poll until every named process has exited and its process group is empty, or the timeout passes.
     *
     * @param list<string> $names
     * @return list<string> The names still alive when the timeout passed
     */
    private function waitForExit(
        array $names,
        float $timeoutSeconds,
    ): array {
        $deadline = microtime(true) + $timeoutSeconds;

        while (true) {
            $names = array_values(array_filter(
                $names,
                // proc_get_status() reaps the exited process, so a zombie never counts as running
                fn (string $name): bool => proc_get_status($this->processes[$name]['resource'])['running']
                    || $this->isProcessGroupAlive($this->pids[$name]),
            ));

            if ($names === [] || microtime(true) >= $deadline) {
                return $names;
            }

            usleep(self::POLL_INTERVAL_MICROS);
        }
    }

    /**
     * Check if any process in the process group led by the given PID is still alive.
     */
    private function isProcessGroupAlive(int $pgid): bool
    {
        if (!function_exists('posix_kill')) {
            return false;
        }

        return @posix_kill(-$pgid, 0);
    }

    /**
     * Stop all managed processes.
     *
     * Every process group gets SIGTERM up front, so their grace periods overlap
     * instead of adding up one service at a time.
     *
     * @throws DevServerException If a process group survives SIGKILL
     */
    public function stopAll(): void
    {
        if ($this->processes === []) {
            return;
        }

        // PHP turns numeric-string array keys into ints, so cast names back to strings
        $this->terminate(array_map(strval(...), array_keys($this->processes)));
    }

    /**
     * Get the PID of a named process.
     */
    public function getPid(string $name): ?int
    {
        return $this->pids[$name] ?? null;
    }

    /**
     * Get all PIDs indexed by name.
     *
     * @return array<string, int>
     */
    public function getPids(): array
    {
        return $this->pids;
    }

    /**
     * Check if a process is still running.
     */
    public function isRunning(string $name): bool
    {
        if (!isset($this->processes[$name])) {
            return false;
        }

        $status = proc_get_status($this->processes[$name]['resource']);

        return $status['running'];
    }

    /**
     * Run in foreground mode: stream process output with prefixes until all processes exit or a signal is received.
     *
     * Registers SIGINT/SIGTERM handlers for graceful shutdown when pcntl is available.
     */
    public function runForeground(): void
    {
        $signaled = false;

        if (function_exists('pcntl_signal')) {
            $handler = function () use (&$signaled): void {
                $signaled = true;
            };
            pcntl_signal(SIGINT, $handler);
            pcntl_signal(SIGTERM, $handler);
            pcntl_async_signals(true);
        }

        while (!$signaled && $this->processes !== []) {
            $this->drainOutput();

            // Remove exited processes and report their exit status
            foreach (array_keys($this->processes) as $name) {
                if (!$this->isRunning($name)) {
                    $this->drainOutput($name);
                    $exitCode = $this->getExitCode($name);
                    if ($exitCode !== 0) {
                        $this->writePrefix($name, "exited with code $exitCode");
                    } else {
                        $this->writePrefix($name, 'exited');
                    }
                    $this->stop($name);
                }
            }

            if ($this->processes !== []) {
                usleep(50000); // 50ms
            }
        }

        if ($signaled) {
            $this->stopAll();
        }
    }

    /**
     * Get the exit code of a process, or -1 if unknown.
     */
    private function getExitCode(string $name): int
    {
        if (!isset($this->processes[$name])) {
            return -1;
        }

        $status = proc_get_status($this->processes[$name]['resource']);

        return $status['exitcode'];
    }

    /**
     * Read available output from process pipes and write with prefix.
     */
    private function drainOutput(?string $onlyName = null): void
    {
        $names = $onlyName !== null ? [$onlyName] : array_keys($this->processes);

        foreach ($names as $name) {
            if (!isset($this->processes[$name])) {
                continue;
            }

            $pipes = $this->processes[$name]['pipes'];

            // Read stdout (pipe 1) and stderr (pipe 2)
            foreach ([1, 2] as $fd) {
                if (!is_resource($pipes[$fd])) {
                    continue;
                }

                while (($line = fgets($pipes[$fd])) !== false) {
                    $this->writePrefix($name, rtrim($line, "\r\n"));
                }
            }
        }
    }

    /**
     * Write a prefixed line to output.
     */
    public function writePrefix(
        string $name,
        string $line,
    ): void {
        $this->output->writeLine("[$name] $line");
    }

    /**
     * Wrap a command to run in its own process group.
     *
     * Uses PHP's posix_setsid() before exec'ing the command, so the process
     * becomes a session leader. This ensures all child processes (e.g. PHP
     * server workers, npx children) share the same group and can be killed
     * or status-checked together.
     */
    private function wrapWithNewProcessGroup(string $command): string
    {
        if (!function_exists('posix_setsid') || !function_exists('pcntl_exec')) {
            return "exec $command";
        }

        $encoded = base64_encode($command);
        $php = PHP_BINARY;

        // `exec` makes the wrapper replace the spawning shell, so the PID proc_open reports
        // is the session leader. Shells such as dash fork a lone command instead of
        // exec'ing it, which would leave a short-lived shell as the reported PID.
        // Use double quotes inside the PHP code to avoid escapeshellarg single-quote conflicts
        return "exec $php -r " . escapeshellarg(
            'posix_setsid();'
            . 'pcntl_exec("/bin/sh", ["-c", base64_decode("' . $encoded . '")]);',
        );
    }
}
