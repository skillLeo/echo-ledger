<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Models\User;
use App\Services\Connector\ConnectorCalls;
use App\Services\Connector\ConnectorLibrary;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list_projects')]
#[Title('List projects')]
#[Description('The person\'s projects with id, name, path, recording count and last recording date. Without parent_id every project is listed; with it, the subfolders directly in that project.')]
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
final class ListProjects extends Tool
{
    public function handle(Request $request, ConnectorCalls $calls, ConnectorLibrary $library): Response
    {
        return $calls->run('list_projects', $request, function (User $person, int $workspaceId) use ($request, $library): string {
            $data = $request->validate(['parent_id' => ['nullable', 'string', 'max:40']]);

            return $library->projectList($person, $workspaceId, $data['parent_id'] ?? null);
        });
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'parent_id' => $schema->string()->description('A project\'s id: list only the subfolders directly in it.'),
        ];
    }
}
