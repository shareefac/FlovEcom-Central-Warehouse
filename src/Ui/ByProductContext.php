<?php

declare(strict_types=1);

namespace CW\Ui;

/**
 * "Opened from Products › By Item" (docs/decisions.md U119): the warehouse item a person is matching, the store they are matching
 * it on, the filter and search their list had, and the search and page their picker had. Like QueueContext it travels in the URL
 * and in hidden form fields from the picker to the variant's page, so the page's Back link leads to the picker as the person left
 * it and the redirect after a decision leads back to By Item.
 *
 * It is never a return address. It is a fixed token (`via=product`), an item's number (`product`), a store's code that must name a
 * store (`channel`), a coverage filter that must be one of the page's (`cov`), the list's search text (`lq`), the picker's search
 * text (`pq`) and the picker's page (`pp`, a number); the links are built here from those values only, so a crafted link can lead
 * nowhere but to By Item. Since U123 it also carries how many items the person's list showed (`ln`: one of PAGE_SIZES, only when
 * they chose another number than the setting's), so the way back opens the list as they had it.
 */
final class ByProductContext
{
    public const TOKEN = 'product';
    public const LIST_PATH = '/ui/review/products';
    /** The longest search text carried (the screens cut a search to this). */
    public const MAX_TEXT = 100;
    /** How many items a page of By Item may show (U123): the setting mapping.by_item_page_size is one of these, and so is `n`. */
    public const PAGE_SIZES = [50, 100, 200];
    /** The most ticked items a link back to the list carries (`ticked`, U126): a page holds no more. */
    public const MAX_TICKED = 200;
    /**
     * How an emptied picker search travels: Html::url() and the forms leave out an empty value, and "no search" means "the
     * picker's own first search". One space is read back as the empty search.
     */
    private const EMPTIED = ' ';

    /**
     * @param string|null $coverage the list's coverage filter as its query value (coverage()), null for none
     * @param string|null $pickerText what the person searched for in the picker: null for the picker's own first search, '' when
     *        they emptied the box (every waiting variant of the store)
     * @param int|null $pickerPage the picker's page when it is not the first
     * @param int|null $rows how many items the list showed, when the person chose another number than the setting's (PAGE_SIZES)
     */
    public function __construct(
        public readonly int $skuId,
        public readonly string $channel,
        public readonly int $channelId,
        public readonly string $channelName,
        public readonly ?string $coverage,
        public readonly string $text,
        public readonly ?string $pickerText = null,
        public readonly ?int $pickerPage = null,
        public readonly ?int $rows = null,
    ) {
    }

    /**
     * @param array<string, mixed> $src query string or form fields
     * @param list<array{id: int, code: string, name: string}> $stores Queries::everyStore()
     * @return self|null null when $src does not say "from By Item" with an item's number and a store
     */
    public static function from(array $src, array $stores): ?self
    {
        if (($src['via'] ?? null) !== self::TOKEN) {
            return null;
        }
        $sku = is_string($src['product'] ?? null) ? UiRequest::id($src['product']) : null;
        $store = self::store($stores, is_string($src['channel'] ?? null) ? $src['channel'] : null);
        if ($sku === null || $store === null) {
            return null;
        }
        $coverage = self::coverage(is_string($src['cov'] ?? null) ? $src['cov'] : null, $stores);
        $page = is_string($src['pp'] ?? null) ? UiRequest::id($src['pp']) : null;
        return new self($sku, (string) $store['code'], (int) $store['id'], (string) $store['name'], $coverage['value'],
            is_string($src['lq'] ?? null) ? self::text($src['lq']) : '',
            is_string($src['pq'] ?? null) ? self::text($src['pq']) : null,
            $page !== null && $page > 1 ? $page : null,
            self::pageSize(is_string($src['ln'] ?? null) ? $src['ln'] : null));
    }

    /** A number of items on a page from its query value: one of PAGE_SIZES, else null (the setting's number). */
    public static function pageSize(?string $value): ?int
    {
        return $value !== null && in_array($value, array_map('strval', self::PAGE_SIZES), true) ? (int) $value : null;
    }

    /**
     * The ticked items a link carries back to the list (`ticked`, U126): item numbers joined by dots, at most MAX_TICKED of them,
     * each once. Anything else is no tick at all.
     *
     * @return list<int>
     */
    public static function ticked(?string $value): array
    {
        if ($value === null || preg_match('/^[1-9][0-9]{0,9}(\.[1-9][0-9]{0,9}){0,' . (self::MAX_TICKED - 1) . '}$/D', $value) !== 1) {
            return [];
        }
        return array_values(array_unique(array_map('intval', explode('.', $value))));
    }

    /** Whether a request names this context at all (before the stores are read). @param array<string, mixed> $src */
    public static function named(array $src): bool
    {
        return ($src['via'] ?? null) === self::TOKEN;
    }

    /** A search text as the screens take it: trimmed and cut to MAX_TEXT characters. */
    public static function text(string $v): string
    {
        return mb_substr(trim($v), 0, self::MAX_TEXT);
    }

    /**
     * A coverage filter from its query value: `every`, `suggested` or `missing-<store code>`. `kind` is null for no filter (also
     * for a value that is none of these); `unknown` says the value names a store that is not one.
     *
     * @param list<array{id: int, code: string, name: string}> $stores
     * @return array{kind: ?string, store: array{id: int, code: string, name: string}|null, value: ?string, unknown: bool}
     */
    public static function coverage(?string $value, array $stores): array
    {
        $none = ['kind' => null, 'store' => null, 'value' => null, 'unknown' => false];
        if ($value === 'every' || $value === 'suggested') {
            return ['kind' => $value, 'store' => null, 'value' => $value, 'unknown' => false];
        }
        if ($value !== null && str_starts_with($value, 'missing-')) {
            $store = self::store($stores, substr($value, 8));
            return $store === null ? ['unknown' => true] + $none : ['kind' => 'missing', 'store' => $store, 'value' => 'missing-' . $store['code'], 'unknown' => false];
        }
        return $none;
    }

    /**
     * @param list<array{id: int, code: string, name: string}> $stores
     * @return array{id: int, code: string, name: string}|null the store a code names
     */
    public static function store(array $stores, ?string $code): ?array
    {
        foreach ($stores as $s) {
            if ($code !== null && $s['code'] === $code) {
                return $s;
            }
        }
        return null;
    }

    /** The same context with the picker's own search and page (the picker's "Choose" links carry them). */
    public function withPicker(?string $text, int $page): self
    {
        return new self($this->skuId, $this->channel, $this->channelId, $this->channelName, $this->coverage, $this->text,
            $text === null ? null : self::text($text), $page > 1 ? $page : null, $this->rows);
    }

    /**
     * What carries this context to a variant's page and its forms; the picker's search and page only when the person had them.
     *
     * @return array<string, scalar|null>
     */
    public function query(): array
    {
        return ['via' => self::TOKEN, 'product' => $this->skuId, 'channel' => $this->channel, 'cov' => $this->coverage,
            'lq' => $this->text === '' ? null : $this->text]
            + ($this->pickerText === null ? [] : ['pq' => $this->pickerText === '' ? self::EMPTIED : $this->pickerText])
            + ($this->pickerPage === null ? [] : ['pp' => $this->pickerPage])
            + ($this->rows === null ? [] : ['ln' => $this->rows]);
    }

    /**
     * By Item's list as the person left it, at this item (`at`: the page that holds it, its row marked). The picker's search
     * and page are not part of it: after a decision the person is back on the list.
     *
     * @param array<string, scalar|null> $extra a notice and the variant it is about
     */
    public function listUrl(array $extra = []): string
    {
        return Html::url(self::LIST_PATH, ['cov' => $this->coverage, 'q' => $this->text === '' ? null : $this->text, 'n' => $this->rows,
            'at' => $this->skuId] + $extra) . '#p-' . $this->skuId;
    }

    /**
     * The picker of this item on this store, as the person left it: their search (also an emptied one, which Html::url() would
     * drop) and their page. $page names another page of the same search (the picker's Previous / Next).
     */
    public function pickerUrl(?int $page = null): string
    {
        $url = Html::url(self::LIST_PATH . '/' . $this->skuId . '/map', ['channel' => $this->channel, 'cov' => $this->coverage,
            'lq' => $this->text === '' ? null : $this->text, 'ln' => $this->rows]);
        $page ??= $this->pickerPage;
        return $url . ($this->pickerText === null ? '' : '&q=' . rawurlencode($this->pickerText)) . ($page !== null && $page > 1 ? '&page=' . $page : '');
    }
}
