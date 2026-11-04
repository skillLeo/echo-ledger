<?php

declare(strict_types=1);

namespace App\Filament\Resources\Clients\Pages;

use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Support\SharingActions;
use Filament\Resources\Pages\EditRecord;

class EditClient extends EditRecord
{
    protected static string $resource = ClientResource::class;

    protected function getHeaderActions(): array
    {
        // Deleting a project sends its recordings to the Inbox (v1.5), never with them.
        return [SharingActions::deleteProject(fn () => $this->redirect(ClientResource::getUrl('index')))];
    }
}
