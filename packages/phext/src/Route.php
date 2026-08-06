<?php

declare(strict_types=1);

namespace PhpJs\Phext;

/**
 * One route, as the file tree describes it — before any URL is matched
 * against it.
 *
 * A route is a `page` file plus the `layout` files that enclose it, outermost
 * first, which is exactly the nesting the rendered tree will have. Both are
 * absolute paths; nothing here has opened them.
 */
final class Route
{
    /**
     * @param string       $pattern  the URL shape, with dynamic segments still
     *                               written as `[slug]` — e.g. `/docs/[slug]/`
     * @param string       $pageFile absolute path to the `page.*` file
     * @param list<string> $layouts  absolute paths to enclosing `layout.*`
     *                               files, outermost first
     * @param list<string> $params   dynamic segment names, in order
     */
    public function __construct(
        public readonly string $pattern,
        public readonly string $pageFile,
        public readonly array $layouts = [],
        public readonly array $params = [],
    ) {
    }

    /** Whether this route needs values filled in before it names a real page. */
    public function isDynamic(): bool
    {
        return $this->params !== [];
    }

    /**
     * The concrete URL for a set of parameter values.
     *
     * @param array<string, string> $params
     */
    public function pathFor(array $params = []): string
    {
        $out = preg_replace_callback(
            '/\[([^\]]+)\]/',
            static function (array $m) use ($params): string {
                if (!isset($params[$m[1]])) {
                    throw new \InvalidArgumentException("Missing route parameter: {$m[1]}");
                }
                return rawurlencode($params[$m[1]]);
            },
            $this->pattern
        );
        return (string)$out;
    }
}
