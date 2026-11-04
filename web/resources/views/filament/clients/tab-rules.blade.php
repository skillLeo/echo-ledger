{{--
    Filing rules (v1.5): a recording that ends in no project comes here when its caller is one of these contacts, else
    when its title contains one of these words, else when it comes from one of these apps. Rules never move a recording
    that is already in a project.
--}}
<div style="display: flex; flex-direction: column; gap: var(--space-5); max-width: 880px">
    <form class="el-card" wire:submit="addRule" style="display: flex; flex-direction: column; gap: var(--space-3)">
        <h3 class="el-card-title">Add a rule</h3>
        <p class="el-caption">Recordings that end without a project are filed here by the first match: a contact, then a title, then an app.</p>
        <div style="display: flex; flex-wrap: wrap; gap: var(--space-2)">
            <label class="el-sr" for="rule-kind">Kind</label>
            <select id="rule-kind" class="el-field-input" style="flex: 0 0 auto" wire:model.live="ruleKind">
                @foreach ($kinds as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
            <label class="el-sr" for="rule-value">Value</label>
            @if ($this->ruleKind === 'app')
                <select id="rule-value" class="el-field-input" style="flex: 1 1 12rem" wire:model="ruleValue">
                    <option value="">Choose an app</option>
                    @foreach ($apps as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            @else
                <input id="rule-value" class="el-field-input" style="flex: 1 1 12rem" maxlength="120" wire:model="ruleValue"
                    placeholder="{{ $this->ruleKind === 'contact' ? '+92 300 1234567 or name@example.com' : 'Words in the title, like Acme weekly' }}">
            @endif
            <button type="submit" class="el-pill el-pill-primary">Add</button>
        </div>
        @error('ruleValue')<p class="el-caption" role="alert" style="color: var(--danger)">{{ $message }}</p>@enderror
    </form>

    @if ($rules->isEmpty())
        <div class="el-empty">
            <span class="el-empty-icon">@svg('heroicon-o-funnel', ['aria-hidden' => 'true'])</span>
            <p class="el-empty-title">No rules yet</p>
            <p class="el-empty-text">Without rules, recordings come here when the caller is one of the project's contacts or when you move them.</p>
        </div>
    @else
        <ul class="el-rows" role="list">
            @foreach ($rules as $rule)
                <li class="el-row-item" wire:key="rule-{{ $rule->id }}">
                    <div class="el-row">
                        <span class="el-row-main">
                            <span class="el-row-title">{{ $rule->kind === 'app' ? ($apps[$rule->value] ?? $rule->value) : $rule->value }}</span>
                            <span class="el-row-sub"><span class="el-row-meta">{{ $kinds[$rule->kind] ?? $rule->kind }}</span></span>
                        </span>
                        <button type="button" class="el-text-btn" wire:click="removeRule({{ $rule->id }})" wire:confirm="Remove this rule?">Remove</button>
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
</div>
