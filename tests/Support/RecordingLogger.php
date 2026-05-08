<?php

declare(strict_types=1);

namespace Mesh0\Tests\Support;

use Psr\Log\LoggerInterface;

/**
 * PSR-3 logger that records every call. We avoid `Psr\Log\Test\TestLogger`
 * because it isn't shipped with psr/log v2/v3.
 */
final class RecordingLogger implements LoggerInterface
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    public array $records = [];

    /** @param array<string, mixed> $context */
    public function emergency(\Stringable|string $message, array $context = []): void
    {
        $this->log('emergency', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function alert(\Stringable|string $message, array $context = []): void
    {
        $this->log('alert', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function critical(\Stringable|string $message, array $context = []): void
    {
        $this->log('critical', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function error(\Stringable|string $message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function warning(\Stringable|string $message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function notice(\Stringable|string $message, array $context = []): void
    {
        $this->log('notice', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function info(\Stringable|string $message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function debug(\Stringable|string $message, array $context = []): void
    {
        $this->log('debug', $message, $context);
    }

    /**
     * @param mixed              $level
     * @param array<string, mixed> $context
     */
    public function log($level, \Stringable|string $message, array $context = []): void
    {
        $this->records[] = [
            'level' => (string) $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }

    /** @return list<array{level: string, message: string, context: array<string, mixed>}> */
    public function recordsAt(string $level): array
    {
        return array_values(array_filter(
            $this->records,
            static fn (array $r): bool => $r['level'] === $level,
        ));
    }
}
