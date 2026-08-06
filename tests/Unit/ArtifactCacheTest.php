<?php

declare(strict_types=1);

namespace PhpJs\Tests\Unit;

use PhpJs\Builtins\BuiltinRegistry;
use PhpJs\Cache\ArtifactCache;
use PhpJs\Compiler\Compiler;
use PhpJs\Engine;
use PHPUnit\Framework\TestCase;

/**
 * The compile cache (`PhpJs\Cache\ArtifactCache`, DESIGN.md §11.1).
 *
 * The claims worth checking are that a hit produces the same program a
 * compile would, and that every kind of miss falls through to compiling
 * rather than failing — a cache whose absence is an error is not a cache.
 */
final class ArtifactCacheTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/phpjs-artifact-' . getmypid() . '-' . uniqid();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->dir)) {
            rmdir($this->dir);
        }
    }

    public function testACachedTemplateRunsAsTheSourceWould(): void
    {
        $source = 'var x = 6 * 7; x;';
        ArtifactCache::write($this->dir, ArtifactCache::contentHash($source), Compiler::compile($source));

        $template = ArtifactCache::compile($source, $this->dir);
        $this->assertSame(42, (new Engine())->runTemplate($template));
    }

    public function testAMissCompilesInstead(): void
    {
        // Three ways to miss, none of which may be an error: no directory at
        // all, a directory with nothing in it, and a directory holding some
        // *other* source's artifact.
        $this->assertSame(3, (new Engine())->runTemplate(ArtifactCache::compile('1 + 2;', null)));
        $this->assertSame(3, (new Engine())->runTemplate(ArtifactCache::compile('1 + 2;', $this->dir)));

        ArtifactCache::write($this->dir, ArtifactCache::contentHash('something else'), Compiler::compile('99;'));
        $this->assertSame(3, (new Engine())->runTemplate(ArtifactCache::compile('1 + 2;', $this->dir)));
    }

    public function testEditingTheSourceMissesRatherThanServingTheOldOne(): void
    {
        ArtifactCache::write($this->dir, ArtifactCache::contentHash('1;'), Compiler::compile('1;'));

        // The whole point of content addressing: a stale artifact is never
        // found, rather than found and detected.
        $this->assertSame(2, (new Engine())->runTemplate(ArtifactCache::compile('2;', $this->dir)));
    }

    public function testACorruptArtifactMissesRatherThanThrowing(): void
    {
        mkdir($this->dir, 0o777, true);
        $hash = ArtifactCache::contentHash('1 + 1;');
        file_put_contents(ArtifactCache::fileFor($this->dir, $hash), "<?php\n\nreturn 'not an artifact';\n");

        $this->assertNull(ArtifactCache::read($this->dir, $hash));
        $this->assertSame(2, (new Engine())->runTemplate(ArtifactCache::compile('1 + 1;', $this->dir)));
    }

    public function testNativesTravelWithTheirTemplateAndRegisterOnce(): void
    {
        $id = ArtifactCache::functionId('deadbeef', 0, 'answer');
        $this->assertFalse(BuiltinRegistry::hasHost($id));

        $this->assertSame(1, ArtifactCache::registerNatives([$id => static fn (): int => 42]));
        $this->assertTrue(BuiltinRegistry::hasHost($id));
        // Registering the same artifact twice in one process is not an error.
        $this->assertSame(0, ArtifactCache::registerNatives([$id => static fn (): int => 42]));
    }

    public function testAFunctionIdIsStableAndDistinguishesItsParts(): void
    {
        $this->assertSame(
            ArtifactCache::functionId('abc', 3, 'render'),
            ArtifactCache::functionId('abc', 3, 'render')
        );
        $this->assertNotSame(
            ArtifactCache::functionId('abc', 3, 'render'),
            ArtifactCache::functionId('abc', 4, 'render')
        );
        $this->assertNotSame(
            ArtifactCache::functionId('abc', 3, 'render'),
            ArtifactCache::functionId('abd', 3, 'render')
        );
        // Anonymous functions are common and must not collide by name.
        $this->assertStringNotContainsString(':', substr(ArtifactCache::functionId('abc', 0, ''), 4));
    }

    public function testTheEnginesOwnLibraryRoundTripsThroughACache(): void
    {
        Engine::cacheEcmaScriptLibrary($this->dir);
        $this->assertCount(1, glob($this->dir . '/*.php') ?: []);

        // The read side is a static process-wide cache, so this cannot prove
        // "compiled nothing" in-process. What it can prove is that the file
        // is a valid artifact for exactly the library the engine would run.
        $artifact = ArtifactCache::read(
            $this->dir,
            ArtifactCache::contentHash(Engine::ecmaScriptLibrarySource())
        );
        $this->assertNotNull($artifact);
        $this->assertSame([], $artifact['natives']);
        $this->assertNotSame([], $artifact['template']);
    }
}
