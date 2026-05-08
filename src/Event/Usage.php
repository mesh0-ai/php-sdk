<?php

declare(strict_types=1);

namespace Mesh0\Event;

/** Token + cost accounting for a model call. */
final readonly class Usage
{
    public function __construct(
        public ?int $promptTokens = null,
        public ?int $completionTokens = null,
        public ?int $totalTokens = null,
        public ?float $costUsd = null,
    ) {
    }

    /** @return array<string, int|float> */
    public function toArray(): array
    {
        $out = [];
        if ($this->promptTokens !== null) {
            $out['prompt_tokens'] = $this->promptTokens;
        }
        if ($this->completionTokens !== null) {
            $out['completion_tokens'] = $this->completionTokens;
        }
        if ($this->totalTokens !== null) {
            $out['total_tokens'] = $this->totalTokens;
        }
        if ($this->costUsd !== null) {
            $out['cost_usd'] = $this->costUsd;
        }
        return $out;
    }
}
