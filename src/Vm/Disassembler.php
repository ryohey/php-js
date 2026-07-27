<?php

declare(strict_types=1);

namespace PhpJs\Vm;

/** Human-readable dump of a function template (debugging aid, DESIGN.md §2.3). */
final class Disassembler
{
    /** @param array<string, mixed> $template */
    public static function disassemble(array $template, string $indent = ''): string
    {
        $out = sprintf(
            "%sfunction %s (params=%d locals=%d env=%d strict=%s)\n",
            $indent,
            $template['name'] !== '' ? $template['name'] : '<anonymous>',
            $template['nparams'],
            $template['nlocals'],
            $template['nenv'],
            $template['strict'] ? 'yes' : 'no'
        );
        $code = $template['code'];
        $consts = $template['consts'];
        $n = count($code);
        for ($pc = 0; $pc < $n;) {
            $at = $pc;
            $op = $code[$pc++];
            $operands = Op::OPERANDS[$op] ?? '';
            $parts = [];
            foreach (str_split($operands === '' ? '' : $operands) as $kind) {
                if ($kind === '') {
                    continue;
                }
                $v = $code[$pc++];
                $parts[] = match ($kind) {
                    'k' => $v . ' (' . self::constRepr($consts[$v] ?? null) . ')',
                    'a' => '@' . $v,
                    default => (string)$v,
                };
            }
            $out .= sprintf("%s  %4d  %-16s %s\n", $indent, $at, Op::name($op), implode(', ', $parts));
        }
        foreach ($template['children'] as $i => $child) {
            $out .= $indent . "  child[$i]:\n" . self::disassemble($child, $indent . '    ');
        }
        return $out;
    }

    private static function constRepr(mixed $v): string
    {
        if (is_string($v)) {
            return strlen($v) > 30 ? var_export(substr($v, 0, 30) . '…', true) : var_export($v, true);
        }
        return var_export($v, true);
    }
}
