<?php

declare(strict_types=1);

use Marko\DevServer\Exceptions\DevServerException;

it('reports an invalid host with the accepted syntaxes', function (): void {
    $exception = DevServerException::invalidHost('0.0.0.0; evil');

    expect($exception->getMessage())->toBe("Invalid host value: '0.0.0.0; evil'")
        ->and($exception->getSuggestion())->toContain('--host=0.0.0.0')
        ->and($exception->getSuggestion())->toContain('--host=[::1]');
});

it('reports a server that exited before accepting connections with its exit code and output', function (): void {
    $exception = DevServerException::serverExited(
        '::1',
        8000,
        1,
        'Failed to listen on [::1]:8000 (reason: Cannot assign requested address)',
    );

    expect($exception->getMessage())->toBe('PHP server exited with code 1 before accepting connections on [::1]:8000')
        ->and($exception->getContext())->toContain('Failed to listen on [::1]:8000')
        ->and($exception->getSuggestion())->not->toBeEmpty();
});

it('reports a server that exited without output or a known exit code', function (): void {
    $exception = DevServerException::serverExited('127.0.0.1', 8000, null, '');

    expect($exception->getMessage())->toBe('PHP server exited before accepting connections on 127.0.0.1:8000')
        ->and($exception->getContext())->toBe('While starting PHP development server (it printed no output)');
});

it('brackets an IPv6 host when the server is not ready in time', function (): void {
    expect(DevServerException::serverNotReady('::1', 8000, 10.0)->getMessage())
        ->toBe('PHP server did not accept connections on [::1]:8000 within 10 seconds');
});

it(
    'names every surviving process and PID when a rollback fails and keeps the original error as previous',
    function (): void {
        $original = DevServerException::processFailedToStart('php', 'php -S localhost:8000');

        $exception = DevServerException::rollbackFailed($original, ['frontend' => 4321, 'queue' => 8765]);

        expect($exception->getMessage())->toContain("'frontend' (PID 4321)")
            ->and($exception->getMessage())->toContain("'queue' (PID 8765)")
            ->and($exception->getContext())->toContain("Failed to start process 'php'")
            ->and($exception->getSuggestion())->toContain('kill -9 -4321 -8765')
            ->and($exception->getPrevious())->toBe($original);
    },
);
