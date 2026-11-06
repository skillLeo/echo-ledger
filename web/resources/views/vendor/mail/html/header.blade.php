@props(['url'])
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block; color: #3E63DD; font-weight: 700; letter-spacing: -0.01em;">
{!! $slot !!}
</a>
</td>
</tr>
