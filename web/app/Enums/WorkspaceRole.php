<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * A person's role in a workspace (B20; admin and viewer since v1.5, docs/API.md "Workspaces, roles and privacy"). What
 * each role may do is App\Policies\WorkspacePolicy.
 */
enum WorkspaceRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Member = 'member';
    case Viewer = 'viewer';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Owner',
            self::Admin => 'Admin',
            self::Member => 'Member',
            self::Viewer => 'Viewer',
        };
    }

    /** Owners and admins run the workspace; members and viewers use it. */
    public function manages(): bool
    {
        return $this === self::Owner || $this === self::Admin;
    }

    /** Everyone but a viewer records, uploads and keeps their own library. */
    public function records(): bool
    {
        return $this !== self::Viewer;
    }

    /** @return array<string, string> value => label, for a role select */
    public static function options(): array
    {
        return array_combine(array_map(fn (self $r): string => $r->value, self::cases()), array_map(fn (self $r): string => $r->label(), self::cases()));
    }
}
