<?php

declare(strict_types=1);

use Marko\Core\Command\Output;
use Marko\DevServer\Exceptions\DevServerException;
use Marko\DevServer\Process\ProcessManager;

it('starts a process with proc_open', function (): void {
    $output = new Output(fopen('php://memory', 'r+'));
    $manager = new ProcessManager($output);

    $pid = $manager->start('echo', 'echo hello');

    expect($pid)->toBeInt()
        ->and($pid)->toBeGreaterThan(0);

    $manager->stopAll();
});

it('stops a running process', function (): void {
    $output = new Output(fopen('php://memory', 'r+'));
    $manager = new ProcessManager($output);

    $manager->start('sleep', 'sleep 5');
    expect($manager->isRunning('sleep'))->toBeTrue();

    $manager->stop('sleep');

    expect($manager->getPid('sleep'))->toBeNull();
});

it('stops all managed processes', function (): void {
    $output = new Output(fopen('php://memory', 'r+'));
    $manager = new ProcessManager($output);

    $manager->start('sleep1', 'sleep 5');
    $manager->start('sleep2', 'sleep 5');

    expect($manager->isRunning('sleep1'))->toBeTrue()
        ->and($manager->isRunning('sleep2'))->toBeTrue();

    $manager->stopAll();

    expect($manager->getPids())->toBeEmpty();
});

it('returns process PIDs after starting', function (): void {
    $output = new Output(fopen('php://memory', 'r+'));
    $manager = new ProcessManager($output);

    $pid1 = $manager->start('sleep1', 'sleep 5');
    $pid2 = $manager->start('sleep2', 'sleep 5');

    expect($manager->getPid('sleep1'))->toBe($pid1)
        ->and($manager->getPid('sleep2'))->toBe($pid2)
        ->and($manager->getPids())->toBe(['sleep1' => $pid1, 'sleep2' => $pid2]);

    $manager->stopAll();
});

it('detects when a process exits unexpectedly', function (): void {
    $output = new Output(fopen('php://memory', 'r+'));
    $manager = new ProcessManager($output);

    $manager->start('echo', 'echo hello');

    expect(devserverWaitUntil(fn (): bool => !$manager->isRunning('echo')))->toBeTrue();

    $manager->stopAll();
});

it('throws DevServerException when process fails to start', function (): void {
    $output = new Output(fopen('php://memory', 'r+'));
    // A generous probe window: start() returns as soon as the command fails, so this
    // costs nothing on a fast machine and survives a slow one under parallel load
    $manager = new ProcessManager($output, startProbeSeconds: 5.0);

    expect(fn () => $manager->start('bad', '/nonexistent-command-abc123'))
        ->toThrow(DevServerException::class);
});

it('detects a command that fails after the default probe window when given a longer one', function (): void {
    $output = new Output(fopen('php://memory', 'r+'));
    $manager = new ProcessManager($output, startProbeSeconds: 5.0);

    // Exits with "command not found" well after the 150ms default probe window
    expect(fn () => $manager->start('slow-fail', 'sleep 0.4; exit 127'))
        ->toThrow(DevServerException::class);
});

it('returns from start as soon as the process exits within the probe window', function (): void {
    $output = new Output(fopen('php://memory', 'r+'));
    $manager = new ProcessManager($output, startProbeSeconds: 5.0);

    $start = microtime(true);
    $manager->start('echo', 'echo hello');

    expect(microtime(true) - $start)->toBeLessThan(2.5);

    $manager->stopAll();
});

it('prefixes output lines with process name', function (): void {
    $stream = fopen('php://memory', 'r+');
    $output = new Output($stream);
    $manager = new ProcessManager($output);

    $manager->writePrefix('php', 'Server started on port 8000');

    rewind($stream);
    $result = stream_get_contents($stream);

    expect($result)->toContain('[php] Server started on port 8000');
});

it('streams prefixed output in foreground mode until processes exit', function (): void {
    $stream = fopen('php://memory', 'r+');
    $output = new Output($stream);
    $manager = new ProcessManager($output);

    $manager->start('echo', 'echo "hello from echo"');
    $manager->runForeground();

    rewind($stream);
    $result = stream_get_contents($stream);

    expect($result)->toContain('[echo] hello from echo');
});

it('streams output from multiple processes in foreground mode', function (): void {
    $stream = fopen('php://memory', 'r+');
    $output = new Output($stream);
    $manager = new ProcessManager($output);

    $manager->start('greet', 'echo "hi there"');
    $manager->start('count', 'echo "one two three"');
    $manager->runForeground();

    rewind($stream);
    $result = stream_get_contents($stream);

    expect($result)->toContain('[greet] hi there')
        ->and($result)->toContain('[count] one two three');
});

it('returns when all foreground processes have exited', function (): void {
    $stream = fopen('php://memory', 'r+');
    $output = new Output($stream);
    $manager = new ProcessManager($output);

    $manager->start('fast', 'echo done');

    // runForeground should return once the process exits
    $manager->runForeground();

    expect($manager->getPids())->toBeEmpty();
});

it('reports process exit with success status', function (): void {
    $stream = fopen('php://memory', 'r+');
    $output = new Output($stream);
    $manager = new ProcessManager($output);

    $manager->start('task', 'echo done');
    $manager->runForeground();

    rewind($stream);
    $result = stream_get_contents($stream);

    expect($result)->toContain('[task] exited');
});

it('reports process exit with failure status', function (): void {
    $stream = fopen('php://memory', 'r+');
    $output = new Output($stream);
    $manager = new ProcessManager($output);

    $manager->start('fail', 'sh -c "exit 1"');
    $manager->runForeground();

    rewind($stream);
    $result = stream_get_contents($stream);

    expect($result)->toContain('[fail] exited with code 1');
});

it('captures the actual command PID not the shell wrapper PID', function (): void {
    $output = new Output(fopen('php://memory', 'r+'));
    $manager = new ProcessManager($output);

    $pid = $manager->start('sleep', 'sleep 10');

    // The stored PID must be the sleep process itself.
    // With exec prefix, posix_kill($pid, 0) returns true because the PID
    // is the actual long-running command, not a transient shell wrapper.
    expect(posix_kill($pid, 0))->toBeTrue();

    $manager->stop('sleep');
});

it('reports running processes as running in dev:status', function (): void {
    $output = new Output(fopen('php://memory', 'r+'));
    $manager = new ProcessManager($output);

    $pid = $manager->start('sleep', 'sleep 10');

    // Simulate what dev:status does: check the stored PID via posix_kill
    // The PID must still be alive after the start() call returns
    expect(posix_kill($pid, 0))->toBeTrue();

    $manager->stop('sleep');
});

it('reports stopped processes as stopped in dev:status', function (): void {
    $output = new Output(fopen('php://memory', 'r+'));
    $manager = new ProcessManager($output);

    $pid = $manager->start('sleep', 'sleep 10');
    $manager->stop('sleep');

    // stop() only returns once the process and its group are gone, so no wait is needed
    expect(posix_kill($pid, 0))->toBeFalse()
        ->and(devserverProcessGroupAlive($pid))->toBeFalse();
});

it('correctly tracks PID for long-running processes', function (): void {
    $output = new Output(fopen('php://memory', 'r+'));
    $manager = new ProcessManager($output);

    $pid = $manager->start('sleep', 'sleep 30');

    // Wait until the setsid wrapper has exec'd into its own process group
    expect(devserverWaitUntil(fn (): bool => devserverProcessGroupAlive($pid)))->toBeTrue()
        // The PID must still be the running process (not a dead shell wrapper)
        ->and(posix_kill($pid, 0))->toBeTrue()
        ->and($pid)->toBe($manager->getPid('sleep'));

    $manager->stop('sleep');
});

it('makes the reported PID the leader of its own process group', function (): void {
    $output = new Output(fopen('php://memory', 'r+'));
    $manager = new ProcessManager($output);

    // Shells that fork instead of exec'ing a lone command (e.g. dash as /bin/sh) must not
    // leave a wrapper shell as the reported PID: stop() signals that PID's group
    $pid = $manager->start('sleep', 'sleep 30');

    expect(devserverWaitUntil(fn (): bool => posix_getpgid($pid) === $pid))->toBeTrue();

    $manager->stop('sleep');
});

it('leaves no process in the group once stop returns', function (): void {
    $output = new Output(fopen('php://memory', 'r+'));
    $manager = new ProcessManager($output);

    $pid = $manager->start('sleep', 'sleep 30');
    expect(devserverWaitUntil(fn (): bool => devserverProcessGroupAlive($pid)))->toBeTrue();

    $manager->stop('sleep');

    expect(devserverProcessGroupAlive($pid))->toBeFalse()
        ->and($manager->isRunning('sleep'))->toBeFalse();
});

it('stops child processes that share the process group', function (): void {
    $output = new Output(fopen('php://memory', 'r+'));
    $manager = new ProcessManager($output);

    $marker = devserverMarkerPath();

    // The shell leader backgrounds a child in the same process group and records its PID
    $pid = $manager->start('tree', "sleep 30 & echo \$! > $marker; wait");
    expect(devserverWaitUntil(fn (): bool => (int) @file_get_contents($marker) > 0))->toBeTrue();
    $childPid = (int) file_get_contents($marker);
    @unlink($marker);

    expect(posix_kill($childPid, 0))->toBeTrue();

    $manager->stop('tree');

    expect(devserverProcessGroupAlive($pid))->toBeFalse()
        ->and(posix_kill($childPid, 0))->toBeFalse();
});

it('escalates to SIGKILL when a process ignores SIGTERM', function (): void {
    $output = new Output(fopen('php://memory', 'r+'));
    $manager = new ProcessManager($output, stopTimeoutSeconds: 0.5);
    $marker = devserverMarkerPath();

    // Ignore SIGTERM (inherited by the sleep child), then signal readiness via a marker file
    $pid = $manager->start('stubborn', "trap '' TERM; touch $marker; sleep 30");
    expect(devserverWaitUntil(fn (): bool => file_exists($marker)))->toBeTrue();

    $manager->stop('stubborn');
    @unlink($marker);

    expect(devserverProcessGroupAlive($pid))->toBeFalse()
        ->and(posix_kill($pid, 0))->toBeFalse();
});

it('returns within the stop timeout when a process ignores SIGTERM', function (): void {
    $output = new Output(fopen('php://memory', 'r+'));
    $manager = new ProcessManager($output, stopTimeoutSeconds: 0.5);
    $marker = devserverMarkerPath();

    $manager->start('stubborn', "trap '' TERM; touch $marker; sleep 30");
    expect(devserverWaitUntil(fn (): bool => file_exists($marker)))->toBeTrue();

    $start = microtime(true);
    $manager->stop('stubborn');
    $elapsed = microtime(true) - $start;
    @unlink($marker);

    // 0.5s grace period plus a bounded wait for SIGKILL to land — never the 30s sleep
    expect($elapsed)->toBeGreaterThanOrEqual(0.5)
        ->and($elapsed)->toBeLessThan(5.0);
});

it('stops a process that has already exited without waiting', function (): void {
    $output = new Output(fopen('php://memory', 'r+'));
    $manager = new ProcessManager($output, stopTimeoutSeconds: 5.0);

    $manager->start('echo', 'echo hello');
    expect(devserverWaitUntil(fn (): bool => !$manager->isRunning('echo')))->toBeTrue();

    $start = microtime(true);
    $manager->stop('echo');

    expect(microtime(true) - $start)->toBeLessThan(1.0)
        ->and($manager->getPid('echo'))->toBeNull();
});

it('overlaps the stop grace periods of all processes in stopAll', function (): void {
    $output = new Output(fopen('php://memory', 'r+'));
    $manager = new ProcessManager($output, stopTimeoutSeconds: 1.0);
    $markerA = devserverMarkerPath();
    $markerB = devserverMarkerPath();

    $manager->start('a', "trap '' TERM; touch $markerA; sleep 30");
    $manager->start('b', "trap '' TERM; touch $markerB; sleep 30");
    expect(devserverWaitUntil(fn (): bool => file_exists($markerA) && file_exists($markerB)))->toBeTrue();

    $start = microtime(true);
    $manager->stopAll();
    $elapsed = microtime(true) - $start;
    @unlink($markerA);
    @unlink($markerB);

    // Stopping one after the other would take at least 2 x 1.0s
    expect($elapsed)->toBeLessThan(2.0)
        ->and($manager->getPids())->toBeEmpty();
});

it('creates a loud exception when a process cannot be stopped', function (): void {
    $exception = DevServerException::processFailedToStop('php', 12345);

    expect($exception->getMessage())->toContain("'php'")
        ->and($exception->getMessage())->toContain('12345')
        ->and($exception->getContext())->not->toBeEmpty()
        ->and($exception->getSuggestion())->toContain('kill -9 -12345');
});
