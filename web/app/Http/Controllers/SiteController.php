<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AppRelease;
use App\Services\Accounts\SignUp;
use App\Services\Entitlements\Entitlements;
use App\Support\SitePlans;
use App\Support\WebsiteV2;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** The public landing page (since v1.5): what EchoLedger is, the apps, sign in. It shows nobody's data. */
final class SiteController
{
    public function home(Request $request): View
    {
        // Since v2.0 (ui_v2.website): the new design, with the same words.
        return view(WebsiteV2::on($request) ? 'site.v2.home' : 'site.home', ['hasBuilds' => AppRelease::query()->exists()]);
    }

    /** `/pricing` (since v2.0, ui_v2.website only): the plans on offer, from Admin › Plans, cheapest first. */
    public function pricing(Request $request, Entitlements $entitlements): View
    {
        abort_unless(WebsiteV2::on($request), 404);

        return view('site.v2.pricing', ['plans' => SitePlans::cards($entitlements->offered())]);
    }

    /** `/sign-up` (since v1.6): the console's sign-up page while sign-up is open, else its sign-in page. */
    public function signUp(SignUp $signUp): RedirectResponse
    {
        return redirect()->to($signUp->open() ? route('filament.app.auth.register') : route('filament.app.auth.login'));
    }
}
