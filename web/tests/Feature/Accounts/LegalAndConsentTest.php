<?php

declare(strict_types=1);

use App\Filament\Pages\RecordingConsentNotice;
use App\Models\User;
use App\Services\SettingsRepository;
use App\Support\RecordingConsent;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

beforeEach(fn () => Filament::setCurrentPanel('app'));

it('serves the final Terms and Privacy Policy, phone-sized, without signing in', function (string $url, string $heading) {
    $page = $this->get($url)->assertOk()->assertSee($heading)->assertDontSee('Draft — for review')
        ->assertSee('width=device-width', false)->getContent();

    expect($page)->toContain(route('legal.terms'))->toContain(route('legal.privacy'))->toContain(route('account.delete'));
})->with([
    'terms' => ['/terms', 'Terms of Service'],
    'privacy' => ['/privacy', 'Privacy Policy'],
]);

it('lists every processor of recordings and what each receives', function () {
    $page = (string) $this->get('/privacy')->getContent();

    foreach (['AssemblyAI', 'Groq', 'Anthropic', 'Google Gemini', 'ElevenLabs', 'Soniox', 'Microsoft Azure Speech', 'hosting provider'] as $processor) {
        expect($page)->toContain($processor);
    }
    expect($page)->toContain('What it receives')->toContain(RecordingConsent::TEXT);
});

it('names a storage company only when the files are in a bucket', function () {
    expect((string) $this->get('/privacy')->getContent())->not->toContain('storage company');

    config(['filesystems.disks.recordings.driver' => 's3-compatible']);

    expect((string) $this->get('/privacy')->getContent())->toContain('The storage company this server uses')->toContain('never a key that opens them');
});

it('says what the server does since v1.5: content is private to its maker, in a team workspace too', function () {
    $privacy = (string) $this->get('/privacy')->getContent();
    $terms = (string) $this->get('/terms')->getContent();

    expect($privacy)->toContain('in a team workspace too: nobody else in the workspace sees them, its owners and admins included')
        ->toContain('never what they recorded')->not->toContain('each member sees the recordings')
        ->and($terms)->toContain('also inside a team workspace')->not->toContain('Those in a team workspace are shared with all its members');
});

it('throttles the legal pages with the rest of the public site (429)', function () {
    foreach (range(1, 60) as $_) {
        $this->get('/terms');
    }
    $this->get('/privacy')->assertStatus(429);
});

it('links the legal pages from the sign-in page and under every console page', function () {
    expect($this->get('/app/login')->getContent())->toContain(route('legal.terms'))->toContain(route('legal.privacy'));
    app(SettingsRepository::class)->set('signup.mode', 'anyone');
    expect($this->get('/app/register')->assertOk()->getContent())->toContain(route('legal.terms'))->toContain(route('legal.privacy'));

    $this->actingAs(Fx::user(), 'web');
    expect($this->get('/app')->assertOk()->getContent())->toContain(route('legal.terms'))->toContain(route('legal.privacy'));
});

it('shows a new account the recording-consent notice once, then keeps it in the profile', function () {
    $user = User::factory()->newAccount()->create();
    $this->actingAs($user, 'web');

    $this->get('/app')->assertRedirect(RecordingConsentNotice::getUrl());
    $this->get('/app/sessions')->assertRedirect(RecordingConsentNotice::getUrl());
    $this->get(RecordingConsentNotice::getUrl())->assertOk()->assertSee(RecordingConsent::TEXT)->assertSee(RecordingConsent::CONFIRM);

    Livewire::test(RecordingConsentNotice::class)->callAction(TestAction::make('understood')->schemaComponent('consent-actions', schema: 'content'))->assertRedirect();
    expect($user->fresh()->consent_seen_at)->not->toBeNull();

    $this->actingAs($user->fresh(), 'web');
    $this->get('/app')->assertOk();
    $this->get('/app/settings/privacy')->assertOk()->assertSee('Recording consent')->assertSee('Acknowledged on');
});

it('never shows the notice to accounts that existed before it', function () {
    $this->actingAs(Fx::user(), 'web');

    $this->get('/app')->assertOk();
});
