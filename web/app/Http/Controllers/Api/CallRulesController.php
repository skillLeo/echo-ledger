<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\User;
use App\Services\CallRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** docs/API.md "Call rules (since 2.0.0-w)": the device's person's own rules, for the whole account. */
final class CallRulesController extends Controller
{
    public function __construct(private readonly CallRules $rules) {}

    /** GET /api/call-rules */
    public function index(Request $request): JsonResponse
    {
        return response()->json($this->rules->payload($this->person($request)));
    }

    /** PUT /api/call-rules/settings */
    public function settings(Request $request): JsonResponse
    {
        $person = $this->person($request);
        $this->rules->updateSettings($person, $request->only(['after_call', 'ask_when', 'short_calls']));

        return response()->json($this->rules->payload($person));
    }

    /** POST /api/call-rules */
    public function store(Request $request): JsonResponse
    {
        [$rule, $new] = $this->rules->save($this->person($request), $request->only(['kind', 'number', 'name', 'group', 'source_app', 'action']));

        return response()->json($rule->toApi(), $new ? 201 : 200);
    }

    /** PATCH /api/call-rules/{id} */
    public function update(Request $request, string $id): JsonResponse
    {
        return response()->json($this->rules->update($this->person($request), $id, $request->only(['action', 'name']))->toApi());
    }

    /** DELETE /api/call-rules/{id} */
    public function destroy(Request $request, string $id): Response
    {
        $this->rules->delete($this->person($request), $id);

        return response()->noContent();
    }

    private function person(Request $request): User
    {
        $device = $request->user();
        abort_unless($device instanceof Device, 401);

        return $device->user;
    }
}
