<?php

declare(strict_types=1);

namespace App\Filament\Pages\Upgrade;

use App\Exceptions\ApiError;
use App\Filament\Support\SettingsContext;
use App\Filament\Support\UpgradeFlow;
use App\Models\UpgradeRequestProof;
use App\Models\User;
use App\Services\Entitlements\PaymentProofs;
use Filament\Panel;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Throwable;

/**
 * Upgrade, step 3 (since v1.7): "Send receipt": up to 3 screenshots or PDFs of the transfer (each read, re-encoded and
 * kept encrypted by PaymentProofs; the browser's temporary copy is deleted at once), the bank's transaction ID and the
 * name it was paid from, then "Send receipt". Files already sent are listed first and can't be removed.
 */
class SendReceipt extends RequestPage
{
    use WithFileUploads;

    public const ACCEPTS = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];

    public const MAX_KB = 5120;

    protected static ?string $slug = 'settings/plan/requests/{request}/receipt';

    protected string $view = 'filament.upgrade.receipt';

    /** @var array<int, TemporaryUploadedFile> */
    public array $uploads = [];

    public string $transactionId = '';

    public string $payerName = '';

    /** @var list<array{name: string, message: string}> files that could not be added, with why */
    public array $failed = [];

    public ?string $error = null;

    public static function getRelativeRouteName(Panel $panel): string
    {
        return 'upgrade.receipt';
    }

    public function mount(string $request): void
    {
        parent::mount($request);
        if (! in_array(UpgradeFlow::stage($this->upgradeRequest()), ['awaiting_payment', 'needs_receipt'], true)) {
            $this->redirect(UpgradeFlow::statusUrl($this->upgradeRequest()));
        }
    }

    public function getTitle(): string
    {
        return 'Send receipt';
    }

    /** Each file chosen or dropped: checked here, then handed to PaymentProofs; the temporary copy is always deleted. */
    public function updatedUploads(): void
    {
        $this->failed = [];
        $this->error = null;
        /** @var User $user */
        $user = auth()->user();
        foreach ($this->uploads as $file) {
            $name = mb_substr($file->getClientOriginalName(), 0, 80);
            try {
                if ($file->getSize() > self::MAX_KB * 1024) {
                    $this->failed[] = ['name' => $name, 'message' => 'This file is over 5 MB. Try a screenshot instead.'];

                    continue;
                }
                if (! in_array($file->getMimeType(), self::ACCEPTS, true)) {
                    $this->failed[] = ['name' => $name, 'message' => 'Send a screenshot or photo (JPEG, PNG or WebP) or a PDF.'];

                    continue;
                }
                app(PaymentProofs::class)->add($this->upgradeRequest(), $user, (string) $file->getRealPath(), $file->getClientOriginalName());
            } catch (ApiError $e) {
                $this->failed[] = ['name' => $name, 'message' => self::errorCopy($e)];
            } catch (Throwable $e) {
                report($e);
                $this->failed[] = ['name' => $name, 'message' => "Couldn't upload. Try again."];
            } finally {
                try {
                    $file->delete();
                } catch (Throwable) {
                    // Already gone: the hourly sweep would take it anyway.
                }
            }
        }
        $this->uploads = [];
        SettingsContext::forget();
    }

    public function removeProof(string $id): void
    {
        $this->error = null;
        $proof = UpgradeRequestProof::query()->whereKey($id)->first();
        if (! $proof instanceof UpgradeRequestProof || (string) $proof->getAttribute('upgrade_request_id') !== $this->upgradeRequest()->id) {
            return;
        }
        try {
            app(PaymentProofs::class)->remove($proof);
        } catch (ApiError $e) {
            $this->error = self::errorCopy($e);
        }
    }

    public function dismissFailed(int $index): void
    {
        unset($this->failed[$index]);
        $this->failed = array_values($this->failed);
    }

    /** Send receipt: the round's files are locked and our team is told. */
    public function send(): void
    {
        $this->error = null;
        $reference = trim($this->transactionId);
        $payer = trim($this->payerName);
        if ($reference !== '' && preg_match('/^[A-Za-z0-9 ._\/#-]{4,64}$/', $reference) !== 1) {
            $this->error = 'The transaction ID is 4 to 64 letters, numbers, spaces or . _ / # -.';

            return;
        }
        if (mb_strlen($payer) > 120) {
            $this->error = 'Write the name in 120 characters or fewer.';

            return;
        }
        try {
            app(PaymentProofs::class)->markPaid($this->upgradeRequest(), $reference !== '' ? $reference : null, $payer !== '' ? $payer : null);
        } catch (ApiError $e) {
            $this->error = self::errorCopy($e);

            return;
        }
        SettingsContext::forget();
        session()->flash('el.receipt.sent', true);
        $this->redirect(UpgradeFlow::statusUrl($this->upgradeRequest()));
    }

    /** The words for an answer of PaymentProofs (docs/API.md "Payment receipts"). */
    public static function errorCopy(ApiError $e): string
    {
        $extra = $e->extra();
        $reason = $extra['reason'] ?? ($extra['errors']['reason'] ?? null);

        return match ($e->apiCode()) {
            'too_large' => 'This file is over 5 MB. Try a screenshot instead.',
            'validation_failed' => match ($reason) {
                'pdf_password' => 'This PDF has a password. Please add a screenshot of the receipt instead.',
                'pdf_active' => "We can't accept this PDF. Please add a screenshot of the receipt instead.",
                'unreadable', 'too_many_pixels' => "We couldn't read this picture. Please try another screenshot.",
                default => isset($extra['errors']['payment_reference']) ? 'The transaction ID is 4 to 64 letters, numbers, spaces or . _ / # -.' : 'Send a screenshot or photo (JPEG, PNG or WebP) or a PDF.',
            },
            'too_many_proofs' => ($extra['scope'] ?? 'round') === 'request'
                ? "We can't take more files on this request. Reply to our email if you need to send more."
                : 'You can add up to 3 files at a time. Remove one to add another.',
            'proof_locked' => "This receipt has been sent, so it can't be removed.",
            'proof_required' => 'Add a screenshot, or type the transaction ID.',
            'request_decided' => 'This request has already been decided.',
            'rate_limited' => 'Too many tries today. Please try again later.',
            default => "Couldn't reach EchoLedger. Try again.",
        };
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $request = $this->upgradeRequest();
        $payment = UpgradeFlow::payment($request);
        $proofs = $this->proofs();
        $unsent = array_values(array_filter($proofs, fn (array $p): bool => ! $p['sent']));

        return [
            'stage' => UpgradeFlow::stage($request),
            'proofRequest' => is_array($payment['proof_request'] ?? null) ? (string) ($payment['proof_request']['note'] ?? '') : null,
            'sent' => array_values(array_filter($proofs, fn (array $p): bool => $p['sent'])),
            'unsent' => $unsent,
            'max' => (int) ($payment['max_proofs'] ?? 3),
            'room' => max(0, (int) ($payment['max_proofs'] ?? 3) - count($unsent)),
            'accept' => implode(',', self::ACCEPTS),
            'statusUrl' => UpgradeFlow::statusUrl($request),
            'payUrl' => UpgradeFlow::payUrl($request),
        ];
    }
}
