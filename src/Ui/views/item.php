<?php
// One item, one page in sections (the approved redesign of 30 Oct 2026, artboard Item; docs/decisions.md RD-A4; before it IV1-IV12): the
// back link to Items, the name with its status label, the fact line, More and the ONE main button (item_bar); the stock card (on the shelf,
// held for orders, free to sell, coming in and what it sells a week); then Details (PRODUCT, BUYING for who sees suppliers, LEGAL: the
// product card, its rules, its confirmation and suggestions; Change opens the card's form) and the recent stock history (All history), and
// beside them the side cards: Store listings (each store: its state, the store product matched and Change, or Match…), Barcodes (+ Add, and
// each one's Change: its items per scan, Remove) and Suppliers. There is no tab row: what the tabs of before held that the artboard does not
// draw is a closed fold under the history (each warehouse's stock, selling on the stores with the selling-mode switch, the item group and
// packs, the card's history), which a refused form, a notice or an old ?tab= link opens (ItemController::openPart). Every form keeps main's
// guards (data-unsaved, data-leaves); every rule and permission is as before.
?>
<?php $bar = $partial('item_bar', ['actions' => $actions, 'skuId' => $sku['id']]); ?>
<div class="doc-head item-head">
<?= $head((string) $sku['name'], intro: 'item', side: $bar, back: ['/ui/items/cards', \CW\Ui\Words::INV_LIST['items']], aside: '<span class="tag ' . $e($top['tone']) . '">' . $e($top['status']) . '</span>', meta: '<span class="item-id">' . $e($top['identity']) . '</span>') ?>
</div>

<?php if ($merged_into !== null): ?>
<p class="note"><?= $word('ITEM', 'merged_into') ?> <a href="/ui/items/<?= $e($merged_into['id']) ?>"><?= $e($merged_into['code']) ?></a> <?= $e($merged_into['name']) ?>. <?= $word('ITEM', 'merged_into_text') ?></p>
<?php endif; ?>
<?php if ($merged_from !== []): ?>
<p class="note"><?= $word('ITEM', 'joined_here') ?> <?php foreach ($merged_from as $m): ?><a href="/ui/items/<?= $e($m['id']) ?>"><?= $e($m['code']) ?></a> <?php endforeach; ?><?= $word('ITEM', 'joined_here_text') ?></p>
<?php endif; ?>
<?php if ($dup_groups !== []): ?>
<p class="muted dup-groups"><?= $word('ITEM', 'dups') ?> <?php foreach ($dup_groups as $gref): ?><a href="/ui/review/duplicates/<?= $e($gref['id']) ?>"><?= $say('ITEM', 'group', $gref['number']) ?></a> <?php endforeach; ?></p>
<?php endif; ?>

<?php if ($error !== null): ?>
<p class="error" role="alert"><?= $e($error) ?><?php if ($errorCode === 'barcode_on_other_item' && isset($errorDetail['sku_id'])): ?>
  <a href="<?= $u('/ui/items/' . (int) $errorDetail['sku_id']) ?>"><?= $say('BARCODE', 'open_other', (string) ($errorDetail['code'] ?? \CW\Ui\Words::BARCODE['that_product'])) ?></a><?php endif; ?></p>
<?php endif; ?>

<section class="doc-progress item-strip" aria-labelledby="stock-h">
  <h2 id="stock-h" class="visually-hidden"><?= $word('ITEM_PAGE', 'stock') ?></h2>
  <div class="doc-step">
    <p class="doc-step-label"><?= $word('ITEM', 'on_shelf') ?></p>
    <p class="doc-step-big"><?= $n($strip['shelf']) ?></p>
  </div>
  <div class="doc-step">
    <p class="doc-step-label"><?= $word('ITEM', 'held_orders') ?></p>
    <p class="doc-step-big"><?php if ($strip['allocated'] + $strip['held'] > 0): ?><a href="<?= $u('/ui/stock/reservations', ['q' => $sku['code']]) ?>" title="<?= $word('ITEM', 'reserved_link') ?>"><?= $n($strip['allocated'] + $strip['held']) ?></a><?php else: ?><?= $n(0) ?><?php endif; ?></p>
  </div>
  <div class="doc-step">
    <p class="doc-step-label"><?= $word('ITEM', 'free') ?></p>
    <p class="doc-step-big free"><?= $n($strip['free']) ?></p>
<?php if ($strip['incoming'] > 0): ?>
    <p class="step-sub"><?= $say('ITEM', 'free_incoming', $strip['incoming']) ?></p>
<?php endif; ?>
  </div>
  <div class="doc-step">
    <p class="doc-step-label"><?= $word('ITEM', 'coming') ?></p>
    <p class="doc-step-big"><?= $n($strip['coming']) ?></p>
<?php if ($strip['weekly'] !== null): ?>
    <p class="step-sub"><?= $say('ITEM_PAGE', 'sells_week', $strip['weekly']['n']) ?></p>
<?php endif; ?>
<?php if ($strip['first'] !== null): ?>
    <p class="step-sub"><?php if ($strip['canOpenPo']): ?><a href="<?= $u('/ui/purchasing/orders/' . $strip['first']['id']) ?>"><?= $e($strip['first']['number']) ?></a><?php else: ?><?= $e($strip['first']['number']) ?><?php endif; ?><?php if ($strip['first']['due'] !== null): ?> · <?= $say('ITEM', 'coming_due', \CW\Ui\Html::day($strip['first']['due'])) ?><?php endif; ?></p>
<?php endif; ?>
  </div>
</section>

<div class="item-cols">
<div class="item-main">
<section class="card item-card item-details<?php if ($rules['level'] === 'block'): ?> blocked<?php elseif ($rules['level'] === 'warn'): ?> warned<?php endif; ?>" id="card" aria-labelledby="card-h">
  <div class="card-head"><h2 id="card-h"><?= $word('ITEM_PAGE', 'details') ?></h2><?php if ($canEditCard): ?><a class="card-link" href="<?= $u('/ui/items/' . $sku['id'] . '/card') ?>"><?= $word('ITEM_PAGE', 'change') ?></a><?php endif; ?></div>
<?php foreach ($facts as $grp): ?>
  <div class="facts-group facts-<?= $e($grp['key']) ?>">
    <p class="facts-title"><?= $e($grp['title']) ?></p>
    <dl class="item-facts-grid<?php if ($grp['key'] === 'legal'): ?> item-card-fields<?php endif; ?>">
<?php foreach ($grp['facts'] as $fact): ?>
      <div><dt><?= $e($fact['label']) ?></dt> <dd><?php if ($fact['value'] === null): ?><span class="muted"><?= $word('CARD', 'not_known') ?></span><?php elseif (($fact['href'] ?? null) !== null): ?><a href="<?= $u($fact['href']) ?>"><?= $e($fact['value']) ?></a><?php else: ?><?= $e($fact['value']) ?><?php endif; ?></dd></div>
<?php endforeach; ?>
    </dl>
  </div>
<?php endforeach; ?>
  <div class="facts-more">
<?php if ($card['state'] === 'none'): ?>
  <p class="card-state"><?= $word('CARD', 'none') ?></p>
<?php elseif ($card['state'] === 'confirmed'): ?>
  <p class="card-state"><?= $say('CARD', 'confirmed', (string) ($card['confirmed_by'] ?? ''), \CW\Ui\Html::when($card['confirmed_at'])) ?></p>
<?php elseif ($card['state'] === 'changed'): ?>
  <p class="card-state"><?= $say('CARD', 'changed', (string) ($card['updated_by'] ?? ''), \CW\Ui\Html::when($card['updated_at'])) ?></p>
<?php else: ?>
  <p class="card-state"><?= $say('CARD', 'unconfirmed', (string) ($card['updated_by'] ?? ''), \CW\Ui\Html::when($card['updated_at'])) ?></p>
<?php endif; ?>
<?php if ($rules['blocked'] !== []): ?>
  <div class="rules block" role="status">
    <p><strong><?= $e($rules['blockEffect']) ?></strong></p>
    <ul class="plain">
<?php foreach ($rules['blocked'] as $b): ?>
      <li><span class="tag blocked"><?= $e($b['label']) ?></span> <?= $e($b['why']) ?><?php if (!$b['still']): ?> <span class="muted">(<?= $word('CARD', 'still_blocked') ?>)</span><?php endif; ?></li>
<?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>
<?php if ($rules['warnings'] !== []): ?>
  <div class="rules warn" role="status">
    <p><strong><?= $word('CARD', 'warning') ?></strong></p>
    <ul class="plain">
<?php foreach ($rules['warnings'] as $b): ?>
      <li><span class="tag needs"><?= $e($b['label']) ?></span> <?= $e($b['why']) ?></li>
<?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>
<?php foreach ($rules['advice'] as $a): ?>
  <p class="note"><?= $e($a) ?></p>
<?php endforeach; ?>
<?php if ($canEditCard): ?>
<?php if ($rules['missing'] !== [] && $card['state'] !== 'confirmed'): ?>
  <p class="hint"><?= $say('CARD', 'missing', implode(', ', $rules['missing'])) ?></p>
<?php elseif ($confirmKey !== null): ?>
<?php $ack = $rules['warnings'] !== [] || array_filter($rules['blocked'], static fn (array $b): bool => $b['still']) !== []; ?>
  <form class="confirm-card" method="post" action="<?= $u('/ui/items/' . $sku['id'] . '/card/confirm') ?>"<?php if ($ack): ?> data-unsaved="1" data-unsaved-text="<?= $word('OP_FINAL', 'unsaved') ?>" data-leaves="1"<?php else: ?> data-leaves="1"<?php endif; ?>>
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="form_key" value="<?= $e($confirmKey) ?>">
    <input type="hidden" name="version" value="<?= $e($card['version']) ?>">
<?php if ($ack): ?>
    <label class="choice dup-confirm"><input type="checkbox" name="acknowledge_block" value="1"> <?= $word('CARD', 'acknowledge') ?></label>
<?php endif; ?>
    <button type="submit" class="btn primary"><?= $word('CARD', 'confirm') ?></button>
  </form>
<?php endif; ?>
<?php if ($fileFlavour !== null): ?>
  <form class="inline" method="post" action="<?= $u('/ui/items/' . $sku['id'] . '/card/accept') ?>" data-leaves="1">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="form_key" value="<?= $e($fileFlavour['formKey']) ?>">
    <input type="hidden" name="version" value="<?= $e($card['version']) ?>">
    <input type="hidden" name="field" value="flavour">
    <input type="hidden" name="value" value="<?= $e($fileFlavour['value']) ?>">
    <span><?= $say('CARD', 'file_flavour', (string) $fileFlavour['value']) ?></span> <button class="btn" type="submit"><?= $word('CARD', 'confirm_flavour') ?></button>
  </form>
<?php endif; ?>
<?php if ($proposals !== null): ?>
  <h3><?= $word('CARD', 'suggestions') ?></h3>
<?php if ($proposals === []): ?>
  <p class="muted"><?= $word('CARD', 'no_suggestions') ?></p>
<?php else: ?>
  <p class="hint"><?= $word('CARD', 'suggestions_text') ?><?php if ($disagree !== []): ?> <?= $say('CARD', 'disagree', implode(', ', $disagree)) ?><?php endif; ?></p>
  <div class="scroll">
  <table class="stack proposals">
    <thead><tr><th scope="col"><?= $word('CARD', 'field') ?></th><th scope="col"><?= $word('CARD', 'suggestion') ?></th><th scope="col"><?= $word('CARD', 'says_so') ?></th><th scope="col"><span class="visually-hidden"><?= $word('CARD', 'use') ?></span></th></tr></thead>
    <tbody>
<?php foreach ($proposals as $p): ?>
      <tr>
        <td data-label="<?= $word('CARD', 'field') ?>"><?= $e($p['label']) ?></td>
        <td data-label="<?= $word('CARD', 'suggestion') ?>"><strong><?= $e($p['shown']) ?></strong></td>
        <td data-label="<?= $word('CARD', 'says_so') ?>"><ul class="plain"><?php foreach ($p['sources'] as $src): ?><li><?= $e($src) ?></li><?php endforeach; ?></ul></td>
        <td class="c-next">
          <form class="inline" method="post" action="<?= $u('/ui/items/' . $sku['id'] . '/card/accept') ?>" data-leaves="1">
            <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
            <input type="hidden" name="form_key" value="<?= $e($p['formKey']) ?>">
            <input type="hidden" name="version" value="<?= $e($card['version']) ?>">
            <input type="hidden" name="field" value="<?= $e($p['field']) ?>">
            <input type="hidden" name="value" value="<?= $e($p['value']) ?>">
            <button class="btn" type="submit"><?= $word('CARD', 'use') ?></button>
          </form>
        </td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?php endif; ?>
<?php if ($disposableSources !== []): ?>
  <p class="note"><?= $say('CARD', 'disposable', implode('; ', $disposableSources)) ?></p>
<?php endif; ?>
<?php endif; ?>
<?php endif; ?>
  </div>
</section>

<section class="card item-moves" aria-labelledby="ledger-h">
  <div class="card-head"><h2 id="ledger-h"><?= $word('ITEM_PAGE', 'history') ?></h2><a class="card-link" href="<?= $e($allMoves) ?>"><?= $word('ITEM_PAGE', 'all_history') ?></a></div>
<?php if ($ledger === []): ?>
  <p class="muted card-pad"><?= $word('ITEM_PAGE', 'no_history') ?></p>
<?php else: ?>
  <div class="scroll">
  <table class="item-history ledger">
    <caption class="visually-hidden"><?= $word('ITEM_PAGE', 'history') ?></caption>
    <tbody>
<?php foreach ($ledger as $r): ?>
      <tr>
        <td class="h-when" data-label="<?= $word('ITEM', 'm_when') ?>"><?= $e(\CW\Ui\Html::near($r['at'] === null ? null : (string) $r['at'])) ?></td>
        <td class="h-what" data-label="<?= $word('ITEM', 'm_what') ?>"><span class="m-what"><?= $e($r['kind']) ?></span><?php if ($r['bucket'] !== 'on_hand'): ?> <span class="muted"><?= $word('STOCK', $r['bucket']) ?></span><?php endif; ?> <?php if (($r['record'] ?? null) !== null): ?><a href="<?= $u($r['record']['href']) ?>"><?= $e($r['ref']) ?></a><?php if ($r['record']['why'] !== null || $r['record']['given'] !== null): ?><span class="o-sub"><?= $e(implode(' · ', array_filter([$r['record']['why'], $r['record']['given']]))) ?></span><?php endif; ?><?php elseif ($r['ref'] !== null): ?><span class="m-ref"><?= $e($r['ref']) ?></span><?php endif; ?><?php if ($moveWarehouses): ?><span class="o-sub"><?= $e(\CW\Ui\Words::STOCK['warehouse_' . $r['warehouse']] ?? $r['warehouse']) ?></span><?php endif; ?></td>
        <td data-label="<?= $word('ITEM', 'm_units') ?>" class="num chg <?= $e($r['dir']) ?>"><?= $e($r['signed']) ?></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?php endif; ?>
</section>

<details class="fold it-fold item-wh" id="it-wh"<?php if ($open === 'warehouses'): ?> open<?php endif; ?>>
  <summary><h2 class="fold-h"><?= $word('ITEM_PAGE', 'f_each') ?></h2></summary>
<?php if ($stock === []): ?>
  <p class="muted"><?= $word('ITEM', 'no_stock') ?></p>
<?php else: ?>
  <?= $explain('stock', \CW\Ui\Words::ITEM['stock']) ?>
  <div class="table-wrap">
  <table class="stack list stock">
    <thead>
      <tr>
        <th scope="col"><?= $word('ITEM', 'warehouse') ?></th>
        <th scope="col" class="num"><?= $word('STOCK', 'on_hand') ?></th>
        <th scope="col" class="num"><?= $word('STOCK', 'incoming') ?></th>
        <th scope="col" class="num"><?= $word('STOCK', 'allocated') ?></th>
        <th scope="col" class="num"><?= $word('STOCK', 'held') ?></th>
        <th scope="col" class="num"><?= $word('STOCK', 'available') ?></th>
        <th scope="col"><?= $word('ITEM', 'last_counted') ?></th>
      </tr>
    </thead>
    <tbody>
<?php foreach ($stock as $b): ?>
      <tr>
        <th scope="row" class="c-head"><?= $e($b['name'] ?? $b['code']) ?><?php if (!$b['sellable']): ?> <span class="tag"><?= $word('ITEM', 'not_sellable') ?></span><?php endif; ?></th>
        <td class="num" data-label="<?= $word('STOCK', 'on_hand') ?>"><?= $n($b['on_hand']) ?></td>
        <td class="num" data-label="<?= $word('STOCK', 'incoming') ?>"><?= $n($b['incoming']) ?></td>
        <td class="num" data-label="<?= $word('STOCK', 'allocated') ?>"><?= $n($b['allocated']) ?></td>
        <td class="num" data-label="<?= $word('STOCK', 'held') ?>"><?= $n($b['held']) ?></td>
        <td class="num" data-label="<?= $word('STOCK', 'available') ?>"><?= $n($b['available']) ?></td>
        <td data-label="<?= $word('ITEM', 'last_counted') ?>"><?php if ($b['counted_at'] === null): ?><span class="muted"><?= $word('ITEM', 'never') ?></span><?php else: ?><?= $when($b['counted_at']) ?><?php endif; ?></td>
      </tr>
<?php endforeach; ?>
      <tr class="sub">
        <th scope="row" class="c-head"><?= $word('STOCK', 'total') ?></th>
        <td class="num" data-label="<?= $word('STOCK', 'on_hand') ?>"><?= $n($totals['on_hand']) ?></td>
        <td class="num" data-label="<?= $word('STOCK', 'incoming') ?>"><?= $n($totals['incoming']) ?></td>
        <td class="num" data-label="<?= $word('STOCK', 'allocated') ?>"><?= $n($totals['allocated']) ?></td>
        <td class="num" data-label="<?= $word('STOCK', 'held') ?>"><?= $n($totals['held']) ?></td>
        <td class="num" data-label="<?= $word('STOCK', 'available') ?>"><?= $n($totals['available']) ?></td>
        <td data-label=""></td>
      </tr>
    </tbody>
  </table>
  </div>
<?php endif; ?>
</details>

<details class="fold it-fold" id="it-selling"<?php if ($open === 'selling'): ?> open<?php endif; ?>>
  <summary><h2 class="fold-h"><?= $word('ITEM_PAGE', 'f_selling') ?></h2></summary>
<section aria-labelledby="listings-h">
  <h2 id="listings-h"><?= $word('ITEM', 'listings') ?></h2>
<?php if ($listings === []): ?>
  <p class="muted"><?= $word('ITEM', 'listings_none') ?></p>
<?php else: ?>
  <div class="table-wrap">
  <table class="stack list item-listings">
    <thead>
      <tr>
        <th scope="col"><?= $word('ITEM', 'product') ?></th>
        <th scope="col"><?= $word('ITEM', 'status') ?></th>
        <th scope="col"><?= $word('ITEM', 'website') ?></th>
        <th scope="col" class="num"><?= $word('ITEM', 'uses') ?></th>
        <th scope="col" class="num"><?= $word('ITEM', 'sold_30') ?></th>
        <th scope="col" class="num"><?= $word('ITEM', 'sold_365') ?></th>
        <th scope="col"><?= $word('ITEM', 'history') ?></th>
      </tr>
    </thead>
    <tbody>
<?php foreach ($listings as $r): ?>
      <tr>
        <th scope="row" class="c-head">
          <a class="o-name" href="/ui/review/listing/<?= $e($r['id']) ?>"><?= $e($r['title'] ?? \CW\Ui\Words::LISTING['no_title']) ?></a>
<?php if ($r['variant_title'] !== null): ?>
          <span class="o-sub"><?= $e($r['variant_title']) ?></span>
<?php endif; ?>
        </th>
        <td class="c-status"><?php if ($r['linked']): ?><?= $chip('done', \CW\Ui\Words::ITEM['now_here']) ?><?php elseif ($r['elsewhere']): ?><?= $chip('off', \CW\Ui\Words::ITEM['now_elsewhere']) ?><?php else: ?><?= $stateChip('LISTING_STATUS', $r['status']) ?><?php endif; ?></td>
        <td data-label="<?= $word('ITEM', 'website') ?>"><?= $e($r['channel']) ?> <span class="muted small"><?= $say('QUEUE', 'option', (string) $r['variant']) ?></span></td>
        <td class="num" data-label="<?= $word('ITEM', 'uses') ?>"><?= $n($r['units_per_item']) ?></td>
        <td class="num" data-label="<?= $word('ITEM', 'sold_30') ?>"><?= $n($r['units_30d'] ?? 0) ?></td>
        <td class="num" data-label="<?= $word('ITEM', 'sold_365') ?>"><?= $n($r['units_365d'] ?? 0) ?></td>
        <td data-label="<?= $word('ITEM', 'history') ?>">
<?php if ($r['periods'] === []): ?>
          <span class="muted"><?= $word('ITEM', 'h_none') ?></span>
<?php else: ?>
          <ul class="plain">
<?php foreach ($r['periods'] as $p): ?>
            <li><?php if ($p['to'] === null): ?><?= $say('ITEM', 'h_since', \CW\Ui\Html::day($p['from']), $p['this_item'] ? \CW\Ui\Words::ITEM['h_this'] : (string) $p['sku_code'], \CW\Ui\Words::of('ACTION_DONE', $p['action'] ?? 'link'), (string) ($p['decider'] ?? \CW\Ui\Words::ITEM['by_cw']), \CW\Ui\Words::saleUses($p['units'])) ?><?php else: ?><?= $say('ITEM', 'h_period', \CW\Ui\Html::day($p['from']), \CW\Ui\Html::day($p['to']), $p['this_item'] ? \CW\Ui\Words::ITEM['h_this'] : (string) $p['sku_code'], \CW\Ui\Words::of('ACTION_DONE', $p['action'] ?? 'link'), (string) ($p['decider'] ?? \CW\Ui\Words::ITEM['by_cw']), \CW\Ui\Words::saleUses($p['units'])) ?><?php endif; ?></li>
<?php endforeach; ?>
          </ul>
<?php endif; ?>
        </td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?php endif; ?>
</section>

<section class="selling-mode" id="selling-mode" aria-labelledby="selling-mode-h">
  <div class="head-help">
    <h2 id="selling-mode-h"><?= $word('SELLING', 'title') ?></h2>
    <?= $explain('selling_mode', \CW\Ui\Words::SELLING['help_label']) ?>
  </div>
<?php $sm = $sellingMode; ?>
<?php if (!$sm['legacy']): ?>
  <p class="hint"><?= $say('SELLING', 'counted', \CW\Ui\Words::of('POLICY', $sm['policy'])) ?></p>
<?php else: ?>
  <p class="hint"><?= $word('SELLING', 'legacy') ?></p>
<?php endif; ?>
<?php if ($sm['blocked']): ?>
  <p class="note"><?= $word('SELLING', 'blocked') ?></p>
<?php endif; ?>
  <div class="table-wrap">
  <table class="stack list selling-sites">
    <thead>
      <tr>
        <th scope="col"><?= $word('SELLING', 'website') ?></th>
        <th scope="col"><?= $word('SELLING', 'link') ?></th>
        <th scope="col"><?= $word('SELLING', 'mode') ?></th>
        <th scope="col"><?= $word('SELLING', 'listings') ?></th>
        <th scope="col" class="num"><?= $word('SELLING', 'threshold') ?></th>
        <th scope="col"><?= $word('SELLING', 'set_by') ?></th>
      </tr>
    </thead>
    <tbody>
<?php foreach ($sm['sites'] as $s): ?>
      <tr class="<?php if ($s['writer']): ?>done<?php elseif ($s['writer_waiting']): ?>waiting<?php else: ?>off<?php endif; ?>">
        <th scope="row" class="c-head"><?= $e($s['name']) ?><?php if ($s['receipts']): ?> <span class="o-sub"><?= $word('SELLING', 'receipts') ?></span><?php endif; ?></th>
        <td class="c-status"><?php if ($s['writer']): ?><?= $chip('done', \CW\Ui\Words::SELLING['link_on']) ?><?php elseif ($s['writer_waiting']): ?><?= $chip('waiting', \CW\Ui\Words::SELLING['link_waiting']) ?><?php else: ?><?= $chip('off', \CW\Ui\Words::SELLING['link_off']) ?><?php endif; ?></td>
        <td class="c-wide" data-label="<?= $word('SELLING', 'mode') ?>"><?php if ($s['mode'] === null): ?><span class="hint"><?= $word('SELLING', $s['why'] === 'unlinked' ? 'unlinked' : 'own') ?></span><?php else: ?><strong><?= $e($s['mode']) ?></strong><?php if ($s['backorders'] === 1): ?> <?= $word('SELLING', 'backorders') ?><?php endif; ?> <span class="o-sub"><?= $word('MODE_MEANING', $s['mode']) ?></span><?php endif; ?>
          <?php if ($s['previous'] !== null): ?><span class="o-sub"><?= $say('SELLING', 'before', (string) $s['previous']) ?></span><?php endif; ?>
          <span class="o-sub"><?= $word('SITE_SYNC', $s['channel_mode']) ?></span></td>
        <td data-label="<?= $word('SELLING', 'listings') ?>"><?php if ($s['listings'] === []): ?><span class="hint"><?= $word('SELLING', 'none_linked') ?></span><?php else: ?><?php foreach ($s['listings'] as $l): ?><span class="o-sub"><?php if ($l['units'] !== 1): ?><?= $say('SELLING', 'option_sale', (string) $l['variant'], \CW\Ui\Words::saleUses($l['units'])) ?><?php else: ?><?= $say('SELLING', 'option', (string) $l['variant']) ?><?php endif; ?><?php if ($l['quarantined']): ?> <?= $stateChip('LISTING_STATUS', 'quarantined') ?><?php endif; ?></span><?php endforeach; ?><?php endif; ?></td>
        <td class="num" data-label="<?= $word('SELLING', 'threshold') ?>"><?php if ($s['threshold'] === null && $s['level'] !== null): ?><?= $n($s['level']) ?> <span class="o-sub"><?= $word('REORDER_LOW', 'item_figure') ?></span><?php elseif ($s['threshold'] === null): ?><span class="hint"><?= $word('SELLING', 'site_own') ?></span><?php else: ?><?= $n($s['threshold']) ?><?php endif; ?></td>
        <td data-label="<?= $word('SELLING', 'set_by') ?>"><?php if ($s['set_by'] === null): ?><span class="hint"><?= $word('SELLING', 'never_set') ?></span><?php else: ?><?= $say('SELLING', 'set_line', \CW\Ui\Words::SELLING[$s['set_source'] === 'receipt' ? 'by_receipt' : 'by_switch'], (string) $s['set_by'], \CW\Ui\Html::when((string) $s['set_at'])) ?><?php endif; ?></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?php if ($sm['canSet']): ?>
  <form class="record selling-mode-form" method="post" action="<?= $u('/ui/items/' . $sku['id'] . '/selling-mode') ?>" data-unsaved="1" data-unsaved-text="<?= $word('OP_FINAL', 'unsaved') ?>" data-leaves="1">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="form_key" value="<?= $e($sm['formKey']) ?>">
    <input type="hidden" name="stamp" value="<?= $e($sm['stamp']) ?>">
    <fieldset>
      <legend><?= $word('SELLING', 'change') ?></legend>
<?php foreach ($sm['modes'] as $m): ?>
      <label class="choice"><input type="radio" name="mode" value="<?= $e($m['value']) ?>"<?php if ($sm['typed']['mode'] === $m['value']): ?> checked<?php endif; ?> required>
        <?= $say('SELLING', 'mode_meaning', (string) $m['value'], \CW\Ui\Words::of('MODE_MEANING', (string) $m['value'])) ?></label>
<?php endforeach; ?>
    </fieldset>
    <fieldset>
      <legend><?= $word('SELLING', 'on_sites') ?></legend>
      <label class="choice"><input type="checkbox" name="all_sites" value="1"<?php if ($sm['typed']['all']): ?> checked<?php endif; ?>> <?= $word('SELLING', 'all_sites') ?></label>
<?php foreach ($sm['sites'] as $s): ?>
      <label class="choice"><input type="checkbox" name="site_<?= $e($s['code']) ?>" value="1"<?php if ($s['checked']): ?> checked<?php endif; ?>> <?= $e($s['name']) ?></label>
<?php endforeach; ?>
    </fieldset>
    <label for="sm-threshold"><?= $word('SELLING', 'threshold') ?> <span class="hint"><?= $word('SELLING', 'optional') ?></span>
      <input id="sm-threshold" name="threshold" inputmode="numeric" pattern="[0-9]*" maxlength="6" value="<?= $e($sm['typed']['threshold']) ?>"></label>
    <label for="sm-reason"><?= $word('SELLING', 'why') ?> <span class="hint"><?= $word('SELLING', 'why_hint') ?></span>
      <input id="sm-reason" name="reason" required minlength="3" maxlength="500" value="<?= $e($sm['typed']['reason']) ?>"></label>
    <p class="actions"><button type="submit" class="btn lg"><span class="btn-title"><?= $word('SELLING', 'save') ?></span> <span class="sub"><?= $word('SELLING', 'save_does') ?></span></button></p>
  </form>
<?php endif; ?>
</section>
</details>

<details class="fold it-fold" id="it-groups"<?php if ($open === 'groups'): ?> open<?php endif; ?>>
  <summary><h2 class="fold-h"><?= $word('ITEM_PAGE', 'f_groups') ?></h2></summary>
<?= $partial('item_groups', $groups) ?>
</details>

<details class="fold it-fold" id="it-history"<?php if ($open === 'history'): ?> open<?php endif; ?>>
  <summary><h2 class="fold-h"><?= $say('CARD', 'history', count($cardHistory)) ?></h2></summary>
<section class="card-history" aria-labelledby="hist-h">
<?php if ($cardHistory === []): ?>
  <p class="muted"><?= $word('ITEM', 'history_none') ?></p>
<?php else: ?>
  <ol class="plain">
<?php foreach ($cardHistory as $h): ?>
    <li><?= $say('CARD', 'h_line', \CW\Ui\Html::when((string) $h['at']), (string) $h['who'], (string) $h['what']) ?></li>
<?php endforeach; ?>
  </ol>
<?php endif; ?>
</section>
</details>
</div>

<aside class="item-side" aria-label="<?= $word('ITEM', 'tabs') ?>">
<section class="card side-card item-stores" aria-labelledby="st-h">
  <div class="card-head"><h2 id="st-h"><?= $word('ITEM_PAGE', 'listings') ?></h2><?php if ($coverage_link !== null): ?><a class="card-link" href="<?= $e($coverage_link) ?>"><?= $word('ITEM_PAGE', 'matching') ?></a><?php endif; ?></div>
<?php if ($storeRows === []): ?>
  <p class="muted side-row"><?= $word('ITEM', 'sells_none') ?></p>
<?php endif; ?>
<?php foreach ($storeRows as $st): ?>
  <div class="side-row" title="<?= $e($st['title']) ?>">
    <p class="side-line"><span class="side-name"><?= $e($st['store']) ?></span><?php if ($st['match'] !== null): ?><a class="card-link" href="<?= $e($st['match']) ?>"><?= $word('ITEM_PAGE', 'match') ?></a><?php else: ?><span class="tag <?= $e($st['tone']) ?>"><?= $e($st['state']) ?></span><?php endif; ?></p>
<?php foreach ($st['listings'] as $l): ?>
    <p class="side-sub"><?= $e($l['line']) ?></p>
<?php if ($l['href'] !== null): ?>
    <a class="card-link" href="<?= $u($l['href']) ?>"><?= $word('ITEM_PAGE', 'listing_change') ?></a>
<?php endif; ?>
<?php endforeach; ?>
  </div>
<?php endforeach; ?>
</section>

<section class="card side-card item-barcodes" id="barcodes" aria-labelledby="bc-h">
  <div class="card-head"><h2 id="bc-h"><?= $word('ITEM_PAGE', 'barcodes') ?></h2></div>
<?php if ($canEditBarcodes): ?>
  <details class="card-add"<?php if ($open === 'barcodes' && $error !== null): ?> open<?php endif; ?>>
    <summary class="card-link"><?= $word('ITEM_PAGE', 'add') ?><span class="visually-hidden"> <?= $word('BARCODE', 'add') ?></span></summary>
  <form class="add-barcode" method="post" action="<?= $u('/ui/items/' . $sku['id'] . '/barcodes') ?>" data-unsaved="1" data-unsaved-text="<?= $word('OP_FINAL', 'unsaved') ?>" data-leaves="1">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="form_key" value="<?= $e($barcodeAddKey) ?>">
    <label for="new-barcode"><?= $word('BARCODE', 'add') ?></label>
    <input id="new-barcode" type="text" name="barcode" inputmode="numeric" autocomplete="off" maxlength="40" value="<?= $e($typedBarcode) ?>" placeholder="<?= $word('BARCODE', 'add_hint') ?>">
    <label for="new-units"><?= $word('BARCODE', 'add_units') ?></label>
    <input id="new-units" class="units" type="number" name="units" min="1" max="10000" step="1" inputmode="numeric" value="<?= $e($typedUnits) ?>">
    <button class="btn" type="submit"><?= $word('BARCODE', 'add_button') ?></button>
    <p class="hint"><?= $word('BARCODE', 'add_text') ?></p>
  </form>
  </details>
<?php endif; ?>
<?php if ($barcodeLines === []): ?>
  <p class="muted side-row"><?= $word('BARCODE', 'none') ?></p>
<?php else: ?>
  <ul class="plain side-list barcodes">
<?php foreach ($barcodeLines as $b): ?>
    <li class="side-row">
      <p class="side-line"><span><?= $e($b['barcode']) ?> <span class="muted">· <?= $e($b['units_words']) ?></span><?php if (!$b['usable']): ?> <span class="tag blocked"><?= $word('ITEM_PAGE', 'not_usable') ?></span><?php endif; ?></span></p>
      <p class="side-sub"><?= $e($b['source']) ?><?php if ($b['note'] !== null): ?> · <?= $e($b['note']) ?><?php endif; ?></p>
<?php if ($canEditBarcodes && $b['stored']): ?>
        <details class="bc-change">
          <summary><?= $word('ITEM_PAGE', 'barcode_change') ?><span class="visually-hidden"> <?= $e($b['barcode']) ?></span></summary>
          <form class="inline" method="post" action="<?= $u('/ui/items/' . $sku['id'] . '/barcodes/units') ?>" data-unsaved="1" data-unsaved-text="<?= $word('OP_FINAL', 'unsaved') ?>" data-leaves="1">
            <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
            <input type="hidden" name="form_key" value="<?= $e($b['unitsKey']) ?>">
            <input type="hidden" name="barcode" value="<?= $e($b['barcode']) ?>">
            <input type="hidden" name="units_was" value="<?= $e($b['units']) ?>">
            <label for="units-<?= $e($b['barcode']) ?>"><?= $word('BARCODE', 'units') ?></label>
            <input class="units" id="units-<?= $e($b['barcode']) ?>" type="number" name="units" min="1" max="10000" step="1" inputmode="numeric" value="<?= $e($b['units']) ?>">
            <button class="btn sm" type="submit"><?= $word('BARCODE', 'save') ?></button>
          </form>
          <details class="remove-barcode">
            <summary><?= $word('BARCODE', 'remove') ?></summary>
            <form method="post" action="<?= $u('/ui/items/' . $sku['id'] . '/barcodes/remove') ?>" data-unsaved="1" data-unsaved-text="<?= $word('OP_FINAL', 'unsaved') ?>" data-leaves="1">
              <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
              <input type="hidden" name="form_key" value="<?= $e($b['removeKey']) ?>">
              <input type="hidden" name="barcode" value="<?= $e($b['barcode']) ?>">
              <p class="muted"><?= $say('BARCODE', 'remove_text', (string) $b['barcode'], (string) $sku['code']) ?></p>
              <label for="why-<?= $e($b['barcode']) ?>"><?= $word('BARCODE', 'remove_why') ?></label>
              <input id="why-<?= $e($b['barcode']) ?>" type="text" name="reason" maxlength="500">
              <button class="btn" type="submit"><?= $word('BARCODE', 'remove_button') ?></button>
            </form>
          </details>
        </details>
<?php endif; ?>
    </li>
<?php endforeach; ?>
  </ul>
<?php endif; ?>
<?php if ($barcodeReviews !== []): ?>
  <p class="note"><?= $word('BARCODE', 'open_reviews') ?>
<?php foreach ($barcodeReviews as $br): ?>
    <?php if ($canSeeReviews): ?><a href="<?= $u('/ui/items/barcodes', ['barcode' => $br['barcode']]) ?>"><?= $e($br['barcode']) ?></a><?php else: ?><?= $e($br['barcode']) ?><?php endif; ?> (<?= $e($br['reason']) ?>)
<?php endforeach; ?>
  </p>
<?php endif; ?>
</section>

<?php if ($supplierRows !== null): ?>
<section class="card side-card item-suppliers" aria-labelledby="sp-h">
  <div class="card-head"><h2 id="sp-h"><?= $word('ITEM_PAGE', 'suppliers') ?></h2></div>
<?php if ($supplierRows === [] && $supplierRowsOff === []): ?>
  <p class="muted side-row"><?= $word('ITEM_PAGE', 'no_suppliers') ?></p>
<?php endif; ?>
<?php foreach ([...$supplierRows, ...$supplierRowsOff] as $r): ?>
  <div class="side-row">
    <p class="side-line"><span><a href="<?= $u('/ui/purchasing/supplier-items/' . $r['id']) ?>"><?= $e($r['name']) ?></a><?php if ($r['preferred']): ?> <span class="tag info"><?= $word('ITEM_PAGE', 'main') ?></span><?php endif; ?><?php if ($r['status'] !== 'active'): ?> <?= $stateChip('SUPPLIER_STATUS', $r['status']) ?><?php endif; ?><?php if (!$r['active']): ?> <span class="tag off"><?= $word('ITEM_PAGE', 'not_used') ?></span><?php endif; ?></span></p>
    <p class="side-sub"><?= $e($r['pack']) ?><?php if ($r['price'] !== null): ?> · <?= $say('ITEM_PAGE', 'last_price', (string) $r['price']) ?><?php endif; ?><?php if ($r['code'] !== null): ?> · <?= $e($r['code']) ?><?php endif; ?></p>
  </div>
<?php endforeach; ?>
</section>
<?php endif; ?>
</aside>
</div>
