<?php

declare(strict_types=1);

namespace PhpJs;

use PhpJs\Cache\ArtifactCache;
use PhpJs\Compiler\Compiler;
use PhpJs\Host\Environment;
use PhpJs\Runtime\Conversions;
use PhpJs\Runtime\JSArray;
use PhpJs\Runtime\JSFunctionBase;
use PhpJs\Runtime\JSObject;
use PhpJs\Runtime\JSPromise;
use PhpJs\Runtime\JSThrowSignal;
use PhpJs\Runtime\JSUndefined;
use PhpJs\Runtime\Realm;
use PhpJs\Vm\Vm;

/**
 * Public facade: compile + run + drain microtasks (DESIGN.md §9).
 * One Engine = one realm = one VM.
 *
 * An Engine is ECMAScript and nothing else. It has the language and the whole
 * standard library, and it has no way to reach the outside world — no
 * `require`, no `process`, no timers, no I/O. Anything beyond the language
 * comes from an `Environment` (see `PhpJs\Host\Environment`), which the engine
 * knows only through that interface.
 */
final class Engine
{
    public readonly Realm $realm;
    public readonly Vm $vm;
    private readonly ?Environment $environment;

    /**
     * @param ?Environment $environment what exists beyond ECMAScript, or null
     *                                  for a sealed language-only runtime
     * @param ?callable    $consoleWriter where `console.*` output goes; the
     *                                  default prints, which is the only thing
     *                                  an Engine does that leaves the process
     */
    public function __construct(?Environment $environment = null, ?callable $consoleWriter = null)
    {
        $this->realm = new Realm();
        $this->vm = new Vm($this->realm);
        if ($consoleWriter !== null) {
            $this->realm->hostWriter = $consoleWriter;
        }
        $this->installEcmaScriptLibrary();
        $this->environment = $environment;
        $environment?->install($this->realm, $this->vm);
    }

    /**
     * Where the JS half of the standard library is cached, process-wide.
     *
     * It is the same file every time and compiling it is a few milliseconds,
     * so no Engine after the first in a process should pay for it. A build
     * step can take even the first one to zero by writing it into a cache
     * directory (`cacheEcmaScriptLibrary()`).
     *
     * @var array<string, mixed>|null
     */
    private static ?array $libraryTemplate = null;

    /** The part of the standard library that is written in JavaScript. */
    public static function ecmaScriptLibrarySource(): string
    {
        return (string)file_get_contents(__DIR__ . '/../js/es-library.js');
    }

    /**
     * Precompile the JS half of the standard library into a cache directory,
     * so constructing an Engine in a later process compiles nothing at all.
     *
     * Ordinary `ArtifactCache` shape, addressed by the library's own content
     * hash — a build tool that already populates a cache directory can put
     * this in the same one, and nothing needs to know it is there.
     *
     * @return string the file written
     */
    public static function cacheEcmaScriptLibrary(string $cacheDir): string
    {
        $source = self::ecmaScriptLibrarySource();
        self::$libraryTemplate ??= Compiler::compile($source);
        return ArtifactCache::write($cacheDir, ArtifactCache::contentHash($source), self::$libraryTemplate);
    }

    /**
     * Load the standard library's compiled form from a cache directory if it
     * has one. Safe to call with a directory that does not, or more than once
     * — a hit only fills the process-wide cache, never clears it.
     */
    public static function warmEcmaScriptLibrary(string $cacheDir): void
    {
        if (self::$libraryTemplate !== null) {
            return;
        }
        $artifact = ArtifactCache::read($cacheDir, ArtifactCache::contentHash(self::ecmaScriptLibrarySource()));
        if ($artifact !== null) {
            self::$libraryTemplate = $artifact['template'];
        }
    }

    private function installEcmaScriptLibrary(): void
    {
        self::$libraryTemplate ??= Compiler::compile(self::ecmaScriptLibrarySource());
        $this->runTemplate(self::$libraryTemplate);
    }

    /**
     * Load a module through the installed environment.
     *
     * The engine has no module system of its own — resolution, format and
     * caching are all the environment's. With none installed there are no
     * modules to load, and asking for one is a programming error rather than
     * a missing file.
     */
    public function importModule(string $specifier, ?string $referrer = null): mixed
    {
        if ($this->environment === null) {
            throw new \LogicException(
                "Cannot import '$specifier': this Engine has no Environment, so it has no modules."
            );
        }
        return $this->environment->loadModule($specifier, $referrer, $this->vm);
    }

    /** @return array<string, mixed> function template (plain array) */
    public function compile(string $source): array
    {
        return Compiler::compile($source);
    }

    /** Evaluate source; returns the completion value as a JS value. */
    public function evaluate(string $source): mixed
    {
        return $this->runTemplate($this->compile($source));
    }

    /** @param array<string, mixed> $template */
    public function runTemplate(array $template): mixed
    {
        try {
            $result = $this->vm->runProgram($template);
            $this->realm->drainMicrotasks($this->vm);
            return $result;
        } catch (JSThrowSignal $e) {
            throw JSException::from($this->vm, $e->value);
        }
    }

    /** Call a JS function value from PHP. */
    public function call(mixed $fn, mixed $thisVal = null, array $args = []): mixed
    {
        try {
            $result = $this->vm->invoke($fn, $thisVal ?? JSUndefined::$undefined, $args);
            $this->realm->drainMicrotasks($this->vm);
            return $result;
        } catch (JSThrowSignal $e) {
            throw JSException::from($this->vm, $e->value);
        }
    }

    /** @return list<mixed> promises rejected with no handler after the last drain */
    public function unhandledRejections(): array
    {
        $out = [];
        foreach ($this->realm->unhandledRejections as $p) {
            if ($p instanceof JSPromise && !$p->handled && $p->state === JSPromise::REJECTED) {
                $out[] = $p->result;
            }
        }
        return $out;
    }

    /** Convert a JS value into plain PHP data (for host consumption). */
    public function toPhp(mixed $v): mixed
    {
        if ($v instanceof JSUndefined) {
            return null;
        }
        if ($v instanceof JSArray) {
            $out = [];
            foreach ($v->toList() as $item) {
                $out[] = $this->toPhp($item);
            }
            return $out;
        }
        if ($v instanceof JSFunctionBase) {
            return '[function ' . $v->name . ']';
        }
        if ($v instanceof JSObject) {
            $out = [];
            foreach ($v->ownEnumerableKeys() as $key) {
                $out[$key] = $this->toPhp($v->get($key, $this->vm));
            }
            return $out;
        }
        return $v;
    }

    /** ToString for host code (e.g. printing REPL results). */
    public function stringify(mixed $v): string
    {
        return Conversions::toString($this->vm, $v);
    }
}
