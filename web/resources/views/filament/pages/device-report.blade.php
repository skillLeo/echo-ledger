<div class="space-y-3 text-sm">
    <p class="text-gray-500 dark:text-gray-400">
        {{ trim($report->platform.' '.$report->os_version) }} · EchoLedger {{ $report->app_version ?? '?' }}
    </p>
    <ul class="space-y-2">
        @foreach ($report->checks as $check)
            <li>
                <span class="font-medium">{{ match ($check['state']) { 'ok' => 'Works', 'limited' => 'Limited', 'off' => 'Off', default => 'Failed' } }}</span>
                · {{ str_replace('_', ' ', $check['key']) }}
                @if (filled($check['detail'] ?? null))
                    <span class="block text-gray-500 dark:text-gray-400">{{ $check['detail'] }}</span>
                @endif
            </li>
        @endforeach
    </ul>
</div>
