@props(['url'])
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block; color: #1C2024; font-weight: 700; font-size: 19px; letter-spacing: -0.01em; text-decoration: none;">
{{-- A PNG, not the SVG mark: many mail clients block SVG. Absolute, because a mail has no base address. --}}
<img src="{{ rtrim((string) config('app.url'), '/') }}/mail-mark.png" width="28" height="28" alt="" style="vertical-align: middle; border: 0; border-radius: 7px; margin-right: 8px;">
<span style="vertical-align: middle;">{!! $slot !!}</span>
</a>
</td>
</tr>
