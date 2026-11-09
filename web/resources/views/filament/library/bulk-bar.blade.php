{{--
    The bulk bar (since v1.6, P5.2): shown by the table only while recordings are selected, as one line, "8 selected ·
    Move · Label · Export · Delete · More · Clear". The actions are the table's own bulk actions, drawn here instead of
    in the toolbar above the list (the toolbar's copy is hidden by the theme).
--}}
<div class="el-bulk-actions" role="group" aria-label="With the selected recordings">
    @foreach ($this->getTable()->getToolbarActions() as $action)
        @if ($action->isVisible())
            {{ $action }}
        @endif
    @endforeach
</div>
