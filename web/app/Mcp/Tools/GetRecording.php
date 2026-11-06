<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Models\User;
use App\Services\Connector\ConnectorCalls;
use App\Services\Connector\ConnectorLibrary;
use App\Services\Screens\MeetingPack;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get_recording')]
#[Title('Get a recording')]
#[Description('One recording: title, date, length, people and project, then the transcript as "[mm:ss] Speaker: text" lines with "[Screen N at mm:ss: description]" lines at the screens\' moments, and each screen\'s text and a picture link that works for 30 minutes.')]
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
final class GetRecording extends Tool
{
    public function handle(Request $request, ConnectorCalls $calls, ConnectorLibrary $library): Response
    {
        return $calls->run('get_recording', $request, function (User $person, int $workspaceId) use ($request, $library): string {
            $data = $request->validate([
                'id' => ['required', 'string', 'max:40'],
                'view' => ['nullable', 'string', Rule::in(MeetingPack::VIEWS)],
                'include_screens' => ['nullable', 'boolean'],
            ]);

            return $library->recording($person, $workspaceId, $data['id'], $data['view'] ?? null, (bool) ($data['include_screens'] ?? true));
        });
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()->required()->description('The recording\'s id.'),
            'view' => $schema->string()->enum(['original', 'roman', 'english', 'urdu'])->description('Which words to show: the original, Roman Urdu, English or Urdu. Default: the view the console opens it in, when it is made.'),
            'include_screens' => $schema->boolean()->default(true)->description('Put the screens in. Default true.'),
        ];
    }
}
