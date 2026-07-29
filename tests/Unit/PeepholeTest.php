<?php

declare(strict_types=1);

namespace PhpJs\Tests\Unit;

use PhpJs\Compiler\Peephole;
use PhpJs\Vm\Op;
use PHPUnit\Framework\TestCase;

/**
 * The peephole pass rewrites addresses, so its failure mode is a jump landing
 * one word off — which produces garbage rather than an error. These tests work
 * on hand-built code streams so each rule can be checked on its own.
 */
final class PeepholeTest extends TestCase
{
    /**
     * @param list<int> $code
     * @param list<array{0: int, 1: int}> $lines
     * @return array<string, mixed>
     */
    private static function tpl(array $code, array $lines = []): array
    {
        return ['code' => $code, 'lines' => $lines];
    }

    public function testFusesSetLocalPop(): void
    {
        $out = Peephole::run(self::tpl([
            Op::PUSH_INT, 7,
            Op::SET_LOCAL, 3,
            Op::POP,
            Op::RETURN_UNDEF,
        ]));
        $this->assertSame([Op::PUSH_INT, 7, Op::STORE_LOCAL, 3, Op::RETURN_UNDEF], $out['code']);
    }

    public function testFusesGetLocalGetProp(): void
    {
        $out = Peephole::run(self::tpl([Op::GET_LOCAL, 1, Op::GET_PROP, 4, Op::RETURN]));
        $this->assertSame([Op::GET_LOCAL_PROP, 1, 4, Op::RETURN], $out['code']);
    }

    public function testFusesCompareAndBranchInBothDirections(): void
    {
        // The branch target (the RETURN at 3) moves back one word with it.
        $this->assertSame(
            [Op::JSNEQ, 2, Op::RETURN],
            Peephole::run(self::tpl([Op::SEQ, Op::JF, 3, Op::RETURN]))['code']
        );
        $this->assertSame(
            [Op::JSEQ, 2, Op::RETURN],
            Peephole::run(self::tpl([Op::SEQ, Op::JT, 3, Op::RETURN]))['code']
        );
    }

    public function testLeavesUnpairedInstructionsAlone(): void
    {
        // SEQ whose result is a value, not a branch condition.
        $code = [Op::SEQ, Op::SET_LOCAL, 0, Op::RETURN];
        $this->assertSame($code, Peephole::run(self::tpl($code))['code']);
    }

    public function testRewritesJumpTargetsPastAFusion(): void
    {
        // JMP over a fused pair: the target must move back by one word.
        $out = Peephole::run(self::tpl([
            Op::JMP, 7,          // 0: jump to the RETURN at 7
            Op::PUSH_INT, 1,     // 2
            Op::SET_LOCAL, 0,    // 4
            Op::POP,             // 6
            Op::RETURN_UNDEF,    // 7
        ]));
        $this->assertSame([Op::JMP, 6, Op::PUSH_INT, 1, Op::STORE_LOCAL, 0, Op::RETURN_UNDEF], $out['code']);
    }

    public function testRefusesToFuseAcrossAJumpTarget(): void
    {
        // Something jumps at the POP, so the SET_LOCAL/POP pair has to stay
        // two instructions: entering at the POP must still pop one value.
        $code = [
            Op::JMP, 4,          // 0: jump straight to the POP
            Op::SET_LOCAL, 0,    // 2
            Op::POP,             // 4
            Op::RETURN_UNDEF,    // 5
        ];
        $this->assertSame($code, Peephole::run(self::tpl($code))['code']);
    }

    public function testFusesTheInstructionThatIsItselfAJumpTarget(): void
    {
        // Landing on the *first* instruction of a pair is fine; the fused
        // instruction takes its place and the target follows it.
        $out = Peephole::run(self::tpl([
            Op::JMP, 2,          // 0
            Op::SET_LOCAL, 0,    // 2 <- target
            Op::POP,             // 4
            Op::RETURN_UNDEF,    // 5
        ]));
        $this->assertSame([Op::JMP, 2, Op::STORE_LOCAL, 0, Op::RETURN_UNDEF], $out['code']);
    }

    public function testRemapsTheLineTable(): void
    {
        $out = Peephole::run(self::tpl(
            [Op::SET_LOCAL, 0, Op::POP, Op::PUSH_INT, 1, Op::RETURN],
            [[0, 10], [3, 11]]
        ));
        $this->assertSame([[0, 10], [2, 11]], $out['lines']);
    }

    public function testCollapsesLineEntriesThatLandOnTheSameInstruction(): void
    {
        // An entry pointing at the second half of a fused pair folds onto the
        // fused instruction. Lookup keeps the last match for an address, so
        // the later entry is the one that has to survive.
        $out = Peephole::run(self::tpl(
            [Op::SET_LOCAL, 0, Op::POP, Op::RETURN_UNDEF],
            [[0, 10], [2, 11]]
        ));
        $this->assertSame([[0, 11]], $out['lines']);
    }

    public function testLeavesAnUndecodableStreamUntouched(): void
    {
        $code = [Op::PUSH_INT, 1, 9999, Op::RETURN];
        $this->assertSame($code, Peephole::run(self::tpl($code))['code']);
    }

    public function testHandlesATargetOnePastTheEnd(): void
    {
        $out = Peephole::run(self::tpl([
            Op::SET_LOCAL, 0,    // 0
            Op::POP,             // 2
            Op::JMP, 5,          // 3: falls off the end
        ]));
        $this->assertSame([Op::STORE_LOCAL, 0, Op::JMP, 4], $out['code']);
    }
}
