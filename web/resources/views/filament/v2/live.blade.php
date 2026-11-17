{{-- Live (v2.0): grouped rows, one icon family, the value or the reason at the end of each row. --}}
<x-filament-panels::page>
    <div class="v2-page v2-stack">
        @foreach ($sections as $section)
            <section class="v2-group" aria-labelledby="live-{{ $loop->index }}">
                <h2 class="v2-group-title" id="live-{{ $loop->index }}">{{ $section['title'] }}</h2>
                <ul class="v2-list">
                    @foreach ($section['rows'] as $row)
                        <li>
                            @if ($row['url'] !== null)
                                <a href="{{ $row['url'] }}" class="v2-row">
                                    <span class="v2-row-tile"><x-el.v2.icon :name="$row['icon']" /></span>
                                    <span class="v2-row-main">
                                        <span class="v2-row-title">{{ $row['title'] }}</span>
                                        <span class="v2-row-meta">{{ $row['help'] }}</span>
                                    </span>
                                    <x-el.v2.icon name="chevron-end" class="v2-row-end" />
                                </a>
                            @else
                                <div class="v2-row is-off" aria-disabled="true">
                                    <span class="v2-row-tile"><x-el.v2.icon :name="$row['icon']" /></span>
                                    <span class="v2-row-main">
                                        <span class="v2-row-title">{{ $row['title'] }}</span>
                                        <span class="v2-row-meta">{{ $row['help'] }}</span>
                                    </span>
                                    <span class="v2-row-value">
                                        {{ $row['reason'] }}
                                        @if (filled($row['link'] ?? null))
                                            <a class="v2-link" href="{{ $row['link'] }}">{{ $row['linkLabel'] }}</a>
                                        @endif
                                    </span>
                                </div>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </section>
        @endforeach
    </div>
</x-filament-panels::page>
