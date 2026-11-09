<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Concerns\FullConsoleOnly;
use App\Filament\Resources\CaptureSessions\Pages\ListCaptureSessions;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * Upload: the Library's drop zone on a page of its own, for the bottom bar on phones. The same chunked, resumable
 * uploader and batch choices; each file becomes its own recording.
 */
class Upload extends Page
{
    use FullConsoleOnly;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUpTray;

    protected static string|BackedEnum|null $activeNavigationIcon = Heroicon::ArrowUpTray;

    protected static ?int $navigationSort = 4;

    protected static ?string $title = 'Upload';

    protected static ?string $slug = 'upload';

    public function getSubheading(): string
    {
        return 'Sound or video files from your computer or phone. Each file becomes its own recording in the Library.';
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            // Since v1.6 (P4.6): Record from the phone's bottom bar, and Record now in Ctrl/⌘+K, come here.
            View::make('filament.library.record-here')->viewData(fn (): array => ['asked' => request()->boolean('record')]),
            View::make('filament.library.uploader')->viewData(fn (): array => ['config' => ListCaptureSessions::uploaderConfig(), 'compact' => false]),
        ]);
    }
}
