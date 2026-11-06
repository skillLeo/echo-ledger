<?php

declare(strict_types=1);

use App\Services\Storage\ObjectStorageDisk;
use App\Services\Storage\S3Adapter;
use App\Services\Storage\S3Signer;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use League\Flysystem\Config;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemException;
use League\Flysystem\UnableToCheckFileExistence;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UnableToSetVisibility;
use League\Flysystem\UnableToWriteFile;
use Tests\Feature\Storage\Support\FakeS3Server;

/*
 * The S3-compatible adapter behind RECORDINGS_DRIVER=s3 (docs/DEPLOY_VPS.md): signatures against AWS's published
 * examples, and each operation over the wire format against a small fake bucket that checks every signature.
 */

describe('the signature', function () {
    // From the AWS documentation, "Authenticating Requests (AWS Signature Version 4): Examples".
    $signer = fn (): S3Signer => new S3Signer('AKIAIOSFODNN7EXAMPLE', 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY', 'us-east-1');
    $at = fn (): CarbonImmutable => CarbonImmutable::create(2013, 5, 24, 0, 0, 0, 'UTC');
    $empty = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

    it('matches AWS\'s GET example, with a Range header signed', function () use ($signer, $at, $empty) {
        $headers = ['host' => 'examplebucket.s3.amazonaws.com', 'range' => 'bytes=0-9', 'x-amz-content-sha256' => $empty, 'x-amz-date' => '20130524T000000Z'];

        expect($signer()->authorization('GET', '/test.txt', '', $headers, $empty, $at()))
            ->toBe('AWS4-HMAC-SHA256 Credential=AKIAIOSFODNN7EXAMPLE/20130524/us-east-1/s3/aws4_request,SignedHeaders=host;range;x-amz-content-sha256;x-amz-date,Signature=f0e8bdb87c964420e857bd35b5d6ed310bd44f0170aba48dd91039c6036bdb41');
    });

    it('matches AWS\'s PUT example, with an encoded key and a body hash', function () use ($signer, $at) {
        $hash = hash('sha256', 'Welcome to Amazon S3.');
        $headers = ['date' => 'Fri, 24 May 2013 00:00:00 GMT', 'host' => 'examplebucket.s3.amazonaws.com', 'x-amz-content-sha256' => $hash, 'x-amz-date' => '20130524T000000Z', 'x-amz-storage-class' => 'REDUCED_REDUNDANCY'];

        expect($signer()->authorization('PUT', '/test%24file.text', '', $headers, $hash, $at()))
            ->toEndWith('Signature=98ad721746da40c64f1a55b78f14c238d841ea1380cd77a1b5971af0ece108bd');
    });

    it('matches AWS\'s list example, with a query', function () use ($signer, $at, $empty) {
        $headers = ['host' => 'examplebucket.s3.amazonaws.com', 'x-amz-content-sha256' => $empty, 'x-amz-date' => '20130524T000000Z'];

        expect($signer()->authorization('GET', '/', 'max-keys=2&prefix=J', $headers, $empty, $at()))
            ->toEndWith('Signature=34b48302e7b5fa45bde8084f4b7868a86f0a534bc59db6670ed5711ef69dc6f7');
    });
});

describe('over the wire', function () {
    beforeEach(function () {
        Http::preventStrayRequests();
        Sleep::fake();
        $this->server = new FakeS3Server;
        $this->server->listen();
        $this->adapter = $this->server->adapter();
        $this->config = new Config;
    });

    it('writes, reads, sizes and deletes an object', function () {
        $this->adapter->write('files/2026/11/a b+c.elr', 'hello', $this->config);

        expect($this->server->keys())->toBe(['files/2026/11/a b+c.elr'])
            ->and($this->adapter->read('files/2026/11/a b+c.elr'))->toBe('hello')
            ->and($this->adapter->fileExists('files/2026/11/a b+c.elr'))->toBeTrue()
            ->and($this->adapter->fileExists('files/2026/11/nothing.elr'))->toBeFalse()
            ->and($this->adapter->fileSize('files/2026/11/a b+c.elr')->fileSize())->toBe(5)
            ->and($this->adapter->lastModified('files/2026/11/a b+c.elr')->lastModified())->toBe(now()->getTimestamp())
            ->and($this->adapter->mimeType('files/2026/11/a b+c.elr')->mimeType())->toBe('application/octet-stream')
            ->and($this->adapter->visibility('files/2026/11/a b+c.elr')->visibility())->toBe('private');
        $this->adapter->delete('files/2026/11/a b+c.elr');
        $this->adapter->delete('files/2026/11/a b+c.elr'); // already gone: no error

        expect($this->server->keys())->toBe([]);
    });

    it('reads an object as a stream and writes one from a stream, leaving the caller\'s handle open', function () {
        $handle = fopen('php://temp', 'w+b');
        fwrite($handle, 'stream body');
        rewind($handle);
        $this->adapter->writeStream('a.elr', $handle, $this->config);

        expect(is_resource($handle))->toBeTrue();
        fclose($handle);
        $read = $this->adapter->readStream('a.elr');
        expect(stream_get_contents($read))->toBe('stream body');
        fclose($read);
    });

    it('takes a stream it cannot seek, and an empty one', function () {
        $pipe = popen('printf piped', 'r');
        $this->adapter->writeStream('piped.elr', $pipe, $this->config);
        pclose($pipe);
        $empty = fopen('php://temp', 'w+b');
        $this->adapter->writeStream('empty.elr', $empty, $this->config);
        fclose($empty);

        expect($this->adapter->read('piped.elr'))->toBe('piped')
            ->and($this->adapter->read('empty.elr'))->toBe('');
    });

    it('answers folders from what is in them, one level or all, across pages', function () {
        foreach (range(1, 1005) as $i) {
            $this->server->objects["parts/one/{$i}.part"] = ['body' => 'x', 'modified' => 100 + $i];
        }
        $this->server->objects['parts/two/0.part'] = ['body' => 'y', 'modified' => 50];
        $this->server->objects['parts/top.txt'] = ['body' => 'z', 'modified' => 60];
        // Another tool's folder marker is not a file.
        $this->server->objects['parts/one/'] = ['body' => '', 'modified' => 1];

        expect($this->adapter->directoryExists('parts'))->toBeTrue()
            ->and($this->adapter->directoryExists('parts/one'))->toBeTrue()
            ->and($this->adapter->directoryExists('par'))->toBeFalse();

        $shallow = iterator_to_array($this->adapter->listContents('parts', false), false);
        expect(array_map(fn ($item): string => $item->path(), $shallow))->toBe(['parts/one', 'parts/two', 'parts/top.txt'])
            ->and($shallow[0]->isDir())->toBeTrue()
            ->and($shallow[2])->toBeInstanceOf(FileAttributes::class)
            ->and($shallow[2]->fileSize())->toBe(1)
            ->and($shallow[2]->lastModified())->toBe(60);

        $deep = iterator_to_array($this->adapter->listContents('parts/one', true), false);
        expect($deep)->toHaveCount(1005) // past one page of 1000, without the marker
            ->and($this->server->requests('GET ?'))->toBeGreaterThan(3);

        $this->adapter->deleteDirectory('parts/one');
        expect($this->server->keys('parts/one/'))->toBe(['parts/one/'])
            ->and($this->server->keys('parts/two/'))->toBe(['parts/two/0.part']);
    });

    it('needs no folders made first', function () {
        $this->adapter->createDirectory('parts/new', $this->config);

        expect($this->server->log)->toBe([])
            ->and($this->adapter->directoryExists('parts/new'))->toBeFalse();
    });

    it('copies and moves', function () {
        $this->adapter->write('a b.elr', 'body', $this->config);
        $this->adapter->copy('a b.elr', 'c.elr', $this->config);
        $this->adapter->move('c.elr', 'd.elr', $this->config);

        expect($this->server->keys())->toBe(['a b.elr', 'd.elr'])
            ->and($this->adapter->read('d.elr'))->toBe('body');
    });

    it('addresses the bucket by name in the path or in the host', function () {
        $seen = [];
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(function (Request $request) use (&$seen) {
            $seen[] = $request->url();

            return Http::response('', 200);
        });

        (new S3Adapter('bucket', 'r', 'k', 's', 'https://S3.Example.com:443/base/', true))->fileExists('a b/c.elr');
        (new S3Adapter('bucket', 'r', 'k', 's', 'https://s3.example.com', false))->fileExists('a b/c.elr');
        (new S3Adapter('bucket', 'r', 'k', 's', 'http://127.0.0.1:9000', true))->fileExists('c.elr');
        (new S3Adapter('bucket', 'eu-west-1', 'k', 's', null, false))->fileExists('c.elr');

        expect($seen)->toBe([
            'https://s3.example.com/base/bucket/a%20b/c.elr',
            'https://bucket.s3.example.com/a%20b/c.elr',
            'http://127.0.0.1:9000/bucket/c.elr',
            'https://bucket.s3.eu-west-1.amazonaws.com/c.elr',
        ]);
    });

    it('signs the host it sends when the bucket is in the host', function () {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        $server = new FakeS3Server(pathStyle: false);
        $server->listen();
        $adapter = $server->adapter();

        $adapter->write('a b/c.elr', 'body', $this->config);

        expect($adapter->read('a b/c.elr'))->toBe('body')
            ->and(iterator_to_array($adapter->listContents('a b', true), false))->toHaveCount(1);
    });

    it('refuses an endpoint that is no address', function () {
        expect(fn () => new S3Adapter('b', 'r', 'k', 's', 'http://', true))->toThrow(RuntimeException::class, 'RECORDINGS_S3_ENDPOINT');
    });

    it('sends the signature and the body hash, never the secret', function () {
        $sent = [];
        $this->adapter->write('a.elr', 'body', $this->config);
        Http::assertSent(function (Request $request) use (&$sent): bool {
            $sent[] = $request->url().json_encode($request->headers());

            return true;
        });

        expect(implode('', $sent))->toContain(FakeS3Server::KEY)->toContain(hash('sha256', 'body'))->not->toContain(FakeS3Server::SECRET);
    });

    it('goes up in parts from the part size, one retry at a time, and comes out whole', function () {
        $adapter = $this->server->adapter(partBytes: 1024);
        $bytes = random_bytes(3000);
        $file = tmpfile();
        fwrite($file, $bytes);
        rewind($file);
        // One part fails once on the way: only that part is sent again.
        $this->server->failNext('PUT', 503);
        $adapter->writeStream('exports/big.elr', $file, $this->config);

        expect(is_resource($file))->toBeTrue();
        fclose($file);
        $puts = array_values(array_filter($this->server->log, fn (string $l): bool => str_starts_with($l, 'PUT ')));
        expect($this->server->objects['exports/big.elr']['body'])->toBe($bytes)
            ->and($this->server->uploads)->toBe([])
            ->and($this->server->requests('POST exports/big.elr?uploads'))->toBe(1)
            ->and($puts)->toHaveCount(4); // parts 1 (twice), 2 and 3
    });

    it('goes up in one piece below the part size', function () {
        $file = tmpfile();
        fwrite($file, str_repeat('x', 1023));
        $this->server->adapter(partBytes: 1024)->writeStream('small.elr', $file, $this->config);
        fclose($file);

        expect($this->server->requests('POST '))->toBe(0)
            ->and($this->server->requests('PUT small.elr'))->toBe(1);
    });

    it('removes what went up when a part fails for good, and says so', function () {
        $adapter = $this->server->adapter(partBytes: 1024);
        $file = tmpfile();
        fwrite($file, random_bytes(3000));
        $this->server->failNext('PUT', 500, 3);

        expect(fn () => $adapter->writeStream('exports/big.elr', $file, $this->config))->toThrow(UnableToWriteFile::class);
        fclose($file);
        expect($this->server->objects)->toBe([])->and($this->server->uploads)->toBe([]);
    });

    it('fails when the bucket refuses to finish an upload', function () {
        $adapter = $this->server->adapter(partBytes: 1024);
        $file = tmpfile();
        fwrite($file, random_bytes(2000));
        $this->server->failNext('POST', 400, match: 'never-a-key');
        Http::swap(new Factory);
        Http::preventStrayRequests();
        // S3 answers 200 with an error in the body when finishing fails late.
        Http::fake(function (Request $request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            if ($request->method() === 'POST' && isset($query['uploadId'])) {
                return Http::response('<Error><Code>InternalError</Code></Error>', 200);
            }

            return $request->method() === 'POST'
                ? Http::response('<InitiateMultipartUploadResult><UploadId>id</UploadId></InitiateMultipartUploadResult>', 200)
                : Http::response('', 200, ['ETag' => '"x"']);
        });

        expect(fn () => $adapter->writeStream('big.elr', $file, $this->config))->toThrow(UnableToWriteFile::class, 'InternalError');
        fclose($file);
    });

    it('tries a request again when the bucket has trouble, and stops when it refuses', function () {
        $this->server->objects['a.elr'] = ['body' => 'body', 'modified' => 1];

        $this->server->failNext('GET', 503, 2);
        expect($this->adapter->read('a.elr'))->toBe('body')->and($this->server->requests('GET a.elr'))->toBe(3);

        $this->server->log = [];
        $this->server->failNext('GET', 429);
        expect($this->adapter->read('a.elr'))->toBe('body')->and($this->server->requests('GET a.elr'))->toBe(2);

        $this->server->log = [];
        $this->server->failNext('GET', 403);
        expect(fn () => $this->adapter->read('a.elr'))->toThrow(UnableToReadFile::class, 'HTTP 403 InternalError');
        expect($this->server->requests('GET a.elr'))->toBe(1);
    });

    it('turns every failure into the storage exception of its operation, without the key or the signature', function () {
        $this->server->objects['a.elr'] = ['body' => 'body', 'modified' => 1];
        $failing = fn (string $method): mixed => $this->server->failNext($method, 403);

        $failing('HEAD');
        expect(fn () => $this->adapter->fileExists('a.elr'))->toThrow(UnableToCheckFileExistence::class);
        $failing('HEAD');
        expect(fn () => $this->adapter->fileSize('a.elr'))->toThrow(UnableToRetrieveMetadata::class);
        $failing('PUT');
        expect(fn () => $this->adapter->write('a.elr', 'x', $this->config))->toThrow(UnableToWriteFile::class);
        $failing('DELETE');
        expect(fn () => $this->adapter->delete('a.elr'))->toThrow(UnableToDeleteFile::class);
        $failing('GET');
        try {
            $this->adapter->readStream('a.elr');
            $this->fail('It should not read.');
        } catch (UnableToReadFile $e) {
            expect($e->getMessage())->toContain('HTTP 403')->not->toContain(FakeS3Server::KEY)->not->toContain('AWS4-HMAC');
        }
        expect(fn () => $this->adapter->read('missing.elr'))->toThrow(UnableToReadFile::class, 'NoSuchKey');
    });

    it('keeps the address, the bucket and the HTTP client\'s text out of a failed connection\'s message, and in the log\'s', function () {
        $this->server->objects['files/a.elr'] = ['body' => 'body', 'modified' => 1];
        $stream = fopen('php://memory', 'w+b');
        $calls = [
            'HEAD' => [fn () => $this->adapter->fileExists('files/a.elr'), fn () => $this->adapter->fileSize('files/a.elr')],
            'GET' => [fn () => $this->adapter->read('files/a.elr'), fn () => $this->adapter->readStream('files/a.elr'),
                fn () => $this->adapter->directoryExists('files'), fn () => iterator_to_array($this->adapter->listContents('files', true)), fn () => $this->adapter->deleteDirectory('files')],
            'PUT' => [fn () => $this->adapter->write('files/a.elr', 'x', $this->config), fn () => $this->adapter->writeStream('files/a.elr', $stream, $this->config)],
            'DELETE' => [fn () => $this->adapter->delete('files/a.elr')],
        ];

        foreach ($calls as $method => $list) {
            foreach ($list as $call) {
                $this->server->dropNext($method, 3);
                try {
                    $call();
                    $this->fail('It should not work.');
                } catch (FilesystemException $e) {
                    expect($e->getMessage())->not->toContain(FakeS3Server::HOST)->not->toContain(FakeS3Server::BUCKET)->not->toContain('cURL')->not->toContain('http');
                    // The log prints the chain.
                    $chain = '';
                    for ($previous = $e; $previous !== null; $previous = $previous->getPrevious()) {
                        $chain .= $previous->getMessage()."\n";
                    }
                    expect($chain)->toContain('The bucket could not be reached.')->toContain(FakeS3Server::HOST);
                }
            }
        }
        fclose($stream);
    });

    it('tells whether the bucket keeps versions, and null when it will not say', function () {
        expect($this->adapter->versioning())->toBe('');

        $this->server->versioning = 'Enabled';
        expect($this->adapter->versioning())->toBe('Enabled');

        $this->server->versioning = 'Suspended';
        expect($this->adapter->versioning())->toBe('Suspended');

        $this->server->versioning = null;
        expect($this->adapter->versioning())->toBeNull();

        $this->server->versioning = 'Enabled';
        $this->server->dropNext('GET', 3);
        expect($this->adapter->versioning())->toBeNull();
    });

    it('sets no visibility: objects stay private', function () {
        expect(fn () => $this->adapter->setVisibility('a.elr', 'public'))->toThrow(UnableToSetVisibility::class);
    });
});

describe('the disk', function () {
    it('stays on the server\'s own disk unless told otherwise', function () {
        expect(config('filesystems.disks.recordings.driver'))->toBe('local');
    });

    it('reads its settings from RECORDINGS_DRIVER and RECORDINGS_S3_*', function () {
        $set = ['RECORDINGS_DRIVER' => 'S3', 'RECORDINGS_S3_KEY' => 'k', 'RECORDINGS_S3_SECRET' => 's', 'RECORDINGS_S3_REGION' => 'auto', 'RECORDINGS_S3_BUCKET' => 'b', 'RECORDINGS_S3_ENDPOINT' => 'https://x.test', 'RECORDINGS_S3_PATH_STYLE' => 'true'];
        foreach ($set as $name => $value) {
            $_ENV[$name] = $_SERVER[$name] = $value;
        }
        try {
            $disk = (require base_path('config/filesystems.php'))['disks']['recordings'];
        } finally {
            foreach ($set as $name => $value) {
                unset($_ENV[$name], $_SERVER[$name]);
            }
        }

        expect($disk)->toMatchArray(['driver' => 's3-compatible', 'key' => 'k', 'secret' => 's', 'region' => 'auto', 'bucket' => 'b', 'endpoint' => 'https://x.test', 'path_style' => true, 'throw' => true]);

        config(['filesystems.disks.recordings' => $disk]);
        Storage::forgetDisk('recordings');
        expect(Storage::disk('recordings'))->toBeInstanceOf(ObjectStorageDisk::class)
            ->and(fn () => Storage::disk('recordings')->path('files/a.elr'))->toThrow(LogicException::class, 'no local paths');
    });

    it('says which setting is missing', function () {
        config(['filesystems.disks.recordings' => ['driver' => 's3-compatible', 'key' => 'k', 'secret' => 's', 'region' => 'auto', 'bucket' => '']]);
        Storage::forgetDisk('recordings');

        expect(fn () => Storage::disk('recordings'))->toThrow(RuntimeException::class, 'RECORDINGS_S3_BUCKET is not set');
    });
});
