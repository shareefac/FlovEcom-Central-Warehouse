<?php
// Settings > Own items (CW-1153; docs/decisions.md VB4), on the settings shell like Reasons: the items that are a shop's own goods (its own
// stock, not ours: our figures leave them out), each with what it holds and its buying price; for an admin or a reviewer: add an item or
// remove one, each with its reason; every period with who and why under it. Nothing is rewritten.
$T = \CW\Ui\Words::OWN_ITEMS;
$shopName = $shop === null ? '' : (string) $shop['name'];
$help = ($shop === null ? $T['no_shop'] : \CW\Ui\Words::say('OWN_ITEMS', 'help', $shopName, $shopName)) . ($lookOnly !== null ? ' ' . \CW\Ui\Words::say('UI', 'look_only', $lookOnly) : '');
?>
<?= $head(\CW\Ui\Words::PAGE_TITLE['own_items'], intro: 'own_items', more: $help) ?>
<div class="set-shell">
<?= $partial('settings_nav', ['current' => 'own_items']) ?>
<div class="set-main">
<?php if ($error !== null): ?>
<p class="error" role="alert"><?= $e($error) ?></p>
<?php endif; ?>
<?php if ($canEdit): ?>
<details class="fold drawer" id="new"<?php if ($error !== null && $typed['code'] !== ''): ?> open<?php endif; ?>>
  <summary><?= $e(\CW\Ui\Words::say('OWN_ITEMS', 'add_title', $shopName)) ?></summary>
  <form class="record" method="post" action="/ui/reference/own-items/add">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <label><?= $word('OWN_ITEMS', 'code') ?>
      <input type="text" name="code" value="<?= $e($typed['code']) ?>" maxlength="16" required placeholder="CW-000123">
    </label>
    <label><?= $word('CONFIG', 'reason') ?> <span class="hint"><?= $word('CONFIG', 'reason_hint') ?></span>
      <textarea name="reason" rows="2" minlength="3" maxlength="500" required><?= $e($typed['reason']) ?></textarea>
    </label>
    <p class="hint"><?= $e(\CW\Ui\Words::say('OWN_ITEMS', 'add_hint', $shopName)) ?></p>
    <p class="actions"><button type="submit" class="btn primary"><?= $word('OWN_ITEMS', 'add_button') ?></button></p>
  </form>
</details>
<?php endif; ?>
<div class="set-list">
<section class="set-card own-items" aria-labelledby="own-items-h">
  <div class="set-head"><h2 id="own-items-h"><?= $e(\CW\Ui\Words::say('OWN_ITEMS', 'title', $shopName)) ?></h2>
    <p class="set-kd"><?= $e(\CW\Ui\Words::say('OWN_ITEMS', 'totals', $n(count($items)), $n($units), $value)) ?><?php if ($unpriced > 0): ?> <?= $e(\CW\Ui\Words::say('OWN_ITEMS', 'unpriced', $n($unpriced))) ?><?php endif; ?></p></div>
<?php if ($items === []): ?>
  <p class="empty"><?= $word('OWN_ITEMS', 'none') ?></p>
<?php else: ?>
  <div class="table-wrap">
  <table class="stack rp-table own-item-list">
    <thead>
      <tr>
        <th scope="col"><?= $word('OWN_ITEMS', 'item') ?></th>
        <th scope="col"><?= $word('OWN_ITEMS', 'since') ?></th>
        <th scope="col" class="num"><?= $word('OWN_ITEMS', 'on_hand') ?></th>
        <th scope="col" class="num"><?= $word('OWN_ITEMS', 'sold_waiting') ?></th>
        <th scope="col" class="num"><?= $word('OWN_ITEMS', 'price') ?></th>
<?php if ($canEdit): ?>
        <th scope="col"><?= $word('OWN_ITEMS', 'remove') ?></th>
<?php endif; ?>
      </tr>
    </thead>
    <tbody>
<?php foreach ($items as $it): ?>
      <tr>
        <th scope="row"><?= $e($it['code']) ?> <span class="o-sub"><?= $e($it['name']) ?></span></th>
        <td data-label="<?= $word('OWN_ITEMS', 'since') ?>"><?= $e(\CW\Ui\Html::when($it['from_at'])) ?></td>
        <td class="num" data-label="<?= $word('OWN_ITEMS', 'on_hand') ?>"><?= $n($it['on_hand']) ?></td>
        <td class="num" data-label="<?= $word('OWN_ITEMS', 'sold_waiting') ?>"><?= $n($it['allocated'] + $it['held']) ?></td>
        <td class="num" data-label="<?= $word('OWN_ITEMS', 'price') ?>"><?php if ($it['unit_cost_e4'] === null): ?><?= $word('OWN_ITEMS', 'no_price') ?><?php elseif ($it['unit_cost_e4'] === 0): ?><?= $word('OWN_ITEMS', 'free') ?><?php else: ?><?= $e(\CW\Profit\ProfitViews::price($it['unit_cost_e4'])) ?><?php endif; ?></td>
<?php if ($canEdit): ?>
        <td data-label="<?= $word('OWN_ITEMS', 'remove') ?>">
          <form class="inline" method="post" action="/ui/reference/own-items/remove">
            <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
            <input type="hidden" name="code" value="<?= $e($it['code']) ?>">
            <input type="text" name="reason" value="" minlength="3" maxlength="500" required placeholder="<?= $word('OWN_ITEMS', 'why') ?>" aria-label="<?= $word('CONFIG', 'reason') ?>">
            <button type="submit" class="btn"><?= $word('OWN_ITEMS', 'remove_button') ?></button>
          </form>
        </td>
<?php endif; ?>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?php endif; ?>
</section>
<details class="fold own-items-history">
  <summary><?= $word('OWN_ITEMS', 'history') ?></summary>
<?php if ($history === []): ?>
  <p class="empty"><?= $word('OWN_ITEMS', 'no_history') ?></p>
<?php else: ?>
  <ul class="history">
<?php foreach ($history as $h): ?>
    <li><?= $e(\CW\Ui\Words::say('OWN_ITEMS', 'period', $h['code'], $h['shop'], \CW\Ui\Html::when($h['from_at']), $h['added_by'], $h['add_reason'])) ?><?php if ($h['to_at'] !== null): ?> <?= $e(\CW\Ui\Words::say('OWN_ITEMS', 'period_end', \CW\Ui\Html::when($h['to_at']), (string) $h['removed_by'], (string) $h['remove_reason'])) ?><?php endif; ?></li>
<?php endforeach; ?>
  </ul>
<?php endif; ?>
</details>
</div>
</div>
</div>
