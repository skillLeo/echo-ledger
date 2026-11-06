<?php

declare(strict_types=1);

namespace App\Services\Storage;

use DateTimeInterface;
use DateTimeZone;

/**
 * AWS Signature Version 4 for S3-compatible storage: the Authorization header of one request. Every provider of the
 * S3 API checks it the same way, and the secret key only ever goes into the HMAC, never over the wire.
 */
final class S3Signer
{
    public function __construct(
        private readonly string $key,
        #[\SensitiveParameter] private readonly string $secret,
        private readonly string $region,
    ) {}

    /** The time format of the `x-amz-date` header. */
    public static function amzDate(DateTimeInterface $at): string
    {
        return self::utc($at)->format('Ymd\THis\Z');
    }

    /**
     * @param  string  $path  the request's path as it goes over the wire (already percent-encoded, one time)
     * @param  string  $query  the request's query, already sorted and percent-encoded
     * @param  array<string, string>  $headers  every header to sign, `host` and `x-amz-date` among them
     */
    public function authorization(string $method, string $path, string $query, array $headers, string $payloadHash, DateTimeInterface $at): string
    {
        $canonical = [];
        foreach ($headers as $name => $value) {
            $canonical[strtolower($name)] = trim($value);
        }
        ksort($canonical, SORT_STRING);
        $signed = implode(';', array_keys($canonical));
        $lines = '';
        foreach ($canonical as $name => $value) {
            $lines .= $name.':'.$value."\n";
        }
        $request = implode("\n", [$method, $path, $query, $lines, $signed, $payloadHash]);

        $day = self::utc($at)->format('Ymd');
        $scope = $day.'/'.$this->region.'/s3/aws4_request';
        $toSign = implode("\n", ['AWS4-HMAC-SHA256', self::amzDate($at), $scope, hash('sha256', $request)]);
        $signingKey = hash_hmac('sha256', 'aws4_request', hash_hmac('sha256', 's3', hash_hmac('sha256', $this->region, hash_hmac('sha256', $day, 'AWS4'.$this->secret, true), true), true), true);

        return 'AWS4-HMAC-SHA256 Credential='.$this->key.'/'.$scope.',SignedHeaders='.$signed.',Signature='.hash_hmac('sha256', $toSign, $signingKey);
    }

    private static function utc(DateTimeInterface $at): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($at)->setTimezone(new DateTimeZone('UTC'));
    }
}
