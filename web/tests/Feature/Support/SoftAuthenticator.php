<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use CBOR\ByteStringObject;
use CBOR\MapObject;
use CBOR\NegativeIntegerObject;
use CBOR\TextStringObject;
use CBOR\UnsignedIntegerObject;
use OpenSSLAsymmetricKey;
use RuntimeException;

/**
 * A software passkey for tests: a real P-256 key, "none" attestation and ECDSA assertions, built byte for byte as a
 * browser and authenticator would send them, so the server's WebAuthn checks run for real.
 */
final class SoftAuthenticator
{
    public readonly string $credentialId;

    private OpenSSLAsymmetricKey $key;

    private ?string $userHandle = null;

    private int $counter = 0;

    public function __construct(private readonly string $origin = 'https://echoledger.test')
    {
        $args = ['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC];
        $key = openssl_pkey_new($args);
        if ($key === false) {
            // Some Windows PHP builds find no openssl.cnf; a minimal one is enough to make a key.
            $config = sys_get_temp_dir().DIRECTORY_SEPARATOR.'el-test-openssl.cnf';
            file_put_contents($config, "[ req ]\ndistinguished_name = dn\n[ dn ]\n");
            $key = openssl_pkey_new($args + ['config' => $config]);
        }
        if ($key === false) {
            throw new RuntimeException('No EC key');
        }
        $this->key = $key;
        $this->credentialId = random_bytes(32);
    }

    public static function b64(string $binary): string
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }

    public static function unb64(string $text): string
    {
        return (string) base64_decode(strtr($text, '-_', '+/'));
    }

    /**
     * The answer to `navigator.credentials.create()` for these options.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function create(array $options, ?string $origin = null): array
    {
        $this->userHandle = self::unb64((string) $options['user']['id']);
        $clientData = json_encode(['type' => 'webauthn.create', 'challenge' => $options['challenge'], 'origin' => $origin ?? $this->origin, 'crossOrigin' => false], JSON_UNESCAPED_SLASHES);
        $authData = hash('sha256', (string) $options['rp']['id'], true)
            .chr(0x45) // user present, user verified, attested credential data
            .pack('N', 0)
            .str_repeat("\0", 16)
            .pack('n', strlen($this->credentialId)).$this->credentialId
            .$this->coseKey();
        $attestation = MapObject::create()
            ->add(TextStringObject::create('fmt'), TextStringObject::create('none'))
            ->add(TextStringObject::create('attStmt'), MapObject::create())
            ->add(TextStringObject::create('authData'), ByteStringObject::create($authData));

        return [
            'id' => self::b64($this->credentialId),
            'rawId' => self::b64($this->credentialId),
            'type' => 'public-key',
            'response' => ['clientDataJSON' => self::b64((string) $clientData), 'attestationObject' => self::b64((string) $attestation), 'transports' => ['internal']],
            'clientExtensionResults' => [],
        ];
    }

    /**
     * The answer to `navigator.credentials.get()` for these options.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function get(array $options, ?string $origin = null, bool $verified = true): array
    {
        $clientData = (string) json_encode(['type' => 'webauthn.get', 'challenge' => $options['challenge'], 'origin' => $origin ?? $this->origin, 'crossOrigin' => false], JSON_UNESCAPED_SLASHES);
        $authData = hash('sha256', (string) $options['rpId'], true).chr($verified ? 0x05 : 0x01).pack('N', ++$this->counter);
        openssl_sign($authData.hash('sha256', $clientData, true), $signature, $this->key, OPENSSL_ALGO_SHA256);

        return [
            'id' => self::b64($this->credentialId),
            'rawId' => self::b64($this->credentialId),
            'type' => 'public-key',
            'response' => [
                'clientDataJSON' => self::b64($clientData),
                'authenticatorData' => self::b64($authData),
                'signature' => self::b64((string) $signature),
                'userHandle' => $this->userHandle === null ? null : self::b64($this->userHandle),
            ],
            'clientExtensionResults' => [],
        ];
    }

    private function coseKey(): string
    {
        $ec = openssl_pkey_get_details($this->key)['ec'] ?? throw new RuntimeException('No EC details');
        $pad = fn (string $n): string => str_pad($n, 32, "\0", STR_PAD_LEFT);

        return (string) MapObject::create()
            ->add(UnsignedIntegerObject::create(1), UnsignedIntegerObject::create(2))
            ->add(UnsignedIntegerObject::create(3), NegativeIntegerObject::create(-7))
            ->add(NegativeIntegerObject::create(-1), UnsignedIntegerObject::create(1))
            ->add(NegativeIntegerObject::create(-2), ByteStringObject::create($pad($ec['x'])))
            ->add(NegativeIntegerObject::create(-3), ByteStringObject::create($pad($ec['y'])));
    }
}
