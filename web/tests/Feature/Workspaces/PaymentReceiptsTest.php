<?php

declare(strict_types=1);

use App\Exceptions\ApiError;
use App\Mail\UpgradeReceiptRequestedMail;
use App\Models\AuditEvent;
use App\Models\Plan;
use App\Models\PromoCode;
use App\Models\PromoCodeUse;
use App\Models\UpgradeRequest;
use App\Models\UpgradeRequestProof;
use App\Models\Workspace;
use App\Services\Accounts\AccountEraser;
use App\Services\Entitlements\PaymentDetails;
use App\Services\Entitlements\PaymentProofs;
use App\Services\Entitlements\UpgradeRequests;
use App\Services\RecordingCipher;
use App\Services\SettingsRepository;
use App\Services\Workspaces\Workspaces;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Feature\Security\Support\ApiClient;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

/*
| Payment receipts (since v1.7, docs/API.md "Payment receipts"): after asking for a plan the customer pays by bank
| transfer, sends screenshots or PDFs and/or the transaction ID, and staff approve, decline or ask for a new receipt.
| Pictures are written again without metadata, unsafe PDFs are refused, everything is encrypted, only the person's
| own request answers, and the files go 90 days after the decision.
*/

uses(ApiClient::class);

require_once __DIR__.'/Support/receipt_helpers.php';

beforeEach(function () {
    Mail::fake();
    Fx::fakeRecordingsDisk();
    $this->person = Fx::user(['name' => 'Nida', 'email' => 'nida@example.test']);
    Workspace::query()->whereKey($this->person->current_workspace_id)->update(['plan_id' => Plan::free()->id]);
    $this->token = Fx::token(Fx::device($this->person));
    $this->id = $this->api($this->token)->postJson('/api/upgrade-requests', ['plan' => 'pro'])->assertCreated()->json('id');
});

afterEach(fn () => Fx::cleanupDisks());

/** Sends [$bytes] as a receipt of the test's request. */
function sendReceipt(object $test, string $bytes, string $name = 'receipt.png', ?string $id = null, ?string $token = null)
{
    return (fn () => $this->api($token ?? $this->token)->post('/api/upgrade-requests/'.($id ?? $this->id).'/proofs', ['file' => receiptUpload($bytes, $name)]))->call($test);
}

function receiptRequest(object $test): UpgradeRequest
{
    return UpgradeRequest::query()->findOrFail($test->id);
}

describe('the request object', function () {
    it('carries the reference code, the amount in rupees, the payment block and the timeline', function () {
        $body = $this->api($this->token)->getJson('/api/upgrade-requests/latest')->assertOk()->json();

        expect($body['reference_code'])->toMatch(UpgradeRequest::REFERENCE_PATTERN)
            ->and($body['amount_due_pkr'])->toBe(2999)
            ->and($body['currency'])->toBe('PKR')
            ->and($body['payment'])->toMatchArray([
                'stage' => 'awaiting_payment', 'accounts' => [], 'paid_at' => null, 'reference' => null, 'payer_name' => null,
                'proof_request' => null, 'proofs' => [], 'max_proofs' => 3, 'max_bytes' => 5242880,
                'accepts' => ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'],
                'review_hours' => PaymentDetails::REVIEW_HOURS,
            ])
            ->and($body['timeline'])->toHaveCount(1)
            ->and($body['timeline'][0]['step'])->toBe('requested')
            ->and($body['timeline'][0]['at'])->toEndWith('Z')
            // v1.6 fields stay.
            ->and($body['status'])->toBe('pending')->and($body['payment_instructions'])->toBe(UpgradeRequests::DEFAULT_INSTRUCTIONS);
        $this->api($this->token)->getJson('/api/me/entitlements')->assertJsonPath('upgrade_request.reference_code', $body['reference_code'])
            ->assertJsonPath('upgrade_request.payment.stage', 'awaiting_payment');
    });

    it('makes reference codes readable aloud and different each time', function () {
        $codes = collect(range(1, 2000))->map(fn () => UpgradeRequest::newReferenceCode());
        foreach ($codes as $code) {
            expect($code)->toMatch(UpgradeRequest::REFERENCE_PATTERN)->and(substr($code, 3))->not->toMatch('/[01ILOU]/');
        }
        expect($codes->unique()->count())->toBeGreaterThan(1990);
    });

    it('shows the payment details as an account, the note with the reference, and text for older apps', function () {
        $settings = app(SettingsRepository::class);
        $settings->set('upgrade.payment_instructions', 'JazzCash 0300 0000000');
        $body = $this->api($this->token)->getJson('/api/upgrade-requests/latest')->json();
        expect($body['payment']['accounts'])->toBe([])->and($body['payment']['note'])->toBe('JazzCash 0300 0000000')
            ->and($body['payment_instructions'])->toBe('JazzCash 0300 0000000');

        $settings->setMany(['payment.bank' => 'Example Bank', 'payment.branch' => 'Main Branch (0001)', 'payment.account_title' => 'EXAMPLE PRIVATE LIMITED',
            'payment.account_number' => '0123456789', 'payment.iban' => 'PK36SCBL0000001123456702']);
        $body = $this->api($this->token)->getJson('/api/upgrade-requests/latest')->json();
        $code = $body['reference_code'];
        expect($body['payment']['accounts'])->toBe([['bank' => 'Example Bank', 'branch' => 'Main Branch (0001)', 'title' => 'EXAMPLE PRIVATE LIMITED',
            'account_number' => '0123456789', 'iban' => 'PK36SCBL0000001123456702']])
            ->and($body['payment']['note'])->toBe("Put {$code} in the transfer's note.")
            ->and($body['payment_instructions'])->toBe("Bank: Example Bank\nBranch: Main Branch (0001)\nAccount title: EXAMPLE PRIVATE LIMITED\nAccount number: 0123456789\nIBAN: PK36SCBL0000001123456702\nPut {$code} in the transfer's note.");

        $settings->set('payment.note', 'Write {reference} as the purpose.');
        expect($this->api($this->token)->getJson('/api/upgrade-requests/latest')->json('payment.note'))->toBe("Write {$code} as the purpose.");
    });

    it('checks IBANs: PK, 24 characters and the mod-97 check', function () {
        expect(PaymentDetails::validIban('PK36SCBL0000001123456702'))->toBeTrue()
            ->and(PaymentDetails::validIban('pk36 scbl 0000 0011 2345 6702'))->toBeTrue()
            ->and(PaymentDetails::validIban('PK37SCBL0000001123456702'))->toBeFalse()
            ->and(PaymentDetails::validIban('PK36SCBL000000112345670'))->toBeFalse()
            ->and(PaymentDetails::validIban('GB82WEST12345698765432'))->toBeFalse();
    });

    it('sets the amount due after a promo code, and plan cards say the rupee price', function () {
        PromoCode::query()->create(['code' => 'SAVE50', 'plan' => 'pro', 'percent' => 50, 'months' => 1, 'one_per_person' => true, 'active' => true]);
        $other = Fx::user();
        Workspace::query()->whereKey($other->current_workspace_id)->update(['plan_id' => Plan::free()->id]);
        $request = app(UpgradeRequests::class)->create($other, (int) $other->current_workspace_id, 'pro', null, 'SAVE50');
        expect($request->amount_due_pkr)->toBe(1500);

        $plans = collect($this->api($this->token)->getJson('/api/me/entitlements')->json('plans'))->keyBy('key');
        expect($plans['pro']['price_pkr'])->toBe(2999)->and($plans['pro']['price_text'])->toBe('Rs 2,999 a month')
            ->and($plans['business']['price_pkr'])->toBe(12499)->and($plans['business']['price_text'])->toBe('Rs 12,499 a month')
            ->and($plans['free']['price_pkr'])->toBeNull()->and($plans['free']['price_text'])->toBe('Free');
        $this->api($this->token)->getJson('/api/me/entitlements')
            ->assertJsonPath('limits.monthly_minutes.resets_at', now('Asia/Karachi')->startOfMonth()->addMonthNoOverflow()->toAtomString());
    });
});

describe('sending a receipt', function () {
    it('keeps a picture re-encoded as WebP with a 480 px thumbnail, encrypted, and the same file once', function () {
        $png = receiptPicture('png', 1200, 600);
        $first = sendReceipt($this, $png, 'my receipt.png')->assertCreated();
        $proof = $first->json('data');
        expect($proof)->toMatchArray(['kind' => 'image', 'mime' => 'image/webp', 'name' => 'my receipt.png', 'width' => 1200, 'height' => 600, 'locked' => false])
            ->and($proof['thumb_url'])->toContain("/api/upgrade-requests/{$this->id}/proofs/{$proof['id']}")->toContain('variant=thumb')
            ->and($proof['file_url'])->toContain('variant=full');

        $row = UpgradeRequestProof::query()->findOrFail($proof['id']);
        expect($row->path)->toStartWith('payment-proofs/'.now()->format('Y/m').'/')->toEndWith('.elr')
            ->and($row->sha256)->toBe(hash('sha256', $png));
        foreach ([$row->path, (string) $row->thumb_path] as $file) {
            expect((string) Storage::disk('recordings')->get($file))->toStartWith(RecordingCipher::MAGIC)->not->toContain('WEBPVP8');
        }

        // The same file again is the same receipt.
        sendReceipt($this, $png)->assertOk()->assertJsonPath('data.id', $proof['id']);
        expect(UpgradeRequestProof::query()->count())->toBe(1)->and(Storage::disk('recordings')->allFiles('payment-proofs'))->toHaveCount(2);

        $full = $this->api($this->token, json: false)->get("/api/upgrade-requests/{$this->id}/proofs/{$proof['id']}")->assertOk()
            ->assertHeader('Content-Type', 'image/webp')->assertHeader('X-Content-Type-Options', 'nosniff');
        expect((string) $full->headers->get('Cache-Control'))->toContain('no-store')->toContain('private')
            ->and((string) $full->headers->get('Content-Disposition'))->toStartWith('inline');
        $thumb = $this->api($this->token, json: false)->get("/api/upgrade-requests/{$this->id}/proofs/{$proof['id']}?variant=thumb")->assertOk();
        expect(getimagesizefromstring((string) $thumb->getContent())[0])->toBe(480);
        $this->api($this->token)->getJson("/api/upgrade-requests/{$this->id}/proofs/{$proof['id']}?variant=original")->assertNotFound()->assertJsonPath('code', 'proof_not_found');
        $this->api($this->token)->getJson('/api/upgrade-requests/latest')->assertJsonPath('payment.proofs.0.id', $proof['id']);
    });

    it('turns a photo upright and leaves its EXIF, location and comments behind', function () {
        $jpeg = receiptJpegWithExif(400, 200, 6);
        expect($jpeg)->toContain('SECRET-LOCATION')->toContain('Exif');
        $proof = sendReceipt($this, $jpeg, 'IMG_0001.jpg')->assertCreated()->json('data');
        // Orientation 6: turned a quarter clockwise, so it stands.
        expect($proof['width'])->toBe(200)->and($proof['height'])->toBe(400);

        $bytes = (string) $this->api($this->token, json: false)->get($proof['file_url'])->getContent();
        expect($bytes)->toStartWith('RIFF')->not->toContain('SECRET-LOCATION')->not->toContain('SECRET-COMMENT')
            ->not->toContain('Exif')->not->toContain('EXIF')->not->toContain('XMP ');
        $size = getimagesizefromstring($bytes);
        expect($size[0])->toBe(200)->and($size[1])->toBe(400);
    });

    it('scales a huge screenshot down to 4096 px on the long side', function () {
        $proof = sendReceipt($this, receiptPicture('png', 5000, 1000))->assertCreated()->json('data');
        expect($proof['width'])->toBe(4096)->and($proof['height'])->toBe(819);
    });

    it('takes JPEG, PNG, WebP and PDF, and refuses anything else by its bytes', function () {
        $refused = [
            'GIF' => receiptPicture('gif'),
            'HEIC' => "\0\0\0\x18ftypheic\0\0\0\0mif1heic".str_repeat("\0", 64),
            'SVG' => '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
            'HTML' => '<!doctype html><title>x</title>',
            'a PNG named .pdf' => null,
        ];
        foreach ($refused as $what => $bytes) {
            $response = $bytes === null ? sendReceipt($this, 'not a picture at all', 'receipt.pdf') : sendReceipt($this, $bytes, 'receipt.png');
            $response->assertStatus(422)->assertJsonPath('code', 'validation_failed')->assertJsonPath('reason', 'file_type')
                ->assertJsonValidationErrors('file');
        }
        // A broken picture (the header of a PNG and nothing after it).
        sendReceipt($this, "\x89PNG\r\n\x1a\n".str_repeat('x', 100))->assertStatus(422)->assertJsonPath('reason', 'unreadable');
        // A PDF cut short.
        sendReceipt($this, substr(receiptPdf(), 0, 200), 'cut.pdf')->assertStatus(422)->assertJsonPath('reason', 'unreadable');
        expect(UpgradeRequestProof::query()->count())->toBe(0)->and(Storage::disk('recordings')->allFiles('payment-proofs'))->toBe([]);

        sendReceipt($this, receiptPicture('jpeg', seed: 2), 'a.jpg')->assertCreated();
        sendReceipt($this, receiptPicture('webp', seed: 3), 'a.webp')->assertCreated();
        sendReceipt($this, receiptPdf(), 'statement.pdf')->assertCreated()->assertJsonPath('data.kind', 'pdf')
            ->assertJsonPath('data.mime', 'application/pdf')->assertJsonPath('data.thumb_url', null)->assertJsonPath('data.width', null);
        expect(UpgradeRequestProof::query()->count())->toBe(3);
    });

    it('refuses a PDF with a password or active content, however it is written', function () {
        $cases = [
            'pdf_password' => [receiptPdf(trailer: '/Encrypt 9 0 R')],
            'pdf_active' => [
                receiptPdf('/OpenAction << /S /JavaScript /JS (app.alert(1)) >>'),
                receiptPdf('/OpenAction << /S /J#61vaScript /J#53 (app.alert(1)) >>'),
                receiptPdf('/OpenAction << /S /Launch /F (cmd.exe) >>'),
                receiptPdf('/Names << /EmbeddedFiles 7 0 R >>'),
                receiptPdf('/AcroForm << /XFA 8 0 R >>'),
                receiptPdf('/Annots [<< /Subtype /RichMedia >>]'),
                // Hidden in a compressed object stream (PDF 1.5).
                receiptPdf(extraObjects: receiptObjectStream('<< /S /JavaScript /JS (app.alert(1)) >>')),
            ],
        ];
        foreach ($cases as $reason => $pdfs) {
            foreach ($pdfs as $i => $pdf) {
                sendReceipt($this, $pdf, "case-{$i}.pdf")->assertStatus(422)->assertJsonPath('reason', $reason);
            }
        }
        // A compressed page whose bytes happen to read "/JS" is not a script; an object stream without one is fine.
        $page = (string) gzcompress(str_repeat('q 1 0 0 1 0 0 cm Q ', 50));
        $binary = "9 0 obj\n<< /Length ".strlen($page)." /Filter /FlateDecode >>\nstream\n{$page}/JS \nendstream\nendobj\n";
        sendReceipt($this, receiptPdf(extraObjects: $binary.receiptObjectStream('<< /Type /Font >>')), 'bank.pdf')->assertCreated();
        expect(UpgradeRequestProof::query()->count())->toBe(1);
    });

    it('sends a PDF only as a download, never in the page', function () {
        $proof = sendReceipt($this, receiptPdf(), 'statement.pdf')->assertCreated()->json('data');
        $code = receiptRequest($this)->reference_code;
        $response = $this->api($this->token, json: false)->get($proof['file_url'])->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Security-Policy', "default-src 'none'; sandbox")
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        expect((string) $response->headers->get('Content-Disposition'))->toBe("attachment; filename=\"{$code}-receipt.pdf\"")
            ->and((string) $response->getContent())->toBe(receiptPdf());
        $this->api($this->token)->getJson("/api/upgrade-requests/{$this->id}/proofs/{$proof['id']}?variant=thumb")->assertNotFound()->assertJsonPath('code', 'proof_not_found');
    });

    it('takes at most 5 MB', function () {
        $big = "%PDF-1.4\n".str_repeat('0', PaymentProofs::MAX_BYTES)."\n%%EOF\n";
        sendReceipt($this, $big, 'big.pdf')->assertStatus(413)->assertJsonPath('code', 'too_large');
        $this->api($this->token)->post("/api/upgrade-requests/{$this->id}/proofs", [])->assertStatus(422)->assertJsonPath('code', 'validation_failed');
        expect(UpgradeRequestProof::query()->count())->toBe(0);
    });

    it('takes 3 unsent files at a time and 10 on a request', function () {
        $seed = 10;
        $send = function (int $count) use (&$seed): void {
            for ($i = 0; $i < $count; $i++) {
                sendReceipt($this, receiptPicture(seed: $seed++))->assertCreated();
            }
        };
        $send(3);
        sendReceipt($this, receiptPicture(seed: 99))->assertStatus(409)->assertJsonPath('code', 'too_many_proofs')->assertJsonPath('max', 3);
        $this->api($this->token)->postJson("/api/upgrade-requests/{$this->id}/paid")->assertOk();
        $send(3);
        $this->api($this->token)->postJson("/api/upgrade-requests/{$this->id}/paid")->assertOk();
        $send(3);
        $this->api($this->token)->postJson("/api/upgrade-requests/{$this->id}/paid")->assertOk();
        $send(1);
        sendReceipt($this, receiptPicture(seed: 98))->assertStatus(409)->assertJsonPath('code', 'too_many_proofs')->assertJsonPath('max', 10);
        expect(UpgradeRequestProof::query()->count())->toBe(10);
    });

    it('lets an unsent file go, never a sent one', function () {
        $draft = sendReceipt($this, receiptPicture(seed: 1))->json('data.id');
        $this->api($this->token)->deleteJson("/api/upgrade-requests/{$this->id}/proofs/{$draft}")->assertNoContent();
        expect(UpgradeRequestProof::query()->find($draft))->toBeNull()->and(Storage::disk('recordings')->allFiles('payment-proofs'))->toBe([]);
        $this->api($this->token)->deleteJson("/api/upgrade-requests/{$this->id}/proofs/{$draft}")->assertNotFound()->assertJsonPath('code', 'proof_not_found');

        $sent = sendReceipt($this, receiptPicture(seed: 2))->json('data.id');
        $this->api($this->token)->postJson("/api/upgrade-requests/{$this->id}/paid")->assertOk()->assertJsonPath('payment.proofs.0.locked', true);
        $this->api($this->token)->deleteJson("/api/upgrade-requests/{$this->id}/proofs/{$sent}")->assertStatus(409)->assertJsonPath('code', 'proof_locked');
        expect(UpgradeRequestProof::query()->find($sent))->not->toBeNull();
    });
});

describe('paid, a new receipt and cancel', function () {
    it('needs a file or a transaction ID, keeps the ID tidy and is in review', function () {
        $this->api($this->token)->postJson("/api/upgrade-requests/{$this->id}/paid", ['payment_reference' => '  '])->assertStatus(422)->assertJsonPath('code', 'proof_required');
        $this->api($this->token)->postJson("/api/upgrade-requests/{$this->id}/paid", ['payment_reference' => str_repeat('A', 65)])->assertStatus(422)
            ->assertJsonPath('code', 'validation_failed')->assertJsonValidationErrors('payment_reference');

        $body = $this->api($this->token)->postJson("/api/upgrade-requests/{$this->id}/paid", ['payment_reference' => 'ft 2611 0977 81', 'payer_name' => 'Nida Khan'])->assertOk()->json();
        expect($body['payment'])->toMatchArray(['stage' => 'in_review', 'reference' => 'FT2611097781', 'payer_name' => 'Nida Khan'])
            ->and($body['payment']['paid_at'])->not->toBeNull()
            ->and(collect($body['timeline'])->pluck('step')->all())->toBe(['requested', 'receipt_sent'])
            ->and(receiptRequest($this)->payment_reference)->toBe('FT2611097781');
        // Nothing new: still a file or an ID.
        $this->api($this->token)->postJson("/api/upgrade-requests/{$this->id}/paid")->assertStatus(422)->assertJsonPath('code', 'proof_required');
    });

    it('tells staff once, when the request goes into review, and again after a new receipt was asked for', function () {
        $staff = Fx::superAdmin();
        $count = fn (): int => $staff->notifications()->count();

        sendReceipt($this, receiptPicture(seed: 1))->assertCreated();
        expect($count())->toBe(0);
        $this->api($this->token)->postJson("/api/upgrade-requests/{$this->id}/paid")->assertOk()->assertJsonPath('payment.stage', 'in_review');
        expect($count())->toBe(1);
        // More while in review: no second notice.
        $this->api($this->token)->postJson("/api/upgrade-requests/{$this->id}/paid", ['payment_reference' => 'TID1'])->assertOk();
        expect($count())->toBe(1);

        app(PaymentProofs::class)->requestNewReceipt(receiptRequest($this), $staff, 'The receipt is cut off.');
        $body = $this->api($this->token)->getJson('/api/upgrade-requests/latest')->json();
        expect($body['payment']['stage'])->toBe('needs_receipt')
            ->and($body['payment']['proof_request']['note'])->toBe('The receipt is cut off.')
            ->and(collect($body['timeline'])->pluck('step')->all())->toBe(['requested', 'receipt_sent', 'receipt_requested']);
        Mail::assertQueued(UpgradeReceiptRequestedMail::class, fn (UpgradeReceiptRequestedMail $m): bool => $m->hasTo('nida@example.test') && $m->note === 'The receipt is cut off.');
        expect(collect($this->api($this->token)->getJson('/api/activity')->json('data'))->firstWhere('kind', 'upgrade_receipt_requested'))->not->toBeNull()
            ->and((new UpgradeReceiptRequestedMail('Pro', 'EL-ABCDEF', 'The receipt is cut off.', url('/app')))->render())->toContain('The receipt is cut off.')->toContain('EL-ABCDEF');

        // Another round of up to 3 files, then in review again, and staff hear of it.
        sendReceipt($this, receiptPicture(seed: 2))->assertCreated();
        $this->api($this->token)->postJson("/api/upgrade-requests/{$this->id}/paid")->assertOk()->assertJsonPath('payment.stage', 'in_review')->assertJsonPath('payment.proof_request', null);
        expect($count())->toBe(2)
            ->and(AuditEvent::query()->where('event', 'upgrade_paid')->count())->toBe(3);
    });

    it('cancels: declined with "Cancelled by you", the promo use given back, nothing more after it', function () {
        PromoCode::query()->create(['code' => 'SAVE50', 'plan' => 'business', 'percent' => 50, 'months' => 1, 'one_per_person' => true, 'active' => true]);
        $other = Fx::user();
        Workspace::query()->whereKey($other->current_workspace_id)->update(['plan_id' => Plan::free()->id]);
        $token = Fx::token(Fx::device($other));
        $id = $this->api($token)->postJson('/api/upgrade-requests', ['plan' => 'business', 'promo_code' => 'SAVE50'])->assertCreated()->json('id');
        $draft = sendReceipt($this, receiptPicture(seed: 5), id: $id, token: $token)->assertCreated()->json('data.id');
        expect(PromoCodeUse::query()->where('upgrade_request_id', $id)->count())->toBe(1);

        $body = $this->api($token)->postJson("/api/upgrade-requests/{$id}/cancel")->assertOk()->json();
        expect($body)->toMatchArray(['status' => 'declined', 'reply' => 'Cancelled by you'])
            ->and($body['payment']['stage'])->toBe('cancelled')
            ->and(collect($body['timeline'])->pluck('step')->last())->toBe('cancelled')
            ->and(PromoCodeUse::query()->where('upgrade_request_id', $id)->count())->toBe(0);

        foreach ([
            fn () => sendReceipt($this, receiptPicture(seed: 6), id: $id, token: $token),
            fn () => $this->api($token)->deleteJson("/api/upgrade-requests/{$id}/proofs/{$draft}"),
            fn () => $this->api($token)->postJson("/api/upgrade-requests/{$id}/paid", ['payment_reference' => 'X1']),
            fn () => $this->api($token)->postJson("/api/upgrade-requests/{$id}/cancel"),
        ] as $call) {
            $call()->assertStatus(409)->assertJsonPath('code', 'request_decided');
        }
        // The code is free again, and a new request can be made.
        $this->api($token)->postJson('/api/upgrade-requests', ['plan' => 'business', 'promo_code' => 'SAVE50'])->assertCreated();
    });

    it('refuses everything on a request decided meanwhile, and a second decision', function () {
        $draft = sendReceipt($this, receiptPicture(seed: 1))->json('data.id');
        $request = receiptRequest($this);
        $admin = Fx::superAdmin(['name' => 'Ayesha']);
        // Staff had it open before the other one approved.
        $stale = UpgradeRequest::query()->findOrFail($this->id);
        app(UpgradeRequests::class)->approve($request, $admin, 'pro', null);

        expect(fn () => app(UpgradeRequests::class)->decline($stale, $admin, 'No payment arrived.'))
            ->toThrow(fn (ApiError $e) => expect([$e->getStatusCode(), $e->apiCode(), $e->getMessage()])->toBe([409, 'request_decided', 'This request was approved already by Ayesha.']));
        expect(fn () => app(UpgradeRequests::class)->approve($stale, $admin, 'business', null))->toThrow(ApiError::class);
        expect(fn () => app(PaymentProofs::class)->requestNewReceipt($stale, $admin, 'Again'))->toThrow(fn (ApiError $e) => expect($e->apiCode())->toBe('request_decided'));
        expect(Workspace::query()->findOrFail($this->person->current_workspace_id)->plan->key)->toBe('pro');

        sendReceipt($this, receiptPicture(seed: 2))->assertStatus(409)->assertJsonPath('code', 'request_decided');
        $this->api($this->token)->deleteJson("/api/upgrade-requests/{$this->id}/proofs/{$draft}")->assertStatus(409)->assertJsonPath('code', 'request_decided');
        $this->api($this->token)->postJson("/api/upgrade-requests/{$this->id}/paid", ['payment_reference' => 'X'])->assertStatus(409)->assertJsonPath('code', 'request_decided');
        $this->api($this->token)->postJson("/api/upgrade-requests/{$this->id}/cancel")->assertStatus(409)->assertJsonPath('code', 'request_decided');
        $this->api($this->token)->getJson('/api/upgrade-requests/latest')->assertJsonPath('payment.stage', 'approved')
            ->assertJsonPath('timeline.1.step', 'decided');
        // A cancelled request cannot be declined by staff either.
        $other = Fx::user();
        Workspace::query()->whereKey($other->current_workspace_id)->update(['plan_id' => Plan::free()->id]);
        $second = app(UpgradeRequests::class)->create($other, (int) $other->current_workspace_id, 'pro', null);
        app(PaymentProofs::class)->cancel($second, $other);
        expect(fn () => app(UpgradeRequests::class)->decline($second, $admin, 'x'))
            ->toThrow(fn (ApiError $e) => expect($e->getMessage())->toBe('The customer cancelled this request.'));
    });

    it('computes each stage from the columns, first match wins', function () {
        $r = new UpgradeRequest(['status' => 'pending']);
        expect($r->stage())->toBe('awaiting_payment');
        $r->paid_at = now()->subHour();
        expect($r->stage())->toBe('in_review');
        $r->proof_requested_at = now();
        expect($r->stage())->toBe('needs_receipt');
        $r->paid_at = now()->addMinute();
        expect($r->stage())->toBe('in_review');
        $r->status = 'declined';
        expect($r->stage())->toBe('declined');
        $r->cancelled_at = now();
        expect($r->stage())->toBe('cancelled');
        $r->status = 'approved';
        expect($r->stage())->toBe('approved');
    });
});

describe('isolation', function () {
    it('answers another person\'s or a teammate\'s request and receipts as unknown ones', function () {
        $proof = sendReceipt($this, receiptPicture(seed: 1))->json('data.id');
        $teammate = Fx::user();
        $team = Workspace::query()->findOrFail($this->person->current_workspace_id);
        app(Workspaces::class)->addMember($team, $teammate);
        app(Workspaces::class)->switchTo($teammate, $team);
        $stranger = Fx::user();
        $own = null;
        foreach ([Fx::token(Fx::device($teammate->refresh())), Fx::token(Fx::device($stranger))] as $i => $token) {
            $calls = [
                fn () => $this->api($token)->post("/api/upgrade-requests/{$this->id}/proofs", ['file' => receiptUpload(receiptPicture(seed: 7))]),
                fn () => $this->api($token)->getJson("/api/upgrade-requests/{$this->id}/proofs/{$proof}"),
                fn () => $this->api($token)->deleteJson("/api/upgrade-requests/{$this->id}/proofs/{$proof}"),
                fn () => $this->api($token)->postJson("/api/upgrade-requests/{$this->id}/paid", ['payment_reference' => 'HIJACK']),
                fn () => $this->api($token)->postJson("/api/upgrade-requests/{$this->id}/cancel"),
                fn () => $this->api($token)->getJson('/api/upgrade-requests/01JZZZZZZZZZZZZZZZZZZZZZZZ/proofs/01JZZZZZZZZZZZZZZZZZZZZZZZ'),
            ];
            foreach ($calls as $call) {
                $call()->assertNotFound()->assertJsonPath('code', 'not_found');
            }
            // Their own request does not reach the other person's receipt either.
            $own = $this->api($token)->postJson('/api/upgrade-requests', ['plan' => 'business'])->assertCreated()->json('id');
            $this->api($token)->getJson("/api/upgrade-requests/{$own}/proofs/{$proof}")->assertNotFound()->assertJsonPath('code', 'proof_not_found');
            $this->api($token)->deleteJson("/api/upgrade-requests/{$own}/proofs/{$proof}")->assertNotFound()->assertJsonPath('code', 'proof_not_found');
        }
        expect(UpgradeRequestProof::query()->count())->toBe(1)
            ->and(receiptRequest($this)->stage())->toBe('awaiting_payment');
    });
});

describe('limits and keeping', function () {
    it('throttles receipts, paid and cancel: 20 an hour and 60 a day per person', function () {
        $call = fn () => $this->api($this->token)->postJson("/api/upgrade-requests/{$this->id}/paid");
        $this->travelTo(now()->startOfDay()->addHour());
        for ($hour = 0; $hour < 3; $hour++) {
            for ($i = 0; $i < 20; $i++) {
                $call()->assertStatus(422);
            }
            $call()->assertStatus(429)->assertHeader('Retry-After');
            $this->travel(61)->minutes();
        }
        // 60 today: the next hour is still refused.
        $call()->assertStatus(429);
        // Another person is not affected.
        $other = Fx::user();
        $this->api(Fx::token(Fx::device($other)))->postJson("/api/upgrade-requests/{$this->id}/paid")->assertNotFound();
    });

    it('erases the files 90 days after the decision and keeps the rows', function () {
        $proof = sendReceipt($this, receiptPicture(seed: 1))->json('data.id');
        $pdf = sendReceipt($this, receiptPdf(), 'r.pdf')->json('data.id');
        $this->api($this->token)->postJson("/api/upgrade-requests/{$this->id}/paid")->assertOk();
        // An open request keeps its files however old.
        $this->travel(200)->days();
        expect(app(PaymentProofs::class)->prune())->toBe(0);
        app(UpgradeRequests::class)->decline(receiptRequest($this), Fx::superAdmin(), 'No payment arrived.');

        $this->travel(89)->days();
        Artisan::call('echoledger:prune-payment-proofs');
        expect(UpgradeRequestProof::query()->whereNotNull('erased_at')->count())->toBe(0);
        $this->travel(2)->days();
        expect(Artisan::call('echoledger:prune-payment-proofs'))->toBe(0)
            ->and(UpgradeRequestProof::query()->whereNotNull('erased_at')->count())->toBe(2)
            ->and(UpgradeRequestProof::query()->findOrFail($proof)->sha256)->toBe(hash('sha256', receiptPicture(seed: 1)))
            ->and(Storage::disk('recordings')->allFiles('payment-proofs'))->toBe([]);
        $token = Fx::token(Fx::device($this->person));
        $this->api($token)->getJson("/api/upgrade-requests/{$this->id}/proofs/{$pdf}")->assertNotFound()->assertJsonPath('code', 'proof_not_found');
        $this->api($token)->getJson('/api/upgrade-requests/latest')->assertJsonPath('payment.proofs', [])->assertJsonPath('status', 'declined');
        expect(app(PaymentProofs::class)->prune())->toBe(0);
        expect(collect(app(Schedule::class)->events())->contains(fn ($e): bool => str_contains((string) $e->command, 'echoledger:prune-payment-proofs') && $e->withoutOverlapping))->toBeTrue();
    });

    it('erases the receipt files with the account', function () {
        sendReceipt($this, receiptPicture(seed: 1))->assertCreated();
        expect(Storage::disk('recordings')->allFiles('payment-proofs'))->toHaveCount(2);
        app(AccountEraser::class)->erase($this->person->fresh());
        expect(Storage::disk('recordings')->allFiles('payment-proofs'))->toBe([])
            ->and(UpgradeRequestProof::query()->count())->toBe(0);
    });
});

it('runs its migration up, down and up again, with a reference for every request and the prices seeded', function () {
    $default = config('database.default');
    $config = config("database.connections.{$default}");
    if ($config['driver'] !== 'sqlite') {
        $this->markTestSkipped('The SQLite cycle; MariaDB has its own run.');
    }
    $file = storage_path('framework/testing/receipts-cycle-'.getmypid().'.sqlite');
    @mkdir(dirname($file), 0777, true);
    file_put_contents($file, '');
    config(['database.connections.receipts_cycle' => [...$config, 'database' => $file, 'foreign_key_constraints' => true]]);
    DB::purge('receipts_cycle');
    /** @var Connection $db */
    $db = DB::connection('receipts_cycle');
    $schema = Schema::connection('receipts_cycle');
    $run = fn (string $command, array $options = []) => expect(Artisan::call($command, ['--database' => 'receipts_cycle', '--force' => true, ...$options]))->toBe(0);
    $columns = ['reference_code', 'amount_due_pkr', 'payment_reference', 'payer_name', 'paid_at', 'proof_requested_at', 'proof_request_note', 'cancelled_at'];

    $run('migrate:fresh');
    $run('migrate:rollback', ['--step' => 1]);
    expect($schema->hasTable('upgrade_request_proofs'))->toBeFalse()->and($schema->hasColumn('plans', 'price_pkr'))->toBeFalse();
    foreach ($columns as $column) {
        expect($schema->hasColumn('upgrade_requests', $column))->toBeFalse($column);
    }
    $now = now();
    $user = $db->table('users')->insertGetId(['name' => 'A', 'email' => 'a@example.test', 'password' => 'x', 'created_at' => $now, 'updated_at' => $now]);
    $workspace = $db->table('workspaces')->insertGetId(['name' => 'A', 'plan_id' => $db->table('plans')->value('id'), 'created_at' => $now, 'updated_at' => $now]);
    foreach (range(1, 3) as $i) {
        $db->table('upgrade_requests')->insert(['id' => strtolower((string) Str::ulid()), 'user_id' => $user, 'workspace_id' => $workspace, 'plan' => 'pro', 'status' => 'pending', 'created_at' => $now, 'updated_at' => $now]);
    }
    $before = $db->table('upgrade_requests')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();

    $run('migrate');
    $codes = $db->table('upgrade_requests')->pluck('reference_code');
    expect($codes)->toHaveCount(3)->and($codes->unique())->toHaveCount(3);
    foreach ($codes as $code) {
        expect($code)->toMatch(UpgradeRequest::REFERENCE_PATTERN);
    }
    expect((int) $db->table('plans')->where('key', 'pro')->value('price_pkr'))->toBe(2999)
        ->and((int) $db->table('plans')->where('key', 'business')->value('price_pkr'))->toBe(12499)
        ->and($db->table('plans')->where('key', 'free')->value('price_pkr'))->toBeNull()
        ->and($schema->hasColumns('upgrade_request_proofs', ['id', 'upgrade_request_id', 'user_id', 'kind', 'mime', 'name', 'bytes', 'width', 'height', 'sha256', 'path', 'thumb_path', 'locked_at', 'erased_at']))->toBeTrue();
    // down() takes the prices with their column and leaves the requests as they were; up() seeds them again.
    $db->table('plans')->where('key', 'pro')->update(['price_pkr' => 3500]);
    $run('migrate:rollback', ['--step' => 1]);
    expect($db->table('upgrade_requests')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all())->toBe($before);
    $run('migrate');
    expect((int) $db->table('plans')->where('key', 'pro')->value('price_pkr'))->toBe(2999);
    $db->disconnect();
    @unlink($file);
});
