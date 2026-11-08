{{-- Profile → Emails (since v1.5, Phase 1C): one switch per category; Account & security always on. --}}
<div class="el-profile-emails" id="emails">
    <style>
        .el-profile-emails .row { display: flex; align-items: center; gap: .75rem; padding: .6rem 0; border-bottom: 1px solid rgba(127,127,127,.15); }
        .el-profile-emails .row:last-of-type { border-bottom: 0; }
        .el-profile-emails .grow { flex: 1; min-width: 0; }
        .el-profile-emails .label { font-weight: 600; }
        .el-profile-emails .muted { opacity: .7; font-size: .85rem; }
        .el-profile-emails .always { font-size: .8rem; padding: .1rem .55rem; border-radius: 999px; background: rgba(127,127,127,.15); white-space: nowrap; }
    </style>
    @foreach ($rows as $row)
        <div class="row">
            <div class="grow">
                <div class="label" id="email-{{ $row['key'] }}">{{ $row['label'] }}</div>
                <div class="muted">{{ $row['what'] }}</div>
            </div>
            <button type="button" class="el-switch" role="switch" aria-checked="{{ $row['on'] ? 'true' : 'false' }}" aria-labelledby="email-{{ $row['key'] }}"
                wire:click="toggleEmail('{{ $row['key'] }}')" data-email="{{ $row['key'] }}"></button>
        </div>
    @endforeach
    <div class="row">
        <div class="grow">
            <div class="label" id="email-account">Account &amp; security</div>
            <div class="muted">{{ $always }}</div>
        </div>
        <span class="always" aria-describedby="email-account">Always on</span>
    </div>
</div>
