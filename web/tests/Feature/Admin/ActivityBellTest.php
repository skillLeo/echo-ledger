<?php

declare(strict_types=1);

use App\Enums\SessionStatus;
use App\Filament\Pages\Activity;
use App\Livewire\ActivityBell;
use App\Services\ActionBadge;
use App\Services\ActivityFeed;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

/*
| v1.6 P6: the app's bell counts only what needs the person: unread problems, answers to an upgrade request and calls
| waiting for Keep or Discard; never finished transcripts, new devices or shares; "9+" above nine.
*/

beforeEach(function () {
    $this->person = Fx::user();
    $this->actingAs($this->person, 'web');
    Filament::setCurrentPanel('app');
});

function bellPost(string $kind, int $times = 1): void
{
    for ($i = 0; $i < $times; $i++) {
        app(ActivityFeed::class)->post(test()->person, $kind, "Title {$kind} {$i}", 'Body', null, 'heroicon-o-bell');
    }
}

function bellBadge(): string
{
    preg_match('/data-action-badge="([^"]*)"/', Livewire::test(ActivityBell::class)->html(), $m);

    return $m[1] ?? 'missing';
}

it('says nothing for twenty finished transcripts, new devices and shares', function () {
    bellPost('transcript_ready', 20);
    bellPost('device_paired', 2);
    bellPost('shared_with_you');

    expect(bellBadge())->toBe('')->and(Activity::getNavigationBadge())->toBeNull()
        ->and($this->person->unreadNotifications()->count())->toBe(23);
});

it('counts unread problems, upgrade answers and calls waiting for Keep or Discard', function () {
    bellPost('transcript_ready', 5);
    bellPost('transcript_failed', 2);
    bellPost('upload_failed');
    bellPost('budget_reached');
    bellPost('upgrade_declined');
    Fx::session($this->person, null, ['status' => SessionStatus::Recording]);

    expect(bellBadge())->toBe('6')->and(Activity::getNavigationBadge())->toBe('6');

    $this->person->unreadNotifications()->get()->each->markAsRead();
    expect(bellBadge())->toBe('1');
});

it('says 9+ above nine', function () {
    bellPost('transcript_failed', 12);

    expect(bellBadge())->toBe('9+')->and(Activity::getNavigationBadge())->toBe('9+')
        ->and(ActionBadge::label(9))->toBe('9')->and(ActionBadge::label(10))->toBe('9+')->and(ActionBadge::label(0))->toBeNull();
});

it('counts only the person\'s own: another person\'s problems never show', function () {
    $other = Fx::user();
    app(ActivityFeed::class)->post($other, 'transcript_failed', 'Theirs', 'x', null, 'heroicon-o-bell');
    Fx::session($other, null, ['status' => SessionStatus::Recording]);

    expect(bellBadge())->toBe('');
});

it('keeps Mark all as read while anything is unread, even when nothing needs you', function () {
    bellPost('transcript_ready', 3);
    Livewire::test(Activity::class)->assertActionVisible('markAllRead')->callAction('markAllRead');
    expect($this->person->unreadNotifications()->count())->toBe(0);
});

it('is the bell of the app\'s pages', function () {
    bellPost('transcript_failed', 2);
    expect((string) $this->get('/app')->assertOk()->getContent())->toContain('data-action-badge="2"');
});
