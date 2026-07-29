<?php

declare(strict_types=1);

namespace PhpJs\Compiler;

use PhpJs\Vm\Op;

/**
 * Post-codegen peephole pass: fuses adjacent instruction pairs into the
 * superinstructions declared in Op (DESIGN.md §2.4).
 *
 * The lever here is instruction *count*, not instruction speed. Dispatch is
 * a fixed cost per opcode — a switch jump, an operand fetch, a loop-back —
 * and at this dispatch cost it is a large share of the total. Fusing a pair
 * pays that cost once instead of twice and skips the intermediate stack
 * traffic, so the win scales with how often the pair actually executes.
 *
 * The pattern table below is not guesswork: it is the head of a dynamic
 * opcode-pair histogram taken over a React server-side render, which is the
 * largest real workload the runtime has. Percentages are that pair's share
 * of all instructions executed in one render. Anything below ~1% is left
 * alone; the table is meant to stay short enough to reason about.
 *
 * Fusion is only legal when nothing can jump *between* the two instructions,
 * so a pair is rejected when the second instruction is a jump target. Every
 * surviving jump target is then rewritten to its new address, as is the
 * line table used for stack traces.
 */
final class Peephole
{
    /**
     * Set to false to emit unfused bytecode. The pass is meant to be
     * unobservable, so this exists to prove it: the tests run the same
     * programs both ways and compare, and it makes a disassembly listing
     * line up with what the compiler emitted when debugging codegen.
     */
    public static bool $enabled = true;

    /**
     * first opcode => [second opcode => fused opcode].
     *
     * The fused opcode's operands are the first instruction's followed by the
     * second's, in that order, which is what makes the rewrite a splice.
     */
    private const PAIRS = [
        // `x = expr;` — SET_LOCAL deliberately leaves the value on the stack
        // for the expression case, and the statement case pops it right back.
        Op::SET_LOCAL => [Op::POP => Op::STORE_LOCAL],          // 5.9%
        Op::GET_LOCAL => [
            Op::GET_PROP => Op::GET_LOCAL_PROP,                 // 4.3%
            Op::TYPEOF => Op::TYPEOF_LOCAL,                     // 1.3%
        ],
        // GET_LOCAL + CALL is another 2.2% and was tried, but the only cheap
        // way to write it is a `case` that falls through into CALL, and that
        // measured *slower* under the tracing JIT than not fusing at all.
        // Duplicating the whole CALL body to avoid the fall-through is not
        // worth 2.2%, so the pair is left alone.
        // Compare-and-branch: `if (a === b)`, `switch`, and the countless
        // `x === undefined` guards a minified bundle is made of.
        Op::SEQ => [Op::JT => Op::JSEQ, Op::JF => Op::JSNEQ],    // 3.1%
    ];

    /**
     * @param array<string, mixed> $template
     * @return array<string, mixed> the template with 'code' and 'lines' rewritten
     */
    public static function run(array $template): array
    {
        if (!self::$enabled) {
            return $template;
        }
        $code = $template['code'];
        $len = count($code);

        // 1. Decode instruction boundaries. Anything we cannot decode means the
        //    stream is not what we think it is, so leave it exactly as it was.
        $starts = [];
        $width = [];
        $pc = 0;
        while ($pc < $len) {
            $operands = Op::OPERANDS[$code[$pc]] ?? null;
            if ($operands === null) {
                return $template;
            }
            $starts[] = $pc;
            $width[$pc] = $w = 1 + strlen($operands);
            $pc += $w;
        }
        if ($pc !== $len) {
            return $template;
        }

        // 2. Collect every address that is jumped to; those must stay reachable.
        $isTarget = [];
        foreach ($starts as $s) {
            $operands = Op::OPERANDS[$code[$s]];
            for ($i = 0, $n = strlen($operands); $i < $n; $i++) {
                if ($operands[$i] === 'a') {
                    $isTarget[$code[$s + 1 + $i]] = true;
                }
            }
        }

        // 3. Splice the pairs, recording where each old instruction landed.
        $out = [];
        $map = [];
        $count = count($starts);
        for ($i = 0; $i < $count; $i++) {
            $s = $starts[$i];
            $map[$s] = count($out);
            $fused = null;
            $next = null;
            if (isset(self::PAIRS[$code[$s]]) && $i + 1 < $count) {
                $next = $starts[$i + 1];
                if (isset($isTarget[$next])) {
                    $next = null;
                } else {
                    $fused = self::PAIRS[$code[$s]][$code[$next]] ?? null;
                }
            }
            if ($fused === null) {
                for ($w = 0; $w < $width[$s]; $w++) {
                    $out[] = $code[$s + $w];
                }
                continue;
            }
            $out[] = $fused;
            for ($w = 1; $w < $width[$s]; $w++) {
                $out[] = $code[$s + $w];
            }
            for ($w = 1; $w < $width[$next]; $w++) {
                $out[] = $code[$next + $w];
            }
            // Nothing jumps to the second instruction, but a line entry can
            // still point at it; fold it onto the fused instruction.
            $map[$next] = $map[$s];
            $i++;
        }
        // A jump target may be one past the last instruction (a loop exit that
        // falls off the end of the function body).
        $map[$len] = count($out);

        // 4. Rewrite jump targets.
        $outLen = count($out);
        for ($pc = 0; $pc < $outLen;) {
            $operands = Op::OPERANDS[$out[$pc]];
            $n = strlen($operands);
            for ($k = 0; $k < $n; $k++) {
                if ($operands[$k] !== 'a') {
                    continue;
                }
                $target = $out[$pc + 1 + $k];
                if (!isset($map[$target])) {
                    return $template; // an address we cannot account for
                }
                $out[$pc + 1 + $k] = $map[$target];
            }
            $pc += 1 + $n;
        }

        $template['code'] = $out;
        $template['lines'] = self::remapLines($template['lines'], $map);
        return $template;
    }

    /**
     * @param list<array{0: int, 1: int}> $lines
     * @param array<int, int> $map
     * @return list<array{0: int, 1: int}>
     */
    private static function remapLines(array $lines, array $map): array
    {
        $out = [];
        $lastPc = -1;
        foreach ($lines as [$pc, $line]) {
            if (!isset($map[$pc])) {
                continue;
            }
            $newPc = $map[$pc];
            if ($newPc === $lastPc) {
                // Two entries collapsed onto one address. Lookup scans the
                // table in order and keeps the last match, so the later entry
                // is the one that was in effect there; preserve that.
                $out[count($out) - 1][1] = $line;
                continue;
            }
            $out[] = [$newPc, $line];
            $lastPc = $newPc;
        }
        return $out;
    }
}
