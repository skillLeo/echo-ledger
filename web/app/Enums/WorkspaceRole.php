<?php

declare(strict_types=1);

namespace App\Enums;

/** A member's role in a workspace (docs/HANDOFF.md "B20 design" → Roles). */
enum WorkspaceRole: string
{
    case Owner = 'owner';
    case Member = 'member';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Owner',
            self::Member => 'Member',
        };
    }
}
