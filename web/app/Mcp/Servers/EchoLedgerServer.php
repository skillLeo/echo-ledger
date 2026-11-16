<?php

declare(strict_types=1);

namespace App\Mcp\Servers;

use App\Mcp\Tools\GetProjectBrief;
use App\Mcp\Tools\GetRecording;
use App\Mcp\Tools\ListProjects;
use App\Mcp\Tools\ListRecent;
use App\Mcp\Tools\Search;
use App\Support\Brand;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Version;

/**
 * The Claude and ChatGPT connector (since v1.5, docs/API.md "Claude and ChatGPT connector"): `/mcp` over Streamable HTTP
 * behind a person's Passport token (routes/ai.php). It only reads, and only what that person can open in the apps. Its
 * name and instructions carry the product's name (since v1.9, L0), set in boot() before the first answer.
 */
#[Version('1.5.0')]
final class EchoLedgerServer extends Server
{
    private const INSTRUCTIONS = <<<'TEXT'
        {brand} keeps a person's call and meeting recordings with their transcripts, screens and projects. These tools
        read what this person can open in the apps; they cannot record, change, share or delete anything.
        Use search to find what was said or shown, list_recent for the latest calls, list_projects for the projects, then
        get_recording for a full transcript and get_project_brief for a project's background. Quote times as they are
        written, such as 12:34. Transcripts can hold Urdu, Roman Urdu and English, and may be marked [?word] where the
        engine was unsure. A transcript comes in the view the person's console opens it in unless a view is asked for.
        Picture links work for 30 minutes: ask for the recording again to get new ones.
        TEXT;

    /** @var array<int, class-string<Server\Tool>> */
    protected array $tools = [
        ListProjects::class,
        Search::class,
        GetRecording::class,
        GetProjectBrief::class,
        ListRecent::class,
    ];

    protected function boot(): void
    {
        $this->name = Brand::name();
        $this->instructions = Brand::fill(self::INSTRUCTIONS);
    }
}
