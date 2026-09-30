<h1>Waiting for a second person</h1>
<p class="muted">Links that touch a protected item, use units per item other than 1, follow an earlier rejection, or merge items take effect only when a mapping lead other than the decider approves them.</p>

<?php if ($error !== null): ?>
<p class="error" role="alert"><?= $e($error) ?></p>
<?php endif; ?>

<?php if ($rows === []): ?>
<p>Nothing is waiting.</p>
<?php else: ?>
<table>
  <thead>
    <tr>
      <th scope="col">Listing</th>
      <th scope="col">Decision</th>
      <th scope="col">Why two people</th>
      <th scope="col">Decided by</th>
      <th scope="col"><span class="visually-hidden">Actions</span></th>
    </tr>
  </thead>
  <tbody>
<?php foreach ($rows as $r): ?>
    <tr<?php if ($error_decision === $r['id']): ?> class="differs"<?php endif; ?>>
      <td>
        <a href="/ui/review/listing/<?= $e($r['listing_id']) ?>"><?= $e($r['title'] ?? '(no title)') ?></a>
<?php if ($r['variant_title'] !== null): ?>
        <span class="muted">&middot; <?= $e($r['variant_title']) ?></span>
<?php endif; ?>
        <div class="muted"><?= $e($r['channel']) ?> <?= $e($r['variant']) ?></div>
      </td>
      <td>
        <?= $partial('pending_decision', ['d' => $r]) ?>
<?php if ($r['reason'] !== null): ?>
        <div class="muted">reason: <?= $e($r['reason']) ?></div>
<?php endif; ?>
<?php foreach ($r['stale'] as $why): ?>
        <div class="error"><?= $e($why) ?> Withdraw it and decide again.</div>
<?php endforeach; ?>
      </td>
      <td>
<?php foreach ($r['needs'] as $need): ?>
        <span class="tag"><?= $e($need) ?></span>
<?php endforeach; ?>
      </td>
      <td><?= $e($r['decider']) ?><div class="muted"><?= $dt($r['created_at']) ?></div></td>
      <td class="actions"><?= $partial('pending_actions', ['id' => $r['id'], 'from' => 'pending', 'can_approve' => $r['can_approve'], 'can_withdraw' => $r['can_withdraw'], 'own' => $r['own']]) ?></td>
    </tr>
<?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>
