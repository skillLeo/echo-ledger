{{-- Plain text reads as text: no Markdown heading marks, asterisks of bold or table rules. --}}
@php($plain = fn ($text) => preg_replace(
    ['/^#{1,6}\s*/m', '/\*\*(.+?)\*\*/s', '/^\|[ \t:|-]*\|[ \t]*\R?/m', '/^\|\s*([^|\n]+?)\s*\|\s*([^|\n]+?)\s*\|[ \t]*$/m'],
    ['', '$1', '', '$1: $2'],
    strip_tags((string) $text)
))
{!! $plain($header ?? '') !!}

{!! $plain($slot) !!}
@isset($subcopy)

{!! $plain($subcopy) !!}
@endisset

{!! $plain($footer ?? '') !!}
