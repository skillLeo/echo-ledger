<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiError;
use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\UpgradeRequest;
use App\Models\UpgradeRequestProof;
use App\Services\Entitlements\PaymentProofs;
use App\Services\Entitlements\UpgradeRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;

/**
 * Upgrade requests (since v1.6, P1.4, docs/API.md "Upgrade requests") and their payment receipts (since v1.7,
 * "Payment receipts"): only ever the device's own person's. Anyone else's request, a teammate's too, is the same
 * `404 not_found` as one that does not exist.
 */
final class UpgradeRequestController extends Controller
{
    public function __construct(private readonly UpgradeRequests $requests, private readonly PaymentProofs $proofs) {}

    /** POST /api/upgrade-requests — `sessions:write`, throttled (5 a day per person). */
    public function store(Request $request): JsonResponse
    {
        /** @var Device $device */
        $device = $request->user();
        $data = $request->validate([
            'plan' => ['required', 'string', 'max:32'],
            'message' => ['nullable', 'string', 'max:1000'],
            'promo_code' => ['nullable', 'string', 'max:40'],
        ]);
        $created = $this->requests->create($device->user, $device->workspace_id, $data['plan'], $data['message'] ?? null, $data['promo_code'] ?? null);

        return response()->json($this->requests->payload($created, created: true), 201);
    }

    /** GET /api/upgrade-requests/latest — `config:read`: the newest request, or `404 not_found`. */
    public function latest(Request $request): JsonResponse
    {
        /** @var Device $device */
        $device = $request->user();
        $latest = $this->requests->latestFor($device->user);
        if ($latest === null) {
            throw new ApiError(404, 'not_found', 'No upgrade request yet.');
        }

        return response()->json($this->requests->payload($latest));
    }

    /** POST /api/upgrade-requests/{id}/proofs — `sessions:write`: a receipt (JPEG, PNG, WebP or PDF, at most 5 MB). */
    public function storeProof(Request $request, string $id): JsonResponse
    {
        $upgrade = $this->own($request, $id);
        $upload = $request->file('file');
        if ($upload instanceof UploadedFile && (in_array($upload->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
            || ($upload->isValid() && (int) $upload->getSize() > PaymentProofs::MAX_BYTES))) {
            throw new ApiError(413, 'too_large', 'A receipt is at most 5 MB.');
        }
        if (! $upload instanceof UploadedFile || ! $upload->isValid()) {
            $message = 'Add a screenshot or PDF of the receipt.';
            throw new ApiError(422, 'validation_failed', $message, ['errors' => ['file' => [$message]], 'reason' => $upload instanceof UploadedFile ? 'unreadable' : 'file_type']);
        }
        /** @var Device $device */
        $device = $request->user();
        $proof = $this->proofs->add($upgrade, $device->user, (string) $upload->getRealPath(), $upload->getClientOriginalName());

        return response()->json(['data' => UpgradeRequests::proofPayload($upgrade, $proof)], $proof->wasRecentlyCreated ? 201 : 200);
    }

    /** DELETE /api/upgrade-requests/{id}/proofs/{proof} — `sessions:write`: a receipt not sent yet. */
    public function destroyProof(Request $request, string $id, string $proof): Response
    {
        $this->proofs->remove($this->ownProof($this->own($request, $id), $proof));

        return response()->noContent();
    }

    /** GET /api/upgrade-requests/{id}/proofs/{proof}?variant=thumb|full — `config:read`: the decrypted file. */
    public function showProof(Request $request, string $id, string $proof): Response
    {
        $file = $this->proofs->read($this->ownProof($this->own($request, $id), $proof), (string) $request->query('variant', 'full'));

        return self::fileResponse($file);
    }

    /** POST /api/upgrade-requests/{id}/paid — `sessions:write`: the receipts so far and the transaction ID are sent. */
    public function paid(Request $request, string $id): JsonResponse
    {
        $upgrade = $this->own($request, $id);
        $data = $request->validate([
            'payment_reference' => ['nullable', 'string', 'max:64'],
            'payer_name' => ['nullable', 'string', 'max:120'],
        ]);

        return response()->json($this->requests->payload($this->proofs->markPaid($upgrade, $data['payment_reference'] ?? null, $data['payer_name'] ?? null)));
    }

    /** POST /api/upgrade-requests/{id}/cancel — `sessions:write`. */
    public function cancel(Request $request, string $id): JsonResponse
    {
        $upgrade = $this->own($request, $id);
        /** @var Device $device */
        $device = $request->user();

        return response()->json($this->requests->payload($this->proofs->cancel($upgrade, $device->user)));
    }

    /**
     * A receipt as a file: pictures inline, a PDF always downloaded and never run in the page; nothing kept on the way.
     *
     * @param  array{bytes: string, mime: string, filename: string, inline: bool}  $file
     */
    public static function fileResponse(array $file): Response
    {
        $headers = [
            'Content-Type' => $file['mime'],
            'Content-Length' => (string) strlen($file['bytes']),
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => ($file['inline'] ? 'inline' : 'attachment').'; filename="'.$file['filename'].'"',
        ];
        if (! $file['inline']) {
            $headers['Content-Security-Policy'] = "default-src 'none'; sandbox";
        }

        return response($file['bytes'], 200, $headers);
    }

    /** The device's person's own request, or `404 not_found` (also for a teammate's). */
    private function own(Request $request, string $id): UpgradeRequest
    {
        /** @var Device $device */
        $device = $request->user();
        $upgrade = UpgradeRequest::query()->where('user_id', $device->user_id)->find($id);
        if (! $upgrade instanceof UpgradeRequest) {
            throw new ApiError(404, 'not_found', 'Upgrade request not found.');
        }

        return $upgrade;
    }

    private function ownProof(UpgradeRequest $upgrade, string $proof): UpgradeRequestProof
    {
        $model = UpgradeRequestProof::query()->where('upgrade_request_id', $upgrade->id)->where('user_id', $upgrade->user_id)->find($proof);
        if (! $model instanceof UpgradeRequestProof) {
            throw new ApiError(404, 'proof_not_found', 'This receipt is not on the request.');
        }

        return $model;
    }
}
