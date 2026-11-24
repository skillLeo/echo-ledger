<?php

declare(strict_types=1);

use App\Enums\SessionStatus;
use App\Livewire\ActivityBell;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ActivityFeed;
use App\Services\Workspaces\Workspaces;
use Filament\Facades\Filament;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Tests\Feature\Security\Support\ApiClient;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

uses(ApiClient::class);

/*
| Contract 2.0.0-r, BE-04 (docs/API.md "The badge"): GET /api/badge is the one number on the Activity icon in every app
| and the web console: unread items that need action and calls waiting for the person's choice after a call, up to 99
| then "99+", red ("danger") only when a failure is among them. The device's person, in the device's workspace.
*/

function badgePost(User $user, string $kind, int $times = 1, string $title = 'Title'): void
{
    for ($i = 0; $i < $times; $i++) {
        app(ActivityFeed::class)->post($user, $kind, "{$title} {$i}", 'Body', null, 'heroicon-o-bell');
    }
}

function badgeOf(User $user): array
{
    return test()->api(Fx::token(Fx::device($user, ['workspace_id' => $user->current_workspace_id])))->getJson('/api/badge')->assertOk()->json();
}

it('is nothing, in the accent colour, when nothing needs the person', function () {
    $me = Fx::user();
    badgePost($me, 'transcript_ready', 4);
    badgePost($me, 'device_paired');

    expect(badgeOf($me))->toBe(['count' => 0, 'label' => null, 'tone' => 'accent']);
});

it('counts what needs action and the calls waiting, the same number as the web console\'s bell', function () {
    $me = Fx::user();
    badgePost($me, 'transcript_ready', 3);
    badgePost($me, 'budget_reached');
    badgePost($me, 'upgrade_declined');
    badgePost($me, 'missed_recording');
    Fx::session($me, null, ['status' => SessionStatus::Recording]);

    expect(badgeOf($me))->toBe(['count' => 4, 'label' => '4', 'tone' => 'accent']);

    badgePost($me, 'upload_failed');
    expect(badgeOf($me))->toBe(['count' => 5, 'label' => '5', 'tone' => 'danger']);

    Filament::setCurrentPanel('app');
    $this->actingAs($me, 'web');
    preg_match('/data-action-badge="([^"]*)"/', Livewire::test(ActivityBell::class)->html(), $bell);
    expect($bell[1] ?? null)->toBe('5');

    $me->unreadNotifications()->get()->each->markAsRead();
    expect(badgeOf($me))->toBe(['count' => 1, 'label' => '1', 'tone' => 'accent']);
});

it('says 99+ above 99, and the count stays the number, exact above 200 too', function () {
    $me = Fx::user();
    badgePost($me, 'transcript_failed', 101);
    expect(badgeOf($me))->toBe(['count' => 101, 'label' => '99+', 'tone' => 'danger']);

    badgePost($me, 'missed_recording', 130);
    badgePost($me, 'transcript_ready', 40);
    expect(badgeOf($me))->toBe(['count' => 231, 'label' => '99+', 'tone' => 'danger']);
});

it('is red only while a failure is unread among what it counts', function () {
    $me = Fx::user();
    badgePost($me, 'missed_recording');
    badgePost($me, 'upgrade_approved');
    expect(badgeOf($me))->toBe(['count' => 2, 'label' => '2', 'tone' => 'accent']);

    badgePost($me, 'upload_failed');
    expect(badgeOf($me)['tone'])->toBe('danger');
    $me->unreadNotifications()->get()->filter(fn ($n): bool => ActivityFeed::kindOf($n) === 'upload_failed')->each->markAsRead();
    expect(badgeOf($me))->toBe(['count' => 2, 'label' => '2', 'tone' => 'accent']);
});

it('counts only the person\'s own: a teammate\'s and another workspace\'s never show', function () {
    $me = Fx::user();
    $teammate = withoutWorkspace(Fx::user());
    app(Workspaces::class)->addMember(Workspace::query()->findOrFail($me->current_workspace_id), $teammate);
    $teammate->forceFill(['current_workspace_id' => $me->current_workspace_id])->save();
    $stranger = Fx::user();
    foreach ([$teammate->fresh(), $stranger] as $other) {
        badgePost($other, 'upload_failed', 3);
        Fx::session($other, null, ['status' => SessionStatus::Recording]);
    }

    expect(badgeOf($me))->toBe(['count' => 0, 'label' => null, 'tone' => 'accent'])
        ->and(badgeOf($teammate->fresh())['count'])->toBe(4)
        ->and(badgeOf($stranger)['count'])->toBe(4);
});

it('needs a device token with library:read, and writes nothing of the items to the log', function () {
    $me = Fx::user();
    $this->getJson('/api/badge')->assertUnauthorized();
    $this->api(Fx::token(Fx::device($me), ['config:read']))->getJson('/api/badge')->assertForbidden();

    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
        $logged[] = $event->message.' '.json_encode($event->context);
    });
    badgePost($me, 'transcript_failed', 2, 'Payment terms for the March shipment');
    badgeOf($me);
    expect(implode("\n", $logged))->not->toContain('Payment terms');
});
