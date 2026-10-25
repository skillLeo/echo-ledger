<?php

declare(strict_types=1);

use App\Models\CaptureSession;
use App\Models\UsageLedgerEntry;
use App\Services\SettingsRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Api\Support\InteractsWithDeviceApi;

uses(InteractsWithDeviceApi::class);

/*
 * docs/API.md "POST /api/usage" and the config `usage` block (since v1.1): devices report streamed minutes, the server
 * prices them from the console price table, and the monthly limit blocks new speech-to-text spend.
 */

beforeEach(function () {
    [$this->device, $this->token] = $this->enroll(platform: 'windows');
    $this->asToken($this->token);
    $this->configureAssemblyAi();
    Http::fake(['streaming.assemblyai.com/v3/token*' => Http::response(['token' => 'tmp_stream_token'], 200)]);
});

function usageReport(array $overrides = []): array
{
    return array_merge([
        'report_id' => 'rep-'.bin2hex(random_bytes(6)),
        'use_case' => 'live_captions',
        'provider' => 'assemblyai',
        'streamed_ms' => 3_600_000,
        'channels' => 2,
    ], $overrides);
}

function ledgerRow(object $test, array $overrides = []): UsageLedgerEntry
{
    return UsageLedgerEntry::query()->create(array_merge([
        'user_id' => $test->device->user_id, 'device_id' => $test->device->id, 'source' => 'device',
        'report_id' => 'seed-'.bin2hex(random_bytes(6)), 'use_case' => 'live_captions', 'provider' => 'assemblyai',
        'duration_ms' => 60_000, 'channels' => 1, 'billed_ms' => 60_000, 'unit_price_usd' => 0.45, 'cost_usd' => 0.0075,
        'billable' => true, 'occurred_at' => now(),
    ], $overrides));
}

describe('usage reports', function () {
    it('stores a report, prices it per channel and answers 201 with the month usage', function () {
        $response = $this->postJson('/api/usage', usageReport())->assertCreated();

        $response->assertJsonPath('report.billed_ms', 7_200_000)
            ->assertJsonPath('report.cost_usd', 0.9)
            ->assertJsonPath('report.duplicate', false)
            ->assertJsonPath('usage.minutes', 120);
        expect(UsageLedgerEntry::query()->count())->toBe(1);
    });

    it('answers 200 with duplicate true when the same report arrives again', function () {
        $report = usageReport();
        $this->postJson('/api/usage', $report)->assertCreated();

        $this->postJson('/api/usage', $report)->assertOk()->assertJsonPath('report.duplicate', true);
        expect(UsageLedgerEntry::query()->count())->toBe(1);
    });

    it('validates every field', function (array $overrides, string $field) {
        $this->postJson('/api/usage', usageReport($overrides))
            ->assertStatus(422)->assertJsonPath('code', 'validation_failed')->assertJsonValidationErrors([$field]);
    })->with([
        'short report id' => [['report_id' => 'abc'], 'report_id'],
        'report id with spaces' => [['report_id' => 'has spaces here'], 'report_id'],
        'final use case' => [['use_case' => 'final_transcript'], 'use_case'],
        'unknown provider' => [['provider' => 'nope'], 'provider'],
        'negative time' => [['streamed_ms' => -1], 'streamed_ms'],
        'more than a day' => [['streamed_ms' => 86_400_001], 'streamed_ms'],
        'three channels' => [['channels' => 3], 'channels'],
        'bad session id' => [['session_id' => 'short'], 'session_id'],
    ]);

    it('links the report to an own session, also a deleted one, and ignores unknown ids', function () {
        $session = CaptureSession::factory()->open()->create(['user_id' => $this->device->user_id, 'device_id' => $this->device->id]);
        $this->postJson('/api/usage', usageReport(['session_id' => $session->id]))->assertCreated()->assertJsonPath('report.session_id', $session->id);

        $session->delete();
        $this->postJson('/api/usage', usageReport(['session_id' => $session->id]))->assertCreated()->assertJsonPath('report.session_id', $session->id);

        $this->postJson('/api/usage', usageReport(['session_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV']))->assertCreated()->assertJsonPath('report.session_id', null);
    });

    it('never links a report to another owner\'s session', function () {
        $other = CaptureSession::factory()->open()->create();

        $this->postJson('/api/usage', usageReport(['session_id' => $other->id]))->assertCreated()->assertJsonPath('report.session_id', null);
    });

    it('still records usage after the monthly limit is reached', function () {
        app(SettingsRepository::class)->set('budget.monthly_minutes', 1);
        ledgerRow($this, ['billed_ms' => 120_000]);

        $this->postJson('/api/usage', usageReport())->assertCreated()->assertJsonPath('usage.limit_reached', true);
    });

    it('prices ElevenLabs streaming from its own row of the table', function () {
        $this->postJson('/api/usage', usageReport(['provider' => 'elevenlabs', 'channels' => 1, 'streamed_ms' => 1_800_000]))
            ->assertCreated()->assertJsonPath('report.cost_usd', 0.235);
    });
});

describe('monthly limit', function () {
    it('counts a row by the month of the console time zone', function () {
        app(SettingsRepository::class)->set('display.timezone', 'Asia/Karachi');
        Carbon::setTestNow(Carbon::parse('2026-10-31 19:30:00', 'UTC')); // 1 November 00:30 in Karachi
        ledgerRow($this, ['occurred_at' => Carbon::parse('2026-10-31 18:59:59', 'UTC'), 'billed_ms' => 600_000]);
        ledgerRow($this, ['occurred_at' => Carbon::parse('2026-10-31 19:00:00', 'UTC'), 'billed_ms' => 60_000]);

        $usage = $this->getJson('/api/config')->assertOk()->json('usage');

        expect($usage['month'])->toBe('2026-11')->and($usage['minutes'])->toEqual(1.0);
        Carbon::setTestNow();
    });

    it('reaches the limit on minutes alone', function () {
        app(SettingsRepository::class)->set('budget.monthly_minutes', 10);
        ledgerRow($this, ['billed_ms' => 600_000]);

        expect($this->getJson('/api/config')->json('usage.limit_reached'))->toBeTrue();
    });

    it('reaches the limit on dollars alone', function () {
        app(SettingsRepository::class)->set('budget.monthly_usd', 1);
        ledgerRow($this, ['cost_usd' => 1.0]);

        expect($this->getJson('/api/config')->json('usage.limit_reached'))->toBeTrue();
    });

    it('warns at the warning percentage before the limit', function () {
        app(SettingsRepository::class)->set('budget.monthly_minutes', 10);
        ledgerRow($this, ['billed_ms' => 480_000]);

        $usage = $this->getJson('/api/config')->json('usage');
        expect($usage['warn'])->toBeTrue()->and($usage['limit_reached'])->toBeFalse()->and($usage['warn_at_percent'])->toBe(80);
    });

    it('does not count rows that are not billable', function () {
        app(SettingsRepository::class)->set('budget.monthly_minutes', 1);
        ledgerRow($this, ['billed_ms' => 600_000, 'billable' => false]);

        expect($this->getJson('/api/config')->json('usage.limit_reached'))->toBeFalse();
    });

    it('refuses new speech tokens for both use cases once the limit is reached', function () {
        app(SettingsRepository::class)->set('budget.monthly_minutes', 1);
        ledgerRow($this, ['billed_ms' => 120_000]);
        $session = $this->openSession();

        foreach ([['use_case' => 'live_captions', 'session_id' => $session], ['use_case' => 'dictation']] as $payload) {
            $this->postJson('/api/stt/token', $payload)->assertForbidden()->assertExactJson([
                'message' => 'Monthly transcription limit reached', 'code' => 'budget_exceeded',
            ]);
        }
        Http::assertNothingSent();
    });
});

it('creates and drops the usage tables', function () {
    expect(Schema::hasTable('usage_ledger'))->toBeTrue()->and(Schema::hasColumn('transcripts', 'error_code'))->toBeTrue();

    // The three usage migrations sit below the bookmarks migration (2026_10_26).
    $this->artisan('migrate:rollback', ['--step' => 4])->assertSuccessful();
    expect(Schema::hasTable('usage_ledger'))->toBeFalse()->and(Schema::hasTable('provider_deletions'))->toBeFalse()
        ->and(Schema::hasColumn('transcripts', 'error_code'))->toBeFalse();

    $this->artisan('migrate')->assertSuccessful();
    expect(Schema::hasTable('usage_ledger'))->toBeTrue();
});
