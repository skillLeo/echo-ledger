<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AppRelease;
use Illuminate\Contracts\View\View;

/** The public landing page (since v1.5): what EchoLedger is, the apps, sign in. It shows nobody's data. */
final class SiteController
{
    public function home(): View
    {
        return view('site.home', ['hasBuilds' => AppRelease::query()->exists()]);
    }
}
