<h1>Key spot-check</h1>
<p class="muted">A spot-check is a random sample of 20 Key proposals that the mapping lead who drew it confirms or rejects one by one on
  the review screen. Only when every proposal in it is confirmed by that lead can the rest of the Key proposals it was drawn from be
  confirmed in one step, by that lead (on the server: <code>bin/bulk_confirm_key.php</code>). One rejection, any other decision, or a
  decision by anyone else stops that step for good.</p>

<?php if ($rows === []): ?>
<p>No spot-check has been drawn yet (on the server: <code>bin/sample_proposals.php</code>).</p>
<?php else: ?>
<table>
  <thead>
    <tr>
      <th scope="col">Sample</th>
      <th scope="col" class="num">Decided</th>
      <th scope="col">State</th>
      <th scope="col" class="num">Drawn from</th>
      <th scope="col">Drawn by</th>
      <th scope="col" class="num">Linked by the bulk confirm</th>
    </tr>
  </thead>
  <tbody>
<?php foreach ($rows as $r): ?>
    <tr>
      <th scope="row"><a href="/ui/review/samples/<?= $e($r['id']) ?>"><?= $e($r['name']) ?></a></th>
      <td class="num"><?= $n($r['decided']) ?> of <?= $n($r['size']) ?></td>
      <td>
<?php if ($r['verdict'] === 'complete' && $r['fit'] !== []): ?>
        <span class="status status-quarantined">all confirmed, but not usable for a bulk confirm</span>
<?php elseif ($r['verdict'] === 'complete'): ?>
        <span class="status status-mapped">all confirmed</span>
<?php elseif ($r['verdict'] === 'failed'): ?>
        <span class="status status-quarantined"><?= $n($r['failed']) ?> not confirmed: no bulk confirm</span>
<?php else: ?>
        <span class="status status-suggested">waiting</span>
<?php endif; ?>
      </td>
      <td class="num"><?= $n($r['population']) ?></td>
      <td><?= $e($r['created_by'] ?? '') ?> <span class="muted"><?= $dt($r['created_at']) ?></span></td>
      <td class="num"><?= $n($r['bulk_linked']) ?><?php if ($r['bulk_undone'] > 0): ?> <span class="muted">(<?= $n($r['bulk_undone']) ?> undone)</span><?php endif; ?></td>
    </tr>
<?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>
