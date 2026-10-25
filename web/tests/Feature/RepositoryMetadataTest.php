<?php

declare(strict_types=1);

use App\Enums\RecordingRole;

// A6.W29: project metadata and templates describe EchoLedger, not the framework skeleton.

it('names the project EchoLedger in composer.json', function () {
    $composer = json_decode((string) file_get_contents(base_path('composer.json')), true, flags: JSON_THROW_ON_ERROR);

    expect($composer['name'])->toBe('echoledger/server')
        ->and($composer['description'])->toContain('EchoLedger')->not->toContain('skeleton')
        ->and($composer['keywords'])->toContain('echoledger')->not->toContain('framework')
        ->and($composer['license'])->toBe('proprietary');
});

it('keeps composer.lock in step with composer.json', function () {
    $content = json_decode((string) file_get_contents(base_path('composer.json')), true, flags: JSON_THROW_ON_ERROR);
    // Same fields and order as Composer's Locker::getContentHash().
    $relevant = array_intersect_key($content, array_flip(['name', 'version', 'require', 'require-dev', 'conflict', 'replace', 'provide', 'minimum-stability', 'prefer-stable', 'repositories', 'extra']));
    if (isset($content['config']['platform'])) {
        $relevant['config']['platform'] = $content['config']['platform'];
    }
    ksort($relevant);
    $lock = json_decode((string) file_get_contents(base_path('composer.lock')), true, flags: JSON_THROW_ON_ERROR);

    expect($lock['content-hash'])->toBe(md5((string) json_encode($relevant)));
});

it('ships an environment template with production defaults and the product name', function () {
    $template = (string) file_get_contents(base_path('.env.example'));

    expect($template)->toMatch('/^APP_NAME=EchoLedger\s*$/m')
        ->toMatch('/^APP_ENV=production\s*$/m')
        ->toMatch('/^APP_DEBUG=false\s*$/m')
        ->toMatch('/^ADMIN_PASSWORD=\s*$/m')
        ->not->toMatch('/^DEMO_SEED=true/m');
});

it('does not tell deployers to run npm ci without a lock file', function () {
    $deploy = (string) file_get_contents(base_path('DEPLOY.md'));

    if (! file_exists(base_path('package-lock.json'))) {
        expect($deploy)->not->toContain('npm ci &&')->toContain('npm install && npm run build');
    }
});

it('has no mis-encoded characters in the shipped sources', function () {
    $hits = [];
    foreach (['app', 'config', 'routes', 'database', 'resources', 'bootstrap'] as $dir) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($dir), FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (! $file->isFile() || ! preg_match('/\.(php|js|css|json|md)$/', $file->getFilename())) {
                continue;
            }
            // UTF-8 text decoded as Windows-1252 and encoded again (a middle dot, a dash or an arrow turning into two or three other characters).
            if (preg_match('/\xC3\x82[\xC2\xA0-\xC2\xBF]|\xC3\xA2\xE2\x82\xAC|\xC3\xA2\xE2\x80\xA0|\xC3\x83[\xC2\x80-\xC2\xBF]/', (string) file_get_contents($file->getPathname())) === 1) {
                $hits[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname());
            }
        }
    }

    expect($hits)->toBe([]);
});

it('labels the channel recordings with a middle dot', function () {
    expect(RecordingRole::Channel1->getLabel())->toBe('Channel 1 · Me')
        ->and(RecordingRole::Channel2->getLabel())->toBe('Channel 2 · Client');
});
