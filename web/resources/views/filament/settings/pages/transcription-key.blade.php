{{--
    Settings › Transcription key: on Free, transcripts use the person's own free Groq key. Three steps, the box and
    Save (from 20 characters); errors stay under the box. A saved key shows its last four characters, with Remove key.
--}}
@if (! $set)
    <x-el.section>
        <div style="padding:16px">
            <p class="el-set-title" style="font-weight:400">Transcripts on Free use your own free Groq key. It takes about 2 minutes.</p>
            <ol style="margin:12px 0 0;padding-left:20px;list-style:decimal;font-size:14px;line-height:22px;color:var(--text)">
                <li>Open console.groq.com and sign in. Google is fine.</li>
                <li>Open API Keys, choose Create API Key, name it EchoLedger and copy the key.</li>
                <li>Paste it here and choose Save.</li>
            </ol>
            <a href="https://console.groq.com/keys" target="_blank" rel="noopener" class="el-btn el-btn-outline" style="margin-top:12px">
                Open console.groq.com @svg('heroicon-o-arrow-top-right-on-square', ['style' => 'width:16px;height:16px', 'aria-hidden' => 'true'])
            </a>
        </div>
        <form class="el-field" wire:submit="saveGroqKey" style="border-top:1px solid var(--border)" x-data="{ key: '' }">
            <label for="el-groq-key">Groq key</label>
            <div class="el-field-inline">
                <input id="el-groq-key" type="password" wire:model="groqKey" x-model="key" placeholder="gsk_…" autocomplete="off" spellcheck="false" maxlength="300"
                    @if ($this->groqKeyError) aria-invalid="true" aria-describedby="el-groq-error" @endif>
                <button type="submit" class="el-btn el-btn-primary" x-bind:disabled="key.trim().length < 20">Save</button>
            </div>
            @if ($this->groqKeyError)
                <p class="el-error" id="el-groq-error" role="alert">{{ $this->groqKeyError }}</p>
            @endif
        </form>
    </x-el.section>
@else
    <x-el.section :footer="$platform ? 'Your plan transcribes for you, so this key isn\'t used now.' : null">
        <x-el.row setting="key.saved" title="Your Groq key" :value="'Ending in '.$last4" />
    </x-el.section>
    <x-el.section>
        <x-el.row setting="key.remove" title="Remove key" click="mountAction('removeKey')" danger />
    </x-el.section>
@endif
