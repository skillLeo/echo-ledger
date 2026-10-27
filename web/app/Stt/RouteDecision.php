<?php

declare(strict_types=1);

namespace App\Stt;

use App\Enums\SttProviderKey;

/** The outcome of [LanguageRouter]: the engine to use, the one the routing table wanted first, and why they differ. */
final readonly class RouteDecision
{
    public function __construct(
        public SttProviderKey $provider,
        public SttProviderKey $wanted,
        public ?string $language,
        public bool $fallback,
    ) {}

    /** @return array{used: string, wanted: string, language: string|null, fallback: bool} stored in transcripts.meta.route */
    public function toArray(): array
    {
        return ['used' => $this->provider->value, 'wanted' => $this->wanted->value, 'language' => $this->language, 'fallback' => $this->fallback];
    }
}
