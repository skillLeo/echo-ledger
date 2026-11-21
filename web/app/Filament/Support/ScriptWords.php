<?php

declare(strict_types=1);

namespace App\Filament\Support;

/**
 * A page script's words in the console's language (W-213): a sentence becomes the JavaScript that writes it, its
 * values joined in where its placeholders are, so a translation may put them in another order. English comes out as
 * the same JavaScript the scripts have always had ("'Uploading ' + size + ' of ' + total"), so classic pages do not
 * change by a byte (tests/Feature/V2/ClassicGoldenTest.php).
 */
final class ScriptWords
{
    /**
     * A text as a JavaScript string in single quotes: "'Ready to upload'". It is written raw ({!! !!}) inside scripts and
     * inside double-quoted HTML attributes (x-text="…"), so nothing in it may end the string, the attribute or the line,
     * open a tag or start an entity: \ ' " & < > CR LF U+2028 U+2029 are escaped.
     */
    public static function quote(string $text): string
    {
        return "'".strtr($text, [
            '\\' => '\\\\', "'" => "\\'", '"' => '\\x22', '&' => '\\x26', '<' => '\\x3c', '>' => '\\x3e',
            "\r" => '\\r', "\n" => '\\n', "\u{2028}" => '\\u2028', "\u{2029}" => '\\u2029',
        ])."'";
    }

    /**
     * The sentence [$english] in the console's language as a JavaScript expression, each `:name` replaced by the
     * expression [$values] gives for it: say('Uploading :sent of :size', ['sent' => 'a', 'size' => 'b']) is
     * "'Uploading ' + a + ' of ' + b". A placeholder in any case (:Name, :NAME) takes the value of its lowercase key.
     *
     * The values are written into the page as they are: only literal JavaScript expressions written in code
     * ('waiting.length', 'this.size(f.size)'), never a user's or the server's data, which goes through @js instead.
     *
     * @param  array<string, string>  $values
     */
    public static function say(string $english, array $values = []): string
    {
        $parts = preg_split('/:([a-z_]+)/i', (string) __($english), -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $out = [];
        foreach ($parts as $i => $part) {
            if ($i % 2 === 1) {
                $out[] = $values[strtolower($part)] ?? self::quote(':'.$part);
            } elseif ($part !== '') {
                $out[] = self::quote($part);
            }
        }

        return $out === [] ? "''" : implode(' + ', $out);
    }

    /**
     * A number with its unit ("8.0 MB") as an expression drawn left to right in one piece inside a right-to-left
     * sentence (LRI … PDI, D4 §5, D11): B8's translator cannot see a value the page script makes. English is the
     * expression itself, so the script stays the same.
     */
    public static function ltr(string $expression): string
    {
        return ConsoleV2::rtl() ? "'\u{2066}' + (".$expression.") + '\u{2069}'" : $expression;
    }

    /** A name inside a sentence, as an expression isolated in right to left so the sentence keeps the page's direction (W-209, D4 §4). */
    public static function name(string $expression): string
    {
        return ConsoleV2::rtl() ? "'\u{2068}' + ".$expression." + '\u{2069}'" : $expression;
    }
}
