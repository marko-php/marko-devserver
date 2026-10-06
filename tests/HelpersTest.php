<?php

declare(strict_types=1);

it('returns true as soon as the waited-for condition holds', function (): void {
    $calls = 0;

    $start = microtime(true);
    $result = devserverWaitUntil(function () use (&$calls): bool {
        $calls++;

        return $calls >= 3;
    }, timeoutSeconds: 5.0, intervalMicros: 1_000);

    expect($result)->toBeTrue()
        ->and($calls)->toBe(3)
        ->and(microtime(true) - $start)->toBeLessThan(1.0);
});

it('returns false when the waited-for condition never holds before the deadline', function (): void {
    $start = microtime(true);
    $result = devserverWaitUntil(fn (): bool => false, timeoutSeconds: 0.05, intervalMicros: 1_000);

    expect($result)->toBeFalse()
        ->and(microtime(true) - $start)->toBeGreaterThanOrEqual(0.05);
});

it('reports a process group as not alive when it does not exist', function (): void {
    // PID_MAX on Linux and macOS is far below this value, so the group cannot exist
    expect(devserverProcessGroupAlive(999_999_999))->toBeFalse();
});

it('returns a unique marker path in the temp directory that does not exist yet', function (): void {
    $first = devserverMarkerPath();
    $second = devserverMarkerPath();

    expect($first)->toStartWith(sys_get_temp_dir())
        ->and($first)->not->toBe($second)
        ->and(file_exists($first))->toBeFalse();
});
