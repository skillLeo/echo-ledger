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

#[Name('list_recent')]
#[Title('List recent recordings')]
#[Description('The latest recordings, newest first: id, title, date, length, project and app.')]
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
final class ListRecent extends Tool
{
    public function handle(Request $request, ConnectorCalls $calls, ConnectorLibrary $library): Response
    {
        return $calls->run('list_recent', $request, function (User $person, int $workspaceId) use ($request, $library): string {
            $data = $request->validate([
                'n' => ['nullable', 'integer', 'min:1', 'max:50'],
                'project_id' => ['nullable', 'string', 'max:40'],
            ]);

            return $library->recent($person, $workspaceId, (int) ($data['n'] ?? 10), $data['project_id'] ?? null);
        });
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'n' => $schema->integer()->min(1)->max(50)->default(10)->description('How many recordings.'),
            'project_id' => $schema->string()->description('Only this project and its subfolders.'),
        ];
    }
}
