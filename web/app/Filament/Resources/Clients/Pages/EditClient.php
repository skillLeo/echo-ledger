<?php

declare(strict_types=1);

namespace App\Filament\Resources\Clients\Pages;

use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Support\SharingActions;
use App\Models\Client;
use App\Services\Projects\ProjectTree;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditClient extends EditRecord
{
    protected static string $resource = ClientResource::class;

    protected function getHeaderActions(): array
    {
        // Deleting a project sends its recordings to the Inbox (v1.5), never with them.
        return [SharingActions::deleteProject(fn () => $this->redirect(ClientResource::getUrl('index')))];
    }

    /**
     * Saved with the other changes of the workspace's projects waiting; the folder and the name are checked again
     * where it lands (ProjectTree::place), as on creation.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Client $record */
        return CreateClient::placed(fn (): Client => app(ProjectTree::class)->place($record->workspace_id, function () use ($record, $data): Client {
            /** @var Client $client */
            $client = parent::handleRecordUpdate($record, $data);

            return $client;
        }));
    }
}
