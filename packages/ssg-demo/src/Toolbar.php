<?php

declare(strict_types=1);

namespace PhpJs\Ssg;

/**
 * The strip the demo server injects at the top of every page: where the markup
 * came from, what it cost, and a switch to render the same page the other way.
 *
 * It exists so the numbers are in front of you in the browser rather than in a
 * benchmark you have to trust. Nothing outside this demo needs it — the layout
 * reserves an empty element and a real deployment leaves it empty.
 */
final class Toolbar
{
    /**
     * @param 'aot'|'bytecode'|'static' $mode
     * @param array<string, int>        $options query options in play, e.g. items
     */
    public static function render(
        string $mode,
        Page $page,
        float $bootMs,
        int $modulesCompiled,
        string $reactVersion,
        array $options = [],
    ): string {
        $label = match ($mode) {
            'aot' => 'ahead-of-time PHP',
            'bytecode' => 'interpreted bytecode',
            'static' => 'exported file',
            default => $mode,
        };

        $stats = [
            ['render', $mode === 'static' ? 'file read' : self::ms($page->renderMs)],
            ['boot', self::ms($bootMs)],
            ['html', self::bytes($page->bytes())],
        ];
        if ($modulesCompiled > 0) {
            // Should never happen with a warm build: it means JavaScript is
            // being parsed on a request.
            $stats[] = ['compiled', $modulesCompiled . ' modules'];
        }

        $out = '<div id="' . self::esc(Renderer::METRICS_ID) . '" class="metrics metrics-' . self::esc($mode) . '">';
        $out .= '<span class="metrics-mode">' . self::esc($label) . '</span>';
        $out .= '<span class="metrics-stats">';
        foreach ($stats as [$name, $value]) {
            $out .= '<span class="stat"><span class="stat-name">' . self::esc($name) . '</span>'
                . '<span class="stat-value">' . self::esc($value) . '</span></span>';
        }
        $out .= '</span>';
        $out .= '<span class="metrics-switch">';
        foreach (['aot' => 'AOT', 'bytecode' => 'bytecode', 'static' => 'static'] as $engine => $short) {
            $classes = 'metrics-link' . ($engine === $mode ? ' current' : '');
            $out .= '<a class="' . $classes . '" href="' . self::esc(self::link($page->path, $engine, $options)) . '">'
                . self::esc($short) . '</a>';
        }
        $out .= '</span>';
        $out .= '<span class="metrics-react">React ' . self::esc($reactVersion) . '</span>';
        return $out . '</div>';
    }

    /** A one-line explanation when the static copy has not been exported yet. */
    public static function missingStatic(string $path): string
    {
        return '<div id="' . self::esc(Renderer::METRICS_ID) . '" class="metrics metrics-warn">'
            . '<span class="metrics-mode">no exported copy of ' . self::esc($path) . '</span>'
            . '<span class="metrics-stats"><span class="stat">'
            . '<span class="stat-name">fix</span><span class="stat-value">bin/phpjs-ssg export</span>'
            . '</span></span></div>';
    }

    /** @param array<string, int> $options */
    private static function link(string $path, string $engine, array $options): string
    {
        $query = ['engine' => $engine] + array_map('strval', $options);
        return $path . '?' . http_build_query($query);
    }

    private static function ms(float $ms): string
    {
        return $ms < 10 ? sprintf('%.2f ms', $ms) : sprintf('%.1f ms', $ms);
    }

    private static function bytes(int $bytes): string
    {
        return $bytes >= 1024 * 1024
            ? sprintf('%.1f MB', $bytes / 1024 / 1024)
            : sprintf('%.0f KB', $bytes / 1024);
    }

    private static function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
