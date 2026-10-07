<div class="head-help">
  <h1><?= $word('MENU', 'duplicates') ?></h1>
  <?= $explain('join_undo', \CW\Ui\Words::DUPS['undo_button']) ?>
</div>
<?= $intro('duplicates', $lookOnly) ?>
<?php if ($look !== null): ?>
<p class="note read-only"><?= $e($look) ?></p>
<?php endif; ?>

<?php if ($rows === []): ?>
<?= $empty(\CW\Ui\Words::DUPS['none'], \CW\Ui\Words::DUPS['none_text']) ?>
<?php else: ?>
<p><strong><?php if ($total === 1): ?><?= $word('DUPS', 'total_one') ?><?php else: ?><?= $say('DUPS', 'total_many', $total) ?><?php endif; ?></strong> <?= $word('DUPS', 'many_differ') ?></p>
<p class="actions"><a class="btn primary" href="/ui/review/duplicates/<?= $e($rows[0]['id']) ?>"><?= $word('DUPS', 'start') ?> &rarr;</a></p>
<div class="table-wrap">
<table class="stack list dups">
  <thead>
    <tr>
      <th scope="col"><?= $word('DUPS', 'keeper') ?></th>
      <th scope="col" class="num"><?= $word('DUPS', 'pages') ?></th>
      <th scope="col" class="num"><?= $word('DUPS', 'sold_365') ?></th>
      <th scope="col" class="num"><?= $word('DUPS', 'sold_30') ?></th>
      <th scope="col"><?= $word('DUPS', 'rules') ?></th>
    </tr>
  </thead>
  <tbody>
<?php foreach ($rows as $r): ?>
    <tr>
      <th scope="row" class="c-head"><a class="o-name" href="/ui/review/duplicates/<?= $e($r['id']) ?>"><?= $e($r['title'] ?? \CW\Ui\Words::say('DUPS', 'group', $r['id'])) ?></a>
<?php if ($r['sku_code'] !== null): ?> <span class="o-sub"><?= $e($r['sku_code']) ?></span><?php endif; ?>
<?php if ($r['waiting']): ?> <?= $chip('waiting', \CW\Ui\Words::DUPS['waiting']) ?><?php endif; ?>
<?php if ($r['decided'] > 0): ?> <?= $chip('info', \CW\Ui\Words::DUPS['partly']) ?><?php endif; ?>
      </th>
      <td class="num" data-label="<?= $word('DUPS', 'pages') ?>"><?= $n($r['size']) ?></td>
      <td class="num" data-label="<?= $word('DUPS', 'sold_365') ?>"><?= $n($r['units_365d']) ?></td>
      <td class="num" data-label="<?= $word('DUPS', 'sold_30') ?>"><?= $n($r['units_30d']) ?></td>
      <td data-label="<?= $word('DUPS', 'rules') ?>"><?php if ($r['against'] !== []): ?><span class="tag bad"><?= $say('DUPS', 'maybe', implode(', ', $r['against'])) ?></span><?php elseif ($r['checked']): ?><span class="muted"><?= $word('DUPS', 'no_reason') ?></span><?php else: ?><span class="muted"><?= $word('DUPS', 'not_checked') ?></span><?php endif; ?></td>
    </tr>
<?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>

<?php if ($recent !== []): ?>
<section aria-labelledby="recent-h">
  <h2 id="recent-h"><?= $word('DUPS', 'recent') ?></h2>
  <p class="muted"><?= $word('DUPS', 'recent_text') ?> <?php if ($recent_total === 1): ?><?= $word('DUPS', 'recent_one') ?><?php else: ?><?= $say('DUPS', 'recent_many', $recent_total) ?><?php endif; ?><?php if ($recent_pages > 1): ?> <?= $say('DUPS', 'recent_page', $recent_page, $recent_pages) ?><?php endif; ?></p>
  <ul class="plain dups-recent">
<?php foreach ($recent as $r): ?>
    <li><a href="/ui/review/duplicates/<?= $e($r['id']) ?>"><?= $e($r['title'] ?? \CW\Ui\Words::say('DUPS', 'group', $r['id'])) ?></a>
      <span class="muted"><?= $say('DUPS', 'recent_line', $r['size'], $r['items'] === 1 ? \CW\Ui\Words::DUPS['recent_items_one'] : \CW\Ui\Words::say('DUPS', 'recent_items_many', $r['items'])) ?><?php if ($r['at'] !== null): ?>, <?= $when($r['at']) ?><?php endif; ?></span></li>
<?php endforeach; ?>
  </ul>
<?php if ($recent_prev !== null || $recent_next !== null): ?>
  <p class="pager"><?php if ($recent_prev !== null): ?><a href="<?= $e($recent_prev) ?>">&larr; <?= $word('DUPS', 'newer') ?></a> <?php endif; ?><?php if ($recent_next !== null): ?><a href="<?= $e($recent_next) ?>"><?= $word('DUPS', 'older') ?> &rarr;</a><?php endif; ?></p>
<?php endif; ?>
</section>
<?php endif; ?>
