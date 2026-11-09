{{--
    Account → Plan & usage → All features (since v1.6.2): every feature in the registry's order with its line; a tick
    where the plan has it, else the badge of the lowest plan that has it, which opens the upgrade sheet for it.
--}}
<div class="el-all-features" id="all-features" data-all-features>
    <style>
        .el-all-features ul { list-style: none; margin: 0; padding: 0; }
        .el-all-features li { display: flex; align-items: center; gap: .75rem; padding: .625rem 0; border-bottom: 1px solid var(--border, rgba(127,127,127,.15)); }
        .el-all-features li:last-child { border-bottom: 0; }
        .el-all-features .el-af-text { flex: 1; min-width: 0; }
        .el-all-features .el-af-name { font-size: 14px; font-weight: 600; color: var(--text, inherit); }
        .el-all-features .el-af-line { font-size: 13px; line-height: 18px; color: var(--text-muted, inherit); overflow-wrap: anywhere; }
        .el-all-features .el-af-has { display: inline-flex; align-items: center; gap: .25rem; font-size: 13px; font-weight: 600; color: var(--success, #1e7b53); white-space: nowrap; }
        .el-all-features .el-af-has svg { width: 1rem; height: 1rem; }
        .el-all-features .el-af-lock { flex: none; padding: 0; border: 0; background: none; cursor: pointer; border-radius: 999px; }
        .el-all-features .el-af-lock:focus-visible { outline: 2px solid var(--accent, #3e63dd); outline-offset: 2px; }
        .el-all-features .el-af-off { font-size: 13px; color: var(--text-faint, inherit); white-space: nowrap; }
        .el-all-features .el-af-foot { margin-top: .75rem; }
    </style>
    <ul>
        @foreach ($features as $feature)
            <li data-feature="{{ $feature['key'] }}" data-has="{{ $feature['has'] ? 'yes' : 'no' }}" wire:key="feature-{{ $feature['key'] }}">
                <div class="el-af-text">
                    <div class="el-af-name">{{ $feature['name'] }}</div>
                    <div class="el-af-line">{{ $feature['description'] }}</div>
                </div>
                @if ($feature['has'])
                    <span class="el-af-has">@svg('heroicon-m-check', ['aria-hidden' => 'true'])<span>In your plan</span></span>
                @elseif ($feature['badge'] !== null)
                    <button type="button" class="el-af-lock" wire:click="mountAction('upgradeFeature', { feature: '{{ $feature['key'] }}' })"
                        aria-label="{{ $feature['name'] }} is not in your plan. See plans">{!! $feature['badge']->toHtml() !!}</button>
                @else
                    <span class="el-af-off">Not offered now</span>
                @endif
            </li>
        @endforeach
    </ul>
    <div class="el-af-foot">
        <button type="button" class="el-text-btn" wire:click="mountAction('upgradeFeature')">@svg('heroicon-o-sparkles', ['aria-hidden' => 'true']) Compare plans</button>
    </div>
</div>
