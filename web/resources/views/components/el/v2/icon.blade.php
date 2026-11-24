{{--
    The v2.0 console's one icon family, the same glyphs as the desktop app (desktop/src/components/Icons.tsx): line
    glyphs on a 24-unit grid, one stroke, round ends, currentColor. Outlined at rest; `filled` for the page you are on.
    Its size comes from CSS (.v2-icon), never from here. Directional glyphs (chevrons, back) turn over right to left.
--}}
@props(['name', 'filled' => false])
@php
    $gear = 'M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z';
    $fill = $filled ? 'currentColor' : 'none';
    $dot = fn (float $x, float $y, float $r = 1.3): string => '<circle cx="'.$x.'" cy="'.$y.'" r="'.$r.'" fill="currentColor" stroke="none"/>';
    $glyphs = [
        'home' => '<path d="M3.5 10.5 12 3.8l8.5 6.7V19a1.5 1.5 0 0 1-1.5 1.5h-4.2v-6h-5.6v6H5A1.5 1.5 0 0 1 3.5 19z" fill="'.$fill.'"/>',
        'library' => '<rect x="7.5" y="3.5" width="13" height="13" rx="2.5"/>'.($filled
            ? '<path d="M10 3.5h8A2.5 2.5 0 0 1 20.5 6v8a2.5 2.5 0 0 1-2.5 2.5h-8A2.5 2.5 0 0 1 7.5 14V6A2.5 2.5 0 0 1 10 3.5zM12.3 7.2v5.6l4.5-2.8z" fill="currentColor" fill-rule="evenodd" stroke="none"/>'
            : '<path d="M12.5 7.6v4.8l4-2.4-4-2.4z"/>').'<path d="M3.5 8v10A2.5 2.5 0 0 0 6 20.5h10"/>',
        'live' => '<circle cx="12" cy="12" r="8.5"/>'.$dot(12, 12, $filled ? 5 : 3.5),
        'ask' => '<path d="M20.5 11.5a8.5 8.5 0 0 1-12.4 7.5L3.5 20l1.1-4.2A8.5 8.5 0 1 1 20.5 11.5z" fill="'.$fill.'"/>',
        'settings' => $filled
            ? '<path d="'.$gear.'M12 8.6a3.4 3.4 0 1 0 0 6.8 3.4 3.4 0 1 0 0-6.8z" fill="currentColor" fill-rule="evenodd"/>'
            : '<circle cx="12" cy="12" r="3"/><path d="'.$gear.'"/>',
        'folder' => '<path d="M3.5 7a2 2 0 0 1 2-2h3.8l2 2.2h7.2a2 2 0 0 1 2 2V17a2 2 0 0 1-2 2h-13a2 2 0 0 1-2-2V7z" fill="'.$fill.'"/>',
        'share' => '<path d="M8 10H6.5A1.5 1.5 0 0 0 5 11.5v7A1.5 1.5 0 0 0 6.5 20h11a1.5 1.5 0 0 0 1.5-1.5v-7a1.5 1.5 0 0 0-1.5-1.5H16" fill="'.$fill.'"/><path d="M12 14V3.5M8.5 7 12 3.5 15.5 7"/>',
        'trash' => '<path d="M4 7h16M10 11v6M14 11v6M5 7l1 13h12l1-13M9 7V4h6v3"/>',
        'search' => '<circle cx="11" cy="11" r="6.5"/><path d="m20 20-4.2-4.2"/>',
        'bell' => '<path d="M6 9.5a6 6 0 1 1 12 0c0 4.2 1.6 6.1 2.3 6.8a.4.4 0 0 1-.3.7H4a.4.4 0 0 1-.3-.7C4.4 15.6 6 13.7 6 9.5z" fill="'.$fill.'"/><path d="M10 20.2a2.2 2.2 0 0 0 4 0"/>',
        'help' => '<circle cx="12" cy="12" r="9"/><path d="M9.6 9.3a2.5 2.5 0 0 1 4.8.9c0 1.7-2.4 2.2-2.4 3.8"/>'.$dot(12, 17, 1.1),
        'menu' => '<path d="M4 7h16M4 12h16M4 17h16"/>',
        'chat' => '<path d="M20.5 11.5a8.5 8.5 0 0 1-12.4 7.5L3.5 20l1.1-4.2A8.5 8.5 0 1 1 20.5 11.5z"/>',
        'store' => '<path d="M4 9.5V20h16V9.5M3 9.5l1.6-5h14.8l1.6 5a2.6 2.6 0 0 1-5.1.6 2.6 2.6 0 0 1-5.1 0 2.6 2.6 0 0 1-5.1 0A2.6 2.6 0 0 1 3 9.5zM10 20v-5h4v5"/>',
        'video' => '<rect x="3" y="6" width="12.5" height="12" rx="2"/><path d="M15.5 10.5 21 7v10l-5.5-3.5"/>',
        'mic' => '<rect x="9" y="2.5" width="6" height="12" rx="3"/><path d="M5.5 11a6.5 6.5 0 0 0 13 0M12 17.5V21"/>',
        'phone' => '<path d="M5 4h3.2l1.6 4-2 1.3a10.5 10.5 0 0 0 4.9 4.9l1.3-2 4 1.6V17a2 2 0 0 1-2.2 2A15 15 0 0 1 3 6.2 2 2 0 0 1 5 4z"/>',
        'device' => '<rect x="7" y="2.5" width="10" height="19" rx="2.2"/><path d="M11 18.5h2"/>',
        'globe' => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c2.5 2.7 3.8 5.7 3.8 9s-1.3 6.3-3.8 9c-2.5-2.7-3.8-5.7-3.8-9S9.5 5.7 12 3z"/>',
        'dots' => '<circle cx="12" cy="12" r="9"/>'.$dot(8, 12, 1.1).$dot(12, 12, 1.1).$dot(16, 12, 1.1),
        'more' => $dot(6, 12, 1.6).$dot(12, 12, 1.6).$dot(18, 12, 1.6),
        'upload' => '<path d="M12 15V4M7.5 8.5 12 4l4.5 4.5M5 19.5h14"/>',
        'download' => '<path d="M12 4v11M7.5 10.5 12 15l4.5-4.5M5 19.5h14"/>',
        'link' => '<path d="M10.2 13.8a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1.1 1.1M13.8 10.2a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1.1-1.1"/>',
        'users' => '<circle cx="9" cy="8.5" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0M15.5 5.2a3.5 3.5 0 0 1 0 6.6M18 14.2a6.5 6.5 0 0 1 3.5 5.8"/>',
        'user' => '<circle cx="12" cy="8.5" r="3.8"/><path d="M4.5 20.5a7.5 7.5 0 0 1 15 0"/>',
        'file' => '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8l-5-5z"/><path d="M14 3v5h5M9 13h6M9 17h4"/>',
        'sparkle' => '<path d="M12 3.5 13.8 9a2 2 0 0 0 1.2 1.2l5.5 1.8-5.5 1.8a2 2 0 0 0-1.2 1.2L12 20.5 10.2 15a2 2 0 0 0-1.2-1.2L3.5 12 9 10.2A2 2 0 0 0 10.2 9L12 3.5z"/>',
        'captions' => '<rect x="3" y="5" width="18" height="14" rx="2.5"/><path d="M7 11h3M12 11h5M7 15h7"/>',
        'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'alert' => '<circle cx="12" cy="12" r="9"/><path d="M12 7.5v5.5M12 16.5h.01"/>',
        'check' => '<path d="m5 12.5 4.5 4.5L19 7.5"/>',
        'close' => '<path d="M6 6l12 12M18 6 6 18"/>',
        'chevron-end' => '<path d="m9 6 6 6-6 6"/>',
        'chevron-start' => '<path d="m14.5 6-6 6 6 6"/>',
        'chevron-down' => '<path d="m7 10 5 5 5-5"/>',
        // Back to top (UX-25).
        'arrow-up' => '<path d="M12 19.5v-15M5.5 11 12 4.5l6.5 6.5"/>',
        'play' => '<path d="M8 5.8v12.4a1 1 0 0 0 1.5.86l10-6.2a1 1 0 0 0 0-1.72l-10-6.2A1 1 0 0 0 8 5.8z" fill="currentColor" stroke="none"/>',
        'pin' => '<path d="M9 4h6M10 4v5.5L7 13v1.5h10V13l-3-3.5V4M12 14.5V20"/>',
        'tune' => '<path d="M4 7h9M17 7h3M4 17h3M11 17h9M15 5v4M9 15v4"/>',
        'inbox' => '<path d="M3.5 13.5 6.2 5.5h11.6l2.7 8M3.5 13.5V18a1.5 1.5 0 0 0 1.5 1.5h14a1.5 1.5 0 0 0 1.5-1.5v-4.5M3.5 13.5h4.8l1.4 2.3h4.6l1.4-2.3h4.8"/>',
        'transcript' => '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8l-5-5z"/><path d="M9 9h2M9 13h6M9 17h6"/>',
        'move' => '<path d="M3.5 7a2 2 0 0 1 2-2h3.8l2 2.2h7.2a2 2 0 0 1 2 2V17a2 2 0 0 1-2 2h-13a2 2 0 0 1-2-2V7z"/><path d="M10 13h5M13 11l2 2-2 2"/>',
        'star' => '<path d="m12 3.8 2.5 5.1 5.6.8-4 3.9.9 5.6-5-2.6-5 2.6.9-5.6-4-3.9 5.6-.8z" fill="'.$fill.'"/>',
        'flag' => '<path d="M5.5 20.5V4.5M5.5 4.5h11l-2.2 4 2.2 4h-11" fill="'.$fill.'"/>',
        'list' => '<path d="M9 6.5h11M9 12h11M9 17.5h11"/>'.$dot(4.8, 6.5).$dot(4.8, 12).$dot(4.8, 17.5),
        'table' => '<rect x="3.5" y="4.5" width="17" height="15" rx="2"/><path d="M3.5 9.5h17M3.5 14.5h17M9.5 9.5v10"/>',
        'columns' => '<rect x="3.5" y="4.5" width="17" height="15" rx="2"/><path d="M9.2 4.5v15M14.8 4.5v15"/>',
        'group' => '<path d="M4 5.5h16M4 12.5h16"/><path d="M7 8.5h10M7 15.5h10M7 18.5h7"/>',
        'plus' => '<path d="M12 5v14M5 12h14"/>',
        'record' => '<circle cx="12" cy="12" r="8"/>'.$dot(12, 12, 4),
        'activity' => '<path d="M3.5 12h3.8l2.4-6.5 4.6 13 2.4-6.5h3.8"/>',
        'copy' => '<rect x="8.5" y="8.5" width="12" height="12" rx="2"/><path d="M15.5 8.5V5.5a2 2 0 0 0-2-2h-8a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h3"/>',
        // A label (the desktop's tag) and Rename (its pencil), for the right-click menus (UX-22).
        'label' => '<path d="M3.5 5.5a2 2 0 0 1 2-2h5.2a2 2 0 0 1 1.4.6l8.3 8.3a2 2 0 0 1 0 2.8l-5.2 5.2a2 2 0 0 1-2.8 0l-8.3-8.3a2 2 0 0 1-.6-1.4z"/>'.$dot(8, 8),
        'rename' => '<path d="M14.5 5.5 18.5 9.5M4 20l1-4.5L16 4.5a2.1 2.1 0 0 1 3 3L8 18.5z"/>',
    ];
    $directional = in_array($name, ['chevron-end', 'chevron-start'], true);
@endphp
<svg {{ $attributes->class(['v2-icon', 'v2-icon-flip' => $directional]) }} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"
    stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">{!! $glyphs[$name] ?? $glyphs['dots'] !!}</svg>
