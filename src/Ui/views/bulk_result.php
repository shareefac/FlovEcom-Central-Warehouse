<p class="crumbs"><a href="<?= $e($list[0]) ?>"><?= $e($list[1]) ?></a></p>
<h1><?= $say('BULK', 'title', $batch['id']) ?></h1>
<?= $intro('bulk_result') ?>
<div class="alert <?php if ($count['skipped'] === 0 && $missing === 0): ?>done<?php else: ?>needs<?php endif; ?>" role="status">
  <p class="alert-title"><?= $e($summary) ?></p>
<?php if ($count['pending_second'] > 0): ?>
  <p><?= $word('BULK', 'pending_text') ?><?php if ($pending_link !== null): ?> <a href="<?= $e($pending_link) ?>"><?= $word('PAGE_TITLE', 'pending') ?></a><?php endif; ?></p>
<?php endif; ?>
<?php if ($count['skipped'] > 0): ?>
  <p><?= $word('BULK', 'skipped_text') ?></p>
<?php endif; ?>
<?php if ($missing > 0): ?>
  <p><?= $say('BULK', 'missing', $missing, $asked) ?></p>
<?php endif; ?>
</div>

<dl class="wide">
  <dt><?= $word('BULK', 'what') ?></dt><dd><?= $word('BULK_ACTION', $batch['action']) ?></dd>
  <dt><?= $word('BULK', 'who') ?></dt><dd><?= $e($batch['by'] ?? '') ?> <span class="muted"><?= $when($batch['at']) ?></span></dd>
  <dt><?= $word('BULK', 'where') ?></dt><dd><?php if ($batch['source'] === 'store'): ?><?= $word('MENU', 'store_products') ?><?php else: ?><?= $word('BAND_TITLE', $batch['band']) ?><?php endif; ?> · <?php if ($batch['store'] !== null): ?><?= $e($batch['store']) ?><?php else: ?><?= $word('BULK', 'all_stores') ?><?php endif; ?></dd>
<?php if ($batch['reason'] !== null): ?>
  <dt><?= $word('BULK', 'note') ?></dt><dd><?= $e($batch['reason']) ?></dd>
<?php endif; ?>
  <dt><?= $word('BULK', 'ticked') ?></dt><dd><?= $n($asked) ?></dd>
</dl>

<?php if ($skipped !== []): ?>
<h3 class="grp-title needs"><button class="grp-toggle" type="button" aria-expanded="true" aria-controls="g-skipped"><?= $word('BULK', 'skipped_title') ?></button><span class="grp-count"><?= $say('BULK', 'count', count($skipped)) ?></span></h3>
<div class="table-wrap" id="g-skipped">
<table class="stack list board needs bulk-skipped">
  <thead><tr><th scope="col" class="c-item"><?= $word('QUEUE', 'product') ?></th><th scope="col"><?= $word('BULK', 'why') ?></th><th scope="col"><span class="visually-hidden"><?= $word('QUEUE', 'open') ?></span></th></tr></thead>
  <tbody>
<?php foreach ($skipped as $r): ?>
    <tr>
      <th scope="row" class="c-head"><a class="o-name" href="<?= $e($r['link']) ?>"><?= $e($r['title'] ?? \CW\Ui\Words::LISTING['no_title']) ?></a><span class="o-sub"><?= $e($r['store'] ?? '') ?><?php if ($r['variant'] !== null): ?> · <?= $say('QUEUE', 'option', (string) $r['variant']) ?><?php endif; ?></span></th>
      <td class="c-wide" data-label="<?= $word('BULK', 'why') ?>"><?= $e($r['why']) ?></td>
      <td class="c-next"><a class="btn sm secondary" href="<?= $e($r['link']) ?>"><?= $word('QUEUE', 'open') ?></a></td>
    </tr>
<?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>

<h3 class="grp-title t-primary"><button class="grp-toggle" type="button" aria-expanded="true" aria-controls="g-all"><?= $word('BULK', 'all_rows') ?></button><span class="grp-count"><?= $say('BULK', 'count', count($rows)) ?></span></h3>
<div class="table-wrap" id="g-all">
<table class="stack list board row-strips bulk-rows">
  <thead><tr><th scope="col" class="c-item"><?= $word('QUEUE', 'product') ?></th><th scope="col" class="c-status"><?= $word('BULK', 'result') ?></th><th scope="col"><?= $word('BULK', 'now') ?></th></tr></thead>
  <tbody>
<?php foreach ($rows as $r): ?>
    <tr class="<?= $e(\CW\Ui\Words::tone('BULK_RESULT', $r['outcome'])) ?>">
      <th scope="row" class="c-head"><a class="o-name" href="<?= $e($r['link']) ?>"><?= $e($r['title'] ?? \CW\Ui\Words::LISTING['no_title']) ?></a><span class="o-sub"><?= $e($r['store'] ?? '') ?><?php if ($r['variant'] !== null): ?> · <?= $say('QUEUE', 'option', (string) $r['variant']) ?><?php endif; ?></span></th>
      <td class="c-status"><?= $stateChip('BULK_RESULT', $r['outcome']) ?></td>
      <td data-label="<?= $word('BULK', 'now') ?>">
<?php if ($r['why'] !== null): ?>
        <?= $e($r['why']) ?>
<?php else: ?>
        <?= $word('DECISION_STATE', $r['state_now']) ?><?php if ($r['sku_code'] !== null): ?> · <?= $e($r['sku_code']) ?><?php endif; ?>
<?php foreach ($r['needs'] as $need): ?>
        <span class="tag"><?= $e($need) ?></span>
<?php endforeach; ?>
<?php endif; ?>
      </td>
    </tr>
<?php endforeach; ?>
  </tbody>
</table>
</div>
<p class="muted"><?= $word('BULK', 'no_undo') ?></p>
<p class="actions"><a class="btn secondary" href="<?= $e($list[0]) ?>"><?= $say('BULK', 'back_to', $list[1]) ?></a></p>
