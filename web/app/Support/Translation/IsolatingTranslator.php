<?php

declare(strict_types=1);

namespace App\Support\Translation;

use Closure;
use Illuminate\Support\Str;
use Illuminate\Translation\Translator;

use function Illuminate\Support\enum_value;

/**
 * Every `:replacement` in an Urdu or Arabic line is put in a Unicode isolate (U+2068 FIRST STRONG ISOLATE ... U+2069 POP
 * DIRECTIONAL ISOLATE), so a Latin name, a number or an email address inside right-to-left text keeps its own direction
 * and does not reorder the words around it ("Call with Ahmed Khan" in an Urdu sentence). Applies to __(), trans(),
 * trans_choice() and @lang alike, wherever the line's locale is right to left; every other locale is exactly Laravel's.
 * Every value is wrapped, even one already in an isolate; closures (`<tag>` replacements) are not values and are untouched.
 *
 * Not covered: Laravel's validation messages fill :attribute, :min and the like with str_replace in the Validator, not
 * through the translator, so they are not isolated. They only appear in the console (the API is always English), and
 * the field they belong to is right beside them.
 */
final class IsolatingTranslator extends Translator
{
    /** Right-to-left languages the console is translated into (the language part of a locale such as `ur_PK`). */
    public const RTL = ['ar', 'ur'];

    private const OPENERS = ["\u{2066}", "\u{2067}", "\u{2068}"];

    private const CLOSE = "\u{2069}";

    /** The locale of the line being filled in, set by get() and choice() around the work of the parent. */
    private ?string $filling = null;

    public static function from(Translator $translator): self
    {
        $isolating = new self($translator->getLoader(), $translator->getLocale());
        $isolating->setFallback($translator->getFallback());

        return $isolating;
    }

    public static function isRtl(?string $locale): bool
    {
        return in_array(Str::lower(Str::before(str_replace('-', '_', (string) $locale), '_')), self::RTL, true);
    }

    /**
     * @param  string  $key
     * @param  array<string, mixed>  $replace
     * @param  string|null  $locale
     * @param  bool  $fallback
     * @return string|array<mixed>
     */
    public function get($key, array $replace = [], $locale = null, $fallback = true)
    {
        return $this->filling($locale ?: $this->locale, fn () => parent::get($key, $replace, $locale, $fallback));
    }

    /**
     * @param  string  $key
     * @param  \Countable|int|float|array<mixed>  $number
     * @param  array<string, mixed>  $replace
     * @param  string|null  $locale
     * @return string
     */
    public function choice($key, $number, array $replace = [], $locale = null)
    {
        return $this->filling($locale ?: $this->locale, fn () => parent::choice($key, $number, $replace, $locale));
    }

    /**
     * @param  string  $line
     * @param  array<string, mixed>  $replace
     * @return string
     */
    protected function makeReplacements($line, array $replace)
    {
        if ($replace === [] || ! self::isRtl($this->filling)) {
            return parent::makeReplacements($line, $replace);
        }

        // Laravel's own steps (makeReplacements), with each value isolated after its case is changed.
        $shouldReplace = [];
        foreach ($replace as $key => $value) {
            if ($value instanceof Closure) {
                $line = preg_replace_callback('/<'.$key.'>(.*?)<\/'.$key.'>/', fn ($args) => $value($args[1]), $line);

                continue;
            }
            if (is_object($value)) {
                $value = isset($this->stringableHandlers[get_class($value)])
                    ? call_user_func($this->stringableHandlers[get_class($value)], $value)
                    : enum_value($value);
            }
            $value = (string) ($value ?? '');
            $shouldReplace[':'.Str::ucfirst($key)] = self::isolate(Str::ucfirst($value));
            $shouldReplace[':'.Str::upper($key)] = self::isolate(Str::upper($value));
            $shouldReplace[':'.$key] = self::isolate($value);
        }

        return strtr($line, $shouldReplace);
    }

    /** The text without any bidi isolate marks (U+2066 to U+2069): for text that leaves the page for a machine. */
    public static function strip(string $text): string
    {
        return str_replace([...self::OPENERS, self::CLOSE], '', $text);
    }

    /**
     * Always wraps, even a value that already looks isolated (wrapping twice is harmless). A closing mark (PDI) with no
     * opener of its own inside the value is dropped, so a typed name cannot close this isolate early and let what
     * follows (an override such as U+202E) flip the sentence; every isolate the value opens and leaves open gets its
     * own PDI (W-233), so the rest of the sentence is never left inside one, and the final PDI closes the value's.
     */
    public static function isolate(string $value): string
    {
        if ($value === '') {
            return $value;
        }
        $depth = 0;
        $safe = '';
        foreach (mb_str_split($value) as $char) {
            if (in_array($char, self::OPENERS, true)) {
                $depth++;
            } elseif ($char === self::CLOSE) {
                if ($depth === 0) {
                    continue;
                }
                $depth--;
            }
            $safe .= $char;
        }

        return "\u{2068}{$safe}".str_repeat(self::CLOSE, $depth).self::CLOSE;
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    private function filling(?string $locale, Closure $work): mixed
    {
        $before = $this->filling;
        $this->filling = $locale;
        try {
            return $work();
        } finally {
            $this->filling = $before;
        }
    }
}
