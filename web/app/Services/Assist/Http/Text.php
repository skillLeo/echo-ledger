<?php

declare(strict_types=1);

namespace App\Services\Assist\Http;

/**
 * The text rules of Live Assist's answers, as the relay applies them (services/assist-relay/src/text.rs): normalising
 * speech-engine text (English, Urdu in both scripts, Arabic), spotting short replies that need no answer and phrases
 * that make a question hard. A miss here costs one request the model answers with SKIP, never a wrong answer.
 */
final class Text
{
    /** @var array<string, true>|null */
    private static ?array $backchannel = null;

    /**
     * Lower case, Arabic-script letters folded to one form, diacritics and tatweel dropped, punctuation turned into
     * spaces (question marks kept), whitespace collapsed.
     */
    public static function normalize(string $text): string
    {
        $out = '';
        $space = true;
        foreach (preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $char) {
            $folded = match ($char) {
                'ك' => 'ک',
                'ي', 'ى', 'ې' => 'ی',
                'ه', 'ە', 'ة' => 'ہ',
                'أ', 'إ', 'آ', 'ٱ' => 'ا',
                'ؤ' => 'و',
                '?', '؟' => '?',
                default => $char,
            };
            // Harakat, superscript alef and tatweel carry no meaning for matching.
            if (preg_match('/^[\x{064B}-\x{065F}\x{0670}\x{0640}]$/u', $folded) === 1) {
                continue;
            }
            if ($folded !== '?' && $folded !== "'" && $folded !== '’' && preg_match('/^[\p{L}\p{N}]$/u', $folded) !== 1) {
                $folded = ' ';
            }
            if ($folded === ' ') {
                if (! $space) {
                    $out .= ' ';
                    $space = true;
                }

                continue;
            }
            if ($folded === '?' && ! $space) {
                $out .= ' ';
            }
            $lower = mb_strtolower($folded, 'UTF-8');
            $out .= $lower === '’' ? "'" : $lower;
            $space = $folded === '?';
            if ($space) {
                $out .= ' ';
            }
        }

        return rtrim($out, ' ');
    }

    /**
     * The words of normalised text, question marks left out.
     *
     * @return list<string>
     */
    public static function words(string $normalized): array
    {
        return array_values(array_filter(explode(' ', $normalized), fn (string $w): bool => $w !== '' && $w !== '?'));
    }

    /** "Okay", "theek hai", "تمام": a short reply that needs no suggestion (and no request to the model). */
    public static function isBackchannel(string $text): bool
    {
        $normalized = self::normalize($text);
        if (str_contains($normalized, '?')) {
            return false;
        }
        $all = self::words($normalized);
        if ($all === [] || count($all) > 3) {
            return false;
        }
        $known = self::backchannel();
        foreach ($all as $word) {
            if (! isset($known[$word])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether [$text] holds one of the [$phrases] as whole words ("walk me through").
     *
     * @param  list<string>  $phrases
     */
    public static function hasPhrase(string $text, array $phrases): bool
    {
        $all = self::words(self::normalize($text));
        foreach ($phrases as $phrase) {
            $wanted = self::words(self::normalize($phrase));
            if ($wanted !== [] && self::contains($all, $wanted)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $words
     * @param  list<string>  $sequence
     */
    private static function contains(array $words, array $sequence): bool
    {
        $length = count($sequence);
        for ($i = 0; $i + $length <= count($words); $i++) {
            if (array_slice($words, $i, $length) === $sequence) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, true> */
    private static function backchannel(): array
    {
        if (self::$backchannel !== null) {
            return self::$backchannel;
        }
        $entries = [
            'ok', 'okay', 'yeah', 'yes', 'yep', 'yup', 'right', 'sure', 'mm', 'mmm', 'mhm', 'mm-hmm', 'uh-huh', 'hmm',
            'uh', 'huh', 'alright', 'great', 'cool', 'nice', 'got', 'it', 'i', 'see', 'thanks', 'thank', 'you', 'perfect', 'good',
            'fine', 'achha', 'acha', 'accha', 'theek', 'thik', 'hai', 'ji', 'jee', 'haan', 'han', 'bilkul', 'sahi',
            'shukriya', 'اچھا', 'ٹھیک', 'ہے', 'جی', 'ہاں', 'بالکل', 'صحیح', 'شکریہ', 'نعم', 'ای', 'ایوہ', 'تمام', 'طیب',
            'حسنا', 'ماشی', 'صح', 'شکرا', 'اوکی',
        ];
        $set = [];
        foreach ($entries as $entry) {
            foreach (explode(' ', self::normalize($entry)) as $word) {
                if ($word !== '') {
                    $set[$word] = true;
                }
            }
        }

        return self::$backchannel = $set;
    }
}
