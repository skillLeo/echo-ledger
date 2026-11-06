<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\TranscriptKind;
use App\Enums\TranscriptStatus;
use App\Http\Controllers\Api\PackController;
use App\Models\CaptureSession;
use App\Services\AuditLog;
use App\Services\Screens\MeetingPack;
use App\Services\Workspaces\CurrentWorkspace;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Export → Meeting pack on the recording page (session + workspace-member middleware on the route): `html` opens in a
 * new tab with a Print button, `zip` downloads. Nothing without a finished transcript.
 */
final class AdminPackController extends Controller
{
    public function __invoke(string $session, string $format, MeetingPack $pack): BinaryFileResponse
    {
        abort_unless(in_array($format, MeetingPack::FORMATS, true), 404);
        $model = CaptureSession::query()->where('workspace_id', CurrentWorkspace::id())->where('user_id', CurrentWorkspace::userId())->find($session);
        abort_if(! $model instanceof CaptureSession, 404);
        $view = request()->query('view');
        $view = is_string($view) && in_array($view, MeetingPack::VIEWS, true) ? $view : null;
        $finished = $model->transcripts()->where('kind', TranscriptKind::Final->value)->where('status', TranscriptStatus::Completed->value)->exists();
        abort_unless($finished, 404);
        $file = $pack->build($model, $format, $view, request()->boolean('include_audio'), printBar: $format === 'html');
        app(AuditLog::class)->record('transcript_exported', (int) auth()->id(), $model, "Meeting pack exported as {$format}", ['format' => 'pack_'.$format]);

        return PackController::send($file, inline: $format === 'html');
    }
}
