<?php

declare(strict_types=1);

namespace App\Support;

use Laravel\Pennant\Contracts\FeatureScopeSerializeable;

/** Whom an interface flag is decided for (Pennant's scope): a person, in a workspace, classic or not. */
final readonly class FlagScope implements FeatureScopeSerializeable
{
    public function __construct(public int $userId, public ?int $workspaceId, public bool $classic) {}

    public function featureScopeSerialize(): string
    {
        return 'user:'.$this->userId.'|workspace:'.($this->workspaceId ?? '-').'|classic:'.($this->classic ? '1' : '0');
    }
}
