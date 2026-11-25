{{-- Settings › Notifications: which emails you get. Account & security and Plan & payments emails always come. --}}
<x-el.section :title="__('Email me about')" :footer="__('The bell in the console and the apps tell you either way.')">
    @foreach ($rows as $row)
        <button type="button" class="el-set-row" id="setting-notifications.email.{{ $row['key'] }}" data-setting="notifications.email.{{ $row['key'] }}"
            role="switch" aria-checked="{{ $row['on'] ? 'true' : 'false' }}" wire:click="toggleEmail('{{ $row['key'] }}')">
            <span class="el-set-text"><span class="el-set-title">{{ __($row['label']) }}</span></span>
            <span class="el-toggle" aria-hidden="true"></span>
        </button>
    @endforeach
    <x-el.row setting="notifications.email.account" :title="__('Account & security')" :value="__('Always on')" />
    <x-el.row setting="notifications.email.plan_payments" :title="__('Plan & payments')" :value="__('Always on')" />
</x-el.section>
<div class="el-btn-row"><button type="button" class="el-btn el-btn-text" wire:click="mountAction('resetPage')">{{ __('Reset to defaults') }}</button></div>
