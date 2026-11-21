<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Passkey;
use App\Models\User;
use App\Support\Brand;
use Cose\Algorithms;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Symfony\Component\Serializer\Encoder\JsonEncode;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;
use Symfony\Component\Serializer\SerializerInterface;
use Throwable;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;
use Webauthn\CredentialRecord;
use Webauthn\Denormalizer\WebauthnSerializerFactory;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\PublicKeyCredentialParameters;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialUserEntity;

/**
 * Passkeys for the console (plan B16), with the WebAuthn library doing every check: challenge, origin, relying
 * party, user presence and verification, signature and counter. The options of a ceremony live in the session until
 * the browser answers; each challenge is used once. Since 2.0.0-l the phone adds passkeys too, with its native ceremony:
 * the options wait in the cache under a ceremony id, and the Android app's apk-key-hash origins are accepted beside the
 * console's (the same rp id, so the same passkeys as the website).
 */
final class Passkeys
{
    public const MAX_PER_USER = 10;

    private const REGISTER_KEY = 'passkeys.register';

    private const LOGIN_KEY = 'passkeys.login';

    private const TIMEOUT_MS = 120_000;

    /** The phone's ceremony works 5 minutes (since 2.0.0-l). */
    private const API_TIMEOUT_MS = 300_000;

    private ?SerializerInterface $serializer = null;

    public function __construct(private readonly AuditLog $audit) {}

    /** The relying party id: the host of APP_URL. */
    public static function rpId(): string
    {
        return (string) (parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost');
    }

    /** scheme://host[:port] of APP_URL, the only origin a passkey answer may come from. */
    public static function origin(): string
    {
        $url = parse_url((string) config('app.url'));
        $origin = ($url['scheme'] ?? 'https').'://'.($url['host'] ?? 'localhost');

        return isset($url['port']) ? "{$origin}:{$url['port']}" : $origin;
    }

    /** The user handle a passkey stores: derived from the app key, so it reveals neither the id nor the e-mail. */
    public static function userHandle(User $user): string
    {
        return hash_hmac('sha256', 'passkey-user:'.$user->id, (string) config('app.key'), true);
    }

    /** JSON options for `navigator.credentials.create()`, kept in the console's session until the answer comes. */
    public function registrationOptions(User $user): string
    {
        $json = $this->creationOptions($user);
        session()->put(self::REGISTER_KEY, $json);

        return $json;
    }

    /**
     * The phone's ceremony (since 2.0.0-l, `POST /api/account/passkeys/options`): the same options, kept in the cache
     * for 5 minutes under a ceremony id of this person, as an app has no session.
     *
     * @return array{ceremony: string, options: array<string, mixed>}
     */
    public function apiRegistrationOptions(User $user): array
    {
        $json = $this->creationOptions($user, self::API_TIMEOUT_MS);
        $ceremony = Str::random(40);
        Cache::put(self::apiKey($user, $ceremony), $json, now()->addMilliseconds(self::API_TIMEOUT_MS));
        /** @var array<string, mixed> $options */
        $options = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return ['ceremony' => $ceremony, 'options' => $options];
    }

    /**
     * The Android app's origins (since 2.0.0-l): `android:apk-key-hash:` and the base64url of each signing certificate's
     * SHA-256 (echoledger.android_app.fingerprints, as assetlinks.json lists them). None = phone passkeys are off.
     *
     * @return list<string>
     */
    public static function androidOrigins(): array
    {
        $origins = [];
        foreach ((array) config('echoledger.android_app.fingerprints') as $fingerprint) {
            $hex = str_replace(':', '', trim((string) $fingerprint));
            if (preg_match('/^[0-9A-Fa-f]{64}$/', $hex) === 1) {
                $origins[] = 'android:apk-key-hash:'.self::encode((string) hex2bin($hex));
            }
        }

        return $origins;
    }

    /** `passkeys_available` of GET /api/account: the server knows the Android app's signing key. */
    public static function availableOnPhone(): bool
    {
        return self::androidOrigins() !== [];
    }

    private function creationOptions(User $user, int $timeout = self::TIMEOUT_MS): string
    {
        $exclude = Passkey::query()->where('user_id', $user->id)->get()
            ->map(fn (Passkey $p): PublicKeyCredentialDescriptor => PublicKeyCredentialDescriptor::create('public-key', self::decode($p->credential_id)))->all();
        $options = PublicKeyCredentialCreationOptions::create(
            rp: PublicKeyCredentialRpEntity::create(Brand::name(), self::rpId()),
            user: PublicKeyCredentialUserEntity::create($user->email, self::userHandle($user), $user->name),
            challenge: random_bytes(32),
            pubKeyCredParams: [
                PublicKeyCredentialParameters::createPk(Algorithms::COSE_ALGORITHM_ES256),
                PublicKeyCredentialParameters::createPk(Algorithms::COSE_ALGORITHM_RS256),
            ],
            authenticatorSelection: AuthenticatorSelectionCriteria::create(
                userVerification: AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_REQUIRED,
                residentKey: AuthenticatorSelectionCriteria::RESIDENT_KEY_REQUIREMENT_REQUIRED,
            ),
            attestation: PublicKeyCredentialCreationOptions::ATTESTATION_CONVEYANCE_PREFERENCE_NONE,
            excludeCredentials: $exclude,
            timeout: $timeout,
        );

        return $this->toJson($options);
    }

    /**
     * Checks the browser's answer and saves the passkey; null when it does not verify (the reason is logged). With
     * [$ceremony] (since 2.0.0-l) the options are the phone's, from the cache, and the Android app's origins count too.
     */
    public function register(User $user, string $responseJson, string $name, ?string $ceremony = null): ?Passkey
    {
        $optionsJson = $ceremony === null ? session()->pull(self::REGISTER_KEY) : Cache::pull(self::apiKey($user, $ceremony));
        if (! is_string($optionsJson) || Passkey::query()->where('user_id', $user->id)->count() >= self::MAX_PER_USER) {
            return null;
        }
        try {
            $credential = $this->serializer()->deserialize($responseJson, PublicKeyCredential::class, 'json');
            $options = $this->serializer()->deserialize($optionsJson, PublicKeyCredentialCreationOptions::class, 'json');
            if (! $credential->response instanceof AuthenticatorAttestationResponse || ! hash_equals($options->user->id, self::userHandle($user))) {
                return null;
            }
            $record = AuthenticatorAttestationResponseValidator::create($this->ceremonies($ceremony !== null)->creationCeremony())
                ->check($credential->response, $options, self::rpId());
        } catch (Throwable $e) {
            report($e);

            return null;
        }
        $passkey = Passkey::query()->create([
            'user_id' => $user->id,
            'name' => Str::limit(trim($name) !== '' ? trim($name) : 'Passkey', 80, ''),
            'credential_id' => self::encode($record->publicKeyCredentialId),
            'credential' => $this->toJson($record),
        ]);
        $this->audit->record('passkey_added', $user->id, $passkey, "Passkey added: {$passkey->name}");

        return $passkey;
    }

    /** JSON options for `navigator.credentials.get()`: any passkey of this site may answer. */
    public function loginOptions(): string
    {
        $options = PublicKeyCredentialRequestOptions::create(
            challenge: random_bytes(32),
            rpId: self::rpId(),
            userVerification: PublicKeyCredentialRequestOptions::USER_VERIFICATION_REQUIREMENT_REQUIRED,
            timeout: self::TIMEOUT_MS,
        );
        $json = $this->toJson($options);
        session()->put(self::LOGIN_KEY, $json);

        return $json;
    }

    /** The user a verified passkey answer belongs to; null for anything that does not verify. */
    public function verifyLogin(string $responseJson): ?User
    {
        $optionsJson = session()->pull(self::LOGIN_KEY);
        if (! is_string($optionsJson)) {
            return null;
        }
        try {
            $credential = $this->serializer()->deserialize($responseJson, PublicKeyCredential::class, 'json');
            $options = $this->serializer()->deserialize($optionsJson, PublicKeyCredentialRequestOptions::class, 'json');
            if (! $credential->response instanceof AuthenticatorAssertionResponse) {
                return null;
            }
            $passkey = Passkey::query()->where('credential_id', self::encode($credential->rawId))->first();
            if (! $passkey instanceof Passkey || ! $passkey->user instanceof User) {
                return null;
            }
            $record = $this->serializer()->deserialize($passkey->credential, CredentialRecord::class, 'json');
            $updated = AuthenticatorAssertionResponseValidator::create($this->ceremonies()->requestCeremony())
                ->check($record, $credential->response, $options, self::rpId(), self::userHandle($passkey->user));
        } catch (Throwable $e) {
            report($e);

            return null;
        }
        $passkey->update(['credential' => $this->toJson($updated), 'last_used_at' => now()]);

        return $passkey->user;
    }

    /** Renames one of the person's passkeys (since 2.0.0-l); null when it is not theirs. */
    public function rename(User $user, string $id, string $name): ?Passkey
    {
        $passkey = Passkey::query()->where('user_id', $user->id)->find($id);
        if (! $passkey instanceof Passkey) {
            return null;
        }
        $passkey->update(['name' => Str::limit($name, 80, '')]);
        $this->audit->record('passkey_renamed', $user->id, $passkey, "Passkey renamed: {$passkey->name}");

        return $passkey;
    }

    public function remove(User $user, string $id): bool
    {
        $passkey = Passkey::query()->where('user_id', $user->id)->find($id);
        if (! $passkey instanceof Passkey) {
            return false;
        }
        $passkey->delete();
        $this->audit->record('passkey_removed', $user->id, $passkey, "Passkey removed: {$passkey->name}");

        return true;
    }

    private static function apiKey(User $user, string $ceremony): string
    {
        return 'passkeys.register.api:'.$user->id.':'.hash('sha256', $ceremony);
    }

    public static function encode(string $binary): string
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }

    public static function decode(string $text): string
    {
        return (string) base64_decode(strtr($text, '-_', '+/'), true);
    }

    private function ceremonies(bool $phone = false): CeremonyStepManagerFactory
    {
        $factory = new CeremonyStepManagerFactory;
        $factory->setAllowedOrigins($phone ? [self::origin(), ...self::androidOrigins()] : [self::origin()]);
        $factory->setSecuredRelyingPartyId(['localhost']); // plain http only for local development

        return $factory;
    }

    private function toJson(object $value): string
    {
        return $this->serializer()->serialize($value, 'json', [
            AbstractObjectNormalizer::SKIP_NULL_VALUES => true,
            JsonEncode::OPTIONS => JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        ]);
    }

    private function serializer(): SerializerInterface
    {
        return $this->serializer ??= (new WebauthnSerializerFactory(AttestationStatementSupportManager::create()))->create();
    }
}
