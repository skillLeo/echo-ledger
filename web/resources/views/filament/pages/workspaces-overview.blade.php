@php $cell = 'padding:.5rem .75rem;border-top:1px solid var(--gray-200);'; @endphp
<x-filament::section :heading="__('All workspaces')" :description="__('Server admin view: names and totals this month only, never recordings. Plans are made and assigned on the Plans page.')">
    <div style="overflow-x:auto">
        <table style="width:100%;font-size:.85rem;border-collapse:collapse">
            <thead>
                <tr style="text-align:left;opacity:.7">
                    <th style="padding:.5rem .75rem">{{ __('Workspace') }}</th><th style="padding:.5rem .75rem">{{ __('Plan') }}</th>
                    <th style="padding:.5rem .75rem;text-align:right">{{ __('Members') }}</th><th style="padding:.5rem .75rem;text-align:right">{{ __('Devices') }}</th>
                    <th style="padding:.5rem .75rem;text-align:right">{{ __('Minutes this month') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $row)
                    <tr>
                        <td style="{{ $cell }}">{{ $row['name'] }}</td><td style="{{ $cell }}">{{ $row['plan'] }}</td>
                        <td style="{{ $cell }}text-align:right">{{ $row['members'] }}</td><td style="{{ $cell }}text-align:right">{{ $row['devices'] }}</td>
                        <td style="{{ $cell }}text-align:right;font-variant-numeric:tabular-nums">{{ number_format($row['minutes'], 1) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</x-filament::section>
