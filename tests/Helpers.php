<?php

declare(strict_types=1);

// ---------------------------------------------------------------------------
// Shared devserver test helpers (loaded via composer autoload-dev.files)
// ---------------------------------------------------------------------------

if (!function_exists('devserverWaitUntil')) {
    /**
     * Poll a condition until it holds or the deadline passes.
     *
     * Returns true as soon as the condition holds, false when the deadline passes first.
     * Tests assert on the result instead of sleeping a fixed time and hoping the OS was fast.
     */
    function devserverWaitUntil(
        callable $condition,
        float $timeoutSeconds = 5.0,
        int $intervalMicros = 10_000,
    ): bool {
        $deadline = microtime(true) + $timeoutSeconds;

        while (true) {
            if ($condition()) {
                return true;
            }

            if (microtime(true) >= $deadline) {
                return false;
            }

            usleep($intervalMicros);
        }
    }

    /**
     * Whether any process in the given process group is still alive.
     */
    function devserverProcessGroupAlive(int $pgid): bool
    {
        return @posix_kill(-$pgid, 0);
    }

    /**
     * A unique temp file path a test process can touch to signal it is ready.
     */
    function devserverMarkerPath(): string
    {
        return sys_get_temp_dir() . '/marko-devserver-ready-' . bin2hex(random_bytes(6));
    }

    /**
     * The PID a test process wrote to a marker file, or 0 while the file is missing or still empty.
     */
    function devserverMarkerPid(string $marker): int
    {
        clearstatcache(true, $marker);

        if (!is_file($marker)) {
            return 0;
        }

        return (int) file_get_contents($marker);
    }
}
