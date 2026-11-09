{{--
    Send receipt (since v1.7): up to 3 screenshots or PDFs a round, the transaction ID and the name it was paid from,
    then "Send receipt". Files already sent come first and are locked.
--}}
<x-filament-panels::page class="el-settings-page">
    <div class="el-flow" data-flow="receipt">
        <a href="{{ $statusUrl }}" class="el-set-back">@svg('heroicon-o-chevron-left', ['aria-hidden' => 'true']) {{ $this->planName() }} request</a>
        <h1 class="el-set-h1">Send receipt</h1>
        @if ($stage === 'needs_receipt' && filled($proofRequest))
            <div class="el-set-box" style="padding:12px 16px;margin-bottom:12px;background:var(--warning-soft)" role="status">
                <p class="el-set-title">We need another receipt</p>
                <p class="el-set-sub el-pre" style="font-size:14px;line-height:20px;color:var(--text)">{{ $proofRequest }}</p>
            </div>
        @endif
        <p class="el-prose" style="padding:0;margin:0 0 16px">A screenshot or PDF of your receipt. It should show the amount, date and time, and the transaction ID. You can cover your balance.</p>

        <section class="el-set-section">
            <div class="el-set-box">
                @if ($sent !== [] || $unsent !== [] || $this->failed !== [])
                    <div class="el-rtiles">
                        @foreach ($sent as $proof)
                            @include('filament.upgrade._tile', ['proof' => $proof, 'number' => $loop->iteration])
                        @endforeach
                        @foreach ($unsent as $proof)
                            @include('filament.upgrade._tile', ['proof' => $proof, 'number' => count($sent) + $loop->iteration, 'removable' => true])
                        @endforeach
                        @foreach ($this->failed as $i => $failed)
                            <div class="el-rtile is-error" role="alert">
                                <div class="el-rtile-pic">
                                    <span>Couldn't upload</span>
                                    <span class="el-rtile-name" style="color:inherit">{{ $failed['name'] }}</span>
                                </div>
                                <span class="el-rtile-meta" style="color:var(--danger)">{{ $failed['message'] }}</span>
                                <button type="button" class="el-rtile-x" wire:click="dismissFailed({{ $i }})" aria-label="Remove">@svg('heroicon-o-x-mark', ['aria-hidden' => 'true'])</button>
                            </div>
                        @endforeach
                    </div>
                @endif
                @if ($room > 0)
                    <label class="el-rdrop" x-data="{ over: false, progress: null }" x-bind:class="{ 'is-over': over }"
                        x-on:dragover.prevent="over = true" x-on:dragleave.prevent="over = false"
                        x-on:drop.prevent="over = false; const input = $refs.files; const dt = new DataTransfer(); [...$event.dataTransfer.files].slice(0, {{ $room }}).forEach((f) => dt.items.add(f)); input.files = dt.files; input.dispatchEvent(new Event('change', { bubbles: true }))"
                        x-on:livewire-upload-start="progress = 0" x-on:livewire-upload-progress="progress = $event.detail.progress"
                        x-on:livewire-upload-finish="progress = null" x-on:livewire-upload-error="progress = null"
                        @if ($sent !== [] || $unsent !== [] || $this->failed !== []) style="margin-top:0" @else style="margin-top:16px" @endif>
                        @svg('heroicon-o-arrow-up-tray', ['style' => 'width:24px;height:24px', 'aria-hidden' => 'true'])
                        <span>Drop a screenshot or PDF here, or <strong>choose a file</strong></span>
                        <span class="el-copy-label">JPEG, PNG, WebP or PDF, up to 5 MB. {{ $room === 1 ? '1 more file' : $room.' more files' }} this time.</span>
                        <input type="file" class="sr-only" x-ref="files" wire:model="uploads" multiple accept="{{ $accept }}">
                        <span x-show="progress !== null" x-cloak class="el-meter-bar" style="width:100%"><span class="el-meter-fill" style="display:block" x-bind:style="'width:' + (progress ?? 0) + '%'"></span></span>
                    </label>
                @else
                    <p class="el-set-foot" style="margin:0;padding:0 16px 16px">You can add up to 3 files at a time. Remove one to add another.</p>
                @endif
                @error('uploads.*')
                    <p class="el-error" role="alert" style="padding:0 16px 16px;margin:0">{{ str_contains($message, '5120') || str_contains($message, 'kilobytes') ? 'This file is over 5 MB. Try a screenshot instead.' : 'Send a screenshot or photo (JPEG, PNG or WebP) or a PDF.' }}</p>
                @enderror
            </div>
        </section>

        <section class="el-set-section">
            <div class="el-set-box">
                <div class="el-form-field">
                    <label for="el-tid">Transaction ID (optional)</label>
                    <input id="el-tid" type="text" wire:model.live.debounce.300ms="transactionId" maxlength="64" autocomplete="off" spellcheck="false" aria-describedby="el-tid-help">
                    <p class="el-field-help" id="el-tid-help">On your receipt as Transaction ID, TID or Reference no.</p>
                </div>
                <div class="el-form-field" style="border-top:1px solid var(--border)">
                    <label for="el-payer">Paid from (optional)</label>
                    <input id="el-payer" type="text" wire:model="payerName" maxlength="120" autocomplete="name" aria-describedby="el-payer-help">
                    <p class="el-field-help" id="el-payer-help">The name on the account you paid from, if it isn't yours.</p>
                </div>
            </div>
            <p class="el-set-foot">Only our payments team sees your receipt. We delete it 90 days after we decide.</p>
        </section>

        <div class="el-flow-bar">
            <a href="{{ $payUrl }}" class="el-btn el-btn-text" style="padding-inline:0">Payment details</a>
            <button type="button" class="el-btn el-btn-primary" wire:click="send" wire:loading.attr="disabled" @disabled($unsent === [] && trim($this->transactionId) === '')>Send receipt</button>
            @if ($this->error)
                <p class="el-error" role="alert" style="flex-basis:100%;margin:0">{{ $this->error }}</p>
            @endif
        </div>
    </div>
</x-filament-panels::page>
