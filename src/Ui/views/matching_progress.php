<section class="progress-box" aria-labelledby="progress-h">
  <h2 id="progress-h"><?= $word('HOME', 'progress') ?></h2>
<?php if ($lookOnly): ?>
  <p class="muted"><?= $word('HOME', 'look_match') ?></p>
<?php endif; ?>

  <div class="head-help">
    <h3 id="to-match-h"><?= $word('HOME', 'to_match') ?></h3>
    <?= $explain('how_sure', $howSure) ?>
  </div>
  <div class="table-wrap">
  <table class="stack bands" aria-labelledby="to-match-h">
    <thead>
      <tr>
        <th scope="col"><?= $word('HOME', 'how_sure') ?></th>
<?php foreach ($channels as $c): ?>
        <th scope="col" class="num"><?= $e($c['name']) ?></th>
<?php endforeach; ?>
        <th scope="col" class="num"><?= $word('HOME', 'total') ?></th>
      </tr>
    </thead>
    <tbody>
<?php foreach ($bands as $b): ?>
      <tr>
        <th scope="row" class="c-head"><?= $word('BAND', $b['band']) ?> <span class="hint"><?= $word('BAND_HELP', $b['band']) ?></span></th>
<?php foreach ($channels as $c): ?>
        <td class="num" data-label="<?= $e($c['name']) ?>"><?php if ($b['by_channel'][$c['id']] > 0): ?><a href="<?= $u('/ui/review', ['queue' => $b['band'], 'channel' => $c['code']]) ?>"><?= $n($b['by_channel'][$c['id']]) ?></a><?php else: ?>0<?php endif; ?></td>
<?php endforeach; ?>
        <td class="num" data-label="<?= $word('HOME', 'total') ?>"><?php if ($b['total'] > 0): ?><a href="<?= $u('/ui/review', ['queue' => $b['band']]) ?>"><?= $n($b['total']) ?></a><?php else: ?>0<?php endif; ?></td>
      </tr>
<?php endforeach; ?>
      <tr class="sub">
        <th scope="row" class="c-head"><?= $word('HOME', 'not_checked') ?> <span class="hint"><?= $word('HOME', 'not_checked_help') ?></span></th>
<?php $unproposedTotal = 0; ?>
<?php foreach ($channels as $c): ?>
<?php $unproposedTotal += $unproposed[$c['id']] ?? 0; ?>
        <td class="num" data-label="<?= $e($c['name']) ?>"><?= $n($unproposed[$c['id']] ?? 0) ?></td>
<?php endforeach; ?>
        <td class="num" data-label="<?= $word('HOME', 'total') ?>"><?= $n($unproposedTotal) ?></td>
      </tr>
    </tbody>
  </table>
  </div>
  <p class="muted"><?= $word('HOME', 'best_first') ?> <?= $e($pendingText) ?><?php if ($pending > 0 && $lead): ?> <a href="<?= $u('/ui/review', ['queue' => 'pending']) ?>"><?= $word('HOME', 'open') ?></a><?php endif; ?></p>
<?php if ($duplicates > 0): ?>
  <p class="note"><?= $e($duplicatesText) ?> <?= $word('HOME', 'duplicates_note') ?> <a href="/ui/review/duplicates"><?= $word('HOME', 'open') ?></a></p>
<?php endif; ?>

  <h3 id="coverage-h"><?= $word('HOME', 'coverage') ?></h3>
  <p class="muted"><?= $word('HOME', 'coverage_text') ?></p>
  <div class="table-wrap">
  <table class="stack coverage" aria-labelledby="coverage-h">
    <thead>
      <tr>
        <th scope="col"><?= $word('HOME', 'site') ?></th>
        <th scope="col" class="num"><?= $word('HOME', 'listings') ?></th>
        <th scope="col" class="num"><?= $word('HOME', 'matched') ?></th>
        <th scope="col" class="num"><?= $word('HOME', 'sold_30') ?></th>
        <th scope="col"><?= $word('HOME', 'share_30') ?></th>
        <th scope="col" class="num"><?= $word('HOME', 'sold_365') ?></th>
        <th scope="col"><?= $word('HOME', 'share_365') ?></th>
        <th scope="col" class="num"><?= $word('HOME', 'ignored_30') ?></th>
      </tr>
    </thead>
    <tbody>
<?php foreach ($coverage as $r): ?>
      <tr>
        <th scope="row" class="c-head"><?= $e($r['channel']['name']) ?></th>
        <td class="num" data-label="<?= $word('HOME', 'listings') ?>"><?= $n($r['listings']) ?></td>
        <td class="num" data-label="<?= $word('HOME', 'matched') ?>"><?= $n($r['linked_listings']) ?></td>
        <td class="num" data-label="<?= $word('HOME', 'sold_30') ?>"><?= $n($r['u30']) ?></td>
        <td class="share" data-label="<?= $word('HOME', 'share_30') ?>"><?php if ($r['u30'] > 0): ?><span class="share-val"><meter aria-hidden="true" min="0" max="100" value="<?= $e(round(100 * $r['l30'] / $r['u30'])) ?>"></meter> <?= $pct($r['l30'], $r['u30']) ?></span><?php else: ?><?= $word('HOME', 'no_sales') ?><?php endif; ?></td>
        <td class="num" data-label="<?= $word('HOME', 'sold_365') ?>"><?= $n($r['u365']) ?></td>
        <td class="share" data-label="<?= $word('HOME', 'share_365') ?>"><?php if ($r['u365'] > 0): ?><span class="share-val"><meter aria-hidden="true" min="0" max="100" value="<?= $e(round(100 * $r['l365'] / $r['u365'])) ?>"></meter> <?= $pct($r['l365'], $r['u365']) ?></span><?php else: ?><?= $word('HOME', 'no_sales') ?><?php endif; ?></td>
        <td class="num" data-label="<?= $word('HOME', 'ignored_30') ?>"><?= $n($r['i30']) ?></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
  </div>
</section>
