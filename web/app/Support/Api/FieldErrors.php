<?php

declare(strict_types=1);

namespace App\Support\Api;

use App\Exceptions\ApiError;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * `422 validation_failed` with stable codes beside the words (since 2.0.0-l, docs/API.md "Account and security from
 * the apps"): `errors` stays `{"field": ["message"]}` as everywhere, and `error_codes` gives `{"field": [{"code":
 * "too_short", "min": 12}]}`, so the apps can write their own words (Urdu). Used by the account routes.
 */
final class FieldErrors
{
    /** Laravel rule names (as Validator::failed() gives them) and the code each one means. */
    private const CODES = [
        'Required' => 'required', 'RequiredWithout' => 'required', 'Present' => 'required',
        'Min' => 'too_short', 'Max' => 'too_long', 'Between' => 'too_long',
        'Email' => 'invalid_email', 'Unique' => 'email_taken', 'Different' => 'same_as_now',
        'In' => 'invalid', 'String' => 'invalid', 'Boolean' => 'invalid', 'Array' => 'invalid',
        'Image' => 'invalid_image', 'Mimes' => 'invalid_image', 'Mimetypes' => 'invalid_image', 'File' => 'invalid_image',
    ];

    /**
     * Validates [$data] or throws `422 validation_failed` with `errors` and `error_codes`.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $rules
     * @param  array<string, string>  $messages
     * @return array<string, mixed>
     */
    public static function validate(array $data, array $rules, array $messages = []): array
    {
        $validator = Validator::make($data, $rules, $messages);
        if (! $validator->fails()) {
            return $validator->validated();
        }
        $codes = [];
        foreach ($validator->failed() as $field => $failed) {
            foreach ($failed as $rule => $parameters) {
                $name = class_exists($rule) ? class_basename($rule) : Str::studly($rule);
                $code = ['code' => self::CODES[$name] ?? 'invalid'];
                if ($name === 'Min' && isset($parameters[0])) {
                    $code['min'] = (int) $parameters[0];
                } elseif (in_array($name, ['Max', 'Between'], true) && isset($parameters[array_key_last($parameters)])) {
                    $code['max'] = (int) $parameters[array_key_last($parameters)];
                }
                $codes[$field][] = $code;
            }
        }

        throw self::error($validator->errors()->toArray(), $codes);
    }

    /**
     * `422 validation_failed` for checks a rule cannot express (a password's letters and numbers, a name's characters).
     *
     * @param  array<string, list<array{0: string, 1: string, 2?: array<string, int|string>}>>  $problems  field => [[code, message, params]]
     */
    public static function fail(array $problems): ApiError
    {
        $errors = [];
        $codes = [];
        foreach ($problems as $field => $list) {
            foreach ($list as $problem) {
                $errors[$field][] = $problem[1];
                $codes[$field][] = ['code' => $problem[0], ...($problem[2] ?? [])];
            }
        }

        return self::error($errors, $codes);
    }

    /**
     * @param  array<string, array<int, string>>  $errors
     * @param  array<string, list<array<string, int|string>>>  $codes
     */
    private static function error(array $errors, array $codes): ApiError
    {
        $first = (string) (array_values($errors)[0][0] ?? 'The given data was invalid.');

        return new ApiError(422, 'validation_failed', $first, ['errors' => $errors, 'error_codes' => $codes]);
    }
}
