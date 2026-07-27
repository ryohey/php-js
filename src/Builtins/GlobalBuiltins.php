<?php

declare(strict_types=1);

namespace PhpJs\Builtins;

use PhpJs\Compiler\Compiler;
use PhpJs\Runtime\Conversions;
use PhpJs\Runtime\JSUndefined;
use PhpJs\Vm\Vm;

final class GlobalBuiltins
{
    public static function entries(): array
    {
        return [
            'global.parseInt' => [self::class, 'parseInt'],
            'global.parseFloat' => [self::class, 'parseFloat'],
            'global.isNaN' => [self::class, 'isNaN'],
            'global.isFinite' => [self::class, 'isFinite'],
            'global.eval' => [self::class, 'evalFn'],
            'global.encodeURIComponent' => [self::class, 'encodeURIComponent'],
            'global.decodeURIComponent' => [self::class, 'decodeURIComponent'],
            'global.encodeURI' => [self::class, 'encodeURI'],
            'global.decodeURI' => [self::class, 'decodeURI'],
        ];
    }

    public static function parseInt(Vm $vm, mixed $t, array $args): mixed
    {
        $s = Conversions::trimJs(Conversions::toString($vm, (\array_key_exists(0, $args) ? $args[0] : JSUndefined::$undefined)));
        $radix = Conversions::toInt32($vm, $args[1] ?? 0);
        $sign = 1;
        if ($s !== '' && ($s[0] === '+' || $s[0] === '-')) {
            $sign = $s[0] === '-' ? -1 : 1;
            $s = substr($s, 1);
        }
        $stripPrefix = true;
        if ($radix !== 0) {
            if ($radix < 2 || $radix > 36) {
                return NAN;
            }
            if ($radix !== 16) {
                $stripPrefix = false;
            }
        } else {
            $radix = 10;
        }
        if ($stripPrefix && strlen($s) >= 2 && $s[0] === '0' && ($s[1] === 'x' || $s[1] === 'X')) {
            $s = substr($s, 2);
            $radix = 16;
        }
        $digits = '0123456789abcdefghijklmnopqrstuvwxyz';
        $valid = substr($digits, 0, $radix);
        $len = strspn(strtolower($s), $valid);
        if ($len === 0) {
            return NAN;
        }
        $s = substr($s, 0, $len);
        $result = 0;
        $isFloat = false;
        for ($i = 0; $i < $len; $i++) {
            $d = strpos($digits, strtolower($s[$i]));
            if (!$isFloat) {
                $next = $result * $radix + $d;
                if (is_float($next)) {
                    $isFloat = true;
                    $result = (float)$result * $radix + $d;
                } else {
                    $result = $next;
                }
            } else {
                $result = $result * $radix + $d;
            }
        }
        return $sign === -1 ? ($result === 0 ? -0.0 : -$result) : $result;
    }

    public static function parseFloat(Vm $vm, mixed $t, array $args): mixed
    {
        $s = Conversions::trimJs(Conversions::toString($vm, (\array_key_exists(0, $args) ? $args[0] : JSUndefined::$undefined)));
        if (preg_match('/^[+-]?Infinity/', $s, $m)) {
            return $m[0][0] === '-' ? -INF : INF;
        }
        if (!preg_match('/^[+-]?(\d+\.?\d*|\.\d+)([eE][+-]?\d+)?/', $s, $m)) {
            return NAN;
        }
        return (float)$m[0];
    }

    public static function isNaN(Vm $vm, mixed $t, array $args): mixed
    {
        $n = Conversions::toNumber($vm, (\array_key_exists(0, $args) ? $args[0] : JSUndefined::$undefined));
        return is_float($n) && is_nan($n);
    }

    public static function isFinite(Vm $vm, mixed $t, array $args): mixed
    {
        $n = Conversions::toNumber($vm, (\array_key_exists(0, $args) ? $args[0] : JSUndefined::$undefined));
        return is_int($n) || (!is_nan($n) && !is_infinite($n));
    }

    /**
     * Indirect eval: evaluates in the global scope. Direct-eval semantics
     * (access to the caller's scope) are not implemented; the compiler treats
     * `eval(x)` as an ordinary call, which resolves here (DESIGN.md §15).
     */
    public static function evalFn(Vm $vm, mixed $t, array $args): mixed
    {
        $src = (\array_key_exists(0, $args) ? $args[0] : JSUndefined::$undefined);
        if (!is_string($src)) {
            return $src;
        }
        try {
            $tpl = Compiler::compile($src);
        } catch (\PhpJs\Compiler\CompileError $e) {
            $vm->throwError('SyntaxError', $e->getMessage());
        }
        return $vm->runProgram($tpl);
    }

    private const URI_UNRESERVED = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_.!~*\'()';

    public static function encodeURIComponent(Vm $vm, mixed $t, array $args): mixed
    {
        return self::encode($vm, $args, self::URI_UNRESERVED);
    }

    public static function encodeURI(Vm $vm, mixed $t, array $args): mixed
    {
        return self::encode($vm, $args, self::URI_UNRESERVED . ';/?:@&=+$,#');
    }

    private static function encode(Vm $vm, array $args, string $keep): string
    {
        $s = Conversions::toString($vm, (\array_key_exists(0, $args) ? $args[0] : JSUndefined::$undefined));
        $out = '';
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            $c = $s[$i];
            if (strpos($keep, $c) !== false) {
                $out .= $c;
            } else {
                $out .= strtoupper('%' . bin2hex($c));
            }
        }
        return $out;
    }

    public static function decodeURIComponent(Vm $vm, mixed $t, array $args): mixed
    {
        return self::decode($vm, $args, '');
    }

    public static function decodeURI(Vm $vm, mixed $t, array $args): mixed
    {
        return self::decode($vm, $args, ';/?:@&=+$,#');
    }

    private static function decode(Vm $vm, array $args, string $reserved): string
    {
        $s = Conversions::toString($vm, (\array_key_exists(0, $args) ? $args[0] : JSUndefined::$undefined));
        $out = '';
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            if ($s[$i] === '%') {
                if ($i + 2 >= $len || !ctype_xdigit($s[$i + 1] . $s[$i + 2])) {
                    $vm->throwError('URIError', 'URI malformed');
                }
                $byte = chr((int)hexdec($s[$i + 1] . $s[$i + 2]));
                if ($reserved !== '' && strpos($reserved, $byte) !== false) {
                    $out .= substr($s, $i, 3);
                } else {
                    $out .= $byte;
                }
                $i += 2;
            } else {
                $out .= $s[$i];
            }
        }
        return $out;
    }
}
