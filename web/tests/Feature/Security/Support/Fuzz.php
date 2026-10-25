<?php

declare(strict_types=1);

namespace Tests\Feature\Security\Support;

use Closure;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;

/**
 * Small request fuzzer: replaces one field at a time with hostile values and reports every response that is a server
 * error, or that leaks a stack trace or file path.
 */
final class Fuzz
{
    /** @return array<string, mixed> */
    public static function values(): array
    {
        return [
            'empty string' => '',
            'blank string' => '   ',
            'null' => null,
            'true' => true,
            'false' => false,
            'zero' => 0,
            'negative one' => -1,
            'int min' => PHP_INT_MIN,
            'int max' => PHP_INT_MAX,
            'overflowing digits' => '99999999999999999999999999',
            'fraction' => 1.5,
            'tiny fraction' => 0.000001,
            'NaN text' => 'NaN',
            'INF text' => 'INF',
            'exponent overflow' => '1e999',
            'negative zero' => '-0',
            'hex text' => '0x1A',
            'empty array' => [],
            'list of strings' => ['a', 'b'],
            'nested arrays' => [[['x']]],
            'associative array' => ['a' => 'b', 'c' => ['d']],
            'huge string' => str_repeat('A', 70000),
            'unicode mix' => "Za\u{017C}\u{00F3}\u{0142}\u{0107} \u{1F600} \u{202E}\u{200B}\u{FEFF}",
            'nul byte' => "a\0b",
            'crlf header injection' => "a\r\nX-Injected: 1",
            'sql injection' => "' OR 1=1; DROP TABLE users; --",
            'script tag' => '<script>alert(1)</script>',
            'path traversal' => '../../../../etc/passwd',
            'template syntax' => '{{7*7}}${7*7}<%= 7*7 %>',
            'format string' => '%s%s%s%n%x',
            'json text' => '{"a":1}',
        ];
    }

    /**
     * @param  Closure(array<string, mixed>): TestResponse  $send  sends one payload and returns the response
     * @param  array<string, mixed>  $baseline  a valid payload
     * @param  list<string>  $paths  dot-notation paths to attack, one at a time
     * @param  array<string, mixed>|null  $values
     * @param  (Closure(): void)|null  $reset  called before each request (new device, fresh pairing code, ...)
     * @return list<string> one line per offending request
     */
    public static function run(Closure $send, array $baseline, array $paths, ?array $values = null, ?Closure $reset = null): array
    {
        $failures = [];
        foreach ($paths as $path) {
            foreach ($values ?? self::values() as $label => $value) {
                if ($reset !== null) {
                    $reset();
                }
                Cache::flush(); // rate limiters live in the cache; fuzzing must not be throttled into silence
                $payload = $baseline;
                Arr::set($payload, $path, $value);

                config(['app.debug' => false]);
                $response = $send($payload);
                $problem = self::problem($response);
                if ($problem !== null) {
                    config(['app.debug' => true]);
                    Cache::flush();
                    if ($reset !== null) {
                        $reset();
                    }
                    $detail = $send($payload)->json();
                    $cause = is_array($detail) ? trim(($detail['exception'] ?? '').': '.mb_substr((string) ($detail['message'] ?? ''), 0, 110)) : '';
                    $failures[] = "{$path} <- {$label}: {$problem} [{$cause}]";
                }
            }
        }

        return $failures;
    }

    public static function problem(TestResponse $response): ?string
    {
        $status = $response->getStatusCode();
        if ($status >= 500) {
            return "HTTP {$status}";
        }
        $body = (string) $response->getContent();
        if (preg_match('~(Stack trace|#0 [A-Za-z]:\\\\|/vendor/|\\\\vendor\\\\|"trace"\s*:)~', $body) === 1) {
            return "HTTP {$status} with a leaked trace or path";
        }

        return null;
    }
}
