<?php

declare(strict_types=1);

use App\Filament\Auth\EditProfile;
use App\Filament\Pages\Upgrade\ChoosePlan;
use App\Filament\Pages\Upgrade\PayForPlan;
use App\Filament\Pages\Upgrade\RequestStatus;
use App\Filament\Pages\Upgrade\SendReceipt;
use App\Filament\Support\SettingsContext;
use App\Filament\Support\UpgradeFlow;
use App\Http\Middleware\ReceiptUploadRules;
use App\Models\Plan;
use App\Models\UpgradeRequest;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Entitlements\PaymentProofs;
use App\Services\Entitlements\UpgradeRequests;
use App\Services\SettingsRepository;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

/*
| v1.7: the upgrade flow of the console. Upgrade (the plans above yours), Pay for {Pro} (the bank details with Copy,
| the exact amount, the reference), Send receipt (up to 3 screenshots or PDFs, the transaction ID) and the request's
| page per stage, with the person's own receipts only.
*/

require_once __DIR__.'/../Workspaces/Support/receipt_helpers.php';

beforeEach(function () {
    Mail::fake();
    Fx::fakeRecordingsDisk();
    Plan::query()->where('key', 'business')->update(['name' => 'Ultra']);
    Filament::setCurrentPanel('app');
    $this->person = Fx::user(['name' => 'Nida Shah']);
    Workspace::query()->whereKey($this->person->current_workspace_id)->update(['plan_id' => Plan::free()->id]);
    $this->actingAs($this->person, 'web');
});

afterEach(fn () => Fx::cleanupDisks());

function flowRequest(User $user, string $plan = 'pro'): UpgradeRequest
{
    SettingsContext::forget();

    return app(UpgradeRequests::class)->create($user, (int) $user->current_workspace_id, $plan, null);
}

function flowBankDetails(): void
{
    app(SettingsRepository::class)->setMany(['payment.bank' => 'Example Bank', 'payment.branch' => 'Main Branch (0001)', 'payment.account_title' => 'EXAMPLE PRIVATE LIMITED',
        'payment.account_number' => '0123456789', 'payment.iban' => 'PK36SCBL0000001123456702']);
}

it('offers only the plans above yours, the next one chosen, or the lowest with the feature that opened it', function () {
    Livewire::test(ChoosePlan::class)->assertSet('plan', 'pro')->assertSee('Request Pro')->assertSee("You're on Free.")->assertSee('To pay');
    Livewire::withQueryParams(['feature' => 'live_translate'])->test(ChoosePlan::class)->assertSet('plan', 'business')->assertSee('Live Translate is in Ultra.');

    Workspace::query()->whereKey($this->person->current_workspace_id)->update(['plan_id' => Plan::query()->where('key', 'pro')->value('id')]);
    SettingsContext::forget();
    $page = Livewire::test(ChoosePlan::class)->assertSet('plan', 'business');
    expect($page->html())->not->toContain('data-plan-card="pro"')->toContain('data-plan-card="business"');
});

it('moves to the request while one is open, and never offers Upgrade then', function () {
    $request = flowRequest($this->person);
    $this->get('/app/settings/plan/upgrade')->assertRedirect(UpgradeFlow::statusUrl($request));
    SettingsContext::forget();
    $html = (string) $this->get('/app/settings/plan')->assertOk()->getContent();
    expect($html)->not->toContain('id="setting-plan.upgrade"')->not->toContain('>Choose Pro<')->toContain('Pro requested')
        ->and(substr_count($html, 'el-btn el-btn-primary'))->toBe(1);
});

it('shows how to pay: the exact amount, each detail with Copy, the IBAN in fours and the reference', function () {
    flowBankDetails();
    $request = flowRequest($this->person);
    $html = (string) $this->get(UpgradeFlow::payUrl($request))->assertOk()->getContent();
    expect($html)->toContain('Pay for Pro')->toContain('Rs 2,999')->toContain('EXAMPLE PRIVATE LIMITED')
        ->toContain('PK36 SCBL 0000 0011 2345 6702')->toContain($request->fresh()->reference_code)
        ->toContain('aria-label="Copy IBAN"')->toContain('aria-label="Copy account title"')->toContain('aria-label="Copy Account number"')
        ->toContain('Put '.$request->fresh()->reference_code.' in the transfer')->toContain('Add payment receipt');
});

it('shows the instructions text when no bank details are set', function () {
    $request = flowRequest($this->person);
    $html = (string) $this->get(UpgradeFlow::payUrl($request))->assertOk()->getContent();
    expect($html)->toContain('How to pay')->not->toContain('Copy IBAN');
});

it('takes a screenshot, refuses what is not a receipt, and keeps no temporary copy', function () {
    $request = flowRequest($this->person);
    $page = Livewire::test(SendReceipt::class, ['request' => $request->id])
        ->set('uploads', [UploadedFile::fake()->createWithContent('receipt.png', receiptPicture())])
        ->assertSet('failed', []);
    expect($request->proofs()->count())->toBe(1);

    $page->set('uploads', [UploadedFile::fake()->createWithContent('anim.gif', receiptPicture('gif'))])
        ->assertSet('failed.0.message', 'Send a screenshot or photo (JPEG, PNG or WebP) or a PDF.');
    $page->set('uploads', [UploadedFile::fake()->create('huge.png', 6000, 'image/png')])
        ->assertSet('failed.0.message', 'This file is over 5 MB. Try a screenshot instead.');
    expect($request->proofs()->count())->toBe(1);
});

it('takes up to 3 files a round, sends them with the transaction ID, and locks them', function () {
    $request = flowRequest($this->person);
    $page = Livewire::test(SendReceipt::class, ['request' => $request->id]);
    foreach ([1, 2, 3] as $seed) {
        $page->set('uploads', [UploadedFile::fake()->createWithContent("r{$seed}.png", receiptPicture(seed: $seed))]);
    }
    $page->set('uploads', [UploadedFile::fake()->createWithContent('r4.png', receiptPicture(seed: 4))])
        ->assertSet('failed.0.message', 'You can add up to 3 files at a time. Remove one to add another.');
    expect($request->proofs()->count())->toBe(3);

    $page->set('transactionId', 'FT26313ABC7781')->call('send')->assertRedirect(UpgradeFlow::statusUrl($request));
    expect($request->fresh()->stage())->toBe('in_review')
        ->and($request->proofs()->whereNull('locked_at')->count())->toBe(0);

    $this->get(UpgradeFlow::receiptUrl($request))->assertRedirect(UpgradeFlow::statusUrl($request));
});

it('needs a screenshot or the transaction ID before Send receipt, and checks the ID', function () {
    $request = flowRequest($this->person);
    Livewire::test(SendReceipt::class, ['request' => $request->id])
        ->call('send')->assertSet('error', 'Add a screenshot, or type the transaction ID.')
        ->set('transactionId', '!!')->call('send')->assertSet('error', 'The transaction ID is 4 to 64 letters, numbers, spaces or . _ / # -.');
    expect($request->fresh()->stage())->toBe('awaiting_payment');
});

it('removes a file not sent yet, and never one already sent', function () {
    $request = flowRequest($this->person);
    $page = Livewire::test(SendReceipt::class, ['request' => $request->id])
        ->set('uploads', [UploadedFile::fake()->createWithContent('a.png', receiptPicture())]);
    $proof = $request->proofs()->sole();
    $page->call('removeProof', $proof->id);
    expect($request->proofs()->count())->toBe(0);

    $page->set('uploads', [UploadedFile::fake()->createWithContent('b.png', receiptPicture(seed: 2))])->call('send');
    $sent = $request->proofs()->sole();
    app(PaymentProofs::class)->requestNewReceipt($request->fresh(), Fx::superAdmin(), 'The amount is cut off.');
    Livewire::test(SendReceipt::class, ['request' => $request->id])->assertSee('We need another receipt')->assertSee('The amount is cut off.')
        ->assertSee('Sent ')->call('removeProof', $sent->id)->assertSet('error', "This receipt has been sent, so it can't be removed.");
    expect($request->proofs()->count())->toBe(1);
});

it('serves the person\'s own receipt only: never cached, a picture inline, a PDF always downloaded', function () {
    $request = flowRequest($this->person);
    Livewire::test(SendReceipt::class, ['request' => $request->id])
        ->set('uploads', [UploadedFile::fake()->createWithContent('a.png', receiptPicture()), UploadedFile::fake()->createWithContent('b.pdf', receiptPdf())]);
    [$image, $pdf] = [$request->proofs()->where('kind', 'image')->sole(), $request->proofs()->where('kind', 'pdf')->sole()];

    $thumb = $this->get(route('account.payment-proofs', ['proof' => $image->id, 'variant' => 'thumb']))->assertOk();
    expect($thumb->headers->get('Cache-Control'))->toContain('no-store')->toContain('private')
        ->and($thumb->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($thumb->headers->get('Content-Disposition'))->toStartWith('inline');
    $file = $this->get(route('account.payment-proofs', ['proof' => $pdf->id, 'variant' => 'full']))->assertOk();
    expect($file->headers->get('Content-Disposition'))->toStartWith('attachment')
        ->and($file->headers->get('Content-Security-Policy'))->toContain('sandbox');
    $this->get(route('account.payment-proofs', ['proof' => $pdf->id, 'variant' => 'thumb']))->assertNotFound();

    $this->actingAs(Fx::user(), 'web');
    $this->get(route('account.payment-proofs', ['proof' => $image->id, 'variant' => 'thumb']))->assertNotFound();
    $this->get(route('account.payment-proofs', ['proof' => $image->id, 'variant' => 'full']))->assertNotFound();
    $this->get('/account-media/payment-proofs/'.$image->id.'/original')->assertNotFound();
});

it('opens nobody else\'s request, a teammate\'s neither', function () {
    $request = flowRequest($this->person);
    $this->actingAs(Fx::user(), 'web');
    foreach (['statusUrl', 'payUrl', 'receiptUrl'] as $url) {
        $this->get(UpgradeFlow::$url($request))->assertNotFound();
    }
});

it('shows the request at each stage with its own words and steps', function () {
    $request = flowRequest($this->person);
    $status = fn (): string => (string) $this->get(UpgradeFlow::statusUrl($request))->assertOk()->getContent();
    expect($status())->toContain('Pro request')->toContain('Waiting for payment')->toContain('Requested')->toContain('Cancel request');

    app(PaymentProofs::class)->markPaid($request->fresh(), 'FT26313ABC7781', 'Nida Shah');
    expect($status())->toContain('Checking your payment')->toContain('In review')->toContain('FT26313ABC7781')->toContain('Receipt sent');

    app(PaymentProofs::class)->requestNewReceipt($request->fresh(), Fx::superAdmin(), 'Please send the whole receipt.');
    expect($status())->toContain('We need another receipt')->toContain('Action needed')->toContain('Please send the whole receipt.')->toContain('New receipt asked for');
    SettingsContext::forget();
    expect((string) $this->get('/app/settings')->getContent())->toContain('We need another receipt for Pro');

    app(UpgradeRequests::class)->approve($request->fresh(), Fx::superAdmin(), 'pro', now()->addMonth());
    expect($status())->toContain("You're on Pro")->toContain('Done')->not->toContain('Cancel request');
    SettingsContext::forget();
    $settings = (string) $this->get('/app/settings')->getContent();
    expect($settings)->toContain('data-plan-chip>Pro</span>')->toContain(e("You're on Pro until"));
});

it('says a declined request was not approved, and lets you dismiss it', function () {
    $request = flowRequest($this->person);
    app(UpgradeRequests::class)->decline($request->fresh(), Fx::superAdmin(), 'We could not find this transfer.');
    SettingsContext::forget();
    $html = (string) $this->get('/app/settings/plan')->assertOk()->getContent();
    expect($html)->toContain(e("We couldn't confirm your payment"))->toContain('We could not find this transfer.')->toContain('Request again');
    Livewire::test(EditProfile::class, ['subpage' => 'plan'])->call('dismissRequest');
    SettingsContext::forget();
    expect((string) $this->get('/app/settings/plan')->getContent())->not->toContain(e("We couldn't confirm your payment"));
});

it('cancels a request after asking, and Upgrade comes back', function () {
    $request = flowRequest($this->person);
    Livewire::test(RequestStatus::class, ['request' => $request->id])->callAction('cancelRequest');
    expect($request->fresh()->stage())->toBe('cancelled');
    SettingsContext::forget();
    expect((string) $this->get('/app/settings')->getContent())->toContain('data-upgrade="primary"');
});

it('holds an upload from Send receipt to a receipt\'s size and types before anything is kept', function () {
    $request = flowRequest($this->person);
    $middleware = new ReceiptUploadRules;
    $from = Request::create('/livewire/upload-file', 'POST', server: ['HTTP_REFERER' => UpgradeFlow::receiptUrl($request)]);
    $middleware->handle($from, fn () => response('ok'));
    expect(config('livewire.temporary_file_upload.rules'))->toBe(ReceiptUploadRules::RULES);

    config(['livewire.temporary_file_upload.rules' => ['required', 'file', 'max:12288']]);
    $middleware->handle(Request::create('/livewire/upload-file', 'POST', server: ['HTTP_REFERER' => url('/app/clients')]), fn () => response('ok'));
    expect(config('livewire.temporary_file_upload.rules'))->toBe(['required', 'file', 'max:12288'])
        ->and(config('livewire.temporary_file_upload.preview_mimes'))->not->toContain('png')->not->toContain('jpg')->not->toContain('pdf')
        ->and(config('livewire.temporary_file_upload.middleware'))->toContain(ReceiptUploadRules::class);
});

it('keeps Pay and Send receipt for open requests only', function () {
    $request = flowRequest($this->person);
    app(UpgradeRequests::class)->decline($request->fresh(), Fx::superAdmin(), 'No.');
    $this->get(UpgradeFlow::payUrl($request))->assertRedirect(UpgradeFlow::statusUrl($request));
    expect(Livewire::test(PayForPlan::class, ['request' => $request->id]))->not->toBeNull();
});
