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

#[Name('search')]
#[Title('Search recordings')]
#[Description('Finds words in titles, transcript text and screen text. Each match gives the recording id, title, date, project, the matching line and its time. Every word must be there; put a phrase in double quotes. Newest recordings first.')]
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
final class Search extends Tool
{
    public function handle(Request $request, ConnectorCalls $calls, ConnectorLibrary $library): Response
    {
        return $calls->run('search', $request, function (User $person, int $workspaceId) use ($request, $library): string {
            $data = $request->validate([
                'query' => ['required', 'string', 'min:2', 'max:200'],
                'project_id' => ['nullable', 'string', 'max:40'],
                'limit' => ['nullable', 'integer', 'min:1', 'max:25'],
            ]);

            return $library->search($person, $workspaceId, $data['query'], $data['project_id'] ?? null, (int) ($data['limit'] ?? 10));
        });
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->min(2)->max(200)->required()->description('The words to find.'),
            'project_id' => $schema->string()->description('Look only in this project and its subfolders.'),
            'limit' => $schema->integer()->min(1)->max(25)->default(10)->description('How many recordings at most.'),
        ];
    }
}
