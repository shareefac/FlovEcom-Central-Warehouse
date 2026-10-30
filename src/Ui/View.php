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
 *   $head($title, intro: $page, look: $who, chips:, meta:, aside:, lede:, more:, tag:, eyebrow:, side:, back:)   the statement every page
 *                           opens with (the approved mockups; docs/decisions.md DC1-DC6): the section as an UPPERCASE eyebrow (or
 *                           `eyebrow`, and `tag` after it; or `back`, [href, label], the record's list as a link and "›", the tag after
 *                           it: DC37), the heading
 *                           (`chips` inside it, `aside` beside it), `meta` (state chips), the page's create button on the right; then
 *                           the switched-off strip (no tab row: the sidebar lists the section's pages, DC28).
 *                           No sentence under the heading: `intro` names the page (its words stay in Words::PAGE_INTRO) and only the
 *                           steps of View::INTRO_KEPT draw their line; `look` (who may change this) or `more` (a look-only sentence) is
 *                           a "?" beside the heading (with `aside`'s help: its last line, one "?", DC34). Text arguments are escaped;
 *                           `chips`, `meta` and `aside` are helper output. Every page template draws it once (tests/Unit/UiTemplatesTest.php).
 *   $intro($page, $who)     the page's intro alone, in its two parts (Words::PAGE_INTRO; $who = "You can look; <who> change this.")
 *   $explain($key, $label)  a "?" that unfolds one Words::HELP text (a <details>: no script)
 *   $cards($cards, $notes)  Home's "Waiting for you": one row per task (its number in its colour, its name, one plain line, one button);
 *                           $notes: the quieter rows of the notes
 *   $empty($title, $text, $href, $button)   an empty state that says why and what to do next
 *
 * and `$body` in the layout. Templates never echo anything else: tests/Unit/UiTemplatesTest.php
 * fails the build when a `<?=` prints something that did not pass through one of them, when a
 * template uses a helper other than by calling it (a rebound `$e` would print anything), or when a
 * template uses an inline script, style or event handler (the CSP forbids them anyway).
 */
final class View
{
    /**
     * The pages whose intro is still drawn under the heading (the owner, 30 Oct 2026: no help paragraphs under titles; docs/decisions.md
     * DC1): only where the line is the step's rule or a safety message that is said nowhere else on the page: the sign-in (what to type, the
     * code app), the password's length, the sign-in set-up (the code is the person's only), and the previews and pending cancellations whose line says nothing is saved or
     * changed yet. Every other page names its intro (`intro:`) and draws none. (Page keys, so here and not in Words, whose texts are all words.)
     */
    public const INTRO_KEPT = ['login', 'login_no_code', 'password', 'enrol', 'new_code', 'bulk_confirm', 'by_item_preview', 'store_new_preview', 'supplier_paper_reversal_pending',
        'supplier_return_reversal_pending'];

    /** @var array<string, mixed> the layout's variables of the page page() is drawing (its head draws the frame from them) */
    private array $frame = [];
    /** Whether the page's template drew its head (then the layout draws no frame of its own) */
    private bool $headDrawn = false;

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
            // Any template but the layout, which prints its `body` as it is: only page() draws it.
            'partial' => fn (string $name, array $v = []): string => $name === 'layout'
                ? throw new \InvalidArgumentException('the layout is drawn by page() only, never as a partial')
                : $this->render($name, $v),
            'word' => static fn (string $group, ?string $code): string => Html::e(Words::of($group, $code)),
            'say' => static fn (string $group, string $code, string|int|float ...$args): string => Html::e(Words::say($group, $code, ...$args)),
            'money' => static fn (mixed $v): string => Html::e(Html::money($v)),
            'day' => static fn (mixed $v): string => Html::e(Html::day($v === null ? null : (string) $v)),
            'when' => static fn (mixed $v): string => Html::e(Html::when($v === null ? null : (string) $v)),
            'jobs' => static fn (array $roles): string => Html::e(Words::roles(array_values(array_map('strval', $roles)))),
            'chip' => static fn (string $tone, string $text): string => Html::chip($tone, $text),
            'stateChip' => static fn (string $group, ?string $code): string => Html::chip(Words::tone($group, $code), Words::of($group, $code)),
            'intro' => static fn (string $page, ?string $lookOnly = null): string => Html::lede(...Words::introParts($page, $lookOnly)),
            'head' => fn (string $title, ?string $intro = null, ?string $look = null, string $chips = '', string $meta = '', string $aside = '',
                ?string $lede = null, ?string $more = null, string $tag = '', ?string $eyebrow = null, ?string $side = null, ?array $back = null): string
                => $this->head($title, $intro, $look, $chips, $meta, $aside, $lede, $more, $tag, $eyebrow, $side, $back),
            'explain' => static fn (string $key, ?string $label = null): string => Html::help($key, $label),
            // Home's "Waiting for you" (HM_Home, docs/decisions.md HV1): one row per task; $notes: the notes after the jobs.
            'cards' => fn (array $cards, bool $notes = false): string => $this->render('cards', ['list' => $cards, 'notes' => $notes]),
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
        $this->frame = $layout;
        $this->headDrawn = false;
        try {
            $body = $this->render($template, $vars);
        } finally {
            $this->frame = [];
        }
        return $this->render('layout', $layout + ['body' => $body, 'headDrawn' => $this->headDrawn]);
    }

    /**
     * The statement and the frame under it (the $head helper): Html::statement with the section's name as the eyebrow (the brand on a
     * page outside every section; the record's list when `back` is given) and the page's create button, then page_frame.php: the
     * switched-off strip (no tab row: the sidebar holds the section's pages, DC28). $side (docs/decisions.md PV27): what the header's right holds instead
     * of the create button (a saved document's action bar); '' for nothing (the new page of what the button makes); null: the create
     * button. No sentence under the heading (DC1): only a step of View::INTRO_KEPT draws its intro; the look-only line is a "?", the last line
     * of the page's own "?" when it has one (ONE "?" beside a heading: DC34).
     *
     * @param array{0: string, 1: string}|null $back
     */
    private function head(string $title, ?string $intro, ?string $look, string $chips, string $meta, string $aside, ?string $lede, ?string $more,
        string $tag, ?string $eyebrow, ?string $side = null, ?array $back = null): string
    {
        $this->headDrawn = true;
        $f = $this->frame;
        $pagebar = is_array($f['pagebar'] ?? null) ? $f['pagebar'] : null;
        $lookLine = $look !== null ? sprintf(Words::UI['look_only'], $look) : (string) ($more ?? ($intro !== null ? Words::INTRO_LOOK[$intro] ?? '' : ''));
        // A page's own `more:` line (not who may change it) is under the general "?" (What does this mean?).
        $lookLabel = $look === null && $more !== null && $more !== '' ? Words::UI['help'] : null;
        $kept = $intro !== null && in_array($intro, self::INTRO_KEPT, true) ? Words::PAGE_INTRO[$intro] : null;
        $sentence = $kept !== null ? $kept[0] : (string) ($lede ?? '');
        $line = $kept !== null && $lookLine === '' && $more === null ? $kept[1] : '';
        // Outside a section (no page bar) a create button is plain (DS5); Home has none: its main action is the first job (HV1).
        $actions = $side ?? (is_array($f['actions'] ?? null) && $f['actions'] !== [] ? $this->render('split', ['actions' => $f['actions'], 'main' => $pagebar !== null]) : '');
        return Html::statement($eyebrow ?? ($pagebar !== null ? (string) $pagebar['label'] : Words::UI['brand']), $title, $sentence, $line, $tag,
            $chips, Html::helpWithLook($aside, $lookLine, $lookLabel), $meta, $actions, $back)
            . $this->render('page_frame', ['switchedOff' => $f['switchedOff'] ?? null]);
    }
}
