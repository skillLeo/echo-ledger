<?php

declare(strict_types=1);

use App\Enums\CompletionMode;
use App\Enums\DevicePlatform;
use App\Enums\RecordingRole;
use App\Enums\SessionStatus;
use App\Enums\SourceApp;
use App\Enums\SourceDevice;
use App\Enums\SttProviderKey;
use App\Enums\SttUseCase;
use App\Enums\TranscriptKind;
use App\Enums\TranscriptStatus;
use App\Enums\UploadStatus;

/** The "Enumerations" table of docs/API.md as [first cell => [every backticked token of the second cell]]. */
function documentedEnumerations(): array
{
    $doc = (string) file_get_contents(dirname(__DIR__, 3).'/docs/API.md');
    $start = strpos($doc, '## Enumerations');
    $end = strpos($doc, '**Segment**', (int) $start);
    $rows = [];
    foreach (explode("\n", substr($doc, (int) $start, $end - $start)) as $line) {
        if (! str_starts_with($line, '| ') || str_starts_with($line, '| Field') || str_starts_with($line, '|---')) {
            continue;
        }
        $cells = array_map('trim', explode('|', trim($line, " |\t")));
        preg_match_all('/`([^`]+)`/', $cells[1] ?? '', $tokens);
        $rows[$cells[0]] = ['tokens' => $tokens[1], 'text' => $cells[1] ?? ''];
    }

    return $rows;
}

/** @return list<string> */
function docTokens(string $rowStartsWith): array
{
    foreach (documentedEnumerations() as $name => $row) {
        if (str_starts_with($name, $rowStartsWith)) {
            return $row['tokens'];
        }
    }

    return [];
}

function caseValues(string $enum): array
{
    return array_map(fn ($case) => $case->value, $enum::cases());
}

describe('docs/API.md enumerations match the code', function () {
    it('lists the platforms', function () {
        expect(docTokens('`platform`'))->toEqualCanonicalizing(caseValues(DevicePlatform::class));
    });

    it('lists the session statuses', function () {
        expect(docTokens('session `status`'))->toEqualCanonicalizing(caseValues(SessionStatus::class));
    });

    it('lists the upload statuses', function () {
        expect(docTokens('upload `status`'))->toEqualCanonicalizing(caseValues(UploadStatus::class));
    });

    it('lists the recording roles', function () {
        expect(docTokens('recording `role`'))->toEqualCanonicalizing(caseValues(RecordingRole::class));
    });

    it('lists the providers', function () {
        expect(docTokens('provider'))->toEqualCanonicalizing(caseValues(SttProviderKey::class));
    });

    it('lists the use cases', function () {
        expect(docTokens('use case'))->toEqualCanonicalizing(caseValues(SttUseCase::class));
    });

    it('lists the transcript kinds and statuses', function () {
        expect(docTokens('transcript `kind`'))->toEqualCanonicalizing([...caseValues(TranscriptKind::class), ...caseValues(TranscriptStatus::class)]);
    });

    it('lists the source apps with their labels', function () {
        $row = documentedEnumerations()['`source_app`'];

        expect(array_values(array_filter($row['tokens'], fn ($t) => $t !== '')))->toEqualCanonicalizing(caseValues(SourceApp::class));
        foreach (SourceApp::cases() as $case) {
            expect($row['text'])->toContain('`'.$case->value.'` "'.$case->getLabel().'"');
        }
    })->skip('A1.7: docs/API.md already lists the source apps whatsapp_web and microsoft_teams, which the server does not have yet');

    it('lists the source devices with their labels and icon keys', function () {
        $row = collect(documentedEnumerations())->first(fn ($r, $name) => str_starts_with($name, '`source_device`'));

        foreach (SourceDevice::cases() as $case) {
            expect($row['text'])->toContain('`'.$case->value.'` "'.$case->getLabel().'" icon `'.$case->iconKey().'`');
        }
    });
});

describe('derived values', function () {
    it('derives the source device from the platform as documented', function () {
        expect(DevicePlatform::Android->sourceDevice())->toBe(SourceDevice::Phone)
            ->and(DevicePlatform::Ios->sourceDevice())->toBe(SourceDevice::Phone)
            ->and(DevicePlatform::Windows->sourceDevice())->toBe(SourceDevice::Desktop)
            ->and(DevicePlatform::MacOs->sourceDevice())->toBe(SourceDevice::Desktop)
            ->and(DevicePlatform::Linux->sourceDevice())->toBe(SourceDevice::Desktop);
    });

    it('never derives "web" for a paired device, since that is reserved for admin uploads', function () {
        foreach (DevicePlatform::cases() as $platform) {
            expect($platform->sourceDevice())->not->toBe(SourceDevice::Web);
        }
    });

    it('treats exactly completed and failed transcripts as terminal', function () {
        foreach (TranscriptStatus::cases() as $status) {
            expect($status->isTerminal())->toBe(in_array($status, [TranscriptStatus::Completed, TranscriptStatus::Failed], true));
        }
    });

    it('marks only the two streaming use cases as streaming', function () {
        expect(array_values(array_filter(SttUseCase::cases(), fn (SttUseCase $c) => $c->isStreaming())))->toBe([SttUseCase::LiveCaptions, SttUseCase::Dictation]);
    });

    it('names the three completion modes the clients switch on', function () {
        expect(caseValues(CompletionMode::class))->toEqualCanonicalizing(['async_webhook', 'sync', 'device']);
    });

    it('gives every enum case a non-empty label', function (string $enum) {
        foreach ($enum::cases() as $case) {
            expect($case->getLabel())->toBeString()->not->toBeEmpty();
        }
    })->with([SourceApp::class, SourceDevice::class, DevicePlatform::class, TranscriptStatus::class, SttProviderKey::class, SttUseCase::class]);

    it('uses unique values within each enum', function (string $enum) {
        expect(array_unique(caseValues($enum)))->toHaveCount(count($enum::cases()));
    })->with([SourceApp::class, SourceDevice::class, DevicePlatform::class, SessionStatus::class, UploadStatus::class, RecordingRole::class, TranscriptKind::class, TranscriptStatus::class, SttProviderKey::class, SttUseCase::class, CompletionMode::class]);

    it('uses lower snake case for every wire value', function (string $enum) {
        foreach (caseValues($enum) as $value) {
            expect($value)->toMatch('/^[a-z][a-z0-9_]*$/');
        }
    })->with([SourceApp::class, SourceDevice::class, DevicePlatform::class, SessionStatus::class, UploadStatus::class, RecordingRole::class, TranscriptKind::class, TranscriptStatus::class, SttProviderKey::class, SttUseCase::class, CompletionMode::class]);
});
