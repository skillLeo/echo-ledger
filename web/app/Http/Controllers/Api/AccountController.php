<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\User;
use App\Services\Accounts\AccountSessions;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Your account (since v1.5, docs/API.md "Your account"): the device's owner, as the device acts for them. */
final class AccountController extends Controller
{
    /** POST /api/account/sign-out-everywhere — every device (this one included) and console session of the account. */
    public function signOutEverywhere(Request $request, AccountSessions $sessions): Response
    {
        $sessions->signOutEverywhere($this->owner($request));

        return response()->noContent();
    }

    private function owner(Request $request): User
    {
        /** @var Device $device */
        $device = $request->user();

        return User::query()->findOrFail($device->user_id);
    }
}
