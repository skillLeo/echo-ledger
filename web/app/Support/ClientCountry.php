<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;

/**
 * The visitor's country as a proxy in front of the server names it (ECHOLEDGER_COUNTRY_HEADER, e.g. CF-IPCountry), or
 * null. Without a configured header nothing is read: anyone could send such a header to a server not behind a proxy.
 */
final class ClientCountry
{
    public static function of(Request $request): ?string
    {
        $header = config('echoledger.country_header');
        if (! is_string($header) || $header === '') {
            return null;
        }
        $country = strtoupper(trim((string) $request->header($header)));

        // XX = unknown and T1 = Tor at Cloudflare: neither is a country.
        return preg_match('/^[A-Z]{2}$/', $country) === 1 && ! in_array($country, ['XX', 'T1'], true) ? $country : null;
    }
}
