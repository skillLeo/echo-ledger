<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiError;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Device;
use App\Services\SettingsRepository;
use App\Services\TextReplacements;
use App\Stt\KeytermPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * docs/API.md "Vocabulary" (since v1.1): global and per-client keyterms and text replacements, and "Add to
 * vocabulary" from a transcript edit.
 */
final class VocabularyController extends Controller
{
    public const MAX_GLOBAL_KEYTERMS = 200;

    public const MAX_CLIENT_KEYTERMS = 200;

    public function __construct(private readonly SettingsRepository $settings) {}

    /** GET /api/vocabulary?client_id= */
    public function show(Request $request): JsonResponse
    {
        $data = $request->validate(['client_id' => ['nullable', 'string', 'size:26']]);
        $client = isset($data['client_id']) ? $this->client($request, $data['client_id']) : null;

        return response()->json([
            'keyterms' => $this->settings->globalKeyterms(),
            'replacements' => $this->settings->globalReplacements(),
            'client' => $client === null ? null : [
                'id' => $client->id,
                'keyterms' => $client->keytermList(),
                'replacements' => TextReplacements::rules($client->replacements),
            ],
        ]);
    }

    /** POST /api/vocabulary/keyterms: one term for the client, or for every recording without `client_id`. */
    public function addKeyterm(Request $request): JsonResponse
    {
        $data = $request->validate([
            'term' => ['required', 'string', 'max:100'],
            'client_id' => ['nullable', 'string', 'size:26'],
        ]);
        $problem = KeytermPolicy::validate($data['term']);
        if ($problem !== null) {
            throw new ApiError(422, 'validation_failed', $problem, ['errors' => ['term' => [$problem]]]);
        }
        $term = KeytermPolicy::sanitize([$data['term']])[0];
        if (isset($data['client_id'])) {
            $client = $this->client($request, $data['client_id']);
            $existing = $client->keytermList();
            if (! in_array(mb_strtolower($term), array_map('mb_strtolower', $existing), true)) {
                if (count($existing) >= self::MAX_CLIENT_KEYTERMS) {
                    throw new ApiError(409, 'vocabulary_full', 'This client already has 200 keyterms. Remove one first.');
                }
                $client->keyterms()->create(['term' => $term]);
            }

            return response()->json(['keyterms' => $client->keytermList()], 201);
        }
        $global = $this->settings->globalKeyterms();
        if (! in_array(mb_strtolower($term), array_map('mb_strtolower', $global), true)) {
            if (count($global) >= self::MAX_GLOBAL_KEYTERMS) {
                throw new ApiError(409, 'vocabulary_full', 'The vocabulary already has 200 keyterms. Remove one first.');
            }
            $this->settings->set('vocabulary.keyterms', [...$global, $term]);
        }

        return response()->json(['keyterms' => $this->settings->globalKeyterms()], 201);
    }

    /** POST /api/vocabulary/replacements: "always write X as Y". */
    public function addReplacement(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => ['required', 'string', 'max:'.TextReplacements::MAX_CHARS],
            'to' => ['present', 'nullable', 'string', 'max:'.TextReplacements::MAX_CHARS],
            'match_case' => ['sometimes', 'boolean'],
            'whole_word' => ['sometimes', 'boolean'],
            'client_id' => ['nullable', 'string', 'size:26'],
        ]);
        $rule = TextReplacements::rules([[
            'from' => $data['from'], 'to' => $data['to'] ?? '',
            'match_case' => $request->boolean('match_case'), 'whole_word' => $request->boolean('whole_word', true),
        ]]);
        if ($rule === []) {
            throw new ApiError(422, 'validation_failed', 'Type the words to replace and what to write instead.');
        }
        if (isset($data['client_id'])) {
            $client = $this->client($request, $data['client_id']);
            $rules = TextReplacements::rules([...$rule, ...TextReplacements::rules($client->replacements)]);
            $client->update(['replacements' => $rules]);

            return response()->json(['replacements' => $rules], 201);
        }
        $rules = TextReplacements::rules([...$rule, ...$this->settings->globalReplacements()]);
        $this->settings->set('vocabulary.replacements', $rules);

        return response()->json(['replacements' => $rules], 201);
    }

    private function client(Request $request, string $id): Client
    {
        /** @var Device $device */
        $device = $request->user();
        $client = Client::query()->where('workspace_id', $device->workspace_id)->find($id);
        if (! $client instanceof Client) {
            throw new ApiError(404, 'client_not_found', 'Client not found.');
        }

        return $client;
    }
}
