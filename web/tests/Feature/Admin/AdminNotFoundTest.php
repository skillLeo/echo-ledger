<?php

declare(strict_types=1);

use App\Models\CaptureSession;
use Tests\Feature\Admin\Support\AdminTestHelpers;

uses(AdminTestHelpers::class);

it('answers an unknown address with a 404 page', function (string $url) {
    $response = $this->get($url);

    $response->assertNotFound();
    expect($response->headers->get('Content-Type'))->toContain('text/html');
    expect($response->getContent())->toContain('Not Found');
})->with(['site root path' => '/nothing-here', 'under the console' => '/admin/nothing-here', 'deep path' => '/admin/sessions/abc/def/ghi', 'static look-alike' => '/admin-media']);

it('serves the same 404 to a signed-in admin', function () {
    $this->signInAsAdmin();

    $this->get('/admin/nothing-here')->assertNotFound()->assertSee('Not Found');
});

it('does not reveal a stack trace, file path or framework version on a 404 when debug is off', function () {
    config(['app.debug' => false]);

    $body = $this->get('/admin/nothing-here')->assertNotFound()->getContent();

    expect($body)->not->toContain('vendor\\')->not->toContain('vendor/')
        ->not->toContain('Stack trace')->not->toContain('Illuminate\\')
        ->not->toContain('NotFoundHttpException')->not->toContain('.php');
});

it('answers an unknown record with a 404 rather than an error', function () {
    $admin = $this->signInAsAdmin();
    CaptureSession::factory()->create(['user_id' => $admin->id]);

    $this->get('/admin/sessions/01JNOSUCHSESSION0000000000')->assertNotFound();
    $this->get('/admin/clients/01JNOSUCHCLIENT000000000000/edit')->assertNotFound();
    $this->get('/admin/sessions/%00')->assertNotFound();
    $this->get("/admin/sessions/'%20OR%201=1")->assertNotFound();
});

it('does not echo the requested path back into the 404 page', function () {
    config(['app.debug' => false]);

    $body = $this->get('/admin/%3Cscript%3Ealert(1)%3C/script%3E')->assertNotFound()->getContent();

    expect($body)->not->toContain('<script>alert(1)</script>');
});

it('rejects an unsupported method with 405 instead of 404 for known console routes', function () {
    $this->signInAsAdmin();

    $this->postJson('/admin/sessions')->assertStatus(405);
    $this->deleteJson('/admin/clients')->assertStatus(405);
});

it('returns JSON errors for unknown API routes', function () {
    config(['app.debug' => false]);

    $this->getJson('/api/nothing-here')->assertNotFound()->assertJsonStructure(['message'])->assertJsonMissingPath('trace');
});

it('shows the health endpoint without exposing internals', function () {
    $response = $this->get('/up');

    expect($response->getStatusCode())->toBe(200);
    expect($response->getContent())->not->toContain('vendor')->not->toContain(base_path());
});
