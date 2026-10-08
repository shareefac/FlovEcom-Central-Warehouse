<?php

declare(strict_types=1);

namespace CW\Ui;

/**
 * Renders the PHP templates of src/Ui/views. A template sees ONLY its own variables plus the
 * helpers below, all of which return HTML-safe text:
 *
 *   $e($v)  escape          $n($v)  integer with thousands separators   $dec($v) decimal, no trailing zeros
 *   $dt($v) 'Y-m-d H:i' (UTC, for technical blocks and files)           $pct($part, $whole)   $u($path, $query) URL for an attribute
 *   $partial($name, $vars)  another template (already-escaped output)
 *
 * and the plain-words helpers (plan §8.2; the words come from Words). A template variable may not be named like a helper
 * (render() refuses it: it would silently replace one or the other):
 *
 *   $word($group, $code)    the word for a code ("Strong match" for BAND Key)
 *   $say($group, $code, ...$args)   a word with its %s filled in (whole numbers get thousands separators)
 *   $money($v)  "£10,500.00"   $day($v) "7 Oct 2026"   $when($v) "7 Oct 2026, 10:26" (UK time, never "UTC")
 *   $jobs($roles)           "Admin · Matching lead (off)"
 *   $chip($tone, $text)     a status chip: icon shape + word (tones: Words::TONES)
 *   $stateChip($group, $code)  the chip of a status code (its word and tone from Words)
 *   $intro($page, $who)     the page's one-sentence intro (Words::PAGE_INTRO; $who = "You can look; <who> change this.")
 *   $explain($key, $label)  a "?" that unfolds one Words::HELP text (a <details>: no script)
 *   $cards($cards, $group, $tone, $id)   the task board: one row per task (job number, title, where, status, how many, "What happens:",
 *                           one button), under the group's title
 *   $empty($title, $text, $href, $button)   an empty state that says why and what to do next
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
            'pct' => static fn (int|float $part, int|float $whole): string => Html::e(Html::pct($part, $whole)),
            'u' => static fn (string $path, array $query = []): string => Html::e(Html::url($path, $query)),
            'partial' => fn (string $name, array $v = []): string => $this->render($name, $v),
            'word' => static fn (string $group, ?string $code): string => Html::e(Words::of($group, $code)),
            'say' => static fn (string $group, string $code, string|int|float ...$args): string => Html::e(Words::say($group, $code, ...$args)),
            'money' => static fn (mixed $v): string => Html::e(Html::money($v)),
            'day' => static fn (mixed $v): string => Html::e(Html::day($v === null ? null : (string) $v)),
            'when' => static fn (mixed $v): string => Html::e(Html::when($v === null ? null : (string) $v)),
            'jobs' => static fn (array $roles): string => Html::e(Words::roles(array_values(array_map('strval', $roles)))),
            'chip' => static fn (string $tone, string $text): string => Html::chip($tone, $text),
            'stateChip' => static fn (string $group, ?string $code): string => Html::chip(Words::tone($group, $code), Words::of($group, $code)),
            'intro' => static fn (string $page, ?string $lookOnly = null): string => Html::intro(Words::intro($page, $lookOnly)),
            'explain' => static fn (string $key, ?string $label = null): string => Html::help($key, $label),
            // The task board (design v4): one group of rows, its title in its colour; $group null = no title.
            'cards' => fn (array $cards, ?string $group = null, string $tone = 'info', string $id = 'tasks'): string
                => $this->render('cards', ['list' => $cards, 'group' => $group, 'tone' => $tone, 'id' => $id]),
            'empty' => static fn (string $title, string $text = '', ?string $href = null, ?string $button = null): string
                => Html::emptyState($title, $text, $href, $button),
        ];
        $clash = array_keys(array_intersect_key($vars + $this->shared, $helpers));
        if ($clash !== []) {
            // A variable named like a helper would silently become the helper (or the other way round): fail loudly.
            throw new \InvalidArgumentException("template {$template}: variable named like a helper: " . implode(', ', $clash));
        }
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
