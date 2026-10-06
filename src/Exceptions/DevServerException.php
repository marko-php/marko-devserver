<?php

declare(strict_types=1);

namespace Marko\DevServer\Exceptions;

use Marko\Core\Exceptions\MarkoException;

class DevServerException extends MarkoException
{
    public static function processFailedToStart(
        string $name,
        string $command,
    ): self {
        return new self(
            message: "Failed to start process '$name' with command: $command",
            context: 'While starting development services',
            suggestion: "Check that the command exists and is executable. Run 'marko dev:status' to see current state.",
        );
    }

    public static function processFailedToStop(
        string $name,
        int $pid,
    ): self {
        return new self(
            message: "Failed to stop process '$name' (PID $pid): it was still running after SIGTERM and SIGKILL",
            context: 'While stopping development services',
            suggestion: "The process may be stuck in uninterruptible I/O. Inspect it with 'ps -o pid,stat,command -g $pid' and kill it manually with 'kill -9 -$pid'.",
        );
    }

    public static function portInUse(int $port): self
    {
        return new self(
            message: "Port $port is already in use",
            context: 'While starting PHP development server',
            suggestion: "Use a different port with --port=XXXX or stop the process using port $port.",
        );
    }
}
