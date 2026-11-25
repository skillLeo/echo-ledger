{{--
    Send to WhatsApp (since v1.6, P5.5), as on the phone and the desktop: Text only (the view being read), Text and voice
    (the text in the message and the audio saved to attach: WhatsApp takes no file through a link), Link (7 days). The
    whole text is always copied too: a long one is cut in the message, and WhatsApp may drop text sent with a file. Each
    choice opens WhatsApp from the click itself, so the browser does not block the new tab.
--}}
<div class="el-whatsapp" x-data="{
        text: @js($text), cut: @js($cut), audio: @js($audio), working: null, saved: false, said: '', error: '',
        t: @js([
            'cutCopied' => __('WhatsApp opens with the start of the text. The whole text is copied: paste it there.'),
            'cut' => __('WhatsApp opens with the start of the text.'),
            'opening' => __('Opening WhatsApp with the text.'),
            'linkFailed' => __('The link could not be made. Try again.'),
            'linkMade' => __('Link made (it works for 7 days) and copied.'),
            'copied' => __('Text copied.'),
            'copyFailed' => __('Copy failed.'),
            'copiedAi' => __('Copied for AI: unsure words are marked, with a first line that tells the AI what the marks mean.'),
            'making' => __('Making the link…'),
            'link' => __('Link (works for 7 days)'),
        ]),
        async copy(value) { try { await navigator.clipboard.writeText(value); return true } catch (e) { return false } },
        sendText(withVoice) {
            const copied = this.copy(this.text);
            if (withVoice && this.audio) {
                const a = document.createElement('a'); a.href = this.audio; a.download = ''; a.rel = 'noopener'; document.body.appendChild(a); a.click(); a.remove();
                this.saved = true;
            }
            copied.then(ok => { this.said = this.cut ? (ok ? this.t.cutCopied : this.t.cut) : this.t.opening });
        },
        async sendLink() {
            if (this.working) return;
            this.working = 'link'; this.error = '';
            const tab = window.open('about:blank', '_blank');
            try {
                const r = await $wire.whatsAppLink(@js($id), @js($view));
                if (! r || r.error) { this.error = (r && r.error) || this.t.linkFailed; if (tab) tab.close(); return; }
                await this.copy(r.link);
                if (tab) { tab.opener = null; tab.location.href = r.url; } else { window.location.href = r.url; }
                this.said = this.t.linkMade;
            } catch (e) { this.error = this.t.linkFailed; if (tab) tab.close(); }
            finally { this.working = null; }
        },
    }">
    <template x-if="saved">
        <div class="el-whatsapp-done" role="status">
            <p class="el-whatsapp-saved">@svg('heroicon-m-check', ['aria-hidden' => 'true']) {{ __('The voice is saved in your downloads:') }} <b dir="auto">{{ $audio_name }}</b></p>
            {{-- The words around the bold parts move as one sentence, so each language can order them. --}}
            <p>{!! __(':note: in the chat, click :plus (or the paper clip), then :document, and choose this file. The text is in the message, and copied too.', [
                'note' => '<b>'.e(__(\App\Filament\Support\WhatsAppActions::ATTACH_NOTE)).'</b>', 'plus' => '<b>+</b>', 'document' => '<b>'.e(__('Document')).'</b>',
            ]) !!}</p>
        </div>
    </template>
    <div class="el-whatsapp-options" x-show="! saved">
        @if ($text === null)
            <p class="el-whatsapp-hint" data-whatsapp-no-text>{{ __('There is no transcript yet: only the link can be sent.') }}</p>
        @else
            <a href="{{ $url }}" target="_blank" rel="noopener noreferrer" class="el-whatsapp-option el-whatsapp-primary" data-whatsapp="text" x-on:click="sendText(false)">
                @svg(...\App\Filament\Support\RtlIcons::of('heroicon-o-chat-bubble-bottom-center-text', ['aria-hidden' => 'true'])) <span>{{ __('Text only') }}</span>
            </a>
            <span class="el-whatsapp-hint">{{ __('The text of the view you are reading.') }}</span>
            @if ($audio !== null)
                <a href="{{ $url }}" target="_blank" rel="noopener noreferrer" class="el-whatsapp-option" data-whatsapp="voice" x-on:click="sendText(true)">
                    @svg('heroicon-o-musical-note', ['aria-hidden' => 'true']) <span>{{ __('Text and voice') }}</span>
                </a>
                <span class="el-whatsapp-hint">{{ __('The text in the message; the voice saved in your downloads to attach.') }}</span>
            @endif
        @endif
        <button type="button" class="el-whatsapp-option" data-whatsapp="link" x-on:click="sendLink()" x-bind:disabled="working !== null">
            @svg('heroicon-o-link', ['aria-hidden' => 'true']) <span x-text="working === 'link' ? t.making : t.link">{{ __('Link (works for 7 days)') }}</span>
        </button>
        <span class="el-whatsapp-hint">{{ $audio !== null ? __('Anyone with it can read and listen for 7 days.') : __('Anyone with it can read for 7 days.') }}</span>
        @if ($text !== null)
            <span class="el-whatsapp-copy">
                <button type="button" class="el-whatsapp-link" data-whatsapp="copy" x-on:click="copy(text).then(ok => said = ok ? t.copied : t.copyFailed)">{{ __('Copy text') }}</button>
                @if ($copy_ai ?? false)
                    {{-- The player's own Copy for AI (unsure words marked, the note line, the screens). --}}
                    <button type="button" class="el-whatsapp-link" data-whatsapp="copy-ai"
                        x-on:click="window.dispatchEvent(new CustomEvent('el-copy-for-ai')); said = t.copiedAi">{{ __('Copy for AI') }}</button>
                @endif
            </span>
        @endif
    </div>
    <p class="el-whatsapp-said" role="status" aria-live="polite" x-text="said"></p>
    <p class="el-whatsapp-error" role="alert" x-show="error" x-text="error"></p>
</div>
