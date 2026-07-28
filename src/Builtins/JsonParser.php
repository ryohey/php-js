<?php

declare(strict_types=1);

namespace PhpJs\Builtins;

use PhpJs\Runtime\Conversions;
use PhpJs\Runtime\JSArray;
use PhpJs\Runtime\JSObject;
use PhpJs\Runtime\Realm;
use PhpJs\Runtime\StringOps;

/**
 * JSON grammar parser (15.12.1) producing JS values directly.
 *
 * Hand-written rather than delegating to json_decode: the JSON number grammar
 * maps onto JS numbers in ways PHP's decoder cannot express — "-0" must yield
 * -0, and integral values must stay PHP ints so the unboxed number
 * representation (DESIGN.md §3) is preserved.
 */
final class JsonParser
{
    private int $pos = 0;
    private int $len;

    private function __construct(
        private readonly string $text,
        private readonly Realm $realm,
    ) {
        $this->len = strlen($text);
    }

    /** @throws JsonSyntaxError */
    public static function parse(string $text, Realm $realm): mixed
    {
        $p = new self($text, $realm);
        $p->skipWhitespace();
        $value = $p->parseValue();
        $p->skipWhitespace();
        if ($p->pos !== $p->len) {
            $p->fail('Unexpected non-whitespace character after JSON');
        }
        return $value;
    }

    private function fail(string $message): never
    {
        throw new JsonSyntaxError($message . ' at position ' . $this->pos);
    }

    private function skipWhitespace(): void
    {
        while ($this->pos < $this->len) {
            $c = $this->text[$this->pos];
            if ($c === ' ' || $c === "\t" || $c === "\n" || $c === "\r") {
                $this->pos++;
            } else {
                break;
            }
        }
    }

    private function parseValue(): mixed
    {
        if ($this->pos >= $this->len) {
            $this->fail('Unexpected end of JSON input');
        }
        $c = $this->text[$this->pos];
        return match (true) {
            $c === '{' => $this->parseObject(),
            $c === '[' => $this->parseArray(),
            $c === '"' => $this->parseString(),
            $c === 't' => $this->parseLiteral('true', true),
            $c === 'f' => $this->parseLiteral('false', false),
            $c === 'n' => $this->parseLiteral('null', null),
            $c === '-' || ($c >= '0' && $c <= '9') => $this->parseNumber(),
            default => $this->fail("Unexpected token $c in JSON"),
        };
    }

    private function parseLiteral(string $word, mixed $value): mixed
    {
        if (substr($this->text, $this->pos, strlen($word)) !== $word) {
            $this->fail("Unexpected token {$this->text[$this->pos]} in JSON");
        }
        $this->pos += strlen($word);
        return $value;
    }

    private function parseNumber(): int|float
    {
        $start = $this->pos;
        if ($this->pos < $this->len && $this->text[$this->pos] === '-') {
            $this->pos++;
        }
        // int: 0 | [1-9][0-9]*
        if ($this->pos < $this->len && $this->text[$this->pos] === '0') {
            $this->pos++;
        } elseif ($this->pos < $this->len && $this->text[$this->pos] >= '1' && $this->text[$this->pos] <= '9') {
            while ($this->pos < $this->len && ctype_digit($this->text[$this->pos])) {
                $this->pos++;
            }
        } else {
            $this->fail('Invalid number in JSON');
        }
        $isFloat = false;
        if ($this->pos < $this->len && $this->text[$this->pos] === '.') {
            $isFloat = true;
            $this->pos++;
            if ($this->pos >= $this->len || !ctype_digit($this->text[$this->pos])) {
                $this->fail('Invalid number in JSON');
            }
            while ($this->pos < $this->len && ctype_digit($this->text[$this->pos])) {
                $this->pos++;
            }
        }
        if ($this->pos < $this->len && ($this->text[$this->pos] === 'e' || $this->text[$this->pos] === 'E')) {
            $isFloat = true;
            $this->pos++;
            if ($this->pos < $this->len && ($this->text[$this->pos] === '+' || $this->text[$this->pos] === '-')) {
                $this->pos++;
            }
            if ($this->pos >= $this->len || !ctype_digit($this->text[$this->pos])) {
                $this->fail('Invalid number in JSON');
            }
            while ($this->pos < $this->len && ctype_digit($this->text[$this->pos])) {
                $this->pos++;
            }
        }
        $lexeme = substr($this->text, $start, $this->pos - $start);
        if (!$isFloat) {
            // "-0" is the one integer literal that must not stay an int.
            if ($lexeme === '-0') {
                return -0.0;
            }
            $asFloat = (float)$lexeme;
            if ($asFloat >= -Conversions::MAX_EXACT_INT && $asFloat <= Conversions::MAX_EXACT_INT) {
                return (int)$lexeme;
            }
            return $asFloat;
        }
        return (float)$lexeme;
    }

    private function parseString(): string
    {
        $this->pos++; // opening quote
        $units = [];
        for (;;) {
            if ($this->pos >= $this->len) {
                $this->fail('Unterminated string in JSON');
            }
            $c = $this->text[$this->pos];
            if ($c === '"') {
                $this->pos++;
                break;
            }
            if ($c === '\\') {
                $this->pos++;
                if ($this->pos >= $this->len) {
                    $this->fail('Unterminated escape in JSON');
                }
                $e = $this->text[$this->pos++];
                switch ($e) {
                    case '"': $units[] = 0x22; break;
                    case '\\': $units[] = 0x5C; break;
                    case '/': $units[] = 0x2F; break;
                    case 'b': $units[] = 0x08; break;
                    case 'f': $units[] = 0x0C; break;
                    case 'n': $units[] = 0x0A; break;
                    case 'r': $units[] = 0x0D; break;
                    case 't': $units[] = 0x09; break;
                    case 'u':
                        $hex = substr($this->text, $this->pos, 4);
                        if (strlen($hex) !== 4 || !ctype_xdigit($hex)) {
                            $this->fail('Invalid \\u escape in JSON');
                        }
                        $units[] = (int)hexdec($hex);
                        $this->pos += 4;
                        break;
                    default:
                        $this->fail("Invalid escape \\$e in JSON");
                }
                continue;
            }
            $code = ord($c);
            if ($code < 0x20) {
                $this->fail('Unescaped control character in JSON string');
            }
            if ($code < 0x80) {
                $units[] = $code;
                $this->pos++;
                continue;
            }
            // Pass multi-byte UTF-8 through untouched.
            $width = match (true) {
                ($code & 0xE0) === 0xC0 => 2,
                ($code & 0xF0) === 0xE0 => 3,
                ($code & 0xF8) === 0xF0 => 4,
                default => 1,
            };
            foreach (StringOps::toCodeUnits(substr($this->text, $this->pos, $width)) as $u) {
                $units[] = $u;
            }
            $this->pos += $width;
        }
        return StringOps::fromCodeUnits($units);
    }

    private function parseArray(): JSArray
    {
        $this->pos++; // [
        $arr = new JSArray($this->realm->arrayPrototype());
        $this->skipWhitespace();
        if ($this->pos < $this->len && $this->text[$this->pos] === ']') {
            $this->pos++;
            return $arr;
        }
        $i = 0;
        for (;;) {
            $this->skipWhitespace();
            $arr->elements[$i++] = $this->parseValue();
            $arr->length = $i;
            $this->skipWhitespace();
            if ($this->pos >= $this->len) {
                $this->fail('Unterminated array in JSON');
            }
            $c = $this->text[$this->pos++];
            if ($c === ']') {
                return $arr;
            }
            if ($c !== ',') {
                $this->fail("Expected ',' or ']' in JSON array");
            }
        }
    }

    private function parseObject(): JSObject
    {
        $this->pos++; // {
        $obj = $this->realm->newObject();
        $this->skipWhitespace();
        if ($this->pos < $this->len && $this->text[$this->pos] === '}') {
            $this->pos++;
            return $obj;
        }
        for (;;) {
            $this->skipWhitespace();
            if ($this->pos >= $this->len || $this->text[$this->pos] !== '"') {
                $this->fail('Expected property name in JSON object');
            }
            $key = $this->parseString();
            $this->skipWhitespace();
            if ($this->pos >= $this->len || $this->text[$this->pos] !== ':') {
                $this->fail("Expected ':' in JSON object");
            }
            $this->pos++;
            $this->skipWhitespace();
            $obj->defineOwnData($key, $this->parseValue());
            $this->skipWhitespace();
            if ($this->pos >= $this->len) {
                $this->fail('Unterminated object in JSON');
            }
            $c = $this->text[$this->pos++];
            if ($c === '}') {
                return $obj;
            }
            if ($c !== ',') {
                $this->fail("Expected ',' or '}' in JSON object");
            }
        }
    }
}
