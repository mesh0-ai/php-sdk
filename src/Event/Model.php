<?php

declare(strict_types=1);

namespace Mesh0\Event;

/** Identifies the model that produced an event (e.g. provider="anthropic", id="claude-opus-4-7"). */
final readonly class Model
{
    public function __construct(
        public ?string $provider = null,
        public ?string $id = null,
    ) {
    }

    /** @return array<string, string> */
    public function toArray(): array
    {
        $out = [];
        if ($this->provider !== null) {
            $out['provider'] = $this->provider;
        }
        if ($this->id !== null) {
            $out['id'] = $this->id;
        }
        return $out;
    }
}
