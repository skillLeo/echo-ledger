<?php

declare(strict_types=1);

namespace Tests\Feature\Storage\Support;

use App\Services\Storage\ObjectStorageDisk;
use App\Services\Storage\S3Adapter;
use App\Services\Storage\S3Signer;
use Carbon\CarbonImmutable;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Filesystem;

/**
 * A small S3 behind Http::fake, so tests run the real adapter over the wire format: it checks every request's signature
 * and body hash as a bucket does, keeps objects in memory, and can fail on demand.
 */
final class FakeS3Server
{
    public const HOST = 's3.storage.test';

    public const BUCKET = 'echoledger-test';

    public const KEY = 'TESTACCESSKEY';

    public const SECRET = 'test-secret-that-must-never-show';

    public const REGION = 'eu-test-1';

    /** @var array<string, array{body: string, modified: int}> */
    public array $objects = [];

    /** @var array<string, array<int, string>> parts of the multipart uploads under way */
    public array $uploads = [];

    /** @var list<string> "METHOD key?query" of every request */
    public array $log = [];

    /** @var list<array{method: string, status: int, match: string}> */
    private array $failures = [];

    /** @var list<int> */
    private array $cuts = [];

    private readonly S3Signer $signer;

    public function __construct(private readonly bool $pathStyle = true)
    {
        $this->signer = new S3Signer(self::KEY, self::SECRET, self::REGION);
    }

    /** A bucket behind the `recordings` disk for this test, with every other HTTP request left to the test's own fakes. */
    public static function install(): self
    {
        $server = new self;
        $server->listen();
        $adapter = $server->adapter();
        Storage::set('recordings', new ObjectStorageDisk(new Filesystem($adapter), $adapter, ['throw' => true]));

        return $server;
    }

    public function adapter(int $partBytes = S3Adapter::MULTIPART_BYTES): S3Adapter
    {
        return new S3Adapter(self::BUCKET, self::REGION, self::KEY, self::SECRET, 'https://'.self::HOST, $this->pathStyle, $partBytes);
    }

    public function listen(): void
    {
        Http::fake(fn (Request $request) => str_contains((string) parse_url($request->url(), PHP_URL_HOST), self::HOST) ? $this->handle($request) : null);
    }

    /** The next $times requests of $method (on a key containing $match) are answered with $status. */
    public function failNext(string $method, int $status = 503, int $times = 1, string $match = ''): void
    {
        for ($i = 0; $i < $times; $i++) {
            $this->failures[] = ['method' => $method, 'status' => $status, 'match' => $match];
        }
    }

    /** The next $times object reads end after $bytes while the file is longer, as a dropped connection does. */
    public function cutNextDownloads(int $bytes, int $times): void
    {
        $this->cuts = array_fill(0, $times, $bytes);
    }

    /** Whether any stored object or unfinished part holds these bytes. */
    public function holds(string $bytes): bool
    {
        foreach ($this->objects as $object) {
            if (str_contains($object['body'], $bytes)) {
                return true;
            }
        }
        foreach ($this->uploads as $parts) {
            foreach ($parts as $part) {
                if (str_contains($part, $bytes)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @return list<string> */
    public function keys(string $prefix = ''): array
    {
        $keys = array_values(array_filter(array_keys($this->objects), fn (string $key): bool => str_starts_with($key, $prefix)));
        sort($keys);

        return $keys;
    }

    /** How many logged requests start with this ("HEAD ", "GET parts/", …). */
    public function requests(string $start): int
    {
        return count(array_filter($this->log, fn (string $line): bool => str_starts_with($line, $start)));
    }

    private function handle(Request $request): PromiseInterface
    {
        $psr = $request->toPsrRequest();
        $uri = $psr->getUri();
        $method = $psr->getMethod();
        $path = rawurldecode($uri->getPath());
        $prefix = $this->pathStyle ? '/'.self::BUCKET.'/' : '/';
        if (! str_starts_with($path.'/', $prefix)) {
            return Http::response(self::error('NoSuchBucket'), 404);
        }
        $key = substr($path, strlen($prefix));
        parse_str($uri->getQuery(), $query);
        $this->log[] = trim($method.' '.$key.($uri->getQuery() !== '' ? '?'.$uri->getQuery() : ''));

        $body = $request->body();
        if (! $this->signatureHolds($request, $body)) {
            return Http::response(self::error('SignatureDoesNotMatch'), 403);
        }
        foreach ($this->failures as $i => $failure) {
            if ($failure['method'] === $method && str_contains($key, $failure['match'])) {
                array_splice($this->failures, $i, 1);

                return Http::response(self::error('InternalError'), $failure['status']);
            }
        }

        return match (true) {
            $method === 'GET' && $key === '' => $this->listing($query),
            $method === 'POST' && array_key_exists('uploads', $query) => $this->startUpload(),
            $method === 'POST' && isset($query['uploadId']) => $this->finishUpload($key, (string) $query['uploadId'], $body),
            $method === 'PUT' && isset($query['uploadId']) => $this->putPart((string) $query['uploadId'], (int) $query['partNumber'], $body),
            $method === 'DELETE' && isset($query['uploadId']) => $this->abortUpload((string) $query['uploadId']),
            $method === 'PUT' && $psr->hasHeader('x-amz-copy-source') => $this->copy($key, $psr->getHeaderLine('x-amz-copy-source')),
            $method === 'PUT' => $this->put($key, $body),
            $method === 'HEAD' => $this->head($key),
            $method === 'GET' => $this->get($key),
            $method === 'DELETE' => $this->delete($key),
            default => Http::response(self::error('MethodNotAllowed'), 405),
        };
    }

    /** Checks the request as a bucket does: the signature over what actually arrived, and the body against its hash. */
    private function signatureHolds(Request $request, string $body): bool
    {
        $psr = $request->toPsrRequest();
        if (preg_match('#^AWS4-HMAC-SHA256 Credential=([^/]+)/(\d{8})/([^/]+)/s3/aws4_request,SignedHeaders=([^,]+),Signature=[0-9a-f]{64}$#', $psr->getHeaderLine('Authorization'), $m) !== 1) {
            return false;
        }
        $hash = $psr->getHeaderLine('x-amz-content-sha256');
        if ($m[1] !== self::KEY || $m[3] !== self::REGION || $hash !== hash('sha256', $body)) {
            return false;
        }
        $headers = [];
        foreach (explode(';', $m[4]) as $name) {
            if (! $psr->hasHeader($name)) {
                return false;
            }
            $headers[$name] = $psr->getHeaderLine($name);
        }
        $pairs = $psr->getUri()->getQuery() === '' ? [] : explode('&', $psr->getUri()->getQuery());
        sort($pairs, SORT_STRING);
        $at = CarbonImmutable::createFromFormat('Ymd\THis\Z', $psr->getHeaderLine('x-amz-date'), 'UTC');
        if (! $at instanceof CarbonImmutable) {
            return false;
        }

        return hash_equals($this->signer->authorization($psr->getMethod(), $psr->getUri()->getPath(), implode('&', $pairs), $headers, $hash, $at), $psr->getHeaderLine('Authorization'));
    }

    /** @param  array<string, mixed>  $query */
    private function listing(array $query): PromiseInterface
    {
        $prefix = (string) ($query['prefix'] ?? '');
        $delimiter = (string) ($query['delimiter'] ?? '');
        $max = (int) ($query['max-keys'] ?? 1000);
        $entries = [];
        foreach ($this->keys($prefix) as $key) {
            $rest = substr($key, strlen($prefix));
            $cut = $delimiter === '' ? false : strpos($rest, $delimiter);
            $entries[$cut === false ? $key : $prefix.substr($rest, 0, $cut + 1)] = $cut === false;
        }
        // The token names where the last page stopped, so files deleted between pages move nothing.
        $names = array_keys($entries);
        $offset = isset($query['continuation-token']) ? count(array_filter($names, fn (string $name): bool => strcmp($name, base64_decode((string) $query['continuation-token'])) <= 0)) : 0;
        $page = array_slice($entries, $offset, $max, true);
        $next = $offset + $max < count($entries) ? base64_encode($names[$offset + $max - 1]) : null;

        $xml = '<?xml version="1.0" encoding="UTF-8"?><ListBucketResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/"><Name>'.self::BUCKET.'</Name><KeyCount>'.count($page).'</KeyCount>';
        $xml .= '<IsTruncated>'.($next === null ? 'false' : 'true').'</IsTruncated>'.($next === null ? '' : '<NextContinuationToken>'.$next.'</NextContinuationToken>');
        foreach ($page as $name => $isKey) {
            $xml .= $isKey
                ? '<Contents><Key>'.htmlspecialchars($name, ENT_XML1).'</Key><LastModified>'.gmdate('Y-m-d\TH:i:s.000\Z', $this->objects[$name]['modified']).'</LastModified><Size>'.strlen($this->objects[$name]['body']).'</Size></Contents>'
                : '<CommonPrefixes><Prefix>'.htmlspecialchars($name, ENT_XML1).'</Prefix></CommonPrefixes>';
        }

        return Http::response($xml.'</ListBucketResult>', 200, ['Content-Type' => 'application/xml']);
    }

    private function put(string $key, string $body): PromiseInterface
    {
        $this->objects[$key] = ['body' => $body, 'modified' => now()->getTimestamp()];

        return Http::response('', 200, ['ETag' => '"'.md5($body).'"']);
    }

    private function head(string $key): PromiseInterface
    {
        if (! isset($this->objects[$key])) {
            return Http::response('', 404);
        }

        return Http::response('', 200, [
            'Content-Length' => (string) strlen($this->objects[$key]['body']),
            'Content-Type' => 'application/octet-stream',
            'Last-Modified' => gmdate('D, d M Y H:i:s \G\M\T', $this->objects[$key]['modified']),
        ]);
    }

    private function get(string $key): PromiseInterface
    {
        if (! isset($this->objects[$key])) {
            return Http::response(self::error('NoSuchKey'), 404);
        }
        $body = $this->objects[$key]['body'];
        if ($this->cuts !== []) {
            $body = substr($body, 0, (int) array_shift($this->cuts));
        }

        return Http::response($body, 200, ['Content-Type' => 'application/octet-stream']);
    }

    private function delete(string $key): PromiseInterface
    {
        unset($this->objects[$key]);

        return Http::response('', 204);
    }

    private function copy(string $key, string $source): PromiseInterface
    {
        $from = substr(rawurldecode($source), strlen('/'.self::BUCKET.'/'));
        if (! isset($this->objects[$from])) {
            return Http::response(self::error('NoSuchKey'), 404);
        }
        $this->objects[$key] = ['body' => $this->objects[$from]['body'], 'modified' => now()->getTimestamp()];

        return Http::response('<CopyObjectResult><ETag>"x"</ETag></CopyObjectResult>', 200);
    }

    private function startUpload(): PromiseInterface
    {
        // A real upload id holds characters that need encoding.
        $id = 'up+load/'.count($this->uploads).'=';
        $this->uploads[$id] = [];

        return Http::response('<InitiateMultipartUploadResult><UploadId>'.htmlspecialchars($id, ENT_XML1).'</UploadId></InitiateMultipartUploadResult>', 200);
    }

    private function putPart(string $id, int $number, string $body): PromiseInterface
    {
        if (! isset($this->uploads[$id])) {
            return Http::response(self::error('NoSuchUpload'), 404);
        }
        $this->uploads[$id][$number] = $body;

        return Http::response('', 200, ['ETag' => '"'.md5($body).'"']);
    }

    private function finishUpload(string $key, string $id, string $body): PromiseInterface
    {
        if (! isset($this->uploads[$id])) {
            return Http::response(self::error('NoSuchUpload'), 404);
        }
        $whole = '';
        $xml = simplexml_load_string($body);
        foreach ($xml === false ? [] : $xml->Part as $part) {
            $content = $this->uploads[$id][(int) $part->PartNumber] ?? null;
            if ($content === null || (string) $part->ETag !== '"'.md5($content).'"') {
                return Http::response(self::error('InvalidPart'), 400);
            }
            $whole .= $content;
        }
        unset($this->uploads[$id]);
        $this->objects[$key] = ['body' => $whole, 'modified' => now()->getTimestamp()];

        return Http::response('<CompleteMultipartUploadResult><Key>'.htmlspecialchars($key, ENT_XML1).'</Key></CompleteMultipartUploadResult>', 200);
    }

    private function abortUpload(string $id): PromiseInterface
    {
        unset($this->uploads[$id]);

        return Http::response('', 204);
    }

    /** The error body of a real bucket, which repeats the access key and the string it signed. */
    private static function error(string $code): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?><Error><Code>'.$code.'</Code><Message>Something</Message><AWSAccessKeyId>'.self::KEY.'</AWSAccessKeyId><StringToSign>AWS4-HMAC-SHA256</StringToSign></Error>';
    }
}
