<?php

declare(strict_types=1);

namespace App\Stt\Data;

use App\Enums\CompletionMode;

final readonly class ProviderCapabilities
{
    /**
     * @param  'cloud'|'on_device'  $liveMode
     * @param  list<string>  $acceptedMimes  empty = any audio format
     */
    public function __construct(
        public CompletionMode $mode,
        public string $liveMode,
        public bool $nativeMultichannel,
        public bool $needsChannelArtifacts,
        public array $acceptedMimes,
        public int $maxKeyterms,
        public int $maxKeytermChars,
        public int $maxKeytermWords,
        public int $maxRealtimeKeyterms,
        public int $maxRealtimeKeytermChars,
    ) {}
}
