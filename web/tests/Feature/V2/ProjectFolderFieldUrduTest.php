<?php

declare(strict_types=1);

use App\Filament\Support\SettingsContext;

/*
 * The project form's folder field is labelled "Inside", and ur.json read it as "کس فولڈر میں" (in which folder?): a
 * question in a one-word key. ur.json is global, so "Inside" keeps its general meaning there (اندر) and the field reads
 * its own context key, client_context.field_inside. English stays the same word, byte for byte.
 */

require_once __DIR__.'/Support/parity_world.php';

beforeEach(function () {
    [$this->owner] = webParityWorld();
});

/** The new project's page in a language, in the new frame or classic: its words without markup or direction marks. */
function projectFolderPage(object $test, bool $v2, string $language): string
{
    webParityDesign($v2);
    $prefs = is_array($test->owner->console_preferences) ? $test->owner->console_preferences : [];
    $prefs['shell'] = ['app_language' => $language];
    $test->owner->forceFill(['console_preferences' => $prefs])->save();
    app('auth')->forgetGuards();
    SettingsContext::forget();
    $html = (string) $test->actingAs($test->owner->fresh(), 'web')->get('/app/clients/create')->assertOk()->getContent();
    $words = preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(preg_replace('#<(script|style)\b.*?</\1>#is', '', $html) ?? ''))) ?? '';

    return preg_replace('/[\x{2066}-\x{2069}]/u', '', $words) ?? $words;
}

it('keeps "Inside" general in ur.json and gives the folder field its own key, English unchanged', function () {
    $urdu = json_decode((string) file_get_contents(lang_path('ur.json')), true, 512, JSON_THROW_ON_ERROR);

    expect($urdu['Inside'])->toBe('اندر')
        ->and(__('client_context.field_inside', [], 'en'))->toBe('Inside')
        ->and(__('client_context.field_inside', [], 'ur'))->toBe('کس فولڈر میں');
});

it('labels the folder field in its context on the new project page, in Urdu and in English', function () {
    // Urdu is the new frame's (classic stays English); English is the same in both.
    expect(projectFolderPage($this, true, 'ur'))->toContain('کس فولڈر میں')->not->toContain('client_context')->not->toContain('Inside');
    foreach ([true, false] as $v2) {
        expect(projectFolderPage($this, $v2, 'en'))->toContain(' Inside ')->not->toContain('client_context');
    }
});
