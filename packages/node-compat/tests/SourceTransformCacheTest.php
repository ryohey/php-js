<?php

declare(strict_types=1);

namespace PhpJs\Node\Tests;

use PhpJs\Cache\ArtifactCache;
use PhpJs\Node\NodeHost;
use PhpJs\Runtime\Conversions;
use PHPUnit\Framework\TestCase;

/**
 * Caching a module whose source is transformed before it is compiled.
 *
 * The property under test is a performance one that is invisible when it
 * breaks: the cache is keyed on the file *as it is on disk*, so a cache hit
 * never has to run the transform. Keying on the transform's output instead
 * would mean stripping a TypeScript file to find out that its compiled form
 * was already sitting on disk — about two seconds of booting a second engine
 * to answer a question the cache had already answered.
 *
 * The correctness half of that bargain is the fingerprint: the transform
 * declares what about it would change its output, and that goes into the key.
 * Both halves are here, because each is useless without the other.
 */
final class SourceTransformCacheTest extends TestCase
{
    private string $root = '';
    private string $cacheDir = '';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/phpjs-transform-' . getmypid() . '-' . uniqid();
        mkdir($this->root, 0o777, true);
        // Canonical, because NodeHost canonicalizes its own root and the
        // module paths it hands back are relative to that -- on macOS the
        // temp directory is a symlink, so the two differ.
        $this->root = realpath($this->root) ?: $this->root;
        $this->cacheDir = $this->root . '/cache';
        mkdir($this->cacheDir, 0o777, true);
        file_put_contents($this->root . '/mod.weird', 'module.exports = MARKER;');
    }

    protected function tearDown(): void
    {
        self::removeTree($this->root);
    }

    /**
     * A host whose `.weird` files have `MARKER` replaced with `$value`,
     * counting how many times the transform actually runs.
     */
    private function host(string $value, string $fingerprint, ?int &$calls = null): NodeHost
    {
        $calls = 0;
        $host = new NodeHost($this->root, captureOutput: true, aotCacheDir: $this->cacheDir);
        $host->modules->registerSourceTransform(
            'weird',
            static function (string $source) use ($value, &$calls): string {
                $calls++;
                return str_replace('MARKER', $value, $source);
            },
            $fingerprint,
        );
        return $host;
    }

    private function load(NodeHost $host): string
    {
        return Conversions::toString($host->vm(), $host->requireModule('./mod.weird'));
    }

    public function testATransformedModuleIsCachedAndReplayed(): void
    {
        $first = $this->host('"one"', 'v1', $calls);
        $this->assertSame('one', $this->load($first));
        $this->assertSame(1, $calls);
        $first->modules->cacheCompiledTemplates($this->cacheDir);

        // A second host, as a later request would be.
        $second = $this->host('"one"', 'v1', $laterCalls);
        $this->assertSame('one', $this->load($second));
        $this->assertSame(0, $second->modules->compileCount, 'the cached template was not used');
        // The point of the whole exercise: a hit runs no transform at all.
        $this->assertSame(0, $laterCalls, 'the transform ran despite a cache hit');
    }

    public function testChangingTheFingerprintInvalidatesTheCache(): void
    {
        $first = $this->host('"one"', 'v1');
        $this->load($first);
        $first->modules->cacheCompiledTemplates($this->cacheDir);

        // Same file on disk, different transform behaviour. Keyed on the file
        // alone this would serve the stale artifact and silently produce the
        // old output forever.
        $second = $this->host('"two"', 'v2', $calls);
        $this->assertSame('two', $this->load($second));
        $this->assertSame(1, $calls);
        $this->assertSame(1, $second->modules->compileCount);
    }

    public function testEditingTheFileInvalidatesTheCache(): void
    {
        $first = $this->host('"one"', 'v1');
        $this->load($first);
        $first->modules->cacheCompiledTemplates($this->cacheDir);

        file_put_contents($this->root . '/mod.weird', 'module.exports = MARKER + "!";');
        $second = $this->host('"one"', 'v1');
        $this->assertSame('one!', $this->load($second));
    }

    public function testAnUntransformedModuleIsKeyedOnItsFileAlone(): void
    {
        // No transform for `.js`, so the key is the file's own hash -- which
        // is what `php-transpile` writes its artifacts under. If these two
        // ever disagreed, every ahead-of-time lookup would silently miss.
        file_put_contents($this->root . '/plain.js', 'module.exports = 41 + 1;');
        $host = new NodeHost($this->root, captureOutput: true, aotCacheDir: $this->cacheDir);
        $this->assertSame(42, $host->requireModule('./plain.js'));

        $expected = ArtifactCache::contentHash('module.exports = 41 + 1;');
        $written = $host->modules->cacheCompiledTemplates($this->cacheDir);
        $this->assertSame(
            ArtifactCache::fileFor($this->cacheDir, $expected),
            $written[$this->root . '/plain.js'],
        );
    }

    public function testOnlyModulesThisLoaderCompiledAreWritten(): void
    {
        file_put_contents($this->root . '/plain.js', 'module.exports = 1;');
        $host = new NodeHost($this->root, captureOutput: true, aotCacheDir: $this->cacheDir);
        $host->requireModule('./plain.js');

        // A module that arrived preloaded was never hashed, so there is no key
        // to write it under and nothing pretends otherwise.
        $host->modules->preloadTemplates(['/somewhere/else.js' => ['name' => 'x']]);
        $written = $host->modules->cacheCompiledTemplates($this->cacheDir);
        $this->assertArrayHasKey($this->root . '/plain.js', $written);
        $this->assertArrayNotHasKey('/somewhere/else.js', $written);
    }

    private static function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $dir . '/' . $name;
            is_dir($path) ? self::removeTree($path) : unlink($path);
        }
        rmdir($dir);
    }
}
