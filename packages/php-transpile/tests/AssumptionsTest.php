<?php

declare(strict_types=1);

namespace PhpJs\Transpile\Tests;

use PhpJs\Compiler\Compiler;
use PhpJs\Engine;
use PhpJs\Transpile\Assumptions;
use PhpJs\Transpile\FunctionEmitter;
use PhpJs\Transpile\ModuleFacts;
use PhpJs\Transpile\Unsupported;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The specializations that a closed build is allowed to make.
 *
 * These are the only places the emitter stops being literal, so they carry the
 * most risk in the package. Two things are checked for each: that the
 * specialized code still computes the same answer, and — more importantly —
 * that the specialization *does not fire* when its proof fails. A missed
 * optimization is a performance bug; a wrongly applied one is a correctness
 * bug, so the negative cases matter more than the positive ones.
 */
final class AssumptionsTest extends TestCase
{
    /** Compile one function of a module and return the generated PHP. */
    private function emit(string $moduleBody, string $fnName, Assumptions $assume): string
    {
        $wrapped = "(function (exports, require, module) {\n$moduleBody\n})";
        $facts = $assume->standardBuiltins ? ModuleFacts::scan($moduleBody) : ModuleFacts::none();
        $php = '';
        Compiler::compile($wrapped, function (object $node, $ctx, bool $isProgram) use (&$php, $fnName, $assume, $facts): ?string {
            if ($isProgram || $ctx->name !== $fnName) {
                return null;
            }
            try {
                $closure = (new FunctionEmitter($ctx, $assume, $facts))->emit($node);
                $php = (new \PhpParser\PrettyPrinter\Standard())->prettyPrintExpr($closure);
            } catch (Unsupported) {
                $php = '<refused>';
            }
            return null;
        });
        return $php;
    }

    // ---- hasOwnProperty ----------------------------------------------------

    private const HAS_OWN_MODULE = <<<'JS'
        var hasOwnProperty = Object.prototype.hasOwnProperty;
        function pick(o) { var n = 0; for (var k in o) { if (hasOwnProperty.call(o, k)) { n++; } } return n; }
        exports.pick = pick;
        JS;

    public function testHasOwnPropertyIsFusedWhenProved(): void
    {
        $php = $this->emit(self::HAS_OWN_MODULE, 'pick', Assumptions::closedBuild());
        $this->assertStringContainsString('Ops::hasOwn', $php);
        $this->assertStringNotContainsString("'call'", $php);
    }

    public function testHasOwnPropertyIsNotFusedByDefault(): void
    {
        $php = $this->emit(self::HAS_OWN_MODULE, 'pick', Assumptions::none());
        $this->assertStringNotContainsString('Ops::hasOwn', $php);
    }

    /** @return iterable<string, array{0: string}> */
    public static function unprovableModules(): iterable
    {
        yield 'the binding is reassigned later' => [<<<'JS'
            var hasOwnProperty = Object.prototype.hasOwnProperty;
            function pick(o) { var n = 0; for (var k in o) { if (hasOwnProperty.call(o, k)) { n++; } } return n; }
            hasOwnProperty = function () { return false; };
            exports.pick = pick;
            JS];
        yield 'it was never the builtin' => [<<<'JS'
            var hasOwnProperty = function () { return true; };
            function pick(o) { var n = 0; for (var k in o) { if (hasOwnProperty.call(o, k)) { n++; } } return n; }
            exports.pick = pick;
            JS];
        yield 'it is a different builtin' => [<<<'JS'
            var hasOwnProperty = Object.prototype.propertyIsEnumerable;
            function pick(o) { var n = 0; for (var k in o) { if (hasOwnProperty.call(o, k)) { n++; } } return n; }
            exports.pick = pick;
            JS];
        yield 'a local shadows the module binding' => [<<<'JS'
            var hasOwnProperty = Object.prototype.hasOwnProperty;
            function pick(o) { var hasOwnProperty = function () { return false; }; var n = 0;
              for (var k in o) { if (hasOwnProperty.call(o, k)) { n++; } } return n; }
            exports.pick = pick;
            JS];
    }

    #[DataProvider('unprovableModules')]
    public function testHasOwnPropertyIsNotFusedWithoutProof(string $module): void
    {
        $php = $this->emit($module, 'pick', Assumptions::closedBuild());
        $this->assertStringNotContainsString('Ops::hasOwn', $php);
    }

    // ---- fresh-object stores -----------------------------------------------

    public function testFreshObjectWritesBecomeStores(): void
    {
        $php = $this->emit(
            'function copy(src) { var out = {}; for (var k in src) { out[k] = src[k]; } return out; }'
                . 'exports.copy = copy;',
            'copy',
            Assumptions::closedBuild()
        );
        $this->assertStringContainsString('Ops::putOwn', $php);
        $this->assertStringNotContainsString('setMember', $php);
    }

    public function testFreshObjectWritesAreNotSpecializedByDefault(): void
    {
        $php = $this->emit(
            'function copy(src) { var out = {}; for (var k in src) { out[k] = src[k]; } return out; }'
                . 'exports.copy = copy;',
            'copy',
            Assumptions::closedBuild()
        );
        $plain = $this->emit(
            'function copy(src) { var out = {}; for (var k in src) { out[k] = src[k]; } return out; }'
                . 'exports.copy = copy;',
            'copy',
            Assumptions::none()
        );
        $this->assertStringContainsString('Ops::putOwn', $php);
        $this->assertStringNotContainsString('Ops::putOwn', $plain);
    }

    /** @return iterable<string, array{0: string}> */
    public static function unprovableObjects(): iterable
    {
        yield 'the object escapes before the write' => [
            'function f(src, sink) { var out = {}; sink(out); out.a = 1; return out; } exports.f = f;',
        ];
        yield 'the local is not always an object literal' => [
            'function f(src, alt) { var out = {}; if (alt) { out = alt; } out.a = 1; return out; } exports.f = f;',
        ];
        yield 'the local is a parameter' => [
            'function f(out) { out.a = 1; return out; } exports.f = f;',
        ];
        yield 'the object escapes inside the loop that writes it' => [
            'function f(src, sink) { var out = {}; for (var k in src) { sink(out); out[k] = src[k]; } return out; } exports.f = f;',
        ];
    }

    #[DataProvider('unprovableObjects')]
    public function testFreshObjectWritesAreNotSpecializedWithoutProof(string $module): void
    {
        $php = $this->emit($module, 'f', Assumptions::closedBuild());
        $this->assertStringNotContainsString('Ops::putOwn', $php);
    }

    // ---- behaviour ---------------------------------------------------------

    /** @return iterable<string, array{0: mixed, 1: string}> */
    public static function behaviourCases(): iterable
    {
        yield 'hasOwnProperty ignores inherited keys' => [
            1,
            'var hasOwnProperty = Object.prototype.hasOwnProperty;'
            . 'function A() {} A.prototype.inherited = 1;'
            . 'function pick(o) { var n = 0; for (var k in o) { if (hasOwnProperty.call(o, k)) { n++; } } return n; }'
            . 'var o = new A(); o.own = 1; exports.result = pick(o);',
        ];
        yield 'hasOwnProperty coerces a non-object receiver' => [
            true,
            'var hasOwnProperty = Object.prototype.hasOwnProperty;'
            . 'function has(o, k) { return hasOwnProperty.call(o, k); }'
            . 'exports.result = has("ab", "0");',
        ];
        yield 'hasOwnProperty coerces the key' => [
            true,
            'var hasOwnProperty = Object.prototype.hasOwnProperty;'
            . 'function has(o, k) { return hasOwnProperty.call(o, k); }'
            . 'exports.result = has({ "1": true }, 1);',
        ];
        yield 'a fresh-object store keeps insertion order' => [
            'z,a',
            'function copy(src) { var out = {}; for (var k in src) { out[k] = src[k]; } return out; }'
            . 'var ks = []; var c = copy({ z: 1, a: 2 }); for (var k in c) { ks.push(k); } exports.result = ks.join(",");',
        ];
        yield 'a fresh-object store handles numeric keys' => [
            'v',
            'function copy(src) { var out = {}; for (var k in src) { out[k] = src[k]; } return out; }'
            . 'exports.result = copy({ "0": "v" })["0"];',
        ];
        yield 'a fresh-object store handles a null value' => [
            true,
            'function copy(src) { var out = {}; for (var k in src) { out[k] = src[k]; } return out; }'
            . 'exports.result = copy({ a: null }).a === null;',
        ];
    }

    /** The specialized code must agree with the interpreter, always. */
    #[DataProvider('behaviourCases')]
    public function testSpecializedCodeAgreesWithTheInterpreter(mixed $expected, string $moduleBody): void
    {
        $wrapped = "(function (exports, require, module) {\n$moduleBody\n})";
        $harness = "var exports = {}; ($wrapped)(exports); exports.result";

        $interpreted = (new Engine())->evaluate($harness);
        $this->assertSame($expected, $interpreted, 'the interpreted run already disagrees with the expectation');

        // Now the same program with every function the emitter accepts replaced.
        $facts = ModuleFacts::scan($moduleBody);
        $ids = [];
        $template = Compiler::compile(
            $harness,
            function (object $node, $ctx, bool $isProgram) use ($facts, &$ids): ?string {
                if ($isProgram) {
                    return null;
                }
                try {
                    $closure = (new FunctionEmitter($ctx, Assumptions::closedBuild(), $facts))->emit($node);
                } catch (Unsupported) {
                    return null;
                }
                $php = (new \PhpParser\PrettyPrinter\Standard())->prettyPrintExpr($closure);
                $id = 'assume:' . hash('xxh128', $php);
                if (!\PhpJs\Builtins\BuiltinRegistry::hasHost($id)) {
                    \PhpJs\Builtins\BuiltinRegistry::registerHost([$id => eval('return ' . $php . ';')]);
                }
                $ids[] = $id;
                return $id;
            }
        );
        $this->assertNotSame([], $ids, 'nothing was specialized, so this proves nothing');
        $this->assertSame($expected, (new Engine())->runTemplate($template));
    }
}
