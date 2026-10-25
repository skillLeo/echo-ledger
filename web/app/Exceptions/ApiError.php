<?php

declare(strict_types=1);

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * A business error of the device API (docs/API.md "Error codes"). Rendered as {"message", "code", ...extra}.
 */
final class ApiError extends HttpException
{
    /**
     * @param  array<string, mixed>  $extra  additional top-level keys of the answer (for example `errors`, `missing_chunks`)
     * @param  array<string, string>  $headers
     */
    public function __construct(
        int $status,
        private readonly string $apiCode,
        string $message,
        private readonly array $extra = [],
        array $headers = [],
    ) {
        parent::__construct($status, $message, null, $headers);
    }

    public function apiCode(): string
    {
        return $this->apiCode;
    }

    /** @return array<string, mixed> */
    public function extra(): array
    {
        return $this->extra;
    }
}
