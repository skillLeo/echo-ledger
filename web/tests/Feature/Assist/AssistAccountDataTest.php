<?php

declare(strict_types=1);

use App\Http\Controllers\Api\Assist\AssistSessionController;
use App\Jobs\MakeAssistReportJob;
use App\Models\AssistPrep;
use App\Models\AssistSession;
use App\Models\AssistSuggestion;
use App\Models\AssistTurn;
use App\Models\AssistUsage;
use App\Models\ClientFile;
use App\Models\ClientMemoryItem;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Accounts\AccountDeletion;
use App\Services\Assist\AssistDefaults;
use App\Services\Workspaces\Workspaces;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Security\Support\ApiClient;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

require_once __DIR__.'/Support/assist_helpers.php';

uses(ApiClient::class);

/*
 * A person's Live Assist in "Export my data" and in the account's erasure (v1.9): their settings, preps with their
 * documents, and every session with its lines, suggestions, facts, summary, report and usage, in every workspace.
 */

beforeEach(function () {
    assistRelaySetUp();
    Mail::fake();
});

/** Everything Live Assist keeps of [$device]'s person: a prep with a document, a reported session, a deleted one, one in a team. */
function assistData(object $test, $device): array
{
    $user = $device->user;
    AssistDefaults::save($user, ['interview' => ['mode' => 'fast', 'language_help' => 'roman_urdu']]);
    $project = Fx::client($user, 'Acme');
    $jd = ClientFile::query()->create(['workspace_id' => $device->workspace_id, 'user_id' => $user->id, 'client_id' => $project->id,
        'name' => 'JD.pdf', 'mime' => 'application/pdf', 'size_bytes' => 10, 'status' => 'ready', 'text' => 'Job text']);
    $prep = AssistPrep::query()->withoutGlobalScopes()->create(['workspace_id' => $device->workspace_id, 'user_id' => $user->id, 'client_id' => $project->id,
        'meeting_type' => 'interview', 'title' => 'PREP-TITLE-SECRET', 'goals' => 'PREP-GOAL-SECRET', 'hint_level' => 'outline', 'mode' => 'fast', 'language_help' => 'off', 'status' => 'draft']);
    $prep->documents()->attach($jd->id, ['tokens' => 2]);

    fakeAssistReportAi();
    $session = AssistSession::query()->withoutGlobalScopes()->create(['workspace_id' => $device->workspace_id, 'user_id' => $user->id, 'device_id' => $device->id,
        'assist_prep_id' => $prep->id, 'mode' => 'fast', 'plan' => 'pro', 'practice' => false, 'platform' => 'desktop', 'status' => 'active', 'started_at' => now()->subMinutes(5),
        'summary' => 'SUMMARY-SECRET', 'facts' => ['FACT-SECRET']]);
    AssistTurn::query()->create(['assist_session_id' => $session->id, 'client_ref' => 'o-1', 'channel' => 'other', 'start_ms' => 0, 'end_ms' => 1000, 'text' => 'THEM-SAID-SECRET?', 'is_question' => true]);
    AssistTurn::query()->create(['assist_session_id' => $session->id, 'client_ref' => 'm-1', 'channel' => 'me', 'start_ms' => 1500, 'end_ms' => 3000, 'text' => 'I-SAID-SECRET']);
    AssistSuggestion::query()->create(['assist_session_id' => $session->id, 'suggestion_id' => 's-1', 'turn_id' => 'o-1', 'text' => 'HINT-SECRET', 'shown_at' => now()]);
    AssistSessionController::close($session, now());
    (new MakeAssistReportJob($session->id))->handle();

    $deleted = AssistSession::query()->withoutGlobalScopes()->create(['workspace_id' => $device->workspace_id, 'user_id' => $user->id, 'mode' => 'fast',
        'practice' => true, 'platform' => 'android', 'status' => 'ended', 'started_at' => now()->subDays(2), 'ended_at' => now()->subDays(2), 'seconds_used' => 60]);
    $deleted->delete();

    $owner = Fx::user();
    $team = Workspace::query()->findOrFail($owner->current_workspace_id);
    app(Workspaces::class)->addMember($team, $user);
    $inTeam = AssistSession::query()->withoutGlobalScopes()->create(['workspace_id' => $team->id, 'user_id' => $user->id, 'mode' => 'fast',
        'practice' => true, 'platform' => 'android', 'status' => 'ended', 'started_at' => now()->subDay(), 'ended_at' => now()->subDay(), 'seconds_used' => 30]);
    AssistTurn::query()->create(['assist_session_id' => $inTeam->id, 'client_ref' => 'm-9', 'channel' => 'me', 'start_ms' => 0, 'end_ms' => 1000, 'text' => 'TEAM-SAID-SECRET']);

    // Someone else's, which must stay out of the export and stay when this account goes.
    $other = assistPerson('pro');
    $theirs = AssistSession::query()->withoutGlobalScopes()->create(['workspace_id' => $other->workspace_id, 'user_id' => $other->user_id, 'mode' => 'fast',
        'practice' => true, 'platform' => 'android', 'status' => 'ended', 'started_at' => now(), 'ended_at' => now(), 'seconds_used' => 1]);
    AssistTurn::query()->create(['assist_session_id' => $theirs->id, 'client_ref' => 'x', 'channel' => 'me', 'start_ms' => 0, 'end_ms' => 1, 'text' => 'SOMEONE-ELSE-SAID']);

    return compact('user', 'prep', 'session', 'deleted', 'inTeam', 'theirs', 'project');
}

it('puts the person\'s Live Assist in Export my data, decrypted for them, from every workspace, and nobody else\'s', function () {
    $device = assistPerson('pro');
    $made = assistData($this, $device);

    $id = $this->api(Fx::token($device))->postJson('/api/account/export')->assertStatus(202)->json('id');
    $url = (string) $this->api(Fx::token($device))->getJson("/api/account/export/{$id}")->assertJsonPath('status', 'ready')->json('url');
    app('auth')->forgetGuards();
    $path = tempnam(sys_get_temp_dir(), 'zip');
    file_put_contents($path, $this->get($url)->assertOk()->streamedContent());
    $zip = new ZipArchive;
    expect($zip->open($path))->toBeTrue();

    $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
    $assist = $manifest['live_assist'];
    expect($assist['settings'])->toBe('live-assist/settings.json')->and($assist['preps'])->toBe('live-assist/preps.json')
        ->and(collect($assist['sessions'])->pluck('id')->sort()->values()->all())->toBe(collect([$made['session']->id, $made['deleted']->id, $made['inTeam']->id])->sort()->values()->all());
    expect(json_decode((string) $zip->getFromName('live-assist/settings.json'), true)['defaults']['interview'])->toBe(['mode' => 'fast', 'hint_level' => 'outline', 'language_help' => 'roman_urdu']);
    $preps = json_decode((string) $zip->getFromName('live-assist/preps.json'), true);
    expect($preps[0])->toMatchArray(['title' => 'PREP-TITLE-SECRET', 'goals' => 'PREP-GOAL-SECRET', 'project_id' => $made['project']->id])
        ->and($preps[0]['documents'])->toBe([['file_id' => $preps[0]['documents'][0]['file_id'], 'name' => 'JD.pdf']]);

    $folder = collect($assist['sessions'])->firstWhere('id', $made['session']->id)['folder'];
    $session = json_decode((string) $zip->getFromName("{$folder}/session.json"), true);
    expect(array_column($session['turns'], 'text'))->toBe(['THEM-SAID-SECRET?', 'I-SAID-SECRET'])
        ->and($session['suggestions'][0]['text'])->toBe('HINT-SECRET')
        ->and($session['summary'])->toBe('SUMMARY-SECRET')->and($session['facts'])->toBe(['FACT-SECRET'])
        ->and($session['report']['summary'])->toBe('They asked about your notice period and salary.')
        ->and(collect($session['usage'])->pluck('kind')->all())->toBe(['report'])
        ->and((string) $zip->getFromName("{$folder}/report.md"))->toContain('## Questions and answers')->toContain('THEM-SAID-SECRET?');
    $team = json_decode((string) $zip->getFromName(collect($assist['sessions'])->firstWhere('id', $made['inTeam']->id)['folder'].'/session.json'), true);
    expect($team['turns'][0]['text'])->toBe('TEAM-SAID-SECRET')
        ->and(json_decode((string) $zip->getFromName(collect($assist['sessions'])->firstWhere('id', $made['deleted']->id)['folder'].'/session.json'), true)['deleted_at'])->not->toBeNull();

    $all = '';
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $all .= (string) $zip->getFromIndex($i);
    }
    expect($all)->not->toContain('SOMEONE-ELSE-SAID');
    $zip->close();
    @unlink($path);
});

it('erases all of the person\'s Live Assist with the account, in every workspace, and leaves everyone else\'s', function () {
    $device = assistPerson('pro');
    $made = assistData($this, $device);
    $made['session']->refresh();
    ClientMemoryItem::query()->withoutGlobalScopes()->create(['workspace_id' => $device->workspace_id, 'client_id' => $made['project']->id, 'kind' => 'decision',
        'text' => 'My action: ACTION-SECRET', 'source_type' => 'user', 'assist_session_id' => $made['session']->id]);
    $ids = [$made['session']->id, $made['deleted']->id, $made['inTeam']->id];
    expect(AssistUsage::query()->whereIn('assist_session_id', $ids)->count())->toBeGreaterThan(0);

    app(AccountDeletion::class)->schedule($made['user']->fresh());
    $this->travel(7)->days();
    $this->travel(1)->minutes();
    expect(Artisan::call('echoledger:erase-accounts'))->toBe(0);

    expect(User::query()->find($made['user']->id))->toBeNull()
        ->and(AssistSession::query()->withoutGlobalScopes()->withTrashed()->whereIn('id', $ids)->count())->toBe(0)
        ->and(AssistTurn::query()->whereIn('assist_session_id', $ids)->count())->toBe(0)
        ->and(AssistSuggestion::query()->whereIn('assist_session_id', $ids)->count())->toBe(0)
        ->and(AssistUsage::query()->whereIn('assist_session_id', $ids)->count())->toBe(0)
        ->and(AssistPrep::query()->withoutGlobalScopes()->withTrashed()->where('user_id', $made['user']->id)->count())->toBe(0)
        ->and(DB::table('assist_prep_documents')->where('assist_prep_id', $made['prep']->id)->count())->toBe(0)
        ->and(ClientMemoryItem::query()->withoutGlobalScopes()->where('text', 'like', '%ACTION-SECRET%')->count())->toBe(0);
    // Someone else's stays.
    expect(AssistSession::query()->withoutGlobalScopes()->find($made['theirs']->id))->not->toBeNull()
        ->and(AssistTurn::query()->where('assist_session_id', $made['theirs']->id)->value('text'))->toBe('SOMEONE-ELSE-SAID');
});
