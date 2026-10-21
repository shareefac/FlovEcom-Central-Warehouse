<?php

declare(strict_types=1);

namespace CW\Ui;

/**
 * "Opened from Products › Store Products" (docs/decisions.md CM3): the store, the state filter, the sort, the search and the page of
 * the list a person was on. Like QueueContext and ByProductContext it travels in the URL and in hidden form fields, from the list's
 * Check & match fold to the decide route, so a decision made there (or on the variant's page it leads to) goes back to the list at
 * that row, and the variant's page has Back to it.
 *
 * It is never a return address: a fixed token (`via=store`), a store's code that must name a store (`channel`), a state that must be
 * one of the list's (`state`), a sort that must be one of the list's (`sort`), a search text (`lq`) and a page number (`lp`). The
 * links are built here from those values only, so a crafted link can lead nowhere but to Store Products.
 */
final class StoreContext
{
    public const TOKEN = 'store';
    public const LIST_PATH = '/ui/review/store';
    /** The list's own sort, which its address does not write. */
    private const DEFAULT_SORT = 'sold_30';

    public function __construct(
        public readonly string $channel,
        public readonly ?string $state,
        public readonly ?string $sort,
        public readonly string $text,
        public readonly ?int $page,
    ) {
    }

    /** Whether a request names this context at all (before the stores are read). @param array<string, mixed> $src */
    public static function named(array $src): bool
    {
        return ($src['via'] ?? null) === self::TOKEN;
    }

    /**
     * @param array<string, mixed> $src query string or form fields
     * @param list<array{id: int, code: string, name: string}> $stores Queries::stores()
     * @return self|null null when $src does not say "from Store Products" with a store
     */
    public static function from(array $src, array $stores): ?self
    {
        if (!self::named($src)) {
            return null;
        }
        $code = is_string($src['channel'] ?? null) ? $src['channel'] : null;
        $store = null;
        foreach ($stores as $s) {
            if ($code !== null && $s['code'] === $code) {
                $store = $s;
            }
        }
        if ($store === null) {
            return null;
        }
        $state = is_string($src['state'] ?? null) && in_array($src['state'], Queries::STORE_STATES, true) ? $src['state'] : null;
        $sort = is_string($src['sort'] ?? null) && isset(Queries::STORE_SORTS[$src['sort']]) && $src['sort'] !== self::DEFAULT_SORT ? $src['sort'] : null;
        $page = is_string($src['lp'] ?? null) ? UiRequest::id($src['lp']) : null;
        return new self((string) $store['code'], $state, $sort, is_string($src['lq'] ?? null) ? mb_substr(trim($src['lq']), 0, 100) : '',
            $page !== null && $page > 1 ? $page : null);
    }

    /** What carries this context to the variant's page and its forms. @return array<string, scalar|null> */
    public function query(): array
    {
        return ['via' => self::TOKEN, 'channel' => $this->channel, 'state' => $this->state, 'sort' => $this->sort, 'lq' => $this->text === '' ? null : $this->text,
            'lp' => $this->page];
    }

    /**
     * The list as the person left it (its page), with $extra (a notice and the variant it is about), at row $row when given (the
     * rows are `l-<listing id>`).
     *
     * @param array<string, scalar|null> $extra
     */
    public function listUrl(array $extra = [], ?int $row = null): string
    {
        return Html::url(self::LIST_PATH, ['channel' => $this->channel, 'state' => $this->state, 'sort' => $this->sort, 'q' => $this->text === '' ? null : $this->text,
            'page' => $this->page] + $extra) . ($row !== null ? '#l-' . $row : '');
    }
}
