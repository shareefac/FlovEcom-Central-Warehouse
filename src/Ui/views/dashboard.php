<h1>Dashboard</h1>

<section>
  <h2>Listings waiting for a decision</h2>
  <table>
    <thead>
      <tr>
        <th scope="col">Band</th>
<?php foreach ($channels as $c): ?>
        <th scope="col" class="num"><?= $e($c['code']) ?></th>
<?php endforeach; ?>
        <th scope="col" class="num">Total</th>
      </tr>
    </thead>
    <tbody>
<?php foreach ($bands as $b): ?>
      <tr>
        <th scope="row"><?= $e($b['label']) ?></th>
<?php foreach ($channels as $c): ?>
        <td class="num"><?php if ($b['by_channel'][$c['id']] > 0): ?><a href="<?= $u('/ui/review', ['queue' => $b['band'], 'channel' => $c['code']]) ?>"><?= $n($b['by_channel'][$c['id']]) ?></a><?php else: ?>0<?php endif; ?></td>
<?php endforeach; ?>
        <td class="num"><?php if ($b['total'] > 0): ?><a href="<?= $u('/ui/review', ['queue' => $b['band']]) ?>"><?= $n($b['total']) ?></a><?php else: ?>0<?php endif; ?></td>
      </tr>
<?php endforeach; ?>
      <tr class="sub">
        <th scope="row">No proposal yet</th>
<?php $unproposedTotal = 0; ?>
<?php foreach ($channels as $c): ?>
<?php $unproposedTotal += $unproposed[$c['id']] ?? 0; ?>
        <td class="num"><?= $n($unproposed[$c['id']] ?? 0) ?></td>
<?php endforeach; ?>
        <td class="num"><?= $n($unproposedTotal) ?></td>
      </tr>
    </tbody>
  </table>
  <p class="muted">Best sellers come first in every queue.
<?php if ($pending > 0): ?>
    <a href="/ui/review?queue=pending"><?= $n($pending) ?> decision<?php if ($pending !== 1): ?>s<?php endif; ?> waiting for a second person</a>.
<?php else: ?>
    No decision is waiting for a second person.
<?php endif; ?>
  </p>
<?php if ($duplicates > 0): ?>
  <p class="note"><?= $n($duplicates) ?> possible duplicate<?php if ($duplicates !== 1): ?>s<?php endif; ?> between Vape and Go items (merge suggestions) <?php if ($duplicates === 1): ?>is<?php else: ?>are<?php endif; ?> not in these queues:
    merges have no screen yet, and many of them are not the same product. Nothing merges them on its own (docs/ops.md).</p>
<?php endif; ?>
</section>

<section>
  <h2>Coverage: sales on linked listings</h2>
  <p class="muted">Share of the units sold that sit on a listing linked to a central item. Ignored listings stay in the total.</p>
  <table>
    <thead>
      <tr>
        <th scope="col">Site</th>
        <th scope="col" class="num">Listings</th>
        <th scope="col" class="num">Linked</th>
        <th scope="col" class="num">Units, 30 days</th>
        <th scope="col" colspan="2">Coverage, 30 days</th>
        <th scope="col" class="num">Units, 365 days</th>
        <th scope="col" colspan="2">Coverage, 365 days</th>
        <th scope="col" class="num">Ignored, 30 days</th>
      </tr>
    </thead>
    <tbody>
<?php foreach ($coverage as $r): ?>
      <tr>
        <th scope="row"><?= $e($r['channel']['code']) ?></th>
        <td class="num"><?= $n($r['listings']) ?></td>
        <td class="num"><?= $n($r['linked_listings']) ?></td>
        <td class="num"><?= $n($r['u30']) ?></td>
        <td class="meter"><meter min="0" max="100" value="<?= $e($r['u30'] > 0 ? round(100 * $r['l30'] / $r['u30']) : 0) ?>"></meter></td>
        <td class="num"><?= $pct($r['l30'], $r['u30']) ?></td>
        <td class="num"><?= $n($r['u365']) ?></td>
        <td class="meter"><meter min="0" max="100" value="<?= $e($r['u365'] > 0 ? round(100 * $r['l365'] / $r['u365']) : 0) ?>"></meter></td>
        <td class="num"><?= $pct($r['l365'], $r['u365']) ?></td>
        <td class="num"><?= $n($r['i30']) ?></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
</section>
