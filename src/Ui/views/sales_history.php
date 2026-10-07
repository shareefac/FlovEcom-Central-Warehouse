<h1><?= $word('MENU', 'sales_history') ?></h1>
<?= $intro('sales_history') ?>
<?php if ($channels === []): ?>
<?= $empty(\CW\Ui\Words::SALES['none'], \CW\Ui\Words::SALES['none_text']) ?>
<?php endif; ?>
<?php foreach ($channels as $c): ?>
<section class="card box" aria-labelledby="ch-<?= $e($c['code']) ?>">
  <h2 id="ch-<?= $e($c['code']) ?>"><?= $e($c['name']) ?></h2>
  <dl>
    <dt><?= $word('SALES', 'loaded') ?></dt> <dd><?= $say('SALES', 'loaded_line', \CW\Ui\Html::day($c['s']), \CW\Ui\Html::day($c['e'])) ?></dd>
<?php if ($c['last_at'] !== null): ?>
    <dt><?= $word('SALES', 'last_load') ?></dt> <dd><?= $say('SALES', 'last_load_line', \CW\Ui\Html::day((string) $c['last_to']), \CW\Ui\Html::when((string) $c['last_at'])) ?></dd>
<?php endif; ?>
    <dt><?= $word('SALES', 'stock_days') ?></dt> <dd><?php if ($c['snapshot_days'] === 0): ?><?= $word('SALES', 'stock_days_none') ?><?php else: ?><?= $say('SALES', 'stock_days_line', $c['snapshot_days'], \CW\Ui\Html::day($c['snapshot_first']), \CW\Ui\Html::day($c['snapshot_last'])) ?><?php endif; ?></dd>
    <dt><?= $word('SALES', 'latest') ?></dt> <dd><?php if ($c['latest_date'] === null): ?><?= $word('SALES', 'latest_none') ?><?php else: ?><?= $say('SALES', 'latest_line', $c['latest_rows'], \CW\Ui\Html::day($c['latest_date'])) ?><?php endif; ?></dd>
  </dl>
  <h3><?= $say('SALES', 'unmatched', $top, $days) ?></h3>
<?php if ($c['top'] === []): ?>
  <p class="muted"><?= $word('SALES', 'all_matched') ?></p>
<?php else: ?>
  <p class="hint"><?= $word('SALES', 'unmatched_text') ?></p>
  <div class="table-wrap">
  <table class="stack list unlinked">
    <thead>
      <tr>
        <th scope="col"><?= $word('SALES', 'product') ?></th>
        <th scope="col"><?= $word('SALES', 'problem') ?></th>
        <th scope="col" class="num"><?= $word('SALES', 'sold') ?></th>
        <th scope="col"><?= $word('SALES', 'last_sale') ?></th>
      </tr>
    </thead>
    <tbody>
<?php foreach ($c['top'] as $t): ?>
      <tr>
        <th scope="row" class="c-head"><?php if ($canLink && $t['listing_id'] !== null): ?><a class="o-name" href="<?= $u('/ui/review/listing/' . $t['listing_id']) ?>"><?= $e($t['product_title'] ?? $t['variant']) ?></a><?php else: ?><span class="o-name"><?= $e($t['product_title'] ?? $t['variant']) ?></span><?php endif; ?>
          <span class="o-sub"><?php if ($t['variant_title'] !== null): ?><?= $e($t['variant_title']) ?> · <?php endif; ?><?= $say('SALES', 'option', $t['variant']) ?><?php if ($t['brand'] !== null): ?> · <?= $e($t['brand']) ?><?php endif; ?></span></th>
        <td class="c-status"><?= $chip($t['listing_id'] === null ? 'off' : 'needs', $t['problem']) ?></td>
        <td class="num" data-label="<?= $word('SALES', 'sold') ?>"><?= $n($t['units']) ?></td>
        <td data-label="<?= $word('SALES', 'last_sale') ?>"><?= $day($t['last_sale']) ?></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?php endif; ?>
  <p class="actions"><a href="<?= $u('/ui/purchasing/sales-history/unlinked.csv', ['channel' => $c['code']]) ?>"><?= $word('SALES', 'download') ?></a></p>
</section>
<?php endforeach; ?>
<?php if ($batches !== []): ?>
<details class="tech-details">
  <summary><?= $word('SALES', 'tech') ?></summary>
  <h2 class="section-title"><?= $word('SALES', 'batches') ?></h2>
  <div class="scroll">
  <table class="batches">
    <thead>
      <tr>
        <th scope="col"><?= $word('SALES', 'batch') ?></th>
        <th scope="col"><?= $word('SALES', 'site') ?></th>
        <th scope="col"><?= $word('SALES', 'days') ?></th>
        <th scope="col"><?= $word('SALES', 'state') ?></th>
        <th scope="col" class="num"><?= $word('SALES', 'rows') ?></th>
        <th scope="col" class="num"><?= $word('SALES', 'units') ?></th>
        <th scope="col" class="num"><?= $word('SALES', 'unknown_units') ?></th>
        <th scope="col" class="num"><?= $word('SALES', 'unlinked_units') ?></th>
        <th scope="col" class="num"><?= $word('SALES', 'stock_rows') ?></th>
        <th scope="col"><?= $word('SALES', 'when') ?></th>
      </tr>
    </thead>
    <tbody>
<?php foreach ($batches as $b): ?>
      <tr>
        <th scope="row"><?= $n($b['id']) ?> <code><?= $e($b['sales_file']) ?></code></th>
        <td data-label="<?= $word('SALES', 'site') ?>"><?= $e($b['channel']) ?> <code><?= $e($b['source']) ?></code></td>
        <td data-label="<?= $word('SALES', 'days') ?>"><?= $say('SALES', 'loaded_line', \CW\Ui\Html::day($b['date_from']), \CW\Ui\Html::day($b['date_to'])) ?></td>
        <td data-label="<?= $word('SALES', 'state') ?>"><code><?= $e($b['status']) ?></code><?php if ($b['error'] !== null): ?> <span class="tag bad"><?= $e($b['error']) ?></span><?php endif; ?></td>
        <td class="num" data-label="<?= $word('SALES', 'rows') ?>"><?= $n($b['rows_loaded']) ?></td>
        <td class="num" data-label="<?= $word('SALES', 'units') ?>"><?= $n($b['units_loaded']) ?></td>
        <td class="num" data-label="<?= $word('SALES', 'unknown_units') ?>"><?= $n($b['unknown_units']) ?> <span class="muted"><?= $pct((int) $b['unknown_units'], (int) $b['units_loaded']) ?></span></td>
        <td class="num" data-label="<?= $word('SALES', 'unlinked_units') ?>"><?= $n($b['unlinked_units']) ?> <span class="muted"><?= $pct((int) $b['unlinked_units'], (int) $b['units_loaded']) ?></span></td>
        <td class="num" data-label="<?= $word('SALES', 'stock_rows') ?>"><?= $n($b['stock_rows']) ?></td>
        <td data-label="<?= $word('SALES', 'when') ?>"><?= $when($b['exported_at']) ?> / <?= $when($b['finished_at']) ?> <code><?= $e($b['actor']) ?></code></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
  </div>
</details>
<?php endif; ?>
