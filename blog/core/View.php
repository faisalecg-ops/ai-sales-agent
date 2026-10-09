<?php
declare(strict_types=1);

/** Renders the theme's templates (themes/default/*.php) inside the page layout. */
final class View
{
    public static function render(string $template, array $vars = []): string
    {
        $file = BLOG_ROOT . '/themes/default/' . $template . '.php';
        extract($vars, EXTR_SKIP);
        ob_start();
        require $file;
        return (string)ob_get_clean();
    }

    /** A full page: the template's content inside layout.php. */
    public static function page(string $template, array $vars, array $seo): string
    {
        $content = self::render($template, $vars);
        return self::render('layout', $vars + ['content' => $content, 'seo' => $seo]);
    }

    /** "Build Asia" style card for an article. */
    public static function card(array $a, string $variant = 'standard', bool $eager = false): string
    {
        return self::render('partials/card', ['a' => $a, 'variant' => $variant, 'eager' => $eager]);
    }
}
