<?php

declare(strict_types=1);

use App\Enums\WorkspaceRole;
use App\Models\WorkspaceInvitation;
use App\Models\WorkspaceInviteLink;
use App\Services\InterfaceFlags;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

/*
 * W-220: on a phone the Workspace page never scrolls sideways. Its invitations and invite links tables scroll in their
 * own box, and that box holds their hidden "Actions" header too (an absolutely placed screen-reader label escapes a box
 * that is not positioned): at 390 px it made the page 58 to 67 px wider, and right to left the page shifted and showed
 * the frame's hidden panel over its edge. scripts/screenshots.mjs page-fit measures it in a browser.
 */

it('keeps the invitations\' and invite links\' hidden labels inside the boxes that scroll, in both designs and languages', function () {
    $owner = Fx::user();
    $ws = (int) $owner->current_workspace_id;
    WorkspaceInvitation::query()->create(['workspace_id' => $ws, 'email' => 'hamza@example.com', 'role' => WorkspaceRole::Member->value,
        'token_hash' => hash('sha256', Str::random(40)), 'invited_by' => $owner->id, 'expires_at' => now()->addDays(7)]);
    WorkspaceInviteLink::query()->create(['workspace_id' => $ws, 'role' => WorkspaceRole::Member->value, 'token_hash' => hash('sha256', Str::random(40)),
        'created_by' => $owner->id, 'expires_at' => now()->addDays(7), 'max_uses' => 5]);
    Filament::setCurrentPanel('app');

    foreach ([[false, 'en'], [true, 'en'], [true, 'ur']] as [$v2, $language]) {
        foreach (InterfaceFlags::UI_V2 as $flag) {
            app(InterfaceFlags::class)->set($flag, $v2, 0, [], [], $v2 ? 'everyone' : 'off');
        }
        $owner->forceFill(['console_preferences' => ['shell' => ['app_language' => $language]]])->save();
        $html = (string) $this->actingAs($owner->fresh(), 'web')->get('/app/workspace')->assertOk()->getContent();
        // Both tables, each in a box that scrolls and is their labels' containing block (the roles' table has no hidden label).
        expect(substr_count($html, '<div style="overflow-x:auto;position:relative">'))->toBe(2, ($v2 ? 'new' : 'classic')." {$language}");
    }
});

it('puts every box that scrolls sideways around a hidden label in a position of its own, in every view', function () {
    $loose = [];
    foreach (File::allFiles(resource_path('views')) as $file) {
        $view = $file->getContents();
        if (! preg_match('/class="[^"]*\b(el-sr|fi-sr-only|sr-only)\b/', $view)) {
            continue;
        }
        preg_match_all('/style="([^"]*overflow-x:\s*auto[^"]*)"/', $view, $boxes);
        foreach ($boxes[1] as $style) {
            if (! str_contains($style, 'position:')) {
                $loose[] = $file->getRelativePathname().': '.$style;
            }
        }
    }

    expect($loose)->toBe([]);
});
