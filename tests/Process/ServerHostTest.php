<?php

declare(strict_types=1);

use Marko\DevServer\Exceptions\DevServerException;
use Marko\DevServer\Process\ServerHost;

it('accepts hostnames and IPv4 addresses unchanged', function (string $host): void {
    expect(ServerHost::fromString($host)->address)->toBe($host);
})->with(['localhost', 'app.test', 'my-host.example.com', '127.0.0.1', '0.0.0.0']);

it(
    'accepts a bare or bracketed IPv6 address and stores it unbracketed',
    function (string $host, string $address): void {
        expect(ServerHost::fromString($host)->address)->toBe($address);
    },
)->with([
    'bare loopback' => ['::1', '::1'],
    'bracketed loopback' => ['[::1]', '::1'],
    'bare wildcard' => ['::', '::'],
    'bracketed wildcard' => ['[::]', '::'],
    'full address' => ['[fe80::1]', 'fe80::1'],
]);

it('rejects values that are not a hostname or IP address', function (string $host): void {
    expect(fn () => ServerHost::fromString($host))
        ->toThrow(DevServerException::class, "Invalid host value: '$host'");
})->with([
    'shell metacharacters' => ['0.0.0.0; evil'],
    'empty' => [''],
    'bracketed hostname' => ['[localhost]'],
    'bracketed IPv4' => ['[127.0.0.1]'],
    'nested brackets' => ['[[::1]]'],
    'unbalanced bracket' => ['[::1'],
    'invalid IPv6' => ['::1::2'],
    'host with port' => ['localhost:8000'],
    'leading hyphen' => ['-localhost'],
]);

it('brackets IPv6 addresses for a URI and leaves other hosts alone', function (string $host, string $uriHost): void {
    expect(ServerHost::fromString($host)->forUri())->toBe($uriHost)
        ->and(ServerHost::formatForUri($host))->toBe($uriHost);
})->with([
    ['::1', '[::1]'],
    ['[::1]', '[::1]'],
    ['::', '[::]'],
    ['127.0.0.1', '127.0.0.1'],
    ['localhost', 'localhost'],
]);

it(
    'substitutes localhost for wildcard addresses in a browser URL',
    function (string $host, string $browserHost): void {
        expect(ServerHost::fromString($host)->forBrowser())->toBe($browserHost);
    },
)->with([
    ['0.0.0.0', 'localhost'],
    ['::', 'localhost'],
    ['[::]', 'localhost'],
    ['::1', '[::1]'],
    ['127.0.0.1', '127.0.0.1'],
    ['app.test', 'app.test'],
]);
