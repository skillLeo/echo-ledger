{{-- The recording page's title with its Rename button (the rename dialog saves the same title devices change). --}}
<span class="el-title">
    <span dir="auto">{{ $title }}</span>
    <button type="button" class="el-title-edit" wire:click="mountAction('rename')" title="Rename" aria-label="Rename this recording">
        {{ \Filament\Support\generate_icon_html(\Filament\Support\Icons\Heroicon::OutlinedPencilSquare) }}
    </button>
</span>
