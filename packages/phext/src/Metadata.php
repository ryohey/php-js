<?php

declare(strict_types=1);

namespace PhpJs\Phext;

use PhpJs\Node\NodeHost;
use PhpJs\Runtime\Conversions;
use PhpJs\Runtime\JSObject;

/**
 * `export const metadata = { title, description }` on a page or layout, and
 * what happens to it.
 *
 * The same idea as Next.js's metadata export, kept to the two fields a
 * server-rendered document actually needs. A page's values win over its
 * layouts', so a root layout can set a site-wide default and a page can
 * override it.
 *
 * Applied by *substitution into the rendered `<head>`* rather than by handing
 * the values to a component, which is worth a note because it is string
 * surgery on React's output. The alternative is worse: a layout would have to
 * accept and render metadata props for every field, and a page's title could
 * then only ever come from a layout that already knew about it. Injection
 * keeps `<title>` where the page that owns it can set it. Nothing is inserted
 * if the document has no `<head>`, so a fragment-rendering route is untouched.
 */
final class Metadata
{
    /** Fields understood, in the order they are emitted. */
    private const FIELDS = ['title', 'description'];

    /**
     * @param  JSObject $exports a page or layout module's exports
     * @return array<string, string>
     */
    public static function from(NodeHost $host, JSObject $exports): array
    {
        $vm = $host->vm();
        $metadata = $exports->get('metadata', $vm);
        if (!$metadata instanceof JSObject) {
            return [];
        }
        $out = [];
        foreach (self::FIELDS as $field) {
            $value = $metadata->get($field, $vm);
            if (!$value instanceof \PhpJs\Runtime\JSUndefined) {
                $out[$field] = Conversions::toString($vm, $value);
            }
        }
        return $out;
    }

    /** @param array<string, string> $metadata */
    public static function apply(string $html, array $metadata): string
    {
        $tags = '';
        if (isset($metadata['title'])) {
            $tags .= '<title>' . self::esc($metadata['title']) . '</title>';
        }
        if (isset($metadata['description'])) {
            $tags .= '<meta name="description" content="' . self::esc($metadata['description']) . '"/>';
        }
        if ($tags === '') {
            return $html;
        }
        $at = strpos($html, '<head>');
        return $at === false ? $html : substr_replace($html, $tags, $at + 6, 0);
    }

    private static function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
