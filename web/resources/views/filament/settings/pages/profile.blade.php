{{--
    Settings › Profile, under the name and email: since BE-06 (contract 2.0.0-r §6; the colours' names since 2.0.0-u) the
    apps' profile too, the same settings as GET and PATCH /api/account: the photo (Add or Change photo…, Remove photo),
    the initials' colour when there is no photo (8 swatches, under the new design's colours), and under the new frame
    the language. Then the workspace (team workspaces only).
--}}
<x-el.section>
    <div class="el-set-row el-profile-photo" id="setting-profile.photo" tabindex="-1" data-setting="profile.photo">
        @if ($photo !== null)
            <img class="el-profile-avatar" src="{{ $photo }}" alt="" width="56" height="56">
        @else
            <span class="el-profile-avatar is-initials" data-avatar-color="{{ $color }}" style="background-color: var(--el-role-avatar-{{ $color }})" aria-hidden="true">{{ $initials }}</span>
        @endif
        <span class="el-set-text">
            <span class="el-set-title">{{ __('Photo') }}</span>
            <span class="el-set-sub">{{ __('Shown beside your name in what you share, on every device.') }}</span>
        </span>
        <span class="el-set-control el-profile-photo-actions">
            {{ $this->changePhotoAction }}
            @if ($this->removePhotoAction->isVisible())
                {{ $this->removePhotoAction }}
            @endif
        </span>
    </div>
    @if ($v2)
        <div class="el-set-row is-stacked" id="setting-profile.color" tabindex="-1" data-setting="profile.color" data-avatar-color="{{ $color }}"
            x-data="{ value: {{ (int) $color }}, saved: false, pick(v) { this.value = v; this.saved = false; $wire.setAvatarColor(v).then((ok) => { this.saved = ok }) } }">
            <span class="el-set-text">
                <span class="el-set-title" id="el-profile-color">{{ __('Colour') }}</span>
                <span class="el-set-sub">{{ __('Your initials\' circle when there is no photo.') }}</span>
            </span>
            <span class="el-set-control el-profile-colors-row">
                <span class="el-set-saved" x-show="saved" x-cloak aria-hidden="true">@svg('heroicon-m-check')</span>
                <span class="el-profile-colors" role="radiogroup" aria-labelledby="el-profile-color" data-radio-keys>
                    @foreach ($colors as $i => $colorName)
                        <button type="button" role="radio" class="el-profile-swatch" aria-label="{{ __($colorName) }}" title="{{ __($colorName) }}"
                            style="background-color: var(--el-role-avatar-{{ $i }})"
                            x-bind:aria-checked="value === {{ $i }} ? 'true' : 'false'" x-bind:tabindex="value === {{ $i }} ? 0 : -1"
                            x-on:click="pick({{ $i }})">{{ $initials }}</button>
                    @endforeach
                </span>
            </span>
        </div>
    @endif
    @if ($appLanguages !== null)
        <div class="el-set-row is-stacked" id="setting-profile.language" tabindex="-1" data-setting="profile.language" x-data="{ value: @js($appLanguage), saved: false,
                pick(v) { this.value = v; this.saved = false; $wire.setPreference('app_language', v).then((ok) => { this.saved = ok }) } }">
            <span class="el-set-text">
                <span class="el-set-title" id="el-profile-language">{{ __('Language') }}</span>
                <span class="el-set-sub">{{ __('The console\'s and the apps\' words and direction. Transcripts keep their own language.') }}</span>
            </span>
            <span class="el-set-control el-profile-colors-row">
                <span class="el-set-saved" x-show="saved" x-cloak aria-hidden="true">@svg('heroicon-m-check')</span>
                <span class="el-segmented" role="radiogroup" aria-labelledby="el-profile-language" data-radio-keys>
                    @foreach ($appLanguages as $value => $label)
                        <button type="button" role="radio" lang="{{ $value }}" x-bind:aria-checked="value === '{{ $value }}' ? 'true' : 'false'" x-bind:tabindex="value === '{{ $value }}' ? 0 : -1"
                            x-on:click="pick('{{ $value }}')">{{ $label }}</button>
                    @endforeach
                </span>
            </span>
        </div>
    @endif
</x-el.section>
@if ($team)
    <x-el.section>
        <x-el.row setting="profile.workspace" title="Workspace" :value="$workspace" />
    </x-el.section>
@endif
