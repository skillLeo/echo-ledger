<?php

declare(strict_types=1);

namespace App\Filament\Resources\Clients\Pages;

use App\Exceptions\ApiError;
use App\Filament\Resources\Clients\ClientResource;
use App\Models\Client;
use App\Services\Projects\ProjectTree;
use App\Services\Workspaces\CurrentWorkspace;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CreateClient extends CreateRecord
{
    protected static string $resource = ClientResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['workspace_id'] = CurrentWorkspace::id();
        $data['user_id'] = auth()->id();

        return $data;
    }

    /**
     * The form checked the folder and the name; they are checked again where the project lands, with the other changes
     * of the workspace's projects waiting (ProjectTree::place), and a refusal shows on its field.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        return self::placed(fn (): Client => app(ProjectTree::class)->place((int) $data['workspace_id'], function () use ($data): Client {
            /** @var Client $client */
            $client = parent::handleRecordCreation($data);

            return $client;
        }));
    }

    /** @param  callable(): Client  $place */
    public static function placed(callable $place): Client
    {
        try {
            return $place();
        } catch (ApiError $e) {
            throw ValidationException::withMessages([$e->apiCode() === 'validation_failed' ? 'data.name' : 'data.parent_id' => $e->getMessage()]);
        }
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
