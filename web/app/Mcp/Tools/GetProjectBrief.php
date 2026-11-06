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

#[Name('get_project_brief')]
#[Title('Get a project\'s brief')]
#[Description('A project\'s brief, notes and instructions, contacts, file names, memory and its latest call notes (memory and call notes only when AI notes are in the person\'s plan).')]
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
final class GetProjectBrief extends Tool
{
    public function handle(Request $request, ConnectorCalls $calls, ConnectorLibrary $library): Response
    {
        return $calls->run('get_project_brief', $request, function (User $person, int $workspaceId) use ($request, $library): string {
            $data = $request->validate(['project_id' => ['required', 'string', 'max:40']]);

            return $library->brief($person, $workspaceId, $data['project_id']);
        });
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'project_id' => $schema->string()->required()->description('The project\'s id, from list_projects.'),
        ];
    }
}
