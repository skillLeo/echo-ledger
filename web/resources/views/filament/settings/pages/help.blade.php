{{-- Settings › Help: the questions people ask, each with its answer and the setting it is about. --}}
<x-el.section :title="__('Questions')">
    @foreach ($articles as $article)
        <details class="el-set-group" id="setting-{{ $article['id'] }}" tabindex="-1" data-setting="{{ $article['id'] }}">
            <summary class="el-set-row">
                <span class="el-set-text"><span class="el-set-title">{{ __($article['question']) }}</span></span>
                @svg(...\App\Filament\Support\RtlIcons::of('heroicon-o-chevron-right', ['class' => 'el-set-chevron', 'aria-hidden' => 'true']))
            </summary>
            <div style="padding:0 16px 16px">
                <p class="el-set-sub" style="font-size:14px;line-height:20px">{{ __($article['answer']) }}</p>
                @if ($article['target'])
                    <a href="{{ $article['target']['url'] }}" class="el-btn el-btn-outline" style="margin-top:12px">{{ __('Open :label', ['label' => __($article['target']['title'])]) }}</a>
                @endif
            </div>
        </details>
    @endforeach
</x-el.section>
<x-el.section>
    <x-el.row setting="help.apps" :title="__('Get the apps')" :href="$apps" chevron />
    <x-el.row :title="__('Keyboard shortcuts')" :href="\App\Filament\Auth\EditProfile::settingsUrl('shortcuts')" chevron />
</x-el.section>
