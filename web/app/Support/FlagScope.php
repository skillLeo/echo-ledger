<?php

declare(strict_types=1);

namespace App\Support;

use Laravel\Pennant\Contracts\FeatureScopeSerializeable;

/**
 * Whom an interface flag is decided for (Pennant's scope): a person, in a workspace, and their own design choice
 * (since 2.0.0-g: "classic", "v2" or none).
 */
final readonly class FlagScope implements FeatureScopeSerializeable
{
    public function __construct(public int $userId, public ?int $workspaceId, public bool $classic, public ?string $design = null) {}

    public function featureScopeSerialize(): string
    {
        return 'user:'.$this->userId.'|workspace:'.($this->workspaceId ?? '-').'|classic:'.($this->classic ? '1' : '0').'|design:'.($this->design ?? '-');
    }
}
