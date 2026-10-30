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
 *                           opens with (the approved redesign of 30 Oct 2026; docs/decisions.md RD3): a list is its title alone (no
 *                           eyebrow; an `eyebrow` given, Home's date, is drawn, `tag` after it); a record has `back`, [href, label]: "‹
 *                           label" above its title (the tag after it, DC37); the heading (`chips` inside it: the record's status pill,
 *                           `aside` beside it), `meta` (the one fact line), the page's create button on the right with More (its other
 *                           "New …", the section's specialist pages, then `menu`: the page's own items, list_more.php's links and
 *                           filters) and `extra` (['href', 'label']: a plain button before them, Stock counts' "Open sheets"); then the
 *                           switched-off strip and, on a page of a group, the group's Show when the page draws none itself.
 *                           No sentence under the heading: `intro` names the page (its words stay in Words::PAGE_INTRO) and only the
 *                           steps of View::INTRO_KEPT draw their line; `look` (who may change this) or `more` (a look-only sentence) is
 *                           a "?" beside the heading (with `aside`'s help: its last line, one "?", DC34). Text arguments are escaped;
 *                           `chips`, `meta` and `aside` are helper output. Every page template draws it once (tests/Unit/UiTemplatesTest.php).
 *   $intro($page, $who)     the page's intro alone, in its two parts (Words::PAGE_INTRO; $who = "You can look; <who> change this.")
 *   $explain($key, $label)  a "?" that unfolds one Words::HELP text (a <details>: no script)
 *   $cards($cards, $notes)  Home's "Waiting for you": one row per task (its number in its colour, its name, one plain line, one button);
 *                           $notes: the quieter rows of the notes
 *   $empty($title, $text, $href, $button)   an empty state that says why and what to do next
 *   $showMenu($choices = null)  the Show choice of a page (docs/decisions.md RD2): a <details> menu of links styled like a select, the current
 *                           choice not a link; no $choices = the page's group (the pages of its sidebar entry this person may open); ''
 *                           when there are fewer than two. Drawn by a page (its toolbar), the head draws no Show of its own.
 *   $groupPages()           the page's group as choices (key, label, href, query, count, countWords, current, here), for a page that mixes
 *                           its own filter choices in: $showMenu([...$groupPages(), ['label' => …, 'href' => …, 'query' => […], 'current' => …]])
 *   $showStart() … $showEnd()   ONE Show that holds a page's own filter fields too (the integration of the redesign, 30 Oct 2026): around
 *                           the page's div.tb-state (its GET form's select and Show button, as before), the group's choices as $showMenu()
 *                           draws them, the tb-state under them in the same panel; with fewer than two choices nothing (the tb-state as it
 *                           was). The head draws no Show of its own then.
 *   $showMine($own, $other) ONE Show of the group with the page's own link choices (label, href with its query, current) in place of the
 *                           group's entries they open; one that opens a page the person may not open is not drawn; $other (optional): the
 *                           words shown first, not a link, when none is the current one (filters chosen under More)
 *   $showNav()              the page draws its group as its own list (Settings' section list, RD-C14): '' and the head draws no Show
 *   $sectionMore()          the section's specialist pages (label, href, query, count, countWords, help) for a page that draws its own
 *                           More beside its title (Product matching, RD-C8): the header's More of RD6, ONE More
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
    /** Whether the page's template drew a Show ($showMenu): then the head's slot stays empty */
    private bool $showDrawn = false;
    /** @var list<string> what $showEnd() closes, the last $showStart() first */
    private array $showOwn = [];
    /** Where the head leaves room for the group's Show, filled once the body is drawn (page()): no template can print it (escaping). */
    private const SHOW_SLOT = '<!--cw:show-->';

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
                ?string $lede = null, ?string $more = null, string $tag = '', ?string $eyebrow = null, ?string $side = null, ?array $back = null,
                ?array $menu = null, ?array $extra = null): string
                => $this->head($title, $intro, $look, $chips, $meta, $aside, $lede, $more, $tag, $eyebrow, $side, $back, $menu, $extra),
            'explain' => static fn (string $key, ?string $label = null): string => Html::help($key, $label),
            // Home's "Waiting for you" (HM_Home, docs/decisions.md HV1): one row per task; $notes: the notes after the jobs.
            'cards' => fn (array $cards, bool $notes = false): string => $this->render('cards', ['list' => $cards, 'notes' => $notes]),
            'empty' => static fn (string $title, string $text = '', ?string $href = null, ?string $button = null): string
                => Html::emptyState($title, $text, $href, $button),
            'showMenu' => fn (?array $choices = null): string => $this->show($choices),
            'groupPages' => fn (): array => is_array($this->frame['segments'] ?? null) ? $this->frame['segments'] : [],
            'showStart' => fn (?array $choices = null): string => $this->showStart($choices),
            'showEnd' => fn (): string => array_pop($this->showOwn) ?? '',
            'showMine' => fn (array $own, ?string $other = null): string => $this->show(self::mine($this->frame['segments'] ?? [], $own, $other)),
            'showNav' => function (): string {
                $this->showDrawn = true;
                return '';
            },
            'sectionMore' => fn (): array => is_array($this->frame['pagebar']['more'] ?? null) ? $this->frame['pagebar']['more'] : [],
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
        $this->showDrawn = false;
        $this->showOwn = [];
        try {
            $body = $this->render($template, $vars);
            // The group's Show under the head, unless the page drew its own (RD2); only on a page that is one of the group's choices.
            $row = $this->showDrawn ? '' : $this->showRow();
            $at = strpos($body, self::SHOW_SLOT);
            $body = str_replace(self::SHOW_SLOT, '', $at === false ? $body : substr_replace($body, $row, $at, strlen(self::SHOW_SLOT)));
            $loose = $this->headDrawn ? '' : $row;
        } finally {
            $this->frame = [];
        }
        return $this->render('layout', $layout + ['body' => $body, 'headDrawn' => $this->headDrawn, 'showRow' => $loose]);
    }

    /**
     * The Show menu ($showMenu): the choices as links, the current one without a link (aria-current); '' for fewer than two. Each choice:
     * label, href, query (optional), current, count (optional) and countWords.
     *
     * @param list<array<string, mixed>>|null $choices
     */
    private function show(?array $choices): string
    {
        $this->showDrawn = true;
        $choices ??= is_array($this->frame['segments'] ?? null) ? $this->frame['segments'] : [];
        return count($choices) < 2 ? '' : $this->render('show_menu', ['choices' => array_values($choices)]);
    }

    /**
     * ONE Show with the page's own filter fields in it ($showStart … $showEnd): the group's choices as show() draws them and, in the same
     * panel (div.show-panel), the page's div.tb-state that the template writes up to $showEnd(); with fewer than two choices nothing: the
     * page's div.tb-state stays as it was.
     *
     * @param list<array<string, mixed>>|null $choices
     */
    private function showStart(?array $choices): string
    {
        $this->showDrawn = true;
        $choices ??= is_array($this->frame['segments'] ?? null) ? $this->frame['segments'] : [];
        if (count($choices) < 2) {
            $this->showOwn[] = '';
            return '';
        }
        $this->showOwn[] = '</div></details>';
        return $this->render('show_menu', ['choices' => array_values($choices), 'own' => true]);
    }

    /**
     * The choices of $showMine: the page's own choices in their order (the page shown: its filters; another page of the group: the page's link
     * with its filters kept, the group's count beside it), then the group's pages no own choice opens. An own choice that opens a page outside
     * the person's group is not drawn (the group is the pages the person may open: rd2a's "Made by hand" was drawn for everyone); when the
     * person may open only this page of the group, only the choices that open it. With $other and none current, $other first (not a link).
     *
     * @param mixed $group the frame's segments
     * @param list<array<string, mixed>> $own
     * @return list<array<string, mixed>>
     */
    private static function mine(mixed $group, array $own, ?string $other): array
    {
        $group = is_array($group) ? array_values($group) : [];
        $path = static fn (array $c): string => (string) parse_url((string) $c['href'], PHP_URL_PATH);
        $here = in_array(true, array_map(static fn (array $g): bool => ($g['current'] ?? false) === true, $group), true);
        $byPath = [];
        foreach ($group as $g) {
            $byPath[$path($g)] ??= $g;
        }
        // Without a group (the person may open only this page of it), only the page's own choices: the ones that open this page.
        $current = array_values(array_filter($own, static fn (array $c): bool => ($c['current'] ?? false) === true))[0] ?? $own[0] ?? null;
        $page = $current === null ? '' : $path($current);
        $out = [];
        $used = [];
        foreach ($own as $c) {
            $g = $byPath[$path($c)] ?? null;
            if ($here ? $g === null : $path($c) !== $page) {
                continue;
            }
            $used[$path($c)] = true;
            $out[] = $g === null || ($g['current'] ?? false) === true ? $c : $c + ['count' => $g['count'] ?? 0, 'countWords' => $g['countWords'] ?? ''];
        }
        foreach ($here ? $group : [] as $g) {
            if (!isset($used[$path($g)])) {
                $out[] = $g;
            }
        }
        if ($other !== null && !in_array(true, array_map(static fn (array $c): bool => ($c['current'] ?? false) === true, $out), true)) {
            array_unshift($out, ['label' => $other, 'href' => '', 'current' => true]);
        }
        return $out;
    }

    /** The group's Show the head draws under itself: only on a page that is one of the group's choices (a list, not one of its records). */
    private function showRow(): string
    {
        $segments = is_array($this->frame['segments'] ?? null) ? $this->frame['segments'] : [];
        if (!in_array(true, array_column($segments, 'here'), true)) {
            return '';
        }
        return '<div class="show-row">' . $this->render('show_menu', ['choices' => $segments]) . '</div>';
    }

    /**
     * The statement and the frame under it (the $head helper): Html::statement (no eyebrow but a given one: a list is its title alone,
     * RD3; a record's way back when `back` is given) and the page's create button with More (its other "New …" and the section's
     * specialist pages, RD6), then page_frame.php (the switched-off strip) and the slot of the group's Show (page()). $side (docs/decisions.md
     * PV27): what the header's right holds instead (a saved document's action bar); '' for nothing (the new page of what the button
     * makes); null: the create button and More. No sentence under the heading (DC1): only a step of View::INTRO_KEPT draws its intro; the
     * look-only line is a "?", the last line of the page's own "?" when it has one (ONE "?" beside a heading: DC34).
     *
     * @param array{0: string, 1: string}|null $back
     */
    private function head(string $title, ?string $intro, ?string $look, string $chips, string $meta, string $aside, ?string $lede, ?string $more,
        string $tag, ?string $eyebrow, ?string $side = null, ?array $back = null, ?array $menu = null, ?array $extra = null): string
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
        $made = is_array($f['actions'] ?? null) ? $f['actions'] : [];
        $pages = $pagebar !== null && is_array($pagebar['more'] ?? null) ? $pagebar['more'] : [];
        $menu = $menu !== null && (($menu['links'] ?? []) !== [] || ($menu['filters'] ?? null) !== null) ? $menu : null;
        $actions = $side ?? ($made !== [] || $pages !== [] || $menu !== null || $extra !== null
            ? $this->render('split', ['actions' => $made, 'main' => $pagebar !== null, 'pages' => $pages, 'menu' => $menu, 'extra' => $extra]) : '');
        return Html::statement($eyebrow ?? '', $title, $sentence, $line, $tag, $chips, Html::helpWithLook($aside, $lookLine, $lookLabel), $meta, $actions, $back)
            . $this->render('page_frame', ['switchedOff' => $f['switchedOff'] ?? null]) . ($f !== [] ? self::SHOW_SLOT : '');
    }
}
