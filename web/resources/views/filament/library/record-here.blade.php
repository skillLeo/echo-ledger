{{--
    Record (since v1.6, P4.6): the console does not record calls (the phone and the computer apps do), but on a phone
    "Record a voice note" opens its voice recorder and the note is uploaded like any file. On a computer it says
    where to record instead.
--}}
<div @class(['el-record-here', 'el-record-asked' => $asked]) data-record-here>
    <div class="el-record-touch">
        <x-filament::button icon="heroicon-o-microphone" x-data x-on:click="$dispatch('el-upload-record')" :autofocus="$asked">{{ __('Record a voice note') }}</x-filament::button>
        {{-- @lang leaves the apostrophe as it is (English unchanged); the brand is the app's own name. --}}
        <p class="el-muted">@lang("Your phone's recorder opens; the note is uploaded when you stop. Calls are recorded by the :brand app.", ['brand' => e(\App\Support\Brand::name())])</p>
    </div>
    <p class="el-record-desk el-muted">
        @lang('To record calls and voice notes, use the :brand app on your phone or computer (:link). Here you can upload files.', [
            'brand' => e(\App\Support\Brand::name()),
            'link' => '<a class="el-link" href="'.e(\App\Filament\Pages\GetTheApps::getUrl()).'">'.e(__('get the apps')).'</a>',
        ])
    </p>
</div>
