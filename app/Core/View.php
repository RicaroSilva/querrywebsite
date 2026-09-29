<?php
declare(strict_types=1);

namespace App\Core;

/** Plain-PHP templates with layouts. All dynamic output must go through e(). */
final class View
{
    private static array $stacks = [];

    /** Templates push per-page assets (scripts/styles) that the layout prints. */
    public static function push(string $stack, string $value): void
    {
        self::$stacks[$stack][$value] = true;
    }

    public static function stack(string $stack): array
    {
        return array_keys(self::$stacks[$stack] ?? []);
    }

    public static function render(string $template, array $data = [], ?string $layout = 'layouts/app'): string
    {
        $content = self::partial($template, $data);
        if ($layout === null) {
            return $content;
        }
        return self::partial($layout, [...$data, 'content' => $content]);
    }

    public static function partial(string $template, array $data = []): string
    {
        $file = base_path('resources/views/' . $template . '.php');
        if (!is_file($file)) {
            throw new \RuntimeException("View not found: $template");
        }
        extract($data, EXTR_SKIP);
        ob_start();
        try {
            include $file;
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
        return (string) ob_get_clean();
    }
}
