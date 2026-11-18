<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Handovers;

use CW\Tests\Support\LineUids;

/**
 * A hand-over through its own service (Handovers::saveLines -> Documents::setLines; 0070, docs/decisions.md LV8): its lines get ids at each
 * save, and its posting's fingerprint covers none.
 */
final class HandoverLineUidTest extends HandoversTestCase
{
    use LineUids;

    public function testAHandoverGetsLineIdsAndItsFingerprintCoversNone(): void
    {
        $sku = $this->item('strict', 20);
        $other = $this->item('legacy', 5);
        $desk = $this->staffUser('purchasing_desk');
        $cp = $this->customer('Uid Trade Ltd', 'trade', ['vat_code' => 'S']);
        $d = $this->draftTo($desk, $cp, [['sku_id' => $sku, 'qty' => 6, 'unit_cost' => '2.50'], ['sku_id' => $other, 'qty' => 3, 'unit_cost' => '1.25']]);
        self::assertSame(['TRD', [1, 2], 3], [$d->docType, self::uidsOf($d->id), self::nextUidOf($d->id)]);
        $d = $this->handovers->saveLines($desk, $d->id, $d->version, [['sku_id' => $other, 'qty' => 3, 'unit_cost' => '1.25']]);
        self::assertSame([3], self::uidsOf($d->id));
        $p = $this->handovers->post($desk, $d->id, $d->version);
        self::assertSame('posted', $p->status);
        self::assertPostingIgnoresUids($this->docs, $p->id);
    }
}
