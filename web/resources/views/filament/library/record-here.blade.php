{{--
    Record (since v1.6, P4.6): the console does not record calls (the phone and the computer apps do), but on a phone
    "Record a voice note" opens its voice recorder and the note is uploaded like any file. On a computer it says
    where to record instead.
--}}
<div @class(['el-record-here', 'el-record-asked' => $asked]) data-record-here>
    <div class="el-record-touch">
        <x-filament::button icon="heroicon-o-microphone" x-data x-on:click="$dispatch('el-upload-record')" :autofocus="$asked">Record a voice note</x-filament::button>
        <p class="el-muted">Your phone's recorder opens; the note is uploaded when you stop. Calls are recorded by the EchoLedger app.</p>
    </div>
    <p class="el-record-desk el-muted">
        To record calls and voice notes, use the EchoLedger app on your phone or computer
        (<a class="el-link" href="{{ \App\Filament\Pages\GetTheApps::getUrl() }}">get the apps</a>). Here you can upload files.
    </p>
</div>
