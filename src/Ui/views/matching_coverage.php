<?php
// Matching › Mapping › To review, under the stores (docs/decisions.md HV5): how much of what each store sells is matched, in 30 days and in a
// year, and what sold on products marked "ignore" (the matching progress Home showed until HV1).
?>
<section class="coverage-box" aria-labelledby="coverage-h">
  <h2 id="coverage-h" class="board-title"><?= $word('HOME', 'coverage') ?></h2>
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
