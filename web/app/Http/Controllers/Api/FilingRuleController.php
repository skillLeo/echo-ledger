<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiError;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Device;
use App\Models\FilingRule;
use App\Services\Projects\FilingRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/** docs/API.md "Filing rules" (since v1.5): a project's title, app and contact rules. */
final class FilingRuleController extends Controller
{
    public function __construct(private readonly FilingRules $rules) {}

    /** GET /api/clients/{id}/rules */
    public function index(Request $request, string $id): JsonResponse
    {
        $project = $this->project($request, $id);

        return response()->json(['data' => FilingRule::query()->where('client_id', $project->id)->orderBy('id')->get()
            ->map(fn (FilingRule $r): array => $r->toApi())->values()]);
    }

    /** POST /api/clients/{id}/rules: 201 a new rule, 200 the one this project already has. */
    public function store(Request $request, string $id): JsonResponse
    {
        $project = $this->project($request, $id);
        $data = $request->validate([
            'kind' => ['required', Rule::in(FilingRule::KINDS)],
            'value' => ['required', 'string', 'max:120'],
        ]);
        ['rule' => $rule, 'created' => $created] = $this->rules->add($project, (string) $data['kind'], (string) $data['value']);

        return response()->json(['data' => $rule->refresh()->toApi()], $created ? 201 : 200);
    }

    /** DELETE /api/clients/{id}/rules/{rule} */
    public function destroy(Request $request, string $id, string $rule): Response
    {
        $project = $this->project($request, $id);
        $found = ctype_digit($rule) ? FilingRule::query()->where('client_id', $project->id)->find((int) $rule) : null;
        if (! $found instanceof FilingRule) {
            throw new ApiError(404, 'rule_not_found', 'Rule not found.');
        }
        $this->rules->remove($found);

        return response()->noContent();
    }

    private function project(Request $request, string $id): Client
    {
        /** @var Device $device */
        $device = $request->user();
        $project = Client::query()->where('workspace_id', $device->workspace_id)->find($id);
        if (! $project instanceof Client) {
            throw new ApiError(404, 'client_not_found', 'Client not found.');
        }

        return $project;
    }
}
