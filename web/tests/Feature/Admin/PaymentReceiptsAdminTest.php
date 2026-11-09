<?php

declare(strict_types=1);

use App\Filament\Admin\Pages\Plans;
use App\Filament\Admin\Pages\UpgradeRequestsPage;
use App\Mail\UpgradeReceiptRequestedMail;
use App\Models\AuditEvent;
use App\Models\Plan;
use App\Models\StaffRole;
use App\Models\UpgradeRequest;
use App\Models\UpgradeRequestProof;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Entitlements\PaymentProofs;
use App\Services\Entitlements\UpgradeRequests;
use App\Services\SettingsRepository;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

/*
| Admin panel → Upgrade requests with payment receipts (since v1.7): requests in review first with the reference,
| stage, amount, transaction ID and receipts; Ask for a new receipt; receipts only for staff who answer upgrade
| requests, each full view in the activity log; and Admin panel → Plans → Payment details.
*/

require_once dirname(__DIR__).'/Workspaces/Support/receipt_helpers.php';

beforeEach(function () {
    Mail::fake();
    Fx::fakeRecordingsDisk();
    $this->ana = Fx::user(['name' => 'Ana', 'email' => 'ana@example.test']);
    Workspace::query()->whereKey($this->ana->current_workspace_id)->update(['plan_id' => Plan::free()->id]);
    $this->request = app(UpgradeRequests::class)->create($this->ana, (int) $this->ana->current_workspace_id, 'pro', null);
});

afterEach(fn () => Fx::cleanupDisks());

function adminReceipt(UpgradeRequest $request, User $user, string $bytes, string $name = 'receipt.png'): UpgradeRequestProof
{
    $path = (string) tempnam(sys_get_temp_dir(), 'eld-receipt');
    file_put_contents($path, $bytes);
    try {
        return app(PaymentProofs::class)->add($request, $user, $path, $name);
    } finally {
        @unlink($path);
    }
}

function receiptStaff(string $role): User
{
    $user = Fx::user();
    $user->forceFill(['staff_role_id' => StaffRole::query()->where('key', $role)->value('id'), 'app_authentication_secret' => Fx::STAFF_TOTP_SECRET])->save();

    return $user->fresh();
}

it('lists requests in review first, with the reference, stage, amount, transaction ID and receipts', function () {
    $bob = Fx::user(['email' => 'bob@example.test']);
    Workspace::query()->whereKey($bob->current_workspace_id)->update(['plan_id' => Plan::free()->id]);
    $waiting = app(UpgradeRequests::class)->create($bob, (int) $bob->current_workspace_id, 'business', null);
    $proof = adminReceipt($this->request, $this->ana, receiptPicture(seed: 1));
    app(PaymentProofs::class)->markPaid($this->request, 'ft 2611 0977', 'Ana Khan');

    $this->actingAs(Fx::superAdmin(), 'web');
    $html = $this->get('/admin/upgrade-requests')->assertOk()
        ->assertSee($this->request->fresh()->reference_code)->assertSee('In review')->assertSee('Waiting for payment')
        ->assertSee('Rs 2,999')->assertSee('FT26110977')->assertSee('From Ana Khan')
        ->assertSee(route('filament.admin.upgrade-proofs', ['proof' => $proof->id, 'variant' => 'thumb']), false)
        ->getContent();
    // In review comes before the request still waiting for payment, even though that one is newer.
    expect(strpos((string) $html, 'ana@example.test'))->toBeLessThan(strpos((string) $html, 'bob@example.test'));

    Filament::setCurrentPanel('admin');
    Livewire::test(UpgradeRequestsPage::class)->assertCanSeeTableRecords([$this->request->fresh(), $waiting], inOrder: true)
        ->filterTable('stage', UpgradeRequest::STAGE_AWAITING_PAYMENT)->assertCanSeeTableRecords([$waiting])->assertCanNotSeeTableRecords([$this->request->fresh()])
        ->searchTable('ft2611')->assertCanNotSeeTableRecords([$waiting]);
});

it('asks for a new receipt with a note, and declines with a reason', function () {
    adminReceipt($this->request, $this->ana, receiptPicture(seed: 1));
    app(PaymentProofs::class)->markPaid($this->request, null, null);
    $this->actingAs(Fx::superAdmin(), 'web');
    Filament::setCurrentPanel('admin');

    Livewire::test(UpgradeRequestsPage::class)
        ->callTableAction('askReceipt', $this->request, data: ['note' => 'The receipt is cut off, so we can\'t see the date.'])
        ->assertHasNoTableActionErrors();
    expect($this->request->fresh()->stage())->toBe(UpgradeRequest::STAGE_NEEDS_RECEIPT)
        ->and($this->request->fresh()->proof_request_note)->toBe('The receipt is cut off, so we can\'t see the date.');
    Mail::assertQueued(UpgradeReceiptRequestedMail::class, fn (UpgradeReceiptRequestedMail $m): bool => $m->hasTo('ana@example.test'));

    Livewire::test(UpgradeRequestsPage::class)
        ->callTableAction('decline', $this->request, data: ['reply' => 'We couldn\'t match this receipt to a payment in our account.'])
        ->assertHasNoTableActionErrors()
        ->filterTable('status', UpgradeRequest::DECLINED)
        ->assertTableActionHidden('askReceipt', $this->request->fresh())->assertTableActionHidden('approve', $this->request->fresh());
    expect($this->request->fresh()->stage())->toBe(UpgradeRequest::STAGE_DECLINED);
});

it('serves receipts to staff who answer upgrade requests only, logging each full view', function () {
    $image = adminReceipt($this->request, $this->ana, receiptPicture(seed: 1));
    $pdf = adminReceipt($this->request, $this->ana, receiptPdf(), 'statement.pdf');
    $url = fn (UpgradeRequestProof $p, string $variant): string => route('filament.admin.upgrade-proofs', ['proof' => $p->id, 'variant' => $variant]);

    $this->actingAs(Fx::superAdmin(['email' => 'boss@example.test']), 'web');
    $this->get($url($image, 'thumb'))->assertOk()->assertHeader('Content-Type', 'image/webp');
    expect(AuditEvent::query()->where('event', 'payment_proof_viewed')->count())->toBe(0);
    $full = $this->get($url($image, 'full'))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
    expect((string) $full->headers->get('Content-Disposition'))->toStartWith('inline')
        ->and((string) $full->headers->get('Cache-Control'))->toContain('no-store');
    $event = AuditEvent::query()->where('event', 'payment_proof_viewed')->sole();
    expect($event->user_id)->toBe($this->ana->id)->and($event->summary)->toContain('boss@example.test');
    $download = $this->get($url($pdf, 'full'))->assertOk()->assertHeader('Content-Security-Policy', "default-src 'none'; sandbox");
    expect((string) $download->headers->get('Content-Disposition'))->toStartWith('attachment');
    $this->get($url($pdf, 'thumb'))->assertNotFound();
    $this->get('/admin/upgrade-requests/proofs/not-a-ulid/full')->assertNotFound();
    $this->get('/admin/upgrade-requests/proofs/'.$image->id.'/original')->assertNotFound();

    // Billing answers upgrade requests; Support does not; a customer is not staff at all.
    $this->actingAs(receiptStaff('billing'), 'web');
    $this->get($url($image, 'full'))->assertOk();
    $this->actingAs(receiptStaff('support'), 'web');
    $this->get($url($image, 'full'))->assertForbidden();
    $this->actingAs($this->ana, 'web');
    $this->get($url($image, 'full'))->assertForbidden();
    expect(AuditEvent::query()->where('event', 'payment_proof_viewed')->count())->toBe(3);
});

it('saves the payment details, checking the IBAN, for staff who change server settings', function () {
    $this->actingAs(Fx::superAdmin(), 'web');
    Filament::setCurrentPanel('admin');
    $details = ['bank' => 'Example Bank', 'branch' => 'Main Branch (0001)', 'account_title' => 'EXAMPLE PRIVATE LIMITED', 'account_number' => '0123456789', 'note' => ''];

    Livewire::test(Plans::class)->callTableAction('paymentDetails', null, [...$details, 'iban' => 'PK37SCBL0000001123456702'])->assertHasTableActionErrors(['iban']);
    expect(app(SettingsRepository::class)->get('payment.bank'))->toBeNull();

    Livewire::test(Plans::class)->callTableAction('paymentDetails', null, [...$details, 'iban' => 'pk36 scbl 0000 0011 2345 6702'])->assertHasNoTableActionErrors();
    $settings = app(SettingsRepository::class);
    $settings->flush();
    expect($settings->get('payment.iban'))->toBe('PK36SCBL0000001123456702')->and($settings->get('payment.account_title'))->toBe('EXAMPLE PRIVATE LIMITED')
        ->and($settings->get('payment.note'))->toBeNull();
    $event = AuditEvent::query()->where('event', 'payment_details_changed')->sole();
    expect($event->meta['iban_after'])->toBe('••••6702')->and(json_encode($event->meta))->not->toContain('SCBL');
    expect(app(UpgradeRequests::class)->payload($this->request->fresh())['payment']['accounts'][0]['iban'])->toBe('PK36SCBL0000001123456702');

    // Billing may answer requests and edit plans, not the account money goes to.
    $this->actingAs(receiptStaff('billing'), 'web');
    Livewire::test(Plans::class)->assertTableActionHidden('paymentDetails');
});

it('keeps the price by bank transfer on each plan', function () {
    $this->actingAs(Fx::superAdmin(), 'web');
    Filament::setCurrentPanel('admin');
    $pro = Plan::query()->where('key', 'pro')->firstOrFail();
    Livewire::test(Plans::class)->callTableAction('edit', $pro, ['price_pkr' => 3499])->assertHasNoTableActionErrors();
    expect($pro->fresh()->price_pkr)->toBe(3499)->and($pro->fresh()->priceText())->toBe('Rs 3,499 a month');
});
