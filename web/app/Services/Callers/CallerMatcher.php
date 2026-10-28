<?php

declare(strict_types=1);

namespace App\Services\Callers;

use App\Models\CaptureSession;
use App\Models\Client;
use App\Models\ClientContact;
use App\Models\Scopes\WorkspaceScope;
use App\Stt\LanguageChoice;

/**
 * Caller names (since v1.2): the person a recording was with, as the device saw them (WhatsApp's call notification,
 * a meeting window's title, the phone's contacts for a SIM call, the calendar), matched to a client contact by phone
 * number, then by name (a contact's or the client's own). A match files the recording under the client and, when the
 * recording has no language yet, gives it the contact's language. The title becomes "Call with <name>" (a meeting's
 * window or calendar title is used as it is).
 */
final class CallerMatcher
{
    /** Where a caller's name came from. */
    public const SOURCES = ['whatsapp_notification', 'window_title', 'calendar', 'contacts', 'phone', 'manual'];

    /** @return array<string, list<mixed>> validation rules of the optional `caller` object on create and finish */
    public static function rules(): array
    {
        return [
            'caller' => ['sometimes', 'nullable', 'array'],
            'caller.name' => ['nullable', 'string', 'max:120'],
            'caller.number' => ['nullable', 'string', 'max:40'],
            'caller.source' => ['nullable', 'string', 'in:'.implode(',', self::SOURCES)],
        ];
    }

    /** The last 10 digits of a phone number (null without at least 6), so local and international forms match. */
    public static function digits(?string $phone): ?string
    {
        $digits = (string) preg_replace('/\D+/', '', (string) $phone);

        return strlen($digits) >= 6 ? substr($digits, -10) : null;
    }

    /** A name for comparing: lower case, single spaces, without emoji or symbols around it. */
    public static function normalName(?string $name): string
    {
        $name = (string) preg_replace('/[^\p{L}\p{N}\s.\'-]+/u', ' ', (string) $name);

        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $name)));
    }

    /** A caller name worth keeping: trimmed, without control characters, not just digits or a placeholder. */
    public static function cleanName(?string $name): ?string
    {
        $name = trim((string) preg_replace('/[\p{C}]+/u', '', (string) $name));
        $name = mb_substr((string) preg_replace('/\s+/u', ' ', $name), 0, 120);
        if ($name === '' || preg_match('/^[\d\s+()-]+$/', $name) === 1 || in_array(mb_strtolower($name), ['unknown', 'private number', 'whatsapp'], true)) {
            return null;
        }

        return $name;
    }

    /**
     * The contact (and its client) the caller is, or only the client when the name is the client's own name.
     *
     * @return array{contact: ClientContact|null, client: Client|null}
     */
    public function match(int $workspaceId, ?string $name, ?string $number): array
    {
        $clients = Client::query()->withoutGlobalScope(WorkspaceScope::class)->where('workspace_id', $workspaceId);
        $digits = self::digits($number);
        if ($digits !== null) {
            $contact = ClientContact::query()->where('phone_digits', $digits)->whereIn('client_id', (clone $clients)->select('id'))->oldest('id')->first();
            if ($contact !== null) {
                return ['contact' => $contact, 'client' => $contact->client];
            }
        }
        $wanted = self::normalName($name);
        if ($wanted === '') {
            return ['contact' => null, 'client' => null];
        }
        foreach (ClientContact::query()->whereIn('client_id', (clone $clients)->select('id'))->with('client')->get() as $contact) {
            if (self::normalName($contact->name) === $wanted) {
                return ['contact' => $contact, 'client' => $contact->client];
            }
        }
        foreach ((clone $clients)->get() as $client) {
            if (self::normalName($client->name) === $wanted) {
                return ['contact' => null, 'client' => $client];
            }
        }

        return ['contact' => null, 'client' => null];
    }

    /**
     * Stores the caller on the recording; a match files it under the client (when the device chose none) and gives
     * it the contact's language (when the recording has none).
     *
     * @param  array<string, mixed>|null  $caller  the validated `caller` object of a create or finish body
     */
    public function apply(CaptureSession $session, ?array $caller): void
    {
        if ($caller === null) {
            return;
        }
        $name = self::cleanName(is_string($caller['name'] ?? null) ? $caller['name'] : null);
        $number = isset($caller['number']) && is_string($caller['number']) && trim($caller['number']) !== '' ? mb_substr(trim($caller['number']), 0, 40) : null;
        if ($name === null && $number === null) {
            return;
        }
        $match = $this->match($session->workspace_id, $name, $number);
        $session->forceFill([
            'caller_name' => $name ?? $match['contact']?->name,
            'caller_number' => $number,
            'caller_source' => is_string($caller['source'] ?? null) && in_array($caller['source'], self::SOURCES, true) ? $caller['source'] : null,
        ]);
        if ($session->client_id === null && $match['client'] !== null) {
            $session->client_id = $match['client']->id;
        }
        // The contact's own language (the client's language already applies when the transcript is made).
        if ($session->language === null && ($language = LanguageChoice::normalize($match['contact']?->language)) !== null) {
            $session->language = $language;
        }
        $session->save();
    }

    /**
     * A recording with a person's name (not a meeting title) moved into a client folder teaches the client that
     * contact, so that person's next call is filed there by itself. Nothing happens when the caller already matches.
     */
    public function learn(CaptureSession $session): ?ClientContact
    {
        if ($session->client_id === null || $session->caller_name === null || in_array($session->caller_source, ['window_title', 'calendar'], true)) {
            return null;
        }
        $match = $this->match($session->workspace_id, $session->caller_name, $session->caller_number);
        if ($match['client'] !== null) {
            return null;
        }

        return ClientContact::query()->create(['client_id' => $session->client_id, 'name' => $session->caller_name, 'phone' => $session->caller_number]);
    }
}
