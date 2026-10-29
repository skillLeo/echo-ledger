<?php
// Read-only production report (no audio, transcript text or secret values). Run from the app folder:
//   Get-Content tools/prod-report.php -Raw | ssh <ssh alias> "cd ~/domains/<app domain>/public_html && /opt/alt/php84/usr/bin/php"
// EL_DAYS=<n> changes the window (default 14).
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$days = (int) (getenv('EL_DAYS') ?: 14);
$since = now()->subDays($days);
$meta = fn ($json) => is_string($json) ? (json_decode($json, true) ?: []) : [];
$cut = fn ($s, $n = 110) => $s === null ? '' : mb_substr(preg_replace('/\s+/', ' ', (string) $s), 0, $n);

$sessions = DB::table('capture_sessions as s')
    ->leftJoin('devices as d', 'd.id', '=', 's.device_id')
    ->where('s.created_at', '>=', $since)
    ->orderBy('s.created_at')
    ->get(['s.id', 's.created_at', 's.deleted_at', 's.source_app', 's.source_device', 's.language', 's.status', 's.duration_ms', 'd.name as device']);
$ids = $sessions->pluck('id')->all();
$recs = DB::table('recordings')->whereIn('capture_session_id', $ids)->get()->groupBy('capture_session_id');
$trs = DB::table('transcripts')->whereIn('capture_session_id', $ids)->get()->groupBy('capture_session_id');

echo "== sessions since {$since->toDateTimeString()} UTC: ".count($sessions)."\n";
$causes = [];
foreach ($sessions as $s) {
    $r = $recs->get($s->id)?->first();
    $all = $trs->get($s->id) ?? collect();
    $f = $all->firstWhere('kind', 'final');
    $m = $f ? $meta($f->meta) : [];
    $cause = null;
    if ($r === null) {
        $cause = in_array($s->status, ['recording', 'uploading'], true) ? 'no recording uploaded (session '.$s->status.')' : 'no recording';
    } elseif ($r->upload_status !== 'ready') {
        $cause = 'upload '.$r->upload_status;
    } elseif ($f === null) {
        $cause = 'no final transcript';
    } elseif ($f->status === 'failed') {
        $cause = 'failed: '.($f->error_code ?: 'no code').' / '.$f->provider;
    } elseif (! in_array($f->status, ['completed'], true) && strtotime($f->updated_at) < time() - 3600) {
        $cause = 'stuck '.$f->status.' / '.$f->provider;
    } elseif ($f->status === 'completed' && trim((string) $f->text) === '') {
        $cause = 'completed but empty / '.$f->provider;
    }
    if ($cause !== null) {
        $causes[$cause][] = $s->id;
    }
    printf(
        "%s %s %-17s %-14s %-10s %5ss rec:%s ch%s %s final:%s %s %s att%s lang:%s->%s%s%s\n",
        substr($s->created_at, 5, 11), substr($s->id, -6), $s->source_app, $cut($s->device, 14), $s->status,
        $s->duration_ms === null ? '-' : round($s->duration_ms / 1000),
        $r->upload_status ?? '-', $r->channels ?? '-', $r ? round(($r->stored_size_bytes ?? 0) / 1024).'KB' : '',
        $f->provider ?? '-', $f->status ?? '-', $f->error_code ?? '', $f->attempts ?? '-',
        $s->language ?? 'auto', $f->language ?? '-',
        $s->deleted_at ? ' DELETED' : '',
        $cause ? '  <<< '.$cause.($f && $f->error ? ' | '.$cut($f->error) : '').($r && $r->error ? ' | rec: '.$cut($r->error) : '') : ''
    );
}
echo "\n== problems by cause\n";
ksort($causes);
foreach ($causes as $cause => $list) {
    printf("%3d  %s\n", count($list), $cause);
}

echo "\n== last 20 final transcripts: language chosen / detected, engine + model, fallback\n";
$last = DB::table('transcripts as t')->join('capture_sessions as s', 's.id', '=', 't.capture_session_id')
    ->where('t.kind', 'final')->orderByDesc('t.created_at')->limit(20)
    ->get(['t.capture_session_id', 't.created_at', 't.provider', 't.status', 't.language', 't.meta', 's.language as chosen', 's.source_app']);
foreach ($last as $t) {
    $m = $meta($t->meta);
    $route = $m['route'] ?? [];
    printf(
        "%s %s %-17s chosen:%-5s detected:%-5s %-10s model:%-22s %-9s fallback:%s key:%s forced:%s%s\n",
        substr($t->created_at, 5, 11), substr($t->capture_session_id, -6), $t->source_app, $t->chosen ?? 'auto', $t->language ?? '-',
        $t->provider, $cut($m['speech_model_used'] ?? $m['model'] ?? $m['speech_model'] ?? '-', 22), $t->status,
        json_encode($route['fallback'] ?? null), $route['key'] ?? $route['route_key'] ?? '-', $m['forced_language'] ?? '-',
        isset($route['tried']) ? ' tried:'.json_encode($route['tried']) : (isset($route['chain']) ? ' chain:'.json_encode($route['chain']) : '')
    );
}

echo "\n== API credentials present (names only)\n";
foreach (DB::table('credentials')->orderBy('provider')->get(['provider', 'key', 'updated_at']) as $c) {
    printf("%-12s %-24s %s\n", $c->provider, $c->key, $c->updated_at);
}
echo "\n== engine each of the last 10 recordings would get now (current keys and routing)\n";
$router = app(App\Stt\LanguageRouter::class);
foreach (App\Models\CaptureSession::query()->withoutGlobalScopes()->latest('created_at')->limit(10)->get() as $s) {
    $language = $router->languageFor($s);
    $app = $s->source_app instanceof App\Enums\SourceApp ? $s->source_app->value : (string) $s->source_app;
    $useCase = $app === 'voice_note' ? App\Enums\SttUseCase::VoiceNote : App\Enums\SttUseCase::FinalTranscript;
    $d = $router->final($language, $useCase, $s);
    printf("%s %s %-17s lang:%-5s -> %-11s wanted:%-11s fallback:%s\n", substr((string) $s->created_at, 5, 11), substr($s->id, -6), $app, $language ?? 'auto', $d->provider->value, $d->wanted->value, json_encode($d->fallback));
}
echo "\n== routing and audio settings\n";
foreach (DB::table('settings')->where(fn ($q) => $q->where('key', 'like', 'routing%')->orWhere('key', 'like', 'audio%')->orWhere('key', 'like', 'languages%')->orWhere('key', 'like', 'accuracy%'))->get(['key', 'value']) as $row) {
    printf("%-32s %s\n", $row->key, $cut($row->value, 160));
}
echo "\n== queue\n";
printf("jobs waiting: %d, failed jobs (%dd): %d\n", DB::table('jobs')->count(), $days, DB::table('failed_jobs')->where('failed_at', '>=', $since)->count());
foreach (DB::table('failed_jobs')->where('failed_at', '>=', $since)->orderByDesc('failed_at')->limit(8)->get(['failed_at', 'payload', 'exception']) as $j) {
    $name = $meta($j->payload)['displayName'] ?? '?';
    printf("%s %s %s\n", $j->failed_at, $name, $cut(strtok((string) $j->exception, "\n"), 160));
}
