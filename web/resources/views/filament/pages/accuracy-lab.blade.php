<x-filament-panels::page>
    @php
        $pct = fn (?float $v): string => $v === null ? '—' : number_format($v * 100, 1).'%';
        $cell = 'padding:.5rem .75rem;border-top:1px solid var(--gray-200);';
        $num = $cell.'text-align:right;font-variant-numeric:tabular-nums;';
        $head = 'padding:.5rem .75rem;text-align:right';
    @endphp

    <div style="display:flex;flex-direction:column;gap:1.5rem">
        <x-filament::section heading="How to test the engines" collapsible :collapsed="$report['recordings'] !== []">
            <ol style="list-style:decimal;padding-left:1.25rem;display:flex;flex-direction:column;gap:.35rem;font-size:.9rem">
                <li>Pick about 10 typical recordings per language (real calls, both speakers, a few noisy ones).</li>
                <li>On each recording choose <strong>⋯ → Use as Accuracy Lab reference</strong>, correct the words exactly as spoken and save.</li>
                <li>Choose <strong>⋯ → Compare engines</strong> with up to three engines for that language.</li>
                <li>Come back here when the runs are done: each one is scored against your reference.</li>
                <li>Press <strong>Use the winners</strong> to make the best engine the first one for each language.</li>
            </ol>
            <p style="font-size:.8rem;opacity:.7;margin-top:.5rem">
                Word error rate (WER) and character error rate (CER) count the words or letters an engine got wrong, left out or added,
                after ignoring case, punctuation and Urdu spelling variants. Lower is better. Long recordings are scored on their first 4,000 words.
            </p>
        </x-filament::section>

        @forelse ($report['languages'] as $language)
            <x-filament::section :heading="$language['label']">
                <div style="overflow-x:auto">
                    <table style="width:100%;font-size:.85rem;border-collapse:collapse">
                        <thead><tr style="text-align:left;opacity:.7">
                            <th style="padding:.5rem .75rem">Engine</th><th style="{{ $head }}">Recordings</th><th style="{{ $head }}">WER</th>
                            <th style="{{ $head }}">CER</th><th style="{{ $head }}">Seconds per audio minute</th><th style="{{ $head }}">Cost per audio hour</th>
                        </tr></thead>
                        <tbody>
                            @foreach ($language['engines'] as $engine)
                                <tr>
                                    <td style="{{ $cell }}">{{ $engine['label'] }} @if ($engine['provider'] === $language['winner']) <span style="font-size:.7rem;opacity:.7">· best</span> @endif</td>
                                    <td style="{{ $num }}">{{ $engine['recordings'] }}</td>
                                    <td style="{{ $num }}">{{ $pct($engine['wer']) }}</td>
                                    <td style="{{ $num }}">{{ $pct($engine['cer']) }}</td>
                                    <td style="{{ $num }}">{{ $engine['seconds_per_audio_minute'] ?? '—' }}</td>
                                    <td style="{{ $num }}">{{ $engine['cost_per_hour_usd'] === null ? '—' : '$'.number_format($engine['cost_per_hour_usd'], 3) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-filament::section>
        @empty
            <x-filament::section>
                <p style="opacity:.7">No scores yet. Mark a recording as a reference and run "Compare engines" on it.</p>
            </x-filament::section>
        @endforelse

        @if ($report['recordings'] !== [])
            <x-filament::section heading="Reference recordings">
                <div style="overflow-x:auto">
                    <table style="width:100%;font-size:.85rem;border-collapse:collapse">
                        <thead><tr style="text-align:left;opacity:.7"><th style="padding:.5rem .75rem">Recording</th><th style="padding:.5rem .75rem">Language</th><th style="padding:.5rem .75rem">Word error rate per engine</th></tr></thead>
                        <tbody>
                            @foreach ($report['recordings'] as $recording)
                                <tr>
                                    <td style="{{ $cell }}"><a href="{{ \App\Filament\Resources\CaptureSessions\CaptureSessionResource::getUrl('view', ['record' => $recording['id']]) }}" style="text-decoration:underline" dir="auto">{{ $recording['title'] }}</a></td>
                                    <td style="{{ $cell }}">{{ $recording['language'] }}</td>
                                    <td style="{{ $cell }}">
                                        @forelse ($recording['scores'] as $provider => $score)
                                            <span style="margin-right:.75rem">{{ \App\Enums\SttProviderKey::tryFrom($provider)?->getLabel() ?? $provider }}: {{ $score === null ? 'scoring…' : $pct($score['wer']) }}</span>
                                        @empty
                                            <span style="opacity:.6">No "Compare engines" runs yet</span>
                                        @endforelse
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-filament::section>
        @endif
    </div>
</x-filament-panels::page>
