{{-- The upgrade sheet (since v1.6): what the feature does, the plans side by side, and a request already waiting. --}}
<div style="display:grid;gap:1rem">
    @if ($feature)
        <div>
            <p style="font-weight:600">{{ $feature['name'] }}</p>
            <p style="opacity:.8;font-size:.875rem">{{ $feature['description'] }}</p>
        </div>
    @endif
    @if ($pending)
        <div role="status" style="padding:.75rem;border-radius:.75rem;background:color-mix(in srgb, #f59e0b 12%, transparent)">
            <p style="font-weight:600">Your request for {{ $pending['plan_name'] }} is waiting.</p>
            <p style="font-size:.875rem;white-space:pre-line;margin-top:.25rem">{{ $instructions }}</p>
            <p style="font-size:.875rem;margin-top:.25rem">We'll activate it once confirmed.</p>
        </div>
    @endif
    <div style="overflow-x:auto">
        <table style="width:100%;font-size:.8125rem;border-collapse:collapse">
            <thead>
                <tr>
                    <th style="text-align:left;padding:.375rem"></th>
                    @foreach ($plans as $plan)
                        <th style="text-align:left;padding:.375rem">
                            <x-plan-badge :badge="$plan['badge']" :color="$plan['color']" :locked="false" />
                            <div style="font-weight:400;opacity:.8;margin-top:.25rem">{{ $plan['price'] }}</div>
                            @if ($plan['key'] === $current)<div style="font-weight:400;font-size:.75rem;opacity:.7">Your plan</div>@endif
                        </th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $row)
                    <tr style="border-top:1px solid color-mix(in srgb, currentColor 10%, transparent){{ $row['highlight'] ? ';font-weight:600' : '' }}">
                        <td style="padding:.375rem">{{ $row['label'] }}</td>
                        @foreach ($row['values'] as $value)
                            <td style="padding:.375rem">{{ $value }}</td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
