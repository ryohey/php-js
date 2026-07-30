<?php

declare(strict_types=1);

namespace PhpJs\Ssg;

/**
 * Render once, on the first request, then serve a file.
 *
 * The shape Next.js calls on-demand ISR, arranged for the hosting this is aimed
 * at: PHP only, no daemon, no background worker, no shared memory beyond
 * opcache. So there is nothing clever here — a hit is a file that exists and is
 * young enough, and a miss renders, writes, and serves.
 *
 * Four properties matter more than features:
 *
 * - **A hit need not reach PHP at all.** `htaccess()` emits a rewrite that hands
 *   the cached file straight to Apache. After the first request the runtime is
 *   out of the loop, which is the entire point and is also why the cache is
 *   written *under the document root* rather than somewhere tidier.
 * - **Writes are atomic.** Render to a temp file in the same directory, then
 *   `rename()`. A reader never sees a half-written page, and on this filesystem
 *   rename is atomic.
 * - **A stampede renders once.** Concurrent misses contend on a lock file; the
 *   winner renders and the losers wait and then serve its output. Without this,
 *   a cold cache plus a burst of traffic means N simultaneous React renders,
 *   which on shared hosting is how a site gets its process limit hit.
 * - **A stale entry is never served silently.** TTL is checked against mtime,
 *   and `null` means no expiry — for a site whose content only changes when you
 *   rebuild, which is the common case here.
 */
final class PageCache
{
    public const HIT = 'HIT';
    public const MISS = 'MISS';
    public const BYPASS = 'BYPASS';
    /** A miss that waited for another request's render and served that. */
    public const WAIT = 'WAIT';

    /** How long to wait for whoever holds the lock, before rendering anyway. */
    private const LOCK_TIMEOUT_SECONDS = 15.0;

    /**
     * Where lock files go. Dot-prefixed, so `fileFor()` -- which rejects a
     * dot-leading segment -- can never address it as a page.
     */
    private const LOCK_DIR = '.locks';

    public string $lastStatus = self::BYPASS;
    public float $lastReadMs = 0.0;

    public function __construct(
        private readonly string $dir,
        /** Seconds before an entry is stale, or null to keep it until cleared. */
        private readonly ?int $ttl = null,
    ) {
    }

    /** Where a path's HTML lives. Mirrors the static export's layout exactly. */
    public function fileFor(string $path): string
    {
        // Before parse_url, which silently rewrites a NUL to an underscore
        // rather than refusing it -- so a malformed path would quietly become a
        // legitimate-looking cache key instead of being turned away.
        if (preg_match('/[\x00-\x1F\x7F]/', $path) === 1) {
            return '';
        }
        $trimmed = trim(parse_url($path, PHP_URL_PATH) ?: '/', '/');
        // A path segment may not climb out of the cache directory, and may not
        // be a dotfile: this string comes from the request line.
        $safe = [];
        foreach ($trimmed === '' ? [] : explode('/', $trimmed) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..' || $segment[0] === '.') {
                return '';
            }
            if (preg_match('/[^A-Za-z0-9._~-]/', $segment) === 1) {
                return '';
            }
            $safe[] = $segment;
        }
        return $this->dir . ($safe === [] ? '' : '/' . implode('/', $safe)) . '/index.html';
    }

    /** The cached HTML, or null if there is no usable entry. */
    public function read(string $path): ?string
    {
        $file = $this->fileFor($path);
        if ($file === '' || !is_file($file)) {
            return null;
        }
        if ($this->ttl !== null && (time() - (int)filemtime($file)) > $this->ttl) {
            return null;
        }
        $started = microtime(true);
        $html = @file_get_contents($file);
        $this->lastReadMs = (microtime(true) - $started) * 1000;
        return $html === false ? null : $html;
    }

    /**
     * Serve $path from the cache, rendering it if needed.
     *
     * @param callable(): string $render  produces the HTML to store
     * @param ?callable(): bool  $storeIf consulted after a render; false means
     *                           serve it but do not cache it, which is how a 404
     *                           avoids becoming a cached page at that path
     */
    public function get(string $path, callable $render, ?callable $storeIf = null): string
    {
        $html = $this->read($path);
        if ($html !== null) {
            $this->lastStatus = self::HIT;
            return $html;
        }

        $file = $this->fileFor($path);
        if ($file === '') {
            // Not cacheable (a path we will not write to disk); render and serve.
            $this->lastStatus = self::BYPASS;
            return $render();
        }

        [$lock, $lockFile] = $this->acquire($path);
        if ($lock === null) {
            // Someone else is rendering this exact page. Wait for them rather
            // than starting a second render of the same thing.
            $html = $this->waitFor($path);
            if ($html !== null) {
                $this->lastStatus = self::WAIT;
                return $html;
            }
            $this->lastStatus = self::MISS;
            return $this->maybeStore($file, $render(), $storeIf);
        }

        $stored = false;
        try {
            // Re-check: the lock may have been held by a render that has since
            // finished and written the file.
            $html = $this->read($path);
            if ($html !== null) {
                $this->lastStatus = self::HIT;
                return $html;
            }
            $this->lastStatus = self::MISS;
            $html = $render();
            $stored = $storeIf === null || $storeIf();
            return $stored ? $this->store($file, $html) : $html;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
            if (!$stored) {
                // Nothing was cached, so nothing needs guarding. Without this a
                // request for any nonexistent path leaves a lock file behind,
                // and enough of those is a full inode table.
                @unlink($lockFile);
            }
        }
    }

    /** @param ?callable(): bool $storeIf */
    private function maybeStore(string $file, string $html, ?callable $storeIf): string
    {
        return $storeIf !== null && !$storeIf() ? $html : $this->store($file, $html);
    }

    /** Write atomically, and return what was written even if the write failed. */
    public function store(string $file, string $html): string
    {
        $dir = dirname($file);
        if (!is_dir($dir) && !mkdir($dir, 0o777, true) && !is_dir($dir)) {
            return $html;   // cannot cache; the page is still good
        }
        // Same directory, so the rename stays on one filesystem and is atomic.
        $temp = $file . '.' . getmypid() . '.tmp';
        if (@file_put_contents($temp, $html) === false) {
            return $html;
        }
        if (!@rename($temp, $file)) {
            @unlink($temp);
        }
        return $html;
    }

    /**
     * @return array{0: resource|null, 1: string} the held lock and its path;
     *         the handle is null when another process holds it
     *
     * Locks live in one flat directory under a hashed name rather than beside
     * the page they guard. Two reasons, and the second is the one that matters:
     * a lock file inside the cache tree would be served as part of it, and
     * creating the page's directory before knowing whether the page will be
     * stored lets a request for any nonexistent path leave a directory behind.
     */
    private function acquire(string $path): array
    {
        $dir = $this->dir . '/' . self::LOCK_DIR;
        if (!is_dir($dir) && !mkdir($dir, 0o777, true) && !is_dir($dir)) {
            return [null, ''];
        }
        $lockFile = $dir . '/' . sha1($path) . '.lock';
        $handle = @fopen($lockFile, 'c');
        if ($handle === false) {
            return [null, $lockFile];
        }
        if (flock($handle, LOCK_EX | LOCK_NB)) {
            return [$handle, $lockFile];
        }
        fclose($handle);
        return [null, $lockFile];
    }

    /** Poll for the lock holder's output. Null if it never arrived. */
    private function waitFor(string $path): ?string
    {
        $deadline = microtime(true) + self::LOCK_TIMEOUT_SECONDS;
        while (microtime(true) < $deadline) {
            usleep(50_000);
            $html = $this->read($path);
            if ($html !== null) {
                return $html;
            }
        }
        return null;
    }

    /** Drop one page, or the whole cache. Returns how many files went. */
    public function clear(?string $path = null): int
    {
        if ($path !== null) {
            $file = $this->fileFor($path);
            if ($file === '') {
                return 0;
            }
            $removed = 0;
            foreach ([$file, $this->dir . '/' . self::LOCK_DIR . '/' . sha1($path) . '.lock'] as $target) {
                if (is_file($target) && @unlink($target)) {
                    $removed++;
                }
            }
            // The directory existed only to hold this page; leaving it behind
            // would litter the cache with empty trees.
            @rmdir(dirname($file));
            return $removed;
        }
        return is_dir($this->dir) ? $this->removeTree($this->dir, false) : 0;
    }

    private function removeTree(string $dir, bool $removeSelf = true): int
    {
        $removed = 0;
        foreach (scandir($dir) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $target = $dir . '/' . $name;
            if (is_dir($target)) {
                $removed += $this->removeTree($target);
            } elseif (@unlink($target)) {
                $removed++;
            }
        }
        if ($removeSelf) {
            @rmdir($dir);
        }
        return $removed;
    }

    /**
     * The Apache rewrite that makes a hit bypass PHP entirely.
     *
     * Written next to the cache rather than applied, because whether
     * `AllowOverride` permits it is the host's business and silently depending
     * on it would be worse than saying so.
     */
    public function htaccess(string $cacheUrlPath): string
    {
        return <<<HTACCESS
        # Generated by phpjs-ssg. Serve a cached page without starting PHP.
        <IfModule mod_rewrite.c>
        RewriteEngine On

        # Only ever for plain GETs: anything else must reach the application.
        RewriteCond %{REQUEST_METHOD} ^GET\$
        # ...and only when the cached file is actually there.
        RewriteCond %{DOCUMENT_ROOT}$cacheUrlPath/%{REQUEST_URI}/index.html -f
        RewriteRule ^(.*)\$ $cacheUrlPath/\$1/index.html [L]

        # Everything else goes to the front controller, which renders and caches.
        RewriteCond %{REQUEST_FILENAME} !-f
        RewriteRule ^(.*)\$ index.php [L,QSA]
        </IfModule>

        HTACCESS;
    }
}
