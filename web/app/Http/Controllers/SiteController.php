<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AppRelease;
use App\Services\Accounts\SignUp;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

/** The public landing page (since v1.5): what EchoLedger is, the apps, sign in. It shows nobody's data. */
final class SiteController
{
    public function home(): View
    {
        return view('site.home', ['hasBuilds' => AppRelease::query()->exists()]);
    }

    /** `/sign-up` (since v1.6): the console's sign-up page while sign-up is open, else its sign-in page. */
    public function signUp(SignUp $signUp): RedirectResponse
    {
        return redirect()->to($signUp->open() ? route('filament.admin.auth.register') : route('filament.admin.auth.login'));
    }
}
