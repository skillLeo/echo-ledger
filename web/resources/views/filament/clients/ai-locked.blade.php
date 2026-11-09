{{-- Since v1.6 (P1.4): when the plan leaves AI notes out, the plan's badge and the upgrade sheet, on Overview and Ask. --}}
@if ($aiLocked ?? false)
    <div class="el-card" role="note" data-ai-locked style="display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: var(--space-3)">
        <p style="margin: 0; font-size: 14px">{!! $aiBadge?->toHtml() !!} Project notes, the memory and Ask are not in your plan.</p>
        <button type="button" class="el-text-btn" wire:click="mountAction('upgradeAiNotes')">See plans</button>
    </div>
@endif
