<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Models\CaptureSession;
use App\Models\Client;
use App\Models\Recording;
use App\Models\Transcript;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Exceptions\MissingAbilityException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Renders every error of an `api/*` request as {"message", "code", ...} (docs/API.md "Error codes").
 * Messages are fixed texts: they never carry model class names, ids or internal paths.
 */
final class ApiExceptionRenderer
{
    /** Seconds a client waits when a 429 arrives without its own Retry-After value. */
    private const DEFAULT_RETRY_AFTER = 60;

    /** @var array<class-string, array{0: string, 1: string}> */
    private const NOT_FOUND = [
        CaptureSession::class => ['session_not_found', 'Session not found.'],
        Recording::class => ['recording_not_found', 'Recording not found.'],
        Transcript::class => ['transcript_not_found', 'Transcript not found.'],
        Client::class => ['client_not_found', 'Client not found.'],
    ];

    public function __invoke(Throwable $e, Request $request): ?JsonResponse
    {
        if (! $request->is('api/*')) {
            return null;
        }

        return $this->render($e);
    }

    /**
     * Called after Laravel's prepareException(): model lookups arrive as NotFoundHttpException and authorization
     * failures (incl. Sanctum's missing ability) as AccessDeniedHttpException, each carrying the original as previous.
     */
    public function render(Throwable $e): ?JsonResponse
    {
        if ($e instanceof HttpResponseException) {
            return null; // a ready response chosen by the code that threw it
        }
        if ($e instanceof ApiError) {
            return $this->json($e->getStatusCode(), $e->apiCode(), $e->getMessage(), $e->extra(), $e->getHeaders());
        }
        if ($e instanceof ValidationException) {
            if ($e->response !== null) {
                return null;
            }

            return $this->json($e->status, 'validation_failed', $e->getMessage(), ['errors' => $e->errors()]);
        }
        if ($e instanceof AuthenticationException) {
            return $this->json(401, 'unauthenticated', 'Unauthenticated.');
        }
        if ($e->getPrevious() instanceof MissingAbilityException) {
            return $this->json(403, 'missing_ability', 'Invalid ability provided.');
        }
        if ($e instanceof NotFoundHttpException) {
            $previous = $e->getPrevious();
            [$code, $message] = $previous instanceof ModelNotFoundException
                ? (self::NOT_FOUND[$previous->getModel()] ?? ['not_found', 'Not found.'])
                : ['not_found', 'Not found.'];

            return $this->json(404, $code, $message);
        }
        if ($e instanceof MethodNotAllowedHttpException) {
            return $this->json(405, 'method_not_allowed', 'Method not allowed.', [], $e->getHeaders());
        }
        if ($e instanceof ThrottleRequestsException) {
            return $this->json(429, 'rate_limited', 'Too Many Attempts.', [], $e->getHeaders());
        }
        if ($e instanceof ConflictHttpException) {
            return $this->json(409, 'upload_complete', $this->messageOf($e, 409), [], $e->getHeaders());
        }
        if ($e instanceof HttpExceptionInterface) {
            $status = $e->getStatusCode();

            return $this->json($status, self::codeForStatus($status), $this->messageOf($e, $status), [], $e->getHeaders());
        }
        if (config('app.debug')) {
            return null; // Laravel's own answer with the trace, for local debugging only
        }

        return $this->json(500, 'server_error', 'Server Error');
    }

    /** Generic code for an HTTP status that has no dedicated business code. */
    public static function codeForStatus(int $status): string
    {
        return match ($status) {
            400 => 'bad_request',
            401 => 'unauthenticated',
            403 => 'forbidden',
            404 => 'not_found',
            405 => 'method_not_allowed',
            408 => 'request_timeout',
            409 => 'conflict',
            413 => 'too_large',
            416 => 'range_not_satisfiable',
            422 => 'validation_failed',
            429 => 'rate_limited',
            503 => 'service_unavailable',
            default => $status >= 500 ? 'server_error' : 'http_error',
        };
    }

    private function messageOf(Throwable $e, int $status): string
    {
        $message = trim($e->getMessage());

        return $message !== '' ? $message : (Response::$statusTexts[$status] ?? 'Error');
    }

    /**
     * @param  array<string, mixed>  $extra
     * @param  array<string, mixed>  $headers
     */
    private function json(int $status, string $code, string $message, array $extra = [], array $headers = []): JsonResponse
    {
        if ($status === 429 && ! array_key_exists('Retry-After', $headers)) {
            $headers['Retry-After'] = self::DEFAULT_RETRY_AFTER;
        }

        return new JsonResponse(['message' => $message, 'code' => $code, ...$extra], $status, $headers);
    }
}
