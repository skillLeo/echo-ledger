<?php

declare(strict_types=1);

namespace App\Http\Controllers\Connector;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Mcp\Server\Http\Controllers\OAuthRegisterController;

/**
 * Dynamic client registration with the apps' own sign-in addresses only (since v1.5, config/mcp.php). The package allows
 * whole sites, but claude.ai and chatgpt.com also carry other people's pages: anyone could register under the name
 * "Claude" with one of those as the way back, and the person would see the usual question. So an http(s) address is
 * compared whole here before the package's own checks run:
 *
 * - an entry with a path is that exact address;
 * - an entry ending in `/*` is that path and one more segment (ChatGPT's id for the connection);
 * - an entry with no path is the whole site, on any port for this machine's own addresses.
 */
final class RegisterController extends OAuthRegisterController
{
    public function __invoke(Request $request): JsonResponse
    {
        $uris = $request->input('redirect_uris');
        foreach (is_array($uris) ? $uris : [] as $i => $uri) {
            if (is_string($uri) && in_array(parse_url($uri, PHP_URL_SCHEME), ['http', 'https'], true) && ! $this->permitted($uri)) {
                return response()->json(['error' => 'invalid_redirect_uri', 'error_description' => "redirect_uris.{$i} is not a permitted redirect address."], 400);
            }
        }

        return parent::__invoke($request);
    }

    /** Every address has passed `permitted` by now, so the package's prefix check has nothing to add. */
    protected function allowedDomains(): array
    {
        return ['http://', 'https://'];
    }

    private function permitted(string $uri): bool
    {
        $parts = parse_url($uri);
        if (! is_array($parts) || isset($parts['query']) || isset($parts['fragment'])) {
            return false;
        }
        // A browser resolves these before it goes anywhere, encoded or not, so they lead out of any allowed path.
        if (array_intersect(explode('/', str_replace('\\', '/', rawurldecode($parts['path'] ?? ''))), ['.', '..']) !== []) {
            return false;
        }
        /** @var list<string> $entries */
        $entries = config('mcp.redirect_domains', []);
        foreach ($entries as $entry) {
            if ($this->matches($uri, $parts, rtrim($entry, '/'))) {
                return true;
            }
        }

        return false;
    }

    /** @param  array<string, int|string>  $parts */
    private function matches(string $uri, array $parts, string $entry): bool
    {
        if (str_ends_with($entry, '/*')) {
            return preg_match('~^'.preg_quote(substr($entry, 0, -1), '~').'[A-Za-z0-9_-]+$~D', $uri) === 1;
        }
        $site = parse_url($entry);
        if (! is_array($site) || ($site['path'] ?? '') !== '') {
            return $uri === $entry;
        }

        return ($parts['scheme'] ?? null) === ($site['scheme'] ?? null)
            && ($parts['host'] ?? null) === ($site['host'] ?? null)
            && (($parts['port'] ?? null) === ($site['port'] ?? null) || $this->isLocalhostUrl($uri));
    }
}
