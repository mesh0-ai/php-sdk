<?php

declare(strict_types=1);

namespace Mesh0\Event;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * A single event ready to send to mesh0's `/v1/events` endpoint.
 *
 * Construct directly when you have all the data, or use {@see EventBuilder}
 * for a fluent API. `timestamp` is required; everything else maps to optional
 * fields on the server-side schema.
 *
 * @phpstan-type EventArray array{
 *   timestamp: string|int|float,
 *   event_id?: string,
 *   duration_ms?: int|float,
 *   trace_id?: string,
 *   span_id?: string,
 *   parent_span_id?: string,
 *   app_id?: string,
 *   environment?: string,
 *   operation?: string,
 *   status?: string,
 *   error_type?: string,
 *   error_message?: string,
 *   model?: array<string, string>,
 *   usage?: array<string, int|float>,
 *   finish_reason?: string,
 *   user_id?: string,
 *   session_id?: string,
 *   tools?: list<string>,
 *   attributes?: array<string, mixed>,
 *   messages?: mixed,
 * }
 */
final readonly class Event
{
    /**
     * @param list<string>|null         $tools
     * @param array<string, mixed>|null $attributes
     */
    public function __construct(
        public DateTimeInterface $timestamp,
        public ?string $eventId = null,
        public ?float $durationMs = null,
        public ?string $traceId = null,
        public ?string $spanId = null,
        public ?string $parentSpanId = null,
        public ?string $appId = null,
        public ?string $environment = null,
        public ?string $operation = null,
        public ?Status $status = null,
        public ?string $errorType = null,
        public ?string $errorMessage = null,
        public ?Model $model = null,
        public ?Usage $usage = null,
        public ?string $finishReason = null,
        public ?string $userId = null,
        public ?string $sessionId = null,
        public ?array $tools = null,
        public ?array $attributes = null,
        public mixed $messages = null,
    ) {
    }

    public static function now(): EventBuilder
    {
        return new EventBuilder(new DateTimeImmutable());
    }

    public static function at(DateTimeInterface $when): EventBuilder
    {
        return new EventBuilder($when);
    }

    /**
     * Serialize to the wire format `/v1/events` accepts.
     *
     * @return EventArray
     */
    public function toArray(): array
    {
        // Server accepts ISO-8601 strings; format with millisecond precision UTC.
        /** @var EventArray $out */
        $out = [
            'timestamp' => $this->timestamp->format('Y-m-d\\TH:i:s.v\\Z'),
        ];

        if ($this->eventId !== null) {
            $out['event_id'] = $this->eventId;
        }
        if ($this->durationMs !== null) {
            $out['duration_ms'] = $this->durationMs;
        }
        if ($this->traceId !== null) {
            $out['trace_id'] = $this->traceId;
        }
        if ($this->spanId !== null) {
            $out['span_id'] = $this->spanId;
        }
        if ($this->parentSpanId !== null) {
            $out['parent_span_id'] = $this->parentSpanId;
        }
        if ($this->appId !== null) {
            $out['app_id'] = $this->appId;
        }
        if ($this->environment !== null) {
            $out['environment'] = $this->environment;
        }
        if ($this->operation !== null) {
            $out['operation'] = $this->operation;
        }
        if ($this->status !== null) {
            $out['status'] = $this->status->value;
        }
        if ($this->errorType !== null) {
            $out['error_type'] = $this->errorType;
        }
        if ($this->errorMessage !== null) {
            $out['error_message'] = $this->errorMessage;
        }
        if ($this->model !== null) {
            $arr = $this->model->toArray();
            if ($arr !== []) {
                $out['model'] = $arr;
            }
        }
        if ($this->usage !== null) {
            $arr = $this->usage->toArray();
            if ($arr !== []) {
                $out['usage'] = $arr;
            }
        }
        if ($this->finishReason !== null) {
            $out['finish_reason'] = $this->finishReason;
        }
        if ($this->userId !== null) {
            $out['user_id'] = $this->userId;
        }
        if ($this->sessionId !== null) {
            $out['session_id'] = $this->sessionId;
        }
        if ($this->tools !== null) {
            $out['tools'] = $this->tools;
        }
        if ($this->attributes !== null) {
            $out['attributes'] = $this->attributes;
        }
        if ($this->messages !== null) {
            $out['messages'] = $this->messages;
        }

        return $out;
    }
}
