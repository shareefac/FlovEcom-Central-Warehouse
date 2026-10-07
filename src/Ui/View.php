<?php

declare(strict_types=1);

namespace CW\Ui;

/**
 * Renders the PHP templates of src/Ui/views. A template sees ONLY its own variables plus the
 * helpers below, all of which return HTML-safe text:
 *
 *   $e($v)  escape          $n($v)  integer with thousands separators   $dec($v) decimal, no trailing zeros
 *   $dt($v) 'Y-m-d H:i'     $pct($part, $whole)                        $u($path, $query) URL for an attribute
 *   $partial($name, $vars)  another template (already-escaped output)
 *
 * and `$body` in the layout. Templates never echo anything else: tests/Unit/UiTemplatesTest.php
 * fails the build when a `<?=` prints something that did not pass through one of them, or when a
 * template uses an inline script, style or event handler (the CSP forbids them anyway).
 */
final class View
{
    /** @param array<string, mixed> $shared variables every template gets (csrf token, who, ...) */
    public function __construct(private readonly string $dir, private readonly array $shared = [])
    {
    }

    public static function defaultDir(): string
    {
        return __DIR__ . '/views';
    }

    /** One template, no layout. @param array<string, mixed> $vars */
    public function render(string $template, array $vars = []): string
    {
        if (preg_match('/^[a-z][a-z_]*$/D', $template) !== 1) {
            throw new \InvalidArgumentException('bad template name');
        }
        $file = $this->dir . '/' . $template . '.php';
        if (!is_file($file)) {
            throw new \InvalidArgumentException("no template {$template}");
        }
        $helpers = [
            'e' => Html::e(...),
            'n' => static fn (mixed $v): string => Html::e(Html::int($v)),
            'dec' => static fn (mixed $v): string => Html::e(Html::dec($v)),
            'dt' => static fn (mixed $v): string => Html::e(Html::dt($v === null ? null : (string) $v)),
            'uk' => static fn (mixed $v): string => Html::e(Html::uk($v === null ? null : (string) $v)),
            'pct' => static fn (int|float $part, int|float $whole): string => Html::e(Html::pct($part, $whole)),
            'u' => static fn (string $path, array $query = []): string => Html::e(Html::url($path, $query)),
            'partial' => fn (string $name, array $v = []): string => $this->render($name, $v),
        ];
        $all = $helpers + $vars + $this->shared;
        return (static function (string $__file, array $__vars): string {
            extract($__vars, EXTR_SKIP);
            ob_start();
            try {
                include $__file;
                return (string) ob_get_contents();
            } finally {
                ob_end_clean();
            }
        })($file, $all);
    }

    /** A page: the template inside layout.php. @param array<string, mixed> $vars @param array<string, mixed> $layout */
    public function page(string $template, array $vars, array $layout): string
    {
        $body = $this->render($template, $vars);
        return $this->render('layout', $layout + ['body' => $body]);
    }
}
