<?php

declare(strict_types=1);

namespace Marko\DevServer\Process;

readonly class ProcessEntry
{
    /**
     * @param string|null $host The address the process listens on (unbracketed for IPv6), or null when it has none
     */
    public function __construct(
        public string $name,
        public int $pid,
        public string $command,
        public int $port,
        public string $startedAt,
        public ?string $host = null,
    ) {}
}
