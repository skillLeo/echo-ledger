<?php

declare(strict_types=1);

namespace App\Services\Storage;

use Carbon\CarbonImmutable;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\Psr7\LimitStream;
use GuzzleHttp\Psr7\StreamWrapper;
use GuzzleHttp\Psr7\Utils;
use GuzzleHttp\Utils as GuzzleUtils;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use League\Flysystem\Config;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\UnableToCheckDirectoryExistence;
use League\Flysystem\UnableToCheckFileExistence;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\UnableToDeleteDirectory;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToListContents;
use League\Flysystem\UnableToMoveFile;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UnableToSetVisibility;
use League\Flysystem\UnableToWriteFile;
use League\Flysystem\Visibility;
use Psr\Http\Message\StreamInterface;
use RuntimeException;
use SimpleXMLElement;
use Throwable;

/**
 * The recordings disk on S3-compatible storage (Amazon S3, Backblaze B2, Cloudflare R2, Wasabi, MinIO), over Laravel's
 * HTTP client with Signature Version 4. Laravel's own s3 driver needs the AWS SDK, which is not in this project; this
 * uses only what the disk needs. Objects are always private and never served from the bucket. Bodies go up with their
 * SHA-256 signed, so the bucket refuses anything that arrives damaged; a big file goes up in parts, so a network error
 * costs one part instead of the whole file.
 */
final class S3Adapter implements FilesystemAdapter
{
    /** Files from this size go up in parts. A single PUT stops at 5 GB, and an account export can pass that. */
    public const MULTIPART_BYTES = 64 * 1024 * 1024;

    /** S3 takes at most this many parts in one upload. */
    private const MAX_PARTS = 10000;

    /** A failed request is tried this many times, waiting this long between them (milliseconds). */
    private const RETRY_DELAYS_MS = [200, 1000];

    private const REQUEST_SECONDS = 60;

    /** Long enough for one part or a whole recording on a slow line. */
    private const TRANSFER_SECONDS = 3600;

    private const CONNECT_SECONDS = 10;

    private readonly S3Signer $signer;

    private readonly string $origin;

    private readonly string $host;

    private readonly string $basePath;

    /** One connection for the whole run: assembling a recording asks for hundreds of parts, each costing a TLS handshake otherwise. */
    private mixed $handler = null;

    public function __construct(
        private readonly string $bucket,
        string $region,
        string $key,
        #[\SensitiveParameter] string $secret,
        ?string $endpoint,
        private readonly bool $pathStyle,
        private readonly int $partBytes = self::MULTIPART_BYTES,
    ) {
        $this->signer = new S3Signer($key, $secret, $region);
        $url = parse_url($endpoint !== null && $endpoint !== '' ? $endpoint : 'https://s3.'.$region.'.amazonaws.com');
        if (! is_array($url) || ! isset($url['host'])) {
            throw new RuntimeException('RECORDINGS_S3_ENDPOINT is not a valid address.');
        }
        $scheme = strtolower($url['scheme'] ?? 'https');
        $port = $url['port'] ?? null;
        // The address the HTTP client sends as Host: lower case, without the scheme's own port.
        $authority = strtolower($url['host']).($port !== null && $port !== ($scheme === 'https' ? 443 : 80) ? ':'.$port : '');
        $this->host = $pathStyle ? $authority : $bucket.'.'.$authority;
        $this->origin = $scheme.'://'.$this->host;
        $this->basePath = rtrim($url['path'] ?? '', '/').($pathStyle ? '/'.rawurlencode($bucket) : '');
    }

    public function fileExists(string $path): bool
    {
        try {
            $response = $this->send('HEAD', $path);
        } catch (Throwable $e) {
            throw UnableToCheckFileExistence::forLocation($path, $e);
        }
        if ($response->status() === 404) {
            return false;
        }
        if (! $response->successful()) {
            throw UnableToCheckFileExistence::forLocation($path, new RuntimeException(self::reason($response)));
        }

        return true;
    }

    public function directoryExists(string $path): bool
    {
        try {
            $page = $this->listPage(self::folder($path), false, null, 1);

            return count($page->Contents) + count($page->CommonPrefixes) > 0;
        } catch (Throwable $e) {
            throw UnableToCheckDirectoryExistence::forLocation($path, $e);
        }
    }

    public function write(string $path, string $contents, Config $config): void
    {
        try {
            $this->put($path, $contents, hash('sha256', $contents));
        } catch (Throwable $e) {
            throw UnableToWriteFile::atLocation($path, $e->getMessage(), $e);
        }
    }

    /** @param  resource  $contents */
    public function writeStream(string $path, $contents, Config $config): void
    {
        $spooled = null;
        try {
            // Its length and hash are needed before it goes up, and a retry reads it again.
            $source = $contents;
            $stat = stream_get_meta_data($contents)['seekable'] ? fstat($contents) : false;
            if ($stat === false) {
                $spooled = fopen('php://temp', 'w+b');
                if ($spooled === false || stream_copy_to_stream($contents, $spooled) === false) {
                    throw new RuntimeException('Could not read the stream to write.');
                }
                $source = $spooled;
                $stat = fstat($spooled);
            }
            $size = is_array($stat) ? (int) $stat['size'] : 0;
            if ($size >= $this->partBytes) {
                $this->putInParts($path, $source, $size);
            } else {
                $this->putRange($path, $source, 0, $size, []);
            }
        } catch (Throwable $e) {
            throw UnableToWriteFile::atLocation($path, $e->getMessage(), $e);
        } finally {
            if (is_resource($spooled)) {
                fclose($spooled);
            }
        }
    }

    public function read(string $path): string
    {
        try {
            $response = $this->send('GET', $path, transfer: true);
        } catch (Throwable $e) {
            throw UnableToReadFile::fromLocation($path, $e->getMessage(), $e);
        }
        if (! $response->successful()) {
            throw UnableToReadFile::fromLocation($path, self::reason($response));
        }

        return $response->body();
    }

    /** @return resource */
    public function readStream(string $path)
    {
        try {
            $response = $this->send('GET', $path, transfer: true, stream: true);
        } catch (Throwable $e) {
            throw UnableToReadFile::fromLocation($path, $e->getMessage(), $e);
        }
        if (! $response->successful()) {
            throw UnableToReadFile::fromLocation($path, self::reason($response));
        }

        return StreamWrapper::getResource($response->toPsrResponse()->getBody());
    }

    public function delete(string $path): void
    {
        try {
            $response = $this->send('DELETE', $path);
        } catch (Throwable $e) {
            throw UnableToDeleteFile::atLocation($path, $e->getMessage(), $e);
        }
        if (! $response->successful() && $response->status() !== 404) {
            throw UnableToDeleteFile::atLocation($path, self::reason($response));
        }
    }

    public function deleteDirectory(string $path): void
    {
        try {
            foreach ($this->listContents($path, true) as $item) {
                if ($item instanceof FileAttributes) {
                    $this->delete($item->path());
                }
            }
        } catch (Throwable $e) {
            throw UnableToDeleteDirectory::atLocation($path, $e->getMessage(), $e);
        }
    }

    /** A folder in a bucket is only the start of its files' names: there is nothing to make. */
    public function createDirectory(string $path, Config $config): void {}

    public function setVisibility(string $path, string $visibility): void
    {
        throw UnableToSetVisibility::atLocation($path, 'Objects in the recordings bucket are always private.');
    }

    public function visibility(string $path): FileAttributes
    {
        return new FileAttributes($path, null, Visibility::PRIVATE);
    }

    public function mimeType(string $path): FileAttributes
    {
        return new FileAttributes($path, mimeType: $this->metadata($path, 'mimeType')->header('Content-Type'));
    }

    public function lastModified(string $path): FileAttributes
    {
        $at = strtotime($this->metadata($path, 'lastModified')->header('Last-Modified'));
        if ($at === false) {
            throw UnableToRetrieveMetadata::lastModified($path, 'The answer has no Last-Modified.');
        }

        return new FileAttributes($path, lastModified: $at);
    }

    public function fileSize(string $path): FileAttributes
    {
        return new FileAttributes($path, (int) $this->metadata($path, 'fileSize')->header('Content-Length'));
    }

    /** @return iterable<DirectoryAttributes|FileAttributes> */
    public function listContents(string $path, bool $deep): iterable
    {
        $prefix = self::folder($path);
        $token = null;
        try {
            do {
                $page = $this->listPage($prefix, $deep, $token);
                foreach ($page->CommonPrefixes as $folder) {
                    yield new DirectoryAttributes((string) $folder->Prefix);
                }
                foreach ($page->Contents as $object) {
                    $key = (string) $object->Key;
                    // A zero-byte object ending in "/" is another tool's folder marker, not a file.
                    if (! str_ends_with($key, '/')) {
                        yield new FileAttributes($key, (int) $object->Size, null, strtotime((string) $object->LastModified) ?: null);
                    }
                }
                $token = (string) $page->IsTruncated === 'true' ? (string) $page->NextContinuationToken : null;
            } while ($token !== null && $token !== '');
        } catch (Throwable $e) {
            throw UnableToListContents::atLocation($path, $deep, $e);
        }
    }

    public function move(string $source, string $destination, Config $config): void
    {
        try {
            $this->copy($source, $destination, $config);
            $this->delete($source);
        } catch (Throwable $e) {
            throw UnableToMoveFile::fromLocationTo($source, $destination, $e);
        }
    }

    public function copy(string $source, string $destination, Config $config): void
    {
        try {
            $response = $this->send('PUT', $destination, headers: ['x-amz-copy-source' => '/'.rawurlencode($this->bucket).'/'.self::encodePath($source)]);
            self::assertFinished($response);
        } catch (Throwable $e) {
            throw UnableToCopyFile::fromLocationTo($source, $destination, $e);
        }
    }

    private function metadata(string $path, string $type): Response
    {
        try {
            $response = $this->send('HEAD', $path);
        } catch (Throwable $e) {
            throw UnableToRetrieveMetadata::create($path, $type, $e->getMessage(), $e);
        }
        if (! $response->successful()) {
            throw UnableToRetrieveMetadata::create($path, $type, self::reason($response));
        }

        return $response;
    }

    /** One page of a listing: files and, unless it goes deep, the folders right under the prefix. */
    private function listPage(string $prefix, bool $deep, ?string $token, int $max = 1000): SimpleXMLElement
    {
        $query = ['list-type' => '2', 'max-keys' => (string) $max, 'prefix' => $prefix];
        if (! $deep) {
            $query['delimiter'] = '/';
        }
        if ($token !== null) {
            $query['continuation-token'] = $token;
        }
        $response = $this->send('GET', '', $query);
        self::assertOk($response);

        return self::xml($response->body());
    }

    /** @param  array<string, string>  $query */
    private function put(string $path, string|StreamInterface $body, string $hash, array $query = []): Response
    {
        $response = $this->send('PUT', $path, $query, [], $body, $hash, true);
        self::assertOk($response);

        return $response;
    }

    /**
     * Sends $length bytes of an open file from $offset as one PUT.
     *
     * @param  resource  $file
     * @param  array<string, string>  $query
     */
    private function putRange(string $path, $file, int $offset, int $length, array $query): Response
    {
        $hash = hash_init('sha256');
        fseek($file, $offset);
        if ($length > 0) {
            hash_update_stream($hash, $file, $length);
        }
        $stream = Utils::streamFor($file);
        try {
            return $this->put($path, new LimitStream($stream, $length, $offset), hash_final($hash), $query);
        } finally {
            // The caller opened the file and closes it: PSR-7 would close it when the wrapper goes away.
            $stream->detach();
        }
    }

    /**
     * A multipart upload; a part that fails after its retries ends it, and what went up is removed.
     *
     * @param  resource  $file
     */
    private function putInParts(string $path, $file, int $size): void
    {
        $started = $this->send('POST', $path, ['uploads' => '']);
        self::assertOk($started);
        $uploadId = (string) self::xml($started->body())->UploadId;
        if ($uploadId === '') {
            throw new RuntimeException('The bucket did not start the upload.');
        }

        try {
            $partBytes = max($this->partBytes, (int) ceil($size / self::MAX_PARTS));
            $etags = [];
            for ($number = 1, $offset = 0; $offset < $size; $number++, $offset += $partBytes) {
                $answer = $this->putRange($path, $file, $offset, min($partBytes, $size - $offset), ['partNumber' => (string) $number, 'uploadId' => $uploadId]);
                $etags[$number] = $answer->header('ETag');
            }
            $list = '';
            foreach ($etags as $number => $etag) {
                $list .= '<Part><PartNumber>'.$number.'</PartNumber><ETag>'.htmlspecialchars($etag, ENT_XML1).'</ETag></Part>';
            }
            $body = '<CompleteMultipartUpload>'.$list.'</CompleteMultipartUpload>';
            self::assertFinished($this->send('POST', $path, ['uploadId' => $uploadId], [], $body, hash('sha256', $body), true));
        } catch (Throwable $e) {
            try {
                $this->send('DELETE', $path, ['uploadId' => $uploadId]);
            } catch (Throwable $abort) {
                report($abort); // the bucket's own rule for unfinished uploads removes it (docs/DEPLOY_VPS.md)
            }
            throw $e;
        }
    }

    /**
     * @param  array<string, string>  $query
     * @param  array<string, string>  $headers  extra headers to sign (`x-amz-…`)
     */
    private function send(string $method, string $path, array $query = [], array $headers = [], string|StreamInterface|null $body = null, ?string $bodyHash = null, bool $transfer = false, bool $stream = false): Response
    {
        ksort($query, SORT_STRING);
        $queryString = http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        $target = $this->basePath.'/'.self::encodePath($path);
        $hash = $bodyHash ?? hash('sha256', '');
        $at = CarbonImmutable::now('UTC');
        $signed = [...$headers, 'host' => $this->host, 'x-amz-content-sha256' => $hash, 'x-amz-date' => S3Signer::amzDate($at)];
        $authorization = $this->signer->authorization($method, $target, $queryString, $signed, $hash, $at);
        unset($signed['host']); // the HTTP client sends the same value itself

        $this->handler ??= GuzzleUtils::chooseHandler();
        $request = Http::withHeaders([...$signed, 'Authorization' => $authorization])
            ->setHandler($this->handler)
            ->contentType('application/octet-stream')
            ->withOptions(['stream' => $stream, 'expect' => false, 'decode_content' => false, 'allow_redirects' => false])
            ->connectTimeout(self::CONNECT_SECONDS)
            ->timeout($transfer ? self::TRANSFER_SECONDS : self::REQUEST_SECONDS)
            ->retry(self::RETRY_DELAYS_MS, 0, fn (Throwable $e): bool => self::worthRetrying($e), false);
        if ($body !== null) {
            $request = $request->withBody($body, 'application/octet-stream');
        }

        return $request->send($method, $this->origin.$target.($queryString !== '' ? '?'.$queryString : ''));
    }

    /** A network error, the bucket's own trouble or its asking to slow down; a refusal does not change on a retry. */
    private static function worthRetrying(Throwable $e): bool
    {
        return $e instanceof ConnectionException || $e instanceof TransferException
            || ($e instanceof RequestException && ($e->response->serverError() || $e->response->status() === 429));
    }

    private static function assertOk(Response $response): void
    {
        if (! $response->successful()) {
            throw new RuntimeException(self::reason($response));
        }
    }

    /** Copying and finishing an upload can answer 200 and still fail: the error is then in the body. */
    private static function assertFinished(Response $response): void
    {
        self::assertOk($response);
        if (self::xml($response->body())->getName() === 'Error') {
            throw new RuntimeException('HTTP 200 '.(string) self::xml($response->body())->Code);
        }
    }

    /** What the bucket said, without the signature details its error body repeats (the access key among them). */
    private static function reason(Response $response): string
    {
        $code = '';
        $body = $response->body();
        if ($body !== '') {
            try {
                $code = (string) self::xml($body)->Code;
            } catch (Throwable) {
                $code = '';
            }
        }

        return 'HTTP '.$response->status().($code !== '' ? ' '.$code : '');
    }

    private static function xml(string $body): SimpleXMLElement
    {
        $previous = libxml_use_internal_errors(true);
        try {
            $xml = simplexml_load_string($body, SimpleXMLElement::class, LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if ($xml === false) {
            throw new RuntimeException('The bucket answered with something that is not XML.');
        }

        return $xml;
    }

    private static function folder(string $path): string
    {
        $path = trim($path, '/');

        return $path === '' ? '' : $path.'/';
    }

    private static function encodePath(string $path): string
    {
        return implode('/', array_map(rawurlencode(...), explode('/', ltrim($path, '/'))));
    }
}
