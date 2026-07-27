<?php

declare(strict_types=1);

namespace PhpJs\RegExp;

/**
 * JS regexp pattern -> PCRE2 pattern translation (DESIGN.md §8). No regexp
 * engine is ported; PCRE executes the translated pattern. PCRE's `u` modifier
 * is always used, so matching is code-point based; lone-surrogate subjects
 * simply fail to match (accepted deviation).
 */
final class RegExpTranslator
{
    /** @throws RegExpSyntaxError */
    public static function translate(string $pattern, string $flags): string
    {
        $multiline = str_contains($flags, 'm');
        $ignoreCase = str_contains($flags, 'i');
        $sticky = str_contains($flags, 'y');
        $unicode = str_contains($flags, 'u');
        foreach (str_split($flags) as $f) {
            if ($f !== '' && !str_contains('gimyus', $f)) {
                throw new RegExpSyntaxError("Invalid regular expression flag '$f'");
            }
        }

        $out = '';
        $len = strlen($pattern);
        $inClass = false;
        for ($i = 0; $i < $len; $i++) {
            $c = $pattern[$i];
            if ($c === '\\') {
                if ($i + 1 >= $len) {
                    throw new RegExpSyntaxError('Pattern may not end with a trailing backslash');
                }
                $n = $pattern[$i + 1];
                if ($n === 'u') {
                    // \uXXXX -> \x{XXXX}; \u{...} (with u flag) -> \x{...}
                    if ($i + 5 < $len && ctype_xdigit(substr($pattern, $i + 2, 4))) {
                        $out .= '\\x{' . substr($pattern, $i + 2, 4) . '}';
                        $i += 5;
                        continue;
                    }
                    if ($unicode && $i + 2 < $len && $pattern[$i + 2] === '{') {
                        $close = strpos($pattern, '}', $i + 3);
                        if ($close !== false && ctype_xdigit(substr($pattern, $i + 3, $close - $i - 3))) {
                            $out .= '\\x{' . substr($pattern, $i + 3, $close - $i - 3) . '}';
                            $i = $close;
                            continue;
                        }
                    }
                    // Annex B: lone \u is a literal 'u'.
                    $out .= 'u';
                    $i++;
                    continue;
                }
                if ($n === '/') {
                    $out .= '\\/';
                    $i++;
                    continue;
                }
                $out .= '\\' . $n;
                $i++;
                continue;
            }
            if ($inClass) {
                if ($c === ']') {
                    $inClass = false;
                }
                $out .= $c === '/' ? '\\/' : $c;
                continue;
            }
            switch ($c) {
                case '[':
                    if (substr($pattern, $i, 2) === '[]') {
                        $out .= '(?!)';   // empty class: matches nothing in JS
                        $i += 1;
                        break;
                    }
                    if (substr($pattern, $i, 3) === '[^]') {
                        $out .= '[\s\S]'; // negated empty class: matches anything
                        $i += 2;
                        break;
                    }
                    $inClass = true;
                    $out .= '[';
                    break;
                case '.':
                    // JS `.` excludes \n \r U+2028 U+2029; PCRE `.` only \n.
                    $out .= '[^\n\r\x{2028}\x{2029}]';
                    break;
                case '(':
                    if (substr($pattern, $i, 3) === '(?<'
                        && !in_array(substr($pattern, $i, 4), ['(?<=', '(?<!'], true)) {
                        throw new RegExpSyntaxError('Named capture groups are not supported (ES5 target)');
                    }
                    $out .= '(';
                    break;
                case '/':
                    $out .= '\\/';
                    break;
                default:
                    $out .= $c;
            }
        }
        if ($inClass) {
            throw new RegExpSyntaxError('Unterminated character class');
        }

        $mods = 'u';
        if ($ignoreCase) {
            $mods .= 'i';
        }
        if ($multiline) {
            $mods .= 'm';
        } else {
            // JS `$` without m never matches before a trailing newline.
            $mods .= 'D';
        }
        if ($sticky) {
            $mods .= 'A';
        }
        $pcre = '/' . $out . '/' . $mods;
        if (@preg_match($pcre, '') === false) {
            throw new RegExpSyntaxError('Invalid regular expression: ' . preg_last_error_msg());
        }
        return $pcre;
    }
}
