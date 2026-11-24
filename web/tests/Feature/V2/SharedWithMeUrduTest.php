<?php

declare(strict_types=1);

use App\Filament\Pages\SharedWithMe;
use App\Models\SentItem;
use App\Models\User;
use App\Services\InterfaceFlags;
use App\Services\Sharing\Teammates;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

/*
 * Shared with me in the console's language: under the new frame in Urdu its title, its words, its columns, its actions,
 * their windows and their notices are Urdu (web/lang/ur.json); in English, and in classic, every word is as it was.
 */

beforeEach(function () {
    Filament::setCurrentPanel('app');
    $this->me = Fx::user(['name' => 'Saif Hassam', 'email' => 'saif@example.com']);
    $this->farah = Fx::user(['name' => 'Farah Khan', 'email' => 'farah@example.com']);
    $session = Fx::session($this->farah, Fx::device($this->farah), ['title' => 'Invoice questions']);
    app(Teammates::class)->send($this->farah, $session, ['saif@example.com'], null);
    $this->item = SentItem::query()->where('to_user_id', $this->me->id)->firstOrFail();
});

function sharedUrdu(bool $v2, string $language, User $me): void
{
    foreach (InterfaceFlags::UI_V2 as $flag) {
        app(InterfaceFlags::class)->set($flag, $v2, 0, [], [], $v2 ? 'everyone' : 'off');
    }
    $me->forceFill(['console_preferences' => ['shell' => ['app_language' => $language]]])->save();
    test()->actingAs($me->fresh(), 'web');
}

/** The page's HTML with the spaces around its tags gone, so each label reads as >label<. */
function sharedPage(object $test): string
{
    return (string) preg_replace(['/>\s+/u', '/\s+</u'], ['>', '<'], (string) $test->get('/app/shared-with-me')->assertOk()->getContent());
}

/** Each of the page's words: English => Urdu. */
const SHARED_WORDS = [
    'Shared with me' => 'میرے ساتھ شیئر شدہ',
    'Recordings and projects others sent you. Save one to keep your own copy in this workspace.' => 'وہ ریکارڈنگز اور پروجیکٹس جو دوسروں نے آپ کو بھیجے۔ اس ورک اسپیس میں اپنی کاپی رکھنے کے لیے انہیں محفوظ کریں۔',
    'Title' => 'عنوان', 'From' => 'منجانب', 'Note' => 'نوٹ', 'Sent' => 'بھیجا گیا', 'Saved' => 'محفوظ کیا', 'Not yet' => 'ابھی نہیں',
    'Recording' => 'ریکارڈنگ', 'Open' => 'کھولیں', 'Save to my library' => 'میری لائبریری میں محفوظ کریں', 'Remove' => 'ہٹائیں',
];

it('reads in Urdu under the new frame: title, words, columns and actions', function () {
    sharedUrdu(true, 'ur', $this->me);
    $html = sharedPage($this);
    foreach (SHARED_WORDS as $english => $urdu) {
        expect($html)->toContain('>'.$urdu.'<');
    }
    // Not one of them left in English (the row's own title and the sender's name and address aside).
    foreach (['>Shared with me<', '>From<', '>Note<', '>Not yet<', '>Save to my library<', '>Remove<', 'Save one to keep your own copy'] as $english) {
        expect($html)->not->toContain($english);
    }
});

it('says its empty state, its windows and its notices in Urdu', function () {
    sharedUrdu(true, 'ur', $this->me);
    app()->setLocale('ur');

    Livewire::test(SharedWithMe::class)
        ->mountAction(TestAction::make('save')->table($this->item))
        ->assertMountedActionModalSee(['کاپی محفوظ کریں؟', 'ریکارڈنگ، اس کی آڈیو اور ٹرانسکرپٹ اس ورک اسپیس کی ریکارڈنگز میں'])
        ->callMountedAction()
        ->assertNotified('آپ کی لائبریری میں محفوظ ہو گیا');
    Livewire::test(SharedWithMe::class)
        ->mountAction(TestAction::make('remove')->table($this->item))
        ->assertMountedActionModalSee('یہ آپ کی فہرست سے ہٹ جاتی ہے۔ بھیجنے والے کے پاس ان کی اپنی رہتی ہے۔')
        ->callMountedAction()
        ->assertNotified('میرے ساتھ شیئر شدہ سے ہٹا دیا گیا');

    $html = sharedPage($this);
    expect($html)->toContain('ابھی آپ کے ساتھ کچھ شیئر نہیں ہوا')->toContain('جب کوئی ساتھی آپ کو ریکارڈنگ یا پروجیکٹ بھیجے گا، وہ یہاں نظر آئے گا۔');
});

it('keeps every word in English, in the new frame and in classic', function () {
    foreach ([true, false] as $v2) {
        sharedUrdu($v2, 'en', $this->me);
        $html = sharedPage($this);
        foreach (array_keys(SHARED_WORDS) as $english) {
            expect($html)->toContain('>'.$english.'<');
        }
        expect($html)->not->toContain('میرے ساتھ شیئر شدہ')->not->toContain('محفوظ کریں');
    }

    // The notices too.
    sharedUrdu(false, 'en', $this->me);
    Livewire::test(SharedWithMe::class)->callAction(TestAction::make('remove')->table($this->item))->assertNotified('Removed from Shared with me');
});

it('says From as the person who shared it, from a group file of its own, with English as it was', function () {
    $en = require lang_path('en/shared_context.php');
    $ur = require lang_path('ur/shared_context.php');
    expect(array_keys($en))->toBe(array_keys($ur))
        ->and($en['col_from'])->toBe('From')
        ->and($ur['col_from'])->toBe('منجانب')
        ->and(__('shared_context.col_from', [], 'en'))->toBe('From')
        ->and(__('shared_context.col_from', [], 'ur'))->toBe('منجانب')
        // ur.json's "From" (a source) stays as it is for the words that mean that.
        ->and(__('From', [], 'ur'))->toBe('ماخذ');

    sharedUrdu(true, 'ur', $this->me);
    expect(sharedPage($this))->toContain('>منجانب<')->not->toContain('>ماخذ<');
    foreach ([true, false] as $v2) {
        sharedUrdu($v2, 'en', $this->me);
        expect(sharedPage($this))->toContain('>From<')->not->toContain('shared_context');
    }
});

it('says the Open window\'s words and dates in Urdu, and in English as they were', function () {
    $this->item->forceFill(['sent_at' => Carbon::parse('2026-11-17 12:00:00', 'UTC')])->save();
    $project = Fx::client($this->farah, 'Acme Holdings');
    app(Teammates::class)->send($this->farah, $project, ['saif@example.com'], null);
    $sent = SentItem::query()->where('to_user_id', $this->me->id)->where('kind', 'project')->firstOrFail();

    sharedUrdu(true, 'ur', $this->me);
    app()->setLocale('ur');
    // A recording with no transcript yet, and a project with no recordings yet.
    Livewire::test(SharedWithMe::class)->mountAction(TestAction::make('open')->table($this->item))
        ->assertMountedActionModalSee(['ٹرانسکرپٹ ابھی تیار نہیں ہے۔', '17 نومبر 2026'])
        ->assertMountedActionModalDontSee(['The transcript is not ready yet.', 'Nov 17']);
    Livewire::test(SharedWithMe::class)->mountAction(TestAction::make('open')->table($sent))
        ->assertMountedActionModalSee('اس پروجیکٹ میں ابھی کوئی ریکارڈنگ نہیں ہے۔');

    foreach ([true, false] as $v2) {
        sharedUrdu($v2, 'en', $this->me);
        app()->setLocale('en');
        Livewire::test(SharedWithMe::class)->mountAction(TestAction::make('open')->table($this->item))
            ->assertMountedActionModalSee(['The transcript is not ready yet.', 'Nov 17, 2026']);
        Livewire::test(SharedWithMe::class)->mountAction(TestAction::make('open')->table($sent))
            ->assertMountedActionModalSee('This project has no recordings yet.');
    }
});
