{{--
    The v2.0 selection bar (ui_v2.library, the parity plan's selection mode): × to leave the selection, then the four
    icon actions and ⋮ (CaptureSessionsTable::v2BulkBar). The count and Select all are the table's own indicator.
--}}
<div class="el-bulk-actions v2-sel-actions" role="group" aria-label="{{ __('With the selected recordings') }}">
    <button type="button" class="v2-sel-exit" x-on:click="deselectAllRecords()" aria-label="{{ __('Leave the selection') }}" title="{{ __('Leave the selection') }}" data-selection-exit>
        <x-el.v2.icon name="close" />
    </button>
    @foreach ($this->getTable()->getToolbarActions() as $action)
        @if ($action->isVisible())
            {{ $action }}
        @endif
    @endforeach
</div>
