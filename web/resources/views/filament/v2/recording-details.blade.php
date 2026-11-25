{{--
    Details (v2.0): the recording's facts as label and value rows, in the console's language. Names, numbers and the
    device keep their own direction (<bdi>). Which engine wrote the transcript is for the server's operator only.
--}}
@php
    use App\Filament\Resources\CaptureSessions\Tables\CaptureSessionsTable;
    use App\Filament\Support\V2RecordingRow;
    use App\Services\Workspaces\ConsoleAccess;

    $record = $getRecord();
    $final = $record->finalTranscript;
    $zone = app(\App\Services\SettingsRepository::class)->displayTimezone();
    $at = $record->started_at->copy()->setTimezone($zone)->locale(app()->getLocale());
    $with = trim(($record->caller_name ?? '').' '.($record->caller_number !== null ? '('.$record->caller_number.')' : ''));
    $rows = array_filter([
        [__('Source'), __($record->source_device->getLabel())],
        [__('App'), V2RecordingRow::source($record->source_app)],
        [__('Started'), $at->translatedFormat('j F Y, '.(\App\Filament\Support\Shell::timeFormat() === 'H:i' ? 'H:i' : 'g:i a'))],
        [__('Length'), CaptureSessionsTable::duration($record->duration_ms) ?: '—'],
        [__('Project'), $record->client?->name ?? '—'],
        [__('recording_context.with_person'), $with !== '' ? $with : '—'],
        [__('Device'), CaptureSessionsTable::deviceLabel($record)],
        $record->hasNoAudio() ? [__('Status'), __('No audio')] : [__('Transcript'), $final !== null ? __($final->status->getLabel()) : __('Not started')],
        [__('Language'), $final?->language !== null ? __(\App\Stt\LanguageChoice::label($final->language)) : __('Automatic')],
        ConsoleAccess::operator() && $final?->provider !== null ? [__('Transcribed by'), \App\Enums\SttProviderKey::tryFrom((string) $final->provider)?->getLabel() ?? (string) $final->provider] : null,
    ]);
@endphp
<dl class="v2-details">
    @foreach ($rows as [$label, $value])
        <div class="v2-details-row">
            <dt>{{ $label }}</dt>
            <dd><bdi>{{ $value }}</bdi></dd>
        </div>
    @endforeach
</dl>
