<?php

declare(strict_types=1);

namespace CW\Ui;

/**
 * Output helpers of the /ui templates. EVERYTHING that came from a database row, a request or a
 * site (product titles are hostile) goes through e() on its way into HTML: the templates never
 * echo a variable raw. The CSP (default-src 'self') is the second line, not the first.
 */
final class Html
{
    /** HTML-escapes any scalar (null -> ''); valid for text and quoted attribute values. */
    public static function e(mixed $v): string
    {
        if ($v === null || $v === false) {
            return '';
        }
        if (is_bool($v)) {
            return '1';
        }
        if (!is_scalar($v)) {
            throw new \InvalidArgumentException('cannot print a ' . get_debug_type($v));
        }
        return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    /** '1,234' or '-' for null. */
    public static function int(mixed $v): string
    {
        return $v === null || $v === '' ? '-' : number_format((int) $v);
    }

    /** A decimal column value without trailing zeros ('6.00' -> '6'), '' for null. */
    public static function dec(mixed $v): string
    {
        if ($v === null || $v === '') {
            return '';
        }
        $s = (string) $v;
        return str_contains($s, '.') ? rtrim(rtrim($s, '0'), '.') : $s;
    }

    /** 'Y-m-d H:i' (UTC) of a database DATETIME(6), '' for null. */
    public static function dt(?string $v): string
    {
        return $v === null || $v === '' ? '' : substr($v, 0, 16);
    }

    /** UK date "7 Oct 2026" of a UTC DATETIME(6) ('' for null); a DATE ('Y-m-d') is shown as it is. */
    public static function day(?string $v): string
    {
        $d = self::london($v);
        return $d === null ? '' : $d->format('j M Y');
    }

    /** UK date and time "7 Oct 2026, 10:26" of a UTC DATETIME(6), converted to Europe/London ('' for null). Never "UTC". */
    public static function when(?string $v): string
    {
        if ($v !== null && strlen(trim($v)) === 10) {
            return self::day($v);
        }
        $d = self::london($v);
        return $d === null ? '' : $d->format('j M Y, H:i');
    }

    /**
     * The time of a UTC DATETIME(6) as a count sheet's "Saved" mark says it (docs/decisions.md CV6), in UK time: "10:15" on today's UK date,
     * else "7 Oct, 10:15"; '' for null.
     */
    public static function clock(?string $v, ?\DateTimeImmutable $now = null): string
    {
        $d = self::london($v);
        if ($d === null) {
            return '';
        }
        $today = ($now ?? new \DateTimeImmutable('now'))->setTimezone(new \DateTimeZone('Europe/London'))->format('Y-m-d');
        return $d->format('Y-m-d') === $today ? $d->format('H:i') : $d->format('j M, H:i');
    }

    /**
     * A recent time as the board mockups say it (GI_List, IT_Item; docs/decisions.md DC17), in UK time: "Today 09:40", "Yesterday 15:10",
     * else "7 Oct 2026, 10:15"; '' for null.
     */
    public static function near(?string $v, ?\DateTimeImmutable $now = null): string
    {
        $d = self::london($v);
        if ($d === null) {
            return '';
        }
        $today = ($now ?? new \DateTimeImmutable('now'))->setTimezone(new \DateTimeZone('Europe/London'));
        return match ($d->format('Y-m-d')) {
            $today->format('Y-m-d') => sprintf(Words::UI['today_at'], $d->format('H:i')),
            $today->modify('-1 day')->format('Y-m-d') => sprintf(Words::UI['yesterday_at'], $d->format('H:i')),
            default => $d->format('j M Y, H:i'),
        };
    }

    /** Money: "£10,500.00", "-£5.00"; '' for null, '' or a non-number. */
    public static function money(mixed $v): string
    {
        if ($v === null || $v === '' || is_bool($v) || !is_numeric($v)) {
            return '';
        }
        $f = round((float) $v, 2);
        return ($f < 0 ? '-' : '') . '£' . number_format(abs($f), 2);
    }

    private static function london(?string $v): ?\DateTimeImmutable
    {
        if ($v === null || trim($v) === '') {
            return null;
        }
        $v = trim($v);
        $london = new \DateTimeZone('Europe/London');
        if (strlen($v) === 10) { // a DATE: no time, no time zone
            $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $v, $london);
            return $d === false ? null : $d;
        }
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', substr($v, 0, 19), new \DateTimeZone('UTC'))
            ?: \DateTimeImmutable::createFromFormat('!Y-m-d H:i', substr($v, 0, 16), new \DateTimeZone('UTC'));
        return $d === false ? null : $d->setTimezone($london);
    }

    /** A status pill: the tone's solid colour (in CSS) and the word. An unknown tone is `info`. */
    public static function chip(string $tone, string $text): string
    {
        $tone = in_array($tone, Words::TONES, true) ? $tone : 'info';
        return '<span class="chip ' . $tone . '">' . self::e($text) . '</span>';
    }

    /** The page intro under the h1 ('' for none). */
    public static function intro(string $text): string
    {
        return $text === '' ? '' : '<p class="lede">' . self::e($text) . '</p>';
    }

    /** The intro in its two parts (Words::introParts): the plain sentence, then the muted line under it ('' for none). */
    public static function lede(string $sentence, string $more = ''): string
    {
        if ($sentence === '' && $more === '') {
            return '';
        }
        return '<p class="lede">' . self::e($sentence) . ($more === '' ? '' : ($sentence === '' ? '' : ' ') . '<span class="lede-more">' . self::e($more) . '</span>')
            . '</p>';
    }

    /**
     * The statement every page opens with (the approved mockups of 29 Oct 2026; docs/decisions.md DC1-DC3): a small UPPERCASE eyebrow
     * ($eyebrow, the section; $tag after it; or $back, the record's list as a link followed by "›", the mockups' breadcrumb), the
     * heading ($title, the page's name, sentence case and no full stop; $chips inside it after the name, the record's status block; $aside
     * beside it, a "?" help), a line of state chips ($meta), then the page's actions on the right. $lede and $more are the one line a few
     * steps keep (View::INTRO_KEPT: a rule or a safety message the step needs); every other page has no sentence under its heading.
     * $eyebrow, $tag, $title, $lede and $more are text, escaped here; $chips, $aside, $meta and $actions are HTML the helpers made;
     * $back is [href, label].
     *
     * @param array{0: string, 1: string}|null $back
     */
    public static function statement(string $eyebrow, string $title, string $lede = '', string $more = '', string $tag = '', string $chips = '',
        string $aside = '', string $meta = '', string $actions = '', ?array $back = null): string
    {
        $out = '<header class="page-head"><div class="ph-text">';
        if ($back !== null) {
            // The "›" is the one separator after the list: its tag follows it ("Store products › Electrofag · 8,767 to match": docs/decisions.md DC37).
            $out .= '<p class="ph-eyebrow ph-back"><a href="' . self::e($back[0]) . '">' . self::e($back[1]) . '</a><span aria-hidden="true"> ›</span>'
                . ($tag === '' ? '' : '<span class="ph-tag"> ' . self::e($tag) . '</span>') . '</p>';
        } elseif ($eyebrow !== '' || $tag !== '') {
            $out .= '<p class="ph-eyebrow">' . self::e($eyebrow)
                . ($tag === '' ? '' : '<span class="ph-tag">' . ($eyebrow === '' ? '' : ' · ') . self::e($tag) . '</span>') . '</p>';
        }
        $out .= '<div class="ph-title"><h1><span class="stmt">' . self::e($title) . '</span>' . ($chips === '' ? '' : ' ' . $chips) . '</h1>'
            . $aside . '</div>';
        if ($meta !== '') {
            $out .= '<p class="ph-meta">' . $meta . '</p>';
        }
        $out .= self::lede($lede, $more) . '</div>';
        if ($actions !== '') {
            $out .= '<div class="ph-actions">' . $actions . '</div>';
        }
        return $out . '</header>';
    }

    /**
     * The "?" beside a heading that says who may change what this person can only look at (docs/decisions.md DC1: the line that stood
     * under the heading; `$label` names another kind of line, a page's `more:`). A <details>: no script needed.
     */
    public static function lookOnly(string $text, ?string $label = null): string
    {
        if ($text === '') {
            return '';
        }
        $name = $label ?? Words::UI['look_help'];
        return '<details class="help look-only"><summary aria-label="' . self::e($name) . '" title="' . self::e($name) . '"></summary>'
            . '<div class="help-body"><p>' . self::e($text) . '</p></div></details>';
    }

    /**
     * ONE "?" beside a heading (docs/decisions.md DC34; Store orders showed two): a page's own help (Html::help, the head's `aside`) takes the
     * look-only line as its last paragraph, the whole then marked look-only. Anything else beside the heading keeps the look-only "?" after it.
     */
    public static function helpWithLook(string $help, string $line, ?string $label = null): string
    {
        $open = '<details class="help">';
        $end = '</div></details>';
        if ($line === '' || !str_starts_with($help, $open) || !str_ends_with($help, $end) || substr_count($help, '<details') !== 1) {
            return $help . self::lookOnly($line, $label);
        }
        return '<details class="help look-only">' . substr($help, strlen($open), -strlen($end)) . '<p>' . self::e($line) . '</p>' . $end;
    }

    /** A person's initials as the mockups draw them ("Bea Smith" BS, "Shareef" SH): the first letters of two names, or a name's first two. */
    public static function initials(?string $name): string
    {
        $parts = preg_split('/\s+/u', trim((string) $name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($parts === []) {
            return '';
        }
        return mb_strtoupper(mb_substr($parts[0], 0, 1) . (isset($parts[1]) ? mb_substr($parts[1], 0, 1) : mb_substr($parts[0], 1, 1)));
    }

    /** Today in London as the Dashboard's eyebrow says it: "Monday 19 October". */
    public static function today(?\DateTimeImmutable $now = null): string
    {
        return ($now ?? new \DateTimeImmutable('now'))->setTimezone(new \DateTimeZone('Europe/London'))->format('l j F');
    }

    /**
     * A "?" next to a word that unfolds its help (Words::HELP; **bold** marks the words explained). A <details>, so it needs
     * no script under the CSP.
     */
    public static function help(string $key, ?string $label = null): string
    {
        $text = Words::HELP[$key] ?? throw new \InvalidArgumentException("no help text {$key}");
        $html = (string) preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', self::e($text));
        $name = $label === null ? Words::UI['help'] : 'What does "' . $label . '" mean?';
        return '<details class="help"><summary aria-label="' . self::e($name) . '" title="' . self::e($name) . '"></summary>'
            . '<div class="help-body"><p>' . $html . '</p></div></details>';
    }

    /** An empty state: why the page is empty (title), what it means (text) and the next step (one button). */
    public static function emptyState(string $title, string $text = '', ?string $href = null, ?string $button = null): string
    {
        $out = '<div class="empty"><p class="empty-title">' . self::e($title) . '</p>';
        if ($text !== '') {
            $out .= '<p>' . self::e($text) . '</p>';
        }
        if ($href !== null && $button !== null) {
            $out .= '<p><a class="btn" href="' . self::e($href) . '">' . self::e($button) . '</a></p>';
        }
        return $out . '</div>';
    }

    /** A file size people read: "640 bytes", "12 KB", "1.5 MB". */
    public static function size(int $bytes): string
    {
        return match (true) {
            $bytes < 1024 => number_format(max(0, $bytes)) . ' bytes',
            $bytes < 1024 * 1024 => number_format($bytes / 1024) . ' KB',
            default => rtrim(rtrim(number_format($bytes / (1024 * 1024), 1), '0'), '.') . ' MB',
        };
    }

    /**
     * A board's groups (the approved board, docs/decisions.md PV18, W9.1): the rows by their `group` key, in $order's order (key => [tone,
     * name]), only the groups that have rows. Each group: key, tone, name, rows.
     *
     * @param list<array<string, mixed>> $rows
     * @param array<string, array{0: string, 1: string}> $order
     * @return list<array{key: string, tone: string, name: string, rows: list<array<string, mixed>>}>
     */
    public static function groups(array $rows, array $order): array
    {
        $by = [];
        foreach ($rows as $r) {
            $by[(string) $r['group']][] = $r;
        }
        $out = [];
        foreach ($order as $key => [$tone, $name]) {
            if (isset($by[$key])) {
                $out[] = ['key' => (string) $key, 'tone' => $tone, 'name' => $name, 'rows' => $by[$key]];
            }
        }
        return $out;
    }

    /** Percentage with one decimal, '-' when there is nothing to divide. */
    public static function pct(int|float $part, int|float $whole): string
    {
        return $whole <= 0 ? '-' : number_format(100 * $part / $whole, 1) . '%';
    }

    /**
     * A URL path with a query string (values url-encoded, null/'' values dropped).
     *
     * @param array<string, scalar|null> $query
     */
    public static function url(string $path, array $query = []): string
    {
        $q = [];
        foreach ($query as $k => $v) {
            if ($v !== null && $v !== '' && $v !== false) {
                $q[$k] = (string) $v;
            }
        }
        return $path . ($q === [] ? '' : '?' . http_build_query($q, '', '&', PHP_QUERY_RFC3986));
    }

    /** An http(s) URL safe to put in href (never javascript:, data:, ...), else null. */
    public static function safeUrl(?string $url): ?string
    {
        if ($url === null || preg_match('#^https?://[^\s<>"\']+$#iD', $url) !== 1) {
            return null;
        }
        return $url;
    }

    /** @param mixed $v a decoded JSON value @return list<string> the scalar members of a JSON list */
    public static function strings(mixed $v): array
    {
        $out = [];
        foreach (is_array($v) ? $v : [] as $x) {
            if (is_string($x) || is_int($x) || is_float($x)) {
                $out[] = (string) $x;
            }
        }
        return $out;
    }

    /** Decodes a JSON column into an array ([] when NULL or malformed). @return array<mixed> */
    public static function json(mixed $v): array
    {
        if (!is_string($v) || $v === '') {
            return [];
        }
        $d = json_decode($v, true);
        return is_array($d) ? $d : [];
    }
}
