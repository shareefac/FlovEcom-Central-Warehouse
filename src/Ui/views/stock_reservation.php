<?php
// One reservation (docs/decisions.md RS8-RS9): read only. Its facts, one line per product, warehouse and state, and what the engine
// booked on the stock ledger for the order (the only history it keeps).
?>
<p class="crumbs"><a href="<?= $e($back) ?>"><?= $word('MENU', 'reservations') ?></a></p>
<h1><?= $e($title) ?> <?= $chip($tone, $chipWord) ?></h1>
<?= $intro('reservation') ?>

<section aria-labelledby="facts-h">
  <h2 id="facts-h" class="visually-hidden"><?= $word('RESV', 'facts') ?></h2>
  <dl class="wide">
<?php foreach ($facts as $fact): ?>
    <dt><?= $e($fact[0]) ?></dt><dd><?= $e($fact[1]) ?></dd>
<?php endforeach; ?>
  </dl>
</section>

<section aria-labelledby="lines-h">
  <h2 id="lines-h"><?= $word('RESV', 'lines') ?></h2>
<?php if ($lines === []): ?>
  <p class="muted"><?= $word('RESV', 'no_lines') ?></p>
<?php else: ?>
  <div class="table-wrap">
  <table class="stack list board row-strips reservation-lines">
    <thead>
      <tr>
        <th scope="col" class="c-item"><?= $word('RESV', 'l_product') ?></th>
        <th scope="col"><?= $word('RESV', 'l_warehouse') ?></th>
        <th scope="col" class="c-status"><?= $word('RESV', 'l_status') ?></th>
        <th scope="col" class="num"><?= $word('RESV', 'l_items') ?></th>
        <th scope="col" class="num"><?= $word('RESV', 'l_units') ?></th>
      </tr>
    </thead>
    <tbody>
<?php foreach ($lines as $l): ?>
      <tr class="<?= $e($l['tone']) ?>">
        <th scope="row" class="c-head c-item"><?php if ($l['sku_id'] !== null): ?><a class="o-name" href="<?= $u('/ui/items/' . $l['sku_id']) ?>"><?= $e($l['name']) ?></a><?php else: ?><?= $e($l['name']) ?><?php endif; ?><span class="o-sub"><?= $e($l['sub']) ?></span></th>
        <td data-label="<?= $word('RESV', 'l_warehouse') ?>"><?= $e($l['warehouse']) ?></td>
        <td class="c-status"><?= $chip($l['tone'], $l['state']) ?></td>
        <td class="num" data-label="<?= $word('RESV', 'l_items') ?>"><?= $n($l['items']) ?></td>
        <td class="num" data-label="<?= $word('RESV', 'l_units') ?>"><?= $n($l['units']) ?></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?php endif; ?>
</section>

<section aria-labelledby="history-h">
  <h2 id="history-h"><?= $word('RESV', 'history') ?></h2>
<?php if ($history === []): ?>
  <p class="muted"><?= $word('RESV', 'no_history') ?></p>
<?php else: ?>
  <p class="muted"><?= $word('RESV', 'history_text') ?></p>
  <div class="table-wrap">
  <table class="stack list ledger reservation-history">
    <thead>
      <tr>
        <th scope="col"><?= $word('RESV', 'h_what') ?></th>
        <th scope="col"><?= $word('RESV', 'h_when') ?></th>
        <th scope="col" class="num"><?= $word('RESV', 'h_items') ?></th>
        <th scope="col"><?= $word('RESV', 'h_figures') ?></th>
        <th scope="col"><?= $word('RESV', 'h_who') ?></th>
      </tr>
    </thead>
    <tbody>
<?php foreach ($history as $h): ?>
      <tr>
        <th scope="row" class="c-head"><?= $e($h['what']) ?></th>
        <td data-label="<?= $word('RESV', 'h_when') ?>"><?= $when($h['at']) ?></td>
        <td class="num" data-label="<?= $word('RESV', 'h_items') ?>"><?= $n($h['items']) ?></td>
        <td data-label="<?= $word('RESV', 'h_figures') ?>"><?= $e($h['figures']) ?></td>
        <td data-label="<?= $word('RESV', 'h_who') ?>"><?= $e($h['who']) ?></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?php endif; ?>
</section>
