<?php

declare(strict_types=1);

namespace PhpJs\Tests\Unit;

use PhpJs\Engine;
use PhpJs\Host\Environment;
use PhpJs\Runtime\JSObject;
use PhpJs\Runtime\Realm;
use PhpJs\Vm\Vm;
use PHPUnit\Framework\TestCase;

/**
 * The line between the language and its host (`PhpJs\Host\Environment`).
 *
 * An Engine on its own is ECMAScript and nothing else: the whole standard
 * library, and no way to reach the outside world. Everything past that comes
 * from an Environment, and the engine knows none of them by name — which is
 * what makes `packages/node-compat` replaceable rather than assumed.
 *
 * Worth asserting rather than believing, because both halves fail silently.
 * A standard builtin that quietly moved into an environment would work
 * perfectly until someone wrote a second environment and found `Map` missing;
 * a host global that leaked into core would work perfectly until someone
 * needed a sandbox and found `require` in it.
 */
final class EnvironmentBoundaryTest extends TestCase
{
    private function truthy(Engine $engine, string $source): bool
    {
        return $engine->evaluate($source) === true;
    }

    /** @return list<array{0: string}> */
    public static function standardLibrary(): array
    {
        return array_map(static fn (string $e): array => [$e], [
            // ES2015 surface that a host must not have to supply.
            'typeof Object.assign === "function"',
            'typeof Object.is === "function"',
            'typeof Object.entries === "function"',
            'typeof Array.prototype.includes === "function"',
            'typeof Array.prototype.find === "function"',
            'typeof String.prototype.padStart === "function"',
            'typeof String.prototype.repeat === "function"',
            'typeof Number.isInteger === "function"',
            'typeof Math.clz32 === "function"',
            'typeof Math.hypot === "function"',
            'typeof Map === "function"',
            'typeof Set === "function"',
            'typeof WeakMap === "function"',
            'typeof Symbol === "function"',
            'typeof Promise === "function"',
            'typeof Uint8Array === "function"',
            // ...and that it actually works, not merely exists.
            'Object.assign({}, {a: 1}, {b: 2}).b === 2',
            '[1, 2, 3].includes(2)',
            '"x".padStart(3, "-") === "--x"',
            'Math.clz32(1) === 31',
            'new Map([[1, "a"]]).get(1) === "a"',
            'new Set([1, 2, 2]).size === 2',
        ]);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('standardLibrary')]
    public function testAnEngineHasTheWholeStandardLibraryWithNoEnvironment(string $expression): void
    {
        $this->assertTrue(
            $this->truthy(new Engine(), $expression),
            "$expression is false in a bare Engine, so a host is supplying standard library surface"
        );
    }

    /** @return list<array{0: string}> */
    public static function hostGlobals(): array
    {
        return array_map(static fn (string $n): array => [$n], [
            'require', 'process', 'global', 'setTimeout', 'setInterval',
            'clearTimeout', 'queueMicrotask', 'fetch', 'Deno', '__dirname', '__filename',
        ]);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('hostGlobals')]
    public function testAnEngineWithNoEnvironmentCannotReachOutside(string $name): void
    {
        $this->assertTrue(
            $this->truthy(new Engine(), "typeof $name === 'undefined'"),
            "$name exists in a bare Engine, so the runtime is assuming a host"
        );
    }

    public function testImportingWithoutAnEnvironmentIsAnError(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('has no Environment');
        (new Engine())->importModule('anything');
    }

    public function testAnEnvironmentDecidesWhatElseExists(): void
    {
        $engine = new Engine(new class () implements Environment {
            public function install(Realm $realm, Vm $vm): void
            {
                $realm->globalObject->defineOwnData('answer', 42, JSObject::W | JSObject::C);
            }

            public function loadModule(string $specifier, ?string $referrer, Vm $vm): mixed
            {
                return "loaded:$specifier";
            }
        });

        $this->assertTrue($this->truthy($engine, 'answer === 42'));
        $this->assertSame('loaded:some/thing', $engine->importModule('some/thing'));
        // ...and installing one takes nothing away.
        $this->assertTrue($this->truthy($engine, 'new Map().size === 0'));
    }

    public function testTwoEnginesDoNotShareAnEnvironmentsGlobals(): void
    {
        new Engine(new class () implements Environment {
            public function install(Realm $realm, Vm $vm): void
            {
                $realm->globalObject->defineOwnData('leaked', true, JSObject::W | JSObject::C);
            }

            public function loadModule(string $specifier, ?string $referrer, Vm $vm): mixed
            {
                return null;
            }
        });

        // Realms are per-Engine (DESIGN.md §11): one host's globals must not
        // survive into the next request's runtime.
        $this->assertTrue($this->truthy(new Engine(), 'typeof leaked === "undefined"'));
    }
}
