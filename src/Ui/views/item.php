<?php
// One item, stock first (docs/decisions.md IV1-IV12; the owner's approved design IT_Item, IT_Phone of 29 Oct 2026): the head (its name, the
// status block, its code and short identity line, the actions this person may use: item_bar), the stock strip, the recent movements, where it
// sells; then the tabs that hold the parts and forms of before (Details, Barcodes, Suppliers and packs, Store matching, History). The tabs are
// plain links (?tab=): the page draws every part and opens one, so it works without the script; a #fragment inside a closed part opens it
// too (CSS :target). The open tab has no link (CV13: a click never reloads the page). Every form, rule and permission is as before.
?>
<p class="crumbs"><a href="/ui/items/cards"><?= $word('MENU', 'cards') ?></a></p>
<?php $bar = $partial('item_bar', ['actions' => $actions, 'skuId' => $sku['id']]); ?>
<div class="doc-head item-head">
<?= $head((string) $sku['name'], intro: 'item', side: $bar, meta: $chip($top['tone'], $top['status']) . ' <span class="item-id">' . $e($top['identity']) . '</span>') ?>
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
  <h2 id="stock-h" class="visually-hidden"><?= $word('ITEM', 'stock') ?></h2>
  <div class="doc-step">
    <p class="doc-step-label"><?= $word('ITEM', 'on_shelf') ?></p>
    <p class="doc-step-big"><?= $n($strip['shelf']) ?></p>
    <p class="step-sub"><?php if ($sku['counted_at'] === null): ?><?= $word('COUNTS', 'item_never') ?><?php else: ?><?= $say('COUNTS', 'item_counted', \CW\Ui\Html::day($sku['counted_at'])) ?><?php endif; ?></p>
  </div>
  <div class="doc-step">
    <p class="doc-step-label"><?= $word('ITEM', 'held_orders') ?></p>
    <p class="doc-step-big"><?= $n($strip['allocated'] + $strip['held']) ?></p>
    <p class="step-sub"><?php if ($strip['allocated'] > 0): ?><a href="<?= $u('/ui/stock/reservations', ['q' => $sku['code'], 'state' => 'committed']) ?>" title="<?= $word('ITEM', 'reserved_link') ?>"><?= $say('ITEM', 'held_paid', $strip['allocated']) ?></a><?php endif; ?><?php if ($strip['allocated'] > 0 && $strip['held'] > 0): ?> · <?php endif; ?><?php if ($strip['held'] > 0): ?><a href="<?= $u('/ui/stock/reservations', ['q' => $sku['code'], 'state' => 'held']) ?>" title="<?= $word('ITEM', 'reserved_link') ?>"><?= $say('ITEM', 'held_unpaid', $strip['held']) ?></a><?php endif; ?></p>
  </div>
  <div class="doc-step">
    <p class="doc-step-label"><?= $word('ITEM', 'free') ?></p>
    <p class="doc-step-big free"><?= $n($strip['free']) ?></p>
    <p class="step-sub"><?php if ($strip['incoming'] > 0): ?><?= $say('ITEM', 'free_incoming', $strip['incoming']) ?><?php endif; ?></p>
  </div>
  <div class="doc-step">
    <p class="doc-step-label"><?= $word('ITEM', 'coming') ?></p>
    <p class="doc-step-big"><?= $n($strip['coming']) ?></p>
    <p class="step-sub"><?php if ($strip['first'] !== null): ?><?php if ($strip['canOpenPo']): ?><a href="<?= $u('/ui/purchasing/orders/' . $strip['first']['id']) ?>"><?= $e($strip['first']['number']) ?></a><?php else: ?><?= $e($strip['first']['number']) ?><?php endif; ?><?php if ($strip['first']['due'] !== null): ?> · <?= $say('ITEM', 'coming_due', \CW\Ui\Html::day($strip['first']['due'])) ?><?php endif; ?><?php endif; ?></p>
  </div>
<?php if ($strip['weekly'] !== null): ?>
  <div class="doc-step">
    <p class="doc-step-label"><?= $word('ITEM', 'weekly') ?></p>
    <p class="doc-step-big"><?= $e($strip['weekly']['n']) ?></p>
    <p class="step-sub"><?= $e($strip['weekly']['lasts']) ?></p>
  </div>
<?php endif; ?>
</section>

<details class="fold item-wh">
  <summary><?= $word('ITEM', 'each_warehouse') ?></summary>
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

<section class="item-moves" aria-labelledby="ledger-h">
  <div class="board-head"><h2 id="ledger-h" class="board-title"><?= $word('ITEM', 'movements') ?></h2><a class="board-link" href="<?= $e($allMoves) ?>"><?= $word('ITEM', 'all_moves') ?></a></div>
<?php if ($ledger === []): ?>
  <p class="muted"><?= $word('ITEM', 'no_movements') ?></p>
<?php else: ?>
  <div class="table-wrap doc-board t-primary">
  <table class="stack list board doc-list ledger">
    <thead>
      <tr>
        <th scope="col"><?= $word('ITEM', 'm_when') ?></th>
        <th scope="col"><?= $word('ITEM', 'm_what') ?></th>
        <th scope="col"><?= $word('ITEM', 'm_doc') ?></th>
<?php if ($moveWarehouses): ?>
        <th scope="col"><?= $word('ITEM', 'warehouse') ?></th>
<?php endif; ?>
        <th scope="col"><?= $word('ITEM', 'who') ?></th>
        <th scope="col" class="num"><?= $word('ITEM', 'm_units') ?></th>
        <th scope="col" class="num"><?= $word('ITEM', 'm_after') ?></th>
      </tr>
    </thead>
    <tbody>
<?php foreach ($ledger as $r): ?>
      <tr>
        <th scope="row" class="c-head"><?= $when($r['at']) ?></th>
        <td class="c-status doc-st <?= $e($r['tone']) ?>"><?= $e($r['kind']) ?><?php if ($r['bucket'] !== 'on_hand'): ?><span class="o-sub"><?= $word('STOCK', $r['bucket']) ?></span><?php endif; ?></td>
        <td data-label="<?= $word('ITEM', 'm_doc') ?>"><?php if (($r['record'] ?? null) !== null): ?><a href="<?= $u($r['record']['href']) ?>"><?= $e($r['ref']) ?></a><span class="o-sub"><?= $e(implode(' · ', array_filter([$r['record']['what'], $r['record']['why'], $r['record']['given']]))) ?></span><?php elseif ($r['ref'] !== null): ?><?= $e($r['ref']) ?><span class="o-sub"><?= $e(implode(' · ', array_filter([\CW\Ui\Words::of('MOVEMENT', $r['type']), $r['note']]))) ?></span><?php else: ?><?= $word('MOVEMENT', $r['type']) ?><?php if ($r['note'] !== null): ?><span class="o-sub"><?= $e($r['note']) ?></span><?php endif; ?><?php endif; ?></td>
<?php if ($moveWarehouses): ?>
        <td data-label="<?= $word('ITEM', 'warehouse') ?>"><?= $e(\CW\Ui\Words::STOCK['warehouse_' . $r['warehouse']] ?? $r['warehouse']) ?></td>
<?php endif; ?>
        <td data-label="<?= $word('ITEM', 'who') ?>"><?= $e($r['actor']) ?></td>
        <td data-label="<?= $word('ITEM', 'm_units') ?>" class="num chg <?= $e($r['dir']) ?>"><?= $e($r['signed']) ?></td>
        <td class="num" data-label="<?= $word('ITEM', 'm_after') ?>"><?= $n($r['after']) ?></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?php endif; ?>
</section>

<section class="card item-sells" aria-labelledby="sells-h">
  <h2 id="sells-h"><?= $word('ITEM', 'sells') ?></h2>
<?php if ($sells === []): ?>
  <p class="muted"><?= $word('ITEM', 'sells_none') ?></p>
<?php else: ?>
  <p class="item-chips"><?php foreach ($sells as $st): ?><?= $chip($st['tone'], $st['text']) ?> <?php endforeach; ?></p>
<?php endif; ?>
</section>

<section class="item-parts" id="it-tabs" aria-label="<?= $word('ITEM', 'tabs') ?>">
  <nav class="tabs item-tabs" aria-label="<?= $word('ITEM', 'tabs') ?>">
<?php foreach (\CW\Ui\Controller\ItemController::TABS as $t): ?>
    <a class="tab"<?php if ($tab === $t): ?> aria-current="page"<?php else: ?> href="<?= $u('/ui/items/' . $sku['id'], ['tab' => $t]) ?>#it-tabs"<?php endif; ?>><span><?= $word('ITEM', 'tab_' . $t) ?></span><?php if (($tabCounts[$t] ?? 0) > 0): ?><span class="count"><?= $n($tabCounts[$t]) ?></span><?php endif; ?></a>
<?php endforeach; ?>
  </nav>
  <div class="it-panels">
  <div class="it-panel<?php if ($tab === 'details'): ?> open<?php endif; ?>" id="t-details">
<section class="card item-card<?php if ($rules['level'] === 'block'): ?> blocked<?php elseif ($rules['level'] === 'warn'): ?> warned<?php endif; ?>" id="card" aria-labelledby="card-h">
  <h2 id="card-h"><?= $word('CARD', 'title') ?> <span class="hint"><?= $word('CARD', 'hint') ?></span></h2>
<?php if ($card['state'] === 'none'): ?>
  <p class="card-state"><?= $stateChip('CARD_STATE', 'none') ?> <?= $word('CARD', 'none') ?></p>
<?php elseif ($card['state'] === 'confirmed'): ?>
  <p class="card-state"><?= $stateChip('CARD_STATE', 'confirmed') ?> <?= $say('CARD', 'confirmed', (string) ($card['confirmed_by'] ?? ''), \CW\Ui\Html::when($card['confirmed_at'])) ?></p>
<?php elseif ($card['state'] === 'changed'): ?>
  <p class="card-state"><?= $stateChip('CARD_STATE', 'changed') ?> <?= $say('CARD', 'changed', (string) ($card['updated_by'] ?? ''), \CW\Ui\Html::when($card['updated_at'])) ?></p>
<?php else: ?>
  <p class="card-state"><?= $stateChip('CARD_STATE', 'unconfirmed') ?> <?= $say('CARD', 'unconfirmed', (string) ($card['updated_by'] ?? ''), \CW\Ui\Html::when($card['updated_at'])) ?></p>
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
  <dl class="item-card-fields">
<?php foreach ($card['values'] as $f): ?>
    <dt><?= $e($f['label']) ?></dt>
    <dd><?php if ($f['shown'] === null): ?><span class="muted"><?= $word('CARD', 'not_known') ?></span><?php else: ?><?= $e($f['shown']) ?><?php endif; ?></dd>
<?php endforeach; ?>
  </dl>
<?php foreach ($rules['advice'] as $a): ?>
  <p class="note"><?= $e($a) ?></p>
<?php endforeach; ?>
<?php if ($canEditCard): ?>
  <p class="actions"><a class="btn" href="<?= $u('/ui/items/' . $sku['id'] . '/card') ?>"><?php if ($card['exists']): ?><?= $word('ITEM', 'edit_details') ?><?php else: ?><?= $word('CARD', 'fill_in') ?><?php endif; ?></a></p>
<?php if ($rules['missing'] !== [] && $card['state'] !== 'confirmed'): ?>
  <p class="hint"><?= $say('CARD', 'missing', implode(', ', $rules['missing'])) ?></p>
<?php elseif ($confirmKey !== null): ?>
  <form class="confirm-card" method="post" action="<?= $u('/ui/items/' . $sku['id'] . '/card/confirm') ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="form_key" value="<?= $e($confirmKey) ?>">
    <input type="hidden" name="version" value="<?= $e($card['version']) ?>">
<?php if ($rules['warnings'] !== [] || array_filter($rules['blocked'], static fn (array $b): bool => $b['still']) !== []): ?>
    <label class="choice dup-confirm"><input type="checkbox" name="acknowledge_block" value="1"> <?= $word('CARD', 'acknowledge') ?></label>
<?php endif; ?>
    <button type="submit" class="btn primary"><?= $word('CARD', 'confirm') ?></button>
  </form>
<?php endif; ?>
<?php if ($fileFlavour !== null): ?>
  <form class="inline" method="post" action="<?= $u('/ui/items/' . $sku['id'] . '/card/accept') ?>">
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
          <form class="inline" method="post" action="<?= $u('/ui/items/' . $sku['id'] . '/card/accept') ?>">
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
</section>

<section class="card item-facts" aria-labelledby="id-h">
  <h2 id="id-h"><?= $word('ITEM', 'matching') ?></h2>
  <p class="hint"><?= $word('ITEM', 'matching_hint') ?></p>
  <dl>
<?php foreach (['brand', 'strength_mg', 'nic_type', 'line', 'form', 'flavour', 'volume_ml', 'puffs', 'pack_units'] as $f): ?>
<?php if ($sku[$f] !== null && $sku[$f] !== ''): ?>
    <dt><?= $word('FIELD', $f) ?></dt><dd><?php if ($f === 'form' && \CW\Ui\Words::has('FORM_VALUE', $sku[$f])): ?><?= $word('FORM_VALUE', $sku[$f]) ?><?php elseif ($f === 'nic_type' && \CW\Ui\Words::has('NIC_TYPE', $sku[$f])): ?><?= $word('NIC_TYPE', $sku[$f]) ?><?php else: ?><?= $e($sku[$f]) ?><?php endif; ?></dd>
<?php endif; ?>
<?php endforeach; ?>
    <dt><?= $word('ITEM', 'rule') ?></dt><dd><?= $stateChip('POLICY', $sku['policy']) ?><?php if ($sku['policy'] !== 'legacy'): ?> <span class="muted">(<?= $word('LISTING', 'protected') ?>)</span><?php endif; ?> <?= $explain('stock_rule', \CW\Ui\Words::ITEM['rule']) ?></dd>
    <dt><?= $word('ITEM', 'made_from') ?></dt><dd><?php if ($made_from !== null): ?><a href="/ui/review/listing/<?= $e($made_from['id']) ?>"><?= $e($made_from['text']) ?></a><?php elseif ($sku['origin'] !== null): ?><?= $word('ORIGIN', $sku['origin']) ?><?php else: ?><span class="muted"><?= $word('LISTING', 'not_stated') ?></span><?php endif; ?><?php if ($sku['cwp'] !== null): ?> <span class="muted small"><?= $word('LISTING', 'tech_cwp') ?>: <code><?= $e($sku['cwp']) ?></code></span><?php endif; ?></dd>
    <dt><?= $word('ITEM', 'created') ?></dt><dd><?= $when($sku['created_at']) ?></dd>
    <dt><?= $word('ITEM', 'last_counted') ?></dt><dd><?php if ($sku['counted_at'] === null): ?><span class="muted"><?= $word('ITEM', 'never') ?></span><?php else: ?><?= $when($sku['counted_at']) ?><?php endif; ?></dd>
  </dl>
</section>
  </div>
  <div class="it-panel<?php if ($tab === 'barcodes'): ?> open<?php endif; ?>" id="t-barcodes">
<section class="card" id="barcodes" aria-labelledby="bc-h">
  <h2 id="bc-h"><?= $word('BARCODE', 'title') ?></h2>
<?php if ($barcodes === []): ?>
  <p class="muted"><?= $word('BARCODE', 'none') ?></p>
<?php else: ?>
  <div class="scroll">
  <table class="stack barcodes">
    <thead><tr><th scope="col"><?= $word('BARCODE', 'barcode') ?></th><th scope="col" class="num"><?= $word('BARCODE', 'units') ?></th><th scope="col"><?= $word('BARCODE', 'source') ?></th><th scope="col"><?= $word('BARCODE', 'usable') ?></th><?php if ($canEditBarcodes): ?><th scope="col"><span class="visually-hidden"><?= $word('BARCODE', 'remove') ?></span></th><?php endif; ?></tr></thead>
    <tbody>
<?php foreach ($barcodes as $b): ?>
      <tr>
        <td data-label="<?= $word('BARCODE', 'barcode') ?>"><?= $e($b['barcode']) ?></td>
        <td data-label="<?= $word('BARCODE', 'units') ?>" class="num">
<?php if ($canEditBarcodes && $b['stored']): ?>
          <form class="inline" method="post" action="<?= $u('/ui/items/' . $sku['id'] . '/barcodes/units') ?>">
            <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
            <input type="hidden" name="form_key" value="<?= $e($b['unitsKey']) ?>">
            <input type="hidden" name="barcode" value="<?= $e($b['barcode']) ?>">
            <input type="hidden" name="units_was" value="<?= $e($b['units']) ?>">
            <label class="visually-hidden" for="units-<?= $e($b['barcode']) ?>"><?= $say('BARCODE', 'units_of', (string) $b['barcode']) ?></label>
            <input class="units" id="units-<?= $e($b['barcode']) ?>" type="number" name="units" min="1" max="10000" step="1" inputmode="numeric" value="<?= $e($b['units']) ?>">
            <button class="btn" type="submit"><?= $word('BARCODE', 'save') ?></button>
          </form>
<?php else: ?>
          <?= $n($b['units']) ?><?php if ($b['units'] > 1): ?> <span class="tag"><?= $word('BARCODE', 'outer') ?></span><?php endif; ?>
<?php endif; ?>
        </td>
        <td data-label="<?= $word('BARCODE', 'source') ?>"><?= $e($b['source']) ?><?php if ($b['note'] !== null): ?> <span class="muted"><?= $e($b['note']) ?></span><?php endif; ?></td>
        <td data-label="<?= $word('BARCODE', 'usable') ?>"><?php if ($b['usable']): ?><?= $word('BARCODE', 'yes') ?><?php else: ?><span class="tag blocked"><?= $word('BARCODE', 'no') ?></span><?php endif; ?></td>
<?php if ($canEditBarcodes): ?>
        <td class="c-next">
<?php if ($b['stored']): ?>
          <details class="remove-barcode">
            <summary><?= $word('BARCODE', 'remove') ?></summary>
            <form method="post" action="<?= $u('/ui/items/' . $sku['id'] . '/barcodes/remove') ?>">
              <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
              <input type="hidden" name="form_key" value="<?= $e($b['removeKey']) ?>">
              <input type="hidden" name="barcode" value="<?= $e($b['barcode']) ?>">
              <p class="muted"><?= $say('BARCODE', 'remove_text', (string) $b['barcode'], (string) $sku['code']) ?></p>
              <label for="why-<?= $e($b['barcode']) ?>"><?= $word('BARCODE', 'remove_why') ?></label>
              <input id="why-<?= $e($b['barcode']) ?>" type="text" name="reason" maxlength="500">
              <button class="btn" type="submit"><?= $word('BARCODE', 'remove_button') ?></button>
            </form>
          </details>
<?php endif; ?>
        </td>
<?php endif; ?>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?php endif; ?>
<?php if ($barcodeReviews !== []): ?>
  <p class="note"><?= $word('BARCODE', 'open_reviews') ?>
<?php foreach ($barcodeReviews as $br): ?>
    <?php if ($canSeeReviews): ?><a href="<?= $u('/ui/items/barcodes', ['barcode' => $br['barcode']]) ?>"><?= $e($br['barcode']) ?></a><?php else: ?><?= $e($br['barcode']) ?><?php endif; ?> (<?= $e($br['reason']) ?>)
<?php endforeach; ?>
  </p>
<?php endif; ?>
<?php if ($canEditBarcodes): ?>
  <form class="add-barcode" method="post" action="<?= $u('/ui/items/' . $sku['id'] . '/barcodes') ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="form_key" value="<?= $e($barcodeAddKey) ?>">
    <label for="new-barcode"><?= $word('BARCODE', 'add') ?></label>
    <input id="new-barcode" type="text" name="barcode" inputmode="numeric" autocomplete="off" maxlength="40" value="<?= $e($typedBarcode) ?>" placeholder="<?= $word('BARCODE', 'add_hint') ?>">
    <label for="new-units"><?= $word('BARCODE', 'add_units') ?></label>
    <input id="new-units" class="units" type="number" name="units" min="1" max="10000" step="1" inputmode="numeric" value="<?= $e($typedUnits) ?>">
    <button class="btn" type="submit"><?= $word('BARCODE', 'add_button') ?></button>
    <p class="hint"><?= $word('BARCODE', 'add_text') ?></p>
  </form>
<?php endif; ?>
</section>
  </div>
  <div class="it-panel<?php if ($tab === 'packs'): ?> open<?php endif; ?>" id="t-packs">
<?php if ($suppliers !== null): ?>
<section aria-labelledby="suppliers-h">
  <h2 id="suppliers-h"><?= $word('MENU', 'suppliers') ?></h2>
<?php if ($suppliers === []): ?>
  <p class="muted"><?= $word('SUPPLIER_ITEMS', 'others_none') ?></p>
<?php else: ?>
  <div class="table-wrap">
  <table class="stack list item-suppliers">
    <thead>
      <tr>
        <th scope="col"><?= $word('SUPPLIER_ITEMS', 'supplier') ?></th>
        <th scope="col"><?= $word('SUPPLIER_ITEMS', 'main') ?></th>
        <th scope="col"><?= $word('SUPPLIER_ITEMS', 'their_code') ?></th>
        <th scope="col"><?= $word('SUPPLIER_ITEMS', 'pack') ?></th>
        <th scope="col" class="num"><?= $word('SUPPLIER_ITEMS', 'per_pack') ?></th>
      </tr>
    </thead>
    <tbody>
<?php foreach ($suppliers as $r): ?>
      <tr<?php if (!$r['active']): ?> class="inactive"<?php endif; ?>>
        <th scope="row" class="c-head"><a href="<?= $u('/ui/purchasing/supplier-items/' . $r['id']) ?>"><?= $e($r['name']) ?></a><?php if ($r['status'] !== 'active'): ?> <?= $stateChip('SUPPLIER_STATUS', $r['status']) ?><?php endif; ?><?php if (!$r['active']): ?> <?= $chip('off', \CW\Ui\Words::SUPPLIER_ITEMS['not_used']) ?><?php endif; ?></th>
        <td class="c-status"><?php if ($r['preferred']): ?><?= $chip('done', \CW\Ui\Words::SUPPLIER_ITEMS['main']) ?><?php endif; ?></td>
        <td data-label="<?= $word('SUPPLIER_ITEMS', 'their_code') ?>"><?= $e($r['code']) ?></td>
        <td data-label="<?= $word('SUPPLIER_ITEMS', 'pack') ?>"><?= $e($r['pack']) ?></td>
        <td class="num" data-label="<?= $word('SUPPLIER_ITEMS', 'per_pack') ?>"><?= $e($r['price']) ?><?php if ($r['price_on'] !== null): ?> <span class="o-sub"><?= $day($r['price_on']) ?></span><?php endif; ?></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?php endif; ?>
</section>
<?php endif; ?>
<?= $partial('item_groups', $groups) ?>
  </div>
  <div class="it-panel<?php if ($tab === 'stores'): ?> open<?php endif; ?>" id="t-stores">
<section aria-labelledby="listings-h">
  <h2 id="listings-h"><?= $word('ITEM', 'listings') ?></h2>
<?php if ($coverage_link !== null): ?>
  <p class="see-also"><a href="<?= $e($coverage_link) ?>"><?= $word('ITEM', 'coverage_link') ?></a></p>
<?php endif; ?>
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
  <form class="record selling-mode-form" method="post" action="<?= $u('/ui/items/' . $sku['id'] . '/selling-mode') ?>">
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
  </div>
  <div class="it-panel<?php if ($tab === 'history'): ?> open<?php endif; ?>" id="t-history">
<section class="card card-history" aria-labelledby="hist-h">
  <h2 id="hist-h"><?= $say('CARD', 'history', count($cardHistory)) ?></h2>
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
  </div>
  </div>
</section>
