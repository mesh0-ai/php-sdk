<?php

declare(strict_types=1);

namespace Mesh0\Event;

use DateTimeInterface;

/**
 * Fluent builder for {@see Event}.
 *
 * Each `with*` method returns a new builder, so the builder is safe to share
 * and reuse — no hidden mutation. Call {@see build()} to materialize the
 * immutable {@see Event}, or pass the builder directly to a sender (the
 * sender will call `build()` internally).
 */
final readonly class EventBuilder
{
    /**
     * @param list<string>|null         $tools
     * @param array<string, mixed>|null $attributes
     */
    public function __construct(
        private DateTimeInterface $timestamp,
        private ?string $eventId = null,
        private ?float $durationMs = null,
        private ?string $traceId = null,
        private ?string $spanId = null,
        private ?string $parentSpanId = null,
        private ?string $appId = null,
        private ?string $environment = null,
        private ?string $operation = null,
        private ?Status $status = null,
        private ?string $errorType = null,
        private ?string $errorMessage = null,
        private ?Model $model = null,
        private ?Usage $usage = null,
        private ?string $finishReason = null,
        private ?string $userId = null,
        private ?string $sessionId = null,
        private ?array $tools = null,
        private ?array $attributes = null,
        private mixed $messages = null,
    ) {
    }

    public function withEventId(string $id): self
    {
        return $this->copy(eventId: $id);
    }

    public function withDurationMs(float $durationMs): self
    {
        return $this->copy(durationMs: $durationMs);
    }

    public function withTraceId(string $traceId): self
    {
        return $this->copy(traceId: $traceId);
    }

    public function withSpan(string $spanId, ?string $parentSpanId = null): self
    {
        return $this->copy(spanId: $spanId, parentSpanId: $parentSpanId);
    }

    public function withApp(string $appId, ?string $environment = null): self
    {
        return $this->copy(appId: $appId, environment: $environment);
    }

    public function withOperation(string $operation): self
    {
        return $this->copy(operation: $operation);
    }

    public function withStatus(Status $status): self
    {
        return $this->copy(status: $status);
    }

    public function withError(string $type, string $message): self
    {
        return $this->copy(status: Status::Error, errorType: $type, errorMessage: $message);
    }

    public function withModel(string $provider, string $id): self
    {
        return $this->copy(model: new Model($provider, $id));
    }

    public function withUsage(
        ?int $promptTokens = null,
        ?int $completionTokens = null,
        ?int $totalTokens = null,
        ?float $costUsd = null,
    ): self {
        return $this->copy(usage: new Usage($promptTokens, $completionTokens, $totalTokens, $costUsd));
    }

    public function withFinishReason(string $reason): self
    {
        return $this->copy(finishReason: $reason);
    }

    /**
     * Tag the event with your application's end-user id.
     *
     * This is *not* the mesh0 platform user that minted the API key — the
     * key already identifies your project. `user_id` is the user inside
     * your product (analogous to OpenAI's `user` param), used for
     * attribution and filtering in the dashboard / TQL.
     */
    public function withUser(string $userId): self
    {
        return $this->copy(userId: $userId);
    }

    /** Tag the event with an application session id (your product's session, not mesh0's). */
    public function withSession(string $sessionId): self
    {
        return $this->copy(sessionId: $sessionId);
    }

    /** @param list<string> $tools */
    public function withTools(array $tools): self
    {
        return $this->copy(tools: $tools);
    }

    /** @param array<string, mixed> $attributes */
    public function withAttributes(array $attributes): self
    {
        $merged = $this->attributes === null ? $attributes : array_merge($this->attributes, $attributes);
        return $this->copy(attributes: $merged);
    }

    public function withAttribute(string $key, mixed $value): self
    {
        return $this->withAttributes([$key => $value]);
    }

    public function withMessages(mixed $messages): self
    {
        return $this->copy(messages: $messages);
    }

    public function build(): Event
    {
        return new Event(
            timestamp: $this->timestamp,
            eventId: $this->eventId,
            durationMs: $this->durationMs,
            traceId: $this->traceId,
            spanId: $this->spanId,
            parentSpanId: $this->parentSpanId,
            appId: $this->appId,
            environment: $this->environment,
            operation: $this->operation,
            status: $this->status,
            errorType: $this->errorType,
            errorMessage: $this->errorMessage,
            model: $this->model,
            usage: $this->usage,
            finishReason: $this->finishReason,
            userId: $this->userId,
            sessionId: $this->sessionId,
            tools: $this->tools,
            attributes: $this->attributes,
            messages: $this->messages,
        );
    }

    /**
     * @param list<string>|null         $tools
     * @param array<string, mixed>|null $attributes
     */
    private function copy(
        ?DateTimeInterface $timestamp = null,
        ?string $eventId = null,
        ?float $durationMs = null,
        ?string $traceId = null,
        ?string $spanId = null,
        ?string $parentSpanId = null,
        ?string $appId = null,
        ?string $environment = null,
        ?string $operation = null,
        ?Status $status = null,
        ?string $errorType = null,
        ?string $errorMessage = null,
        ?Model $model = null,
        ?Usage $usage = null,
        ?string $finishReason = null,
        ?string $userId = null,
        ?string $sessionId = null,
        ?array $tools = null,
        ?array $attributes = null,
        mixed $messages = null,
    ): self {
        return new self(
            timestamp: $timestamp ?? $this->timestamp,
            eventId: $eventId ?? $this->eventId,
            durationMs: $durationMs ?? $this->durationMs,
            traceId: $traceId ?? $this->traceId,
            spanId: $spanId ?? $this->spanId,
            parentSpanId: $parentSpanId ?? $this->parentSpanId,
            appId: $appId ?? $this->appId,
            environment: $environment ?? $this->environment,
            operation: $operation ?? $this->operation,
            status: $status ?? $this->status,
            errorType: $errorType ?? $this->errorType,
            errorMessage: $errorMessage ?? $this->errorMessage,
            model: $model ?? $this->model,
            usage: $usage ?? $this->usage,
            finishReason: $finishReason ?? $this->finishReason,
            userId: $userId ?? $this->userId,
            sessionId: $sessionId ?? $this->sessionId,
            tools: $tools ?? $this->tools,
            attributes: $attributes ?? $this->attributes,
            messages: $messages ?? $this->messages,
        );
    }
}
