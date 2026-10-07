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

    /** A status chip: an icon shape (by tone, in CSS) and the word. An unknown tone is `info`. */
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
            $out .= '<p><a class="btn primary" href="' . self::e($href) . '">' . self::e($button) . '</a></p>';
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
