{{--
    A Live Assist session's report (v1.9 L7/L8, App\Filament\Pages\LiveAssist\AssistReportPage): the summary, the Q&A
    table, coaching, mistakes, promises, action items and the follow-up email. Being made: it looks again every 5 s.
--}}
@php
    use App\Services\Assist\AssistRecords;
    $r = $report ?? [];
    $clock = fn (int $ms): string => sprintf('%d:%02d', intdiv(intdiv($ms, 1000), 60), intdiv($ms, 1000) % 60);
    $cell = 'padding:.5rem .75rem;border-top:1px solid var(--gray-200);vertical-align:top;';
    $muted = 'color:var(--gray-500);';
    $tone = ['good' => 'success', 'weak' => 'warning', 'missed' => 'danger'];
    $c = (array) ($r['coaching'] ?? []);
    $aiNote = match ($r['ai']['reason'] ?? null) {
        'budget' => __("This month's allowance was used up, so the AI did not write the summary, the better answers or the email."),
        'not_set_up' => __('The AI is not set up on the server, so this report has no summary, better answers or email.'),
        'unavailable' => __('The AI could not write its part of this report. The rest is below.'),
        'nothing_said' => __('Nothing you said was heard, so there is nothing to coach.'),
        default => null,
    };
    $memoryNote = __('Added to the project’s memory.');
@endphp
<x-filament-panels::page>
    <div @if ($making || ! $session->ended()) wire:poll.5s @endif style="display:flex;flex-direction:column;gap:1.5rem" class="el-assist-report">
        <p style="{{ $muted }} margin:0">
            {{ $session->practice ? __('Practice') : __('Call') }} · {{ $meetingType }} ·
            {{ $session->started_at->copy()->setTimezone($tz)->format('M j, Y · g:i A') }} · {{ AssistRecords::duration($session->seconds()) }} ·
            {{ isset(AssistRecords::MODES[$session->mode]) ? __(AssistRecords::MODES[$session->mode]) : $session->mode }}
        </p>

        @if ($answers !== [])
            <x-filament::section :heading="__('Your questions')" :description="__('Answered from this session. Not kept: they go when you leave the page.')" data-assist-answers>
                <div style="display:flex;flex-direction:column;gap:1rem">
                    @foreach ($answers as $a)
                        <div>
                            <p style="margin:0;font-weight:600">{{ $a['question'] }}</p>
                            <p style="margin:.25rem 0 0;white-space:pre-line">{{ $a['answer'] }}</p>
                            @if ($a['citations'] !== [])
                                <ul style="margin:.5rem 0 0;padding-left:1.25rem;list-style:disc;font-size:.875rem">
                                    @foreach ($a['citations'] as $c)
                                        <li><span style="{{ $muted }}font-variant-numeric:tabular-nums">{{ $clock((int) $c['at_ms']) }} · {{ $c['channel'] === 'me' ? __('You') : __('Them') }}</span> “{{ $c['text'] }}”</li>
                                    @endforeach
                                </ul>
                            @endif
                        </div>
                    @endforeach
                </div>
            </x-filament::section>
        @endif

        @if (! $session->ended())
            <x-filament::section><p style="margin:0">{{ __('This session is on now. Its report is made when it ends.') }}</p></x-filament::section>
        @elseif ($report === null && $session->report_status === 'failed')
            <x-filament::section><p style="margin:0">{{ __('The report could not be made. Your words and the suggestions are kept; ask about the session, or delete it.') }}</p></x-filament::section>
        @elseif ($report === null)
            <x-filament::section><p style="margin:0" role="status">{{ __('The report is being made. It shows here in a moment.') }}</p></x-filament::section>
        @else
            @if ($making)
                <p style="{{ $muted }}margin:0" role="status">{{ __('Updating with the last words of the call…') }}</p>
            @endif
            @if ($aiNote)
                <x-filament::section compact><p style="margin:0">{{ $aiNote }}</p></x-filament::section>
            @endif

            @if (filled($r['summary'] ?? null))
                <x-filament::section :heading="__('Summary')">
                    <p style="margin:0;white-space:pre-line">{{ $r['summary'] }}</p>
                    @if (is_array($r['summary_translation'] ?? null))
                        <p style="margin:.75rem 0 0;white-space:pre-line" @if (($r['summary_translation']['language'] ?? '') !== 'roman_urdu') dir="auto" @endif
                           lang="{{ ['urdu' => 'ur', 'roman_urdu' => 'ur-Latn', 'arabic' => 'ar'][$r['summary_translation']['language']] ?? '' }}">{{ $r['summary_translation']['text'] }}</p>
                    @endif
                </x-filament::section>
            @endif

            <x-filament::section :heading="__('Questions and answers')" :description="count($r['qa'] ?? []) === 0 ? __('No questions were asked.') : null">
                @if (($r['qa'] ?? []) !== [])
                    <div style="overflow-x:auto">
                        <table style="width:100%;border-collapse:collapse;font-size:.875rem">
                            <thead><tr style="text-align:left">
                                <th scope="col" style="padding:.5rem .75rem">{{ __('Question') }}</th>
                                <th scope="col" style="padding:.5rem .75rem">{{ __('What you said') }}</th>
                                <th scope="col" style="padding:.5rem .75rem">{{ __('Suggested') }}</th>
                                <th scope="col" style="padding:.5rem .75rem">{{ __('How it went') }}</th>
                            </tr></thead>
                            <tbody>
                            @foreach ($r['qa'] as $row)
                                <tr>
                                    <td style="{{ $cell }}"><span style="{{ $muted }}font-variant-numeric:tabular-nums">{{ $clock((int) $row['at_ms']) }}</span> {{ $row['question'] }}</td>
                                    <td style="{{ $cell }}">{!! $row['answer'] === null ? '<span style="'.$muted.'">'.e(__('Not answered')).'</span>' : e($row['answer']) !!}</td>
                                    <td style="{{ $cell }}">
                                        @if ($row['hint'])
                                            {{ $row['hint']['text'] }}
                                            @if (($row['hint']['action'] ?? null) === 'used')
                                                <x-filament::badge color="gray" size="sm">{{ __('Used') }}</x-filament::badge>
                                            @endif
                                        @else
                                            <span style="{{ $muted }}">—</span>
                                        @endif
                                    </td>
                                    <td style="{{ $cell }}">
                                        @if ($row['verdict'])
                                            <x-filament::badge :color="$tone[$row['verdict']] ?? 'gray'">{{ __(AssistRecords::VERDICTS[$row['verdict']]) }}</x-filament::badge>
                                        @endif
                                        @if ($row['better_answer'])
                                            <p style="margin:.5rem 0 0"><strong>{{ __('Better:') }}</strong> {{ $row['better_answer'] }}</p>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-filament::section>

            <x-filament::section :heading="__('Coaching')">
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(10rem,1fr));gap:1rem">
                    @foreach ([
                        [__('Words per minute'), $c['words_per_minute'] ?? '—', __('About 120–150 is easy to follow')],
                        [__('Filler words'), (string) ($c['filler_words']['count'] ?? 0), collect($c['filler_words']['top'] ?? [])->map(fn ($f) => $f['word'].' ×'.$f['count'])->implode(', ') ?: __('None heard')],
                        [__('You talked'), isset($c['talk_ratio']['me']) ? $c['talk_ratio']['me'].'%' : '—', __('of the time either of you spoke')],
                        [__('Long pauses'), (string) ($c['long_pauses']['count'] ?? 0), __('before an answer, :seconds s or more', ['seconds' => intdiv((int) ($c['long_pauses']['threshold_ms'] ?? 3000), 1000)])],
                    ] as [$label, $value, $hint])
                        <div>
                            <div style="{{ $muted }}font-size:.875rem">{{ $label }}</div>
                            <div style="font-size:1.5rem;font-weight:600;font-variant-numeric:tabular-nums">{{ $value }}</div>
                            <div style="{{ $muted }}font-size:.75rem">{{ $hint }}</div>
                        </div>
                    @endforeach
                </div>
                @if (($c['grammar'] ?? []) !== [])
                    <h3 style="font-weight:600;margin:1.25rem 0 .5rem">{{ __('Grammar') }}</h3>
                    <ul style="margin:0;padding-left:1.25rem;list-style:disc">
                        @foreach ($c['grammar'] as $fix)
                            <li><s style="{{ $muted }}">{{ $fix['said'] }}</s> → {{ $fix['better'] }} <span style="{{ $muted }}">({{ $fix['why'] }})</span></li>
                        @endforeach
                    </ul>
                @endif
            </x-filament::section>

            @foreach ([['mistakes', __('Against the facts')], ['promises', __('Promises you made')], ['action_items', __('Action items')]] as [$key, $heading])
                @if (($r[$key] ?? []) !== [])
                    <x-filament::section :heading="$heading" :description="$key === 'action_items' && ($r['memory_added'] ?? 0) > 0 ? $memoryNote : null">
                        <ul style="margin:0;padding-left:1.25rem;list-style:disc">
                            @foreach ($r[$key] as $item)
                                @if (is_array($item))
                                    <li>{{ $item['said'] }} <span style="{{ $muted }}">— {{ $item['fact'] }}</span></li>
                                @else
                                    <li>{{ $item }}</li>
                                @endif
                            @endforeach
                        </ul>
                    </x-filament::section>
                @endif
            @endforeach

            @if (is_array($r['follow_up_email'] ?? null))
                @php($copyEmail = '{ text: '.\Illuminate\Support\Js::from($r['follow_up_email']['subject']."\n\n".$r['follow_up_email']['body']).' }')
                <x-filament::section :heading="__('Follow-up email')">
                    <p style="margin:0 0 .5rem;font-weight:600">{{ $r['follow_up_email']['subject'] }}</p>
                    <p style="margin:0;white-space:pre-line">{{ $r['follow_up_email']['body'] }}</p>
                    <div style="margin-top:.75rem">
                        <x-filament::button color="gray" size="sm" icon="heroicon-o-clipboard" :x-data="$copyEmail"
                            x-on:click="window.navigator.clipboard.writeText(text); $tooltip('{{ __('Copied') }}', { timeout: 1500 })">{{ __('Copy email') }}</x-filament::button>
                    </div>
                </x-filament::section>
            @endif

            @if (($r['facts'] ?? []) !== [])
                <x-filament::section :heading="__('Facts noted during the call')" collapsible collapsed>
                    <ul style="margin:0;padding-left:1.25rem;list-style:disc">
                        @foreach ($r['facts'] as $fact)
                            <li>{{ $fact }}</li>
                        @endforeach
                    </ul>
                </x-filament::section>
            @endif
        @endif
    </div>
    <style>
        @media print {
            .fi-sidebar, .fi-topbar, .fi-header-actions, .fi-breadcrumbs { display: none !important; }
            .el-assist-report .fi-section { break-inside: avoid; }
        }
    </style>
</x-filament-panels::page>
