<?php

declare(strict_types=1);

namespace PhpJs\Phext\Tests;

use PhpJs\Phext\PageCache;
use PHPUnit\Framework\TestCase;

/**
 * Incremental static regeneration, which here means exactly two things: a
 * page renders once and is then served from a file, and an entry older than
 * its TTL is re-rendered by whichever request finds it stale.
 *
 * There is no stale-while-revalidate and there is no background worker, so
 * the tests that would exist for those do not. What replaces them is the
 * safety properties — a 404 never becomes a cached page, a hostile path never
 * becomes a file — because those are what a cache that writes to disk under
 * the document root actually risks getting wrong.
 */
final class PageCacheTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/phext-cache-' . getmypid() . '-' . uniqid();
        mkdir($this->dir, 0o777, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->dir)) {
            self::removeTree($this->dir);
        }
    }

    public function testTheFirstRequestRendersAndTheSecondDoesNot(): void
    {
        $cache = new PageCache($this->dir);
        $renders = 0;
        $render = function () use (&$renders): string {
            $renders++;
            return '<p>rendered</p>';
        };

        $this->assertSame('<p>rendered</p>', $cache->get('/docs/', $render));
        $this->assertSame(PageCache::MISS, $cache->lastStatus);
        $this->assertSame('<p>rendered</p>', $cache->get('/docs/', $render));
        $this->assertSame(PageCache::HIT, $cache->lastStatus);
        $this->assertSame(1, $renders, 'the second request re-rendered');
    }

    public function testAnExpiredEntryIsRegeneratedByTheRequestThatFindsIt(): void
    {
        $cache = new PageCache($this->dir, 1);
        $cache->get('/', static fn (): string => 'first');
        $this->assertSame('first', $cache->get('/', static fn (): string => 'second'));

        // Backdate past the TTL rather than sleeping through it.
        touch($cache->fileFor('/'), time() - 5);
        clearstatcache();

        // The whole of ISR here: the request that finds it stale pays for the
        // re-render and gets the fresh page, rather than being served the old
        // one while something else refreshes it.
        $this->assertSame('second', $cache->get('/', static fn (): string => 'second'));
        $this->assertSame(PageCache::MISS, $cache->lastStatus);
        $this->assertSame('second', $cache->get('/', static fn (): string => 'third'));
        $this->assertSame(PageCache::HIT, $cache->lastStatus);
    }

    public function testNoTtlMeansTheEntryNeverExpiresOnItsOwn(): void
    {
        $cache = new PageCache($this->dir);
        $cache->get('/', static fn (): string => 'first');
        touch($cache->fileFor('/'), time() - 86400 * 365);
        clearstatcache();
        $this->assertSame('first', $cache->get('/', static fn (): string => 'second'));
    }

    public function testTheLayoutIsTheStaticExportsLayout(): void
    {
        $cache = new PageCache($this->dir);
        $cache->get('/', static fn (): string => 'home');
        $cache->get('/docs/getting-started/', static fn (): string => 'doc');

        // Same shape an export writes, which is what lets a web server serve
        // a hit without starting PHP at all.
        $this->assertFileExists($this->dir . '/index.html');
        $this->assertFileExists($this->dir . '/docs/getting-started/index.html');
    }

    public function testARefusedPageIsServedButNotStored(): void
    {
        $cache = new PageCache($this->dir);
        $html = $cache->get(
            '/nope/',
            static fn (): string => '<p>not found</p>',
            static fn (): bool => false
        );
        $this->assertSame('<p>not found</p>', $html);
        $this->assertFileDoesNotExist($cache->fileFor('/nope/'));
        // ...and it leaves nothing behind at all, lock file included.
        $this->assertSame([], glob($this->dir . '/.locks/*') ?: []);
        $this->assertFalse(is_dir($this->dir . '/nope'));
    }

    /** @return iterable<string, array{0: string}> */
    public static function unsafePaths(): iterable
    {
        yield 'parent traversal' => ['/../secret/'];
        yield 'encoded traversal' => ['/a/../../etc/'];
        yield 'dotfile' => ['/.git/config'];
        yield 'hidden directory' => ['/.well-known/x/'];
        yield 'null byte' => ["/a\0b/"];
        yield 'backslash' => ['/a\\b/'];
        yield 'space' => ['/a b/'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unsafePaths')]
    public function testAnUnsafePathIsNeverWrittenToDisk(string $path): void
    {
        $cache = new PageCache($this->dir);
        $this->assertSame('', $cache->fileFor($path), "fileFor accepted $path");

        $html = $cache->get($path, static fn (): string => 'body');
        $this->assertSame('body', $html, 'the page should still be served');
        $this->assertSame(PageCache::BYPASS, $cache->lastStatus);
        $this->assertSame([], glob($this->dir . '/*') ?: []);
    }

    public function testClearingRemovesPagesAndLocks(): void
    {
        $cache = new PageCache($this->dir);
        $cache->get('/', static fn (): string => 'home');
        $cache->get('/docs/', static fn (): string => 'docs');
        $this->assertGreaterThan(0, $cache->clear());
        $this->assertFileDoesNotExist($this->dir . '/index.html');
        $this->assertFileDoesNotExist($this->dir . '/docs/index.html');
    }

    public function testClearingOnePageLeavesTheRest(): void
    {
        $cache = new PageCache($this->dir);
        $cache->get('/', static fn (): string => 'home');
        $cache->get('/docs/', static fn (): string => 'docs');
        $cache->clear('/docs/');
        $this->assertFileExists($this->dir . '/index.html');
        $this->assertFileDoesNotExist($this->dir . '/docs/index.html');
    }

    public function testTheRewriteOnlyMatchesGetsWithAFilePresent(): void
    {
        $rules = (new PageCache($this->dir))->htaccess('/cache');
        $this->assertStringContainsString('%{REQUEST_METHOD} ^GET$', $rules);
        $this->assertStringContainsString('/cache/%{REQUEST_URI}/index.html -f', $rules);
    }

    private static function removeTree(string $dir): void
    {
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
