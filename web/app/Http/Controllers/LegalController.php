<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;

/**
 * The Terms of Service and the Privacy Policy (since v1.5, docs/API.md "Public pages and app downloads"). Drafts for
 * review until the operator's lawyer has read them; the apps open them in the browser.
 */
final class LegalController
{
    public function terms(): View
    {
        return view('legal.terms');
    }

    public function privacy(): View
    {
        return view('legal.privacy');
    }
}
