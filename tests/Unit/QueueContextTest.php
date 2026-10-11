<?php

declare(strict_types=1);

namespace CW\Tests\Unit;

use CW\Ui\Html;
use CW\Ui\Queries;
use CW\Ui\QueueContext;
use PHPUnit\Framework\TestCase;

/**
 * Which list a reviewer is working through (Ui\QueueContext), and since docs/decisions.md U140 what that list shows (`show`): one of
 * Queries::SHOWS, every row when the request names none or a word that is not one of them; it is written into every address and form
 * the context makes (the list's own, a variant's page), and never when it is "every row", so the addresses of the list as it always
 * was are unchanged. Pure: no database.
 */
final class QueueContextTest extends TestCase
{
    private const STORES = [['id' => 3, 'code' => 'alt', 'name' => 'Alt Store'], ['id' => 9, 'code' => 'vbig', 'name' => 'Big Store']];

    public function testWhatTheListShowsIsOneOfTheFourAndAnythingElseIsEveryRow(): void
    {
        self::assertSame(['all', 'ready', 'held', 'spot'], Queries::SHOWS);
        self::assertSame('all', Queries::SHOW_ALL);
        $from = static fn (mixed $show): QueueContext => QueueContext::from(['queue' => 'Key', 'show' => $show], 'q', self::STORES) ?? throw new \LogicException('not a list');
        self::assertSame(['ready', 'held', 'spot', 'all'], array_map(static fn (string $s): string => $from($s)->show, ['ready', 'held', 'spot', 'all']));
        foreach ([null, '', 'READY', ' ready', "ready\n", 'ready,held', 'in_spot_check', 'nonsense', '1', ['ready'], 7, true, '//evil.example', "held' OR 1=1"] as $junk) {
            self::assertSame('all', $from($junk)->show, json_encode($junk));
            self::assertNull($from($junk)->showValue(), json_encode($junk));
            self::assertSame('all', QueueContext::show($junk), json_encode($junk));
        }
        $none = QueueContext::from(['queue' => 'Key'], 'q', self::STORES);
        self::assertSame(['all', null], [$none?->show, $none?->showValue()], 'a request that names none');
        self::assertNull(QueueContext::from(['show' => 'ready'], 'q', self::STORES), 'what a list shows does not make a list: the strength does');
    }

    public function testItTravelsInEveryAddressAndFormTheContextMakesAndIsNotWrittenForEveryRow(): void
    {
        $src = ['queue' => 'Check', 'channel' => 'alt', 'lane' => 'barcode', 'min' => '5', 'show' => 'ready', 'q' => ' elux ', 'fq' => 'other'];
        $list = QueueContext::from($src, 'q', self::STORES);
        self::assertNotNull($list);
        self::assertSame(['Check', 'alt', 3, 'barcode', 5, 'elux', 'ready'], [$list->band, $list->channel, $list->channelId, $list->lane, $list->min, $list->text, $list->show]);
        // To a variant's page and its forms (the text as `fq`), and back to the list (as `q`).
        self::assertSame(['queue' => 'Check', 'channel' => 'alt', 'lane' => 'barcode', 'min' => 5, 'show' => 'ready', 'fq' => 'elux'], $list->query());
        self::assertSame(['queue' => 'Check', 'channel' => 'alt', 'lane' => 'barcode', 'min' => 5, 'show' => 'ready', 'q' => 'elux'], $list->pageQuery());
        self::assertSame('/ui/review?queue=Check&channel=alt&lane=barcode&min=5&show=ready&q=elux', Html::url('/ui/review', $list->pageQuery()));
        // A variant's page reads it back from what the list gave it.
        $page = QueueContext::from(array_map('strval', array_filter($list->query(), static fn (mixed $v): bool => $v !== null)), 'fq', self::STORES);
        self::assertEquals($list, $page);
        // Every row: no value, so the address is the one the list always had.
        $plain = QueueContext::from(['queue' => 'Key', 'channel' => 'alt', 'show' => 'all'], 'q', self::STORES);
        self::assertSame(['queue' => 'Key', 'channel' => 'alt', 'lane' => null, 'min' => null, 'show' => null, 'fq' => null], $plain?->query());
        self::assertSame('/ui/review?queue=Key&channel=alt', Html::url('/ui/review', $plain?->pageQuery() ?? []));
        self::assertSame('/ui/review/listing/7?queue=Key&channel=alt', Html::url('/ui/review/listing/7', $plain?->query() ?? []));
        foreach (['held', 'spot'] as $show) {
            self::assertSame('/ui/review?queue=Key&show=' . $show, Html::url('/ui/review', QueueContext::from(['queue' => 'Key', 'show' => $show], 'q', self::STORES)?->pageQuery() ?? []));
        }
    }

    /** A list is narrowed by what it shows, by how a match was found, by the units sold or by a search; the store alone does not narrow it. */
    public function testAListIsNarrowedByAnyOfItsFiltersAndNotByItsStore(): void
    {
        $narrowed = static fn (array $more): bool => (QueueContext::from(['queue' => 'Key'] + $more, 'q', self::STORES) ?? throw new \LogicException('not a list'))->filtered();
        self::assertSame([false, false, false, false, false, false], array_map($narrowed, [[], ['channel' => 'alt'], ['show' => 'all'], ['show' => 'nonsense'], ['min' => '0'],
            ['q' => '   ', 'lane' => 'nonsense']]));
        self::assertSame([true, true, true, true, true, true], array_map($narrowed, [['show' => 'ready'], ['show' => 'held'], ['show' => 'spot'], ['lane' => 'barcode'], ['min' => '3'],
            ['q' => 'elux']]));
        self::assertTrue((QueueContext::from(['queue' => 'Key', 'fq' => 'elux'], 'fq', self::STORES) ?? throw new \LogicException('not a list'))->filtered(), 'on a variant\'s page the search is `fq`');
    }
}
