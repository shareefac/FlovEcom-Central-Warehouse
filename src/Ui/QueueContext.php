<?php

declare(strict_types=1);

namespace CW\Ui;

use CW\Mapping\Proposals;

/**
 * Which queue a reviewer is working through: band, site, lane, minimum 30-day units and a text
 * filter. It travels in the URL (and in hidden form fields) from the queue to the listing pages, so
 * "after an action, go to the next one" knows what "next" means.
 */
final class QueueContext
{
    public function __construct(
        public readonly string $band,
        public readonly ?string $channel,
        public readonly ?int $channelId,
        public readonly ?string $lane,
        public readonly int $min,
        public readonly string $text,
    ) {
    }

    /**
     * @param array<string, mixed> $src query string or form fields
     * @param string $textKey name of the text filter in $src ('q' on the queue page, 'fq' elsewhere)
     * @param list<array{id: int, code: string, name: string}> $channels
     * @return self|null null when $src names no (valid) band
     */
    public static function from(array $src, string $textKey, array $channels): ?self
    {
        $band = $src['queue'] ?? null;
        if (!is_string($band) || !in_array($band, Proposals::BANDS, true)) {
            return null;
        }
        $code = is_string($src['channel'] ?? null) ? $src['channel'] : null;
        $channelId = null;
        $channel = null;
        foreach ($channels as $c) {
            if ($c['code'] === $code) {
                $channelId = $c['id'];
                $channel = $c['code'];
            }
        }
        $lane = is_string($src['lane'] ?? null) && in_array($src['lane'], Queries::LANES, true) ? $src['lane'] : null;
        $min = is_string($src['min'] ?? null) && preg_match('/^[0-9]{1,6}$/D', $src['min']) === 1 ? (int) $src['min'] : 0;
        $text = is_string($src[$textKey] ?? null) ? mb_substr(trim($src[$textKey]), 0, 100) : '';
        return new self($band, $channel, $channelId, $lane, $min, $text);
    }

    /** Parameters that carry this context to a listing page or a form. @return array<string, scalar|null> */
    public function query(): array
    {
        return ['queue' => $this->band, 'channel' => $this->channel, 'lane' => $this->lane, 'min' => $this->min > 0 ? $this->min : null,
            'fq' => $this->text === '' ? null : $this->text];
    }

    /** Parameters of the queue page itself. @return array<string, scalar|null> */
    public function pageQuery(): array
    {
        $q = $this->query();
        $q['q'] = $q['fq'];
        unset($q['fq']);
        return $q;
    }
}
