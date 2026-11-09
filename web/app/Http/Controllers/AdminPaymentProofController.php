<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\ApiError;
use App\Http\Controllers\Api\UpgradeRequestController;
use App\Models\UpgradeRequestProof;
use App\Services\AuditLog;
use App\Services\Entitlements\PaymentProofs;
use App\Services\Staff\StaffAccess;
use Illuminate\Http\Response;

/**
 * A payment receipt for staff (since v1.7, Admin panel → Upgrade requests): behind the admin panel's sign-in and
 * two-step check, for staff who answer upgrade requests only. Decrypted for this answer, never cached; a picture
 * opens in the browser, a PDF is always downloaded. Opening the full file is in the activity log of the customer.
 */
final class AdminPaymentProofController extends Controller
{
    public function __invoke(string $proof, string $variant, PaymentProofs $proofs, AuditLog $audit): Response
    {
        abort_unless(StaffAccess::can('upgrades.manage'), 403);
        $model = UpgradeRequestProof::query()->with('request:id,reference_code')->find($proof);
        abort_unless($model instanceof UpgradeRequestProof, 404);
        try {
            $file = $proofs->read($model, $variant);
        } catch (ApiError) {
            abort(404);
        }
        if ($variant === 'full') {
            $audit->record('payment_proof_viewed', $model->user_id, $model->request, 'Receipt of '.$model->request->reference_code.' opened by '.(string) auth()->user()?->email);
        }

        return UpgradeRequestController::fileResponse($file);
    }
}
