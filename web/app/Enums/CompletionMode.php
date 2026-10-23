<?php

declare(strict_types=1);

namespace App\Enums;

/** How a provider delivers the final transcript (PLAN.md D6). */
enum CompletionMode: string
{
    case AsyncWebhook = 'async_webhook';
    case Sync = 'sync';
    case Device = 'device';
}
