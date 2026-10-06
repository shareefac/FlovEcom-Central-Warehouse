<p class="crumbs"><a href="/ui/review/samples">&larr; Key spot-check</a></p>

<h1>Key spot-check <?= $e($s['name']) ?></h1>

<p class="progress"><strong><?= $n($s['decided']) ?> of <?= $n($s['size']) ?> decided</strong>
  &middot; <?= $n($s['confirmed']) ?> confirmed by <?= $e($s['created_by'] ?? 'its owner') ?><?php if ($s['failed'] > 0): ?> &middot; <?= $n($s['failed']) ?> not confirmed<?php endif; ?></p>

<?php if ($fit !== []): ?>
<p class="error" role="alert">This sample cannot unlock a bulk confirm: <?= $e(implode('; ', $fit)) ?>.</p>
<?php endif; ?>
<?php if ($s['verdict'] === 'complete' && $fit === []): ?>
<p class="notice">All <?= $n($s['size']) ?> proposals are confirmed by <?= $e($s['created_by'] ?? 'its owner') ?>. The bulk confirm of the other
  Key proposals of this sample's population may run, by <?= $e($s['created_by'] ?? 'its owner') ?> only (on the server, dry run first:
  <code>bin/bulk_confirm_key.php --sample=<?= $e($s['name']) ?> --lead=&lt;e-mail&gt;</code>).</p>
<?php elseif ($s['verdict'] === 'failed'): ?>
<p class="error" role="alert">At least one proposal of this sample was not confirmed (rejected, decided otherwise, or decided by someone
  other than <?= $e($s['created_by'] ?? 'its owner') ?>). The bulk confirm refuses this sample for good, and its listings are never drawn
  into another sample: the Key proposals stay in the queue, one at a time.</p>
<?php elseif ($s['verdict'] !== 'complete'): ?>
<p class="note">Only <?= $e($s['created_by'] ?? 'the owner of this sample') ?> decides these. Open each proposal below, check it as usual,
  and confirm it (or reject it, or decide otherwise) on the review screen. The bulk confirm can run only once every one is confirmed.</p>
<?php endif; ?>

<table class="sample">
  <thead>
    <tr>
      <th scope="col" class="num">#</th>
      <th scope="col">Listing</th>
      <th scope="col">Proposed item</th>
      <th scope="col" class="num">AI confidence</th>
      <th scope="col">State</th>
    </tr>
  </thead>
  <tbody>
<?php foreach ($members as $m): ?>
    <tr<?php if ($m['bad']): ?> class="differs"<?php endif; ?>>
      <td class="num"><?= $n($m['position']) ?></td>
      <td>
        <a href="<?= $e($m['link']) ?>"><?= $e($m['title'] ?? '(no title)') ?></a>
<?php if ($m['variant_title'] !== null): ?>
        <span class="muted">&middot; <?= $e($m['variant_title']) ?></span>
<?php endif; ?>
        <div class="muted"><?= $e($m['channel']) ?> <?= $e($m['variant']) ?> &middot; listing #<?= $e($m['listing_id']) ?> &middot; proposal #<?= $e($m['proposal_id']) ?></div>
      </td>
      <td><?= $e($m['sku_code'] ?? '') ?> <span class="muted"><?= $e($m['sku_name'] ?? '') ?></span></td>
      <td class="num"><?= $e($m['confidence'] ?? '') ?></td>
      <td>
        <span class="state state-<?= $e($m['state']) ?>"><?= $e($m['state_label']) ?></span>
<?php if ($m['by'] !== null): ?>
        <div class="muted"><?= $e($m['by']) ?>, <?= $dt($m['at']) ?></div>
<?php endif; ?>
      </td>
    </tr>
<?php endforeach; ?>
  </tbody>
</table>

<section>
  <h2>Held back from the bulk confirm</h2>
<?php if ($holds === []): ?>
  <p class="muted">None. A mapping lead holds listings of this sample's population back for one-at-a-time review on the server
    (<code>bin/key_bulk_hold.php --sample=<?= $e($s['name']) ?></code>); no bulk confirm then links them.</p>
<?php else: ?>
  <p>Held back now: <strong><?= $n($held_open) ?></strong> of this sample's population still waiting. No bulk confirm links them,
    whatever their proposal (a newer run's included); they stay in the Key queue, to be decided one at a time on their review screens.
<?php if (count($holds) > $held_open): ?>
    <?= $n(count($holds) - $held_open) ?> more held <?= $e(count($holds) - $held_open === 1 ? 'listing was' : 'listings were') ?> decided since.
<?php endif; ?>
  </p>
  <table class="held">
    <thead>
      <tr>
        <th scope="col">Listing</th>
        <th scope="col">Why</th>
        <th scope="col">Held by</th>
        <th scope="col">Now</th>
      </tr>
    </thead>
    <tbody>
<?php foreach ($holds as $h): ?>
      <tr<?php if ($h['linked_by_batch'] !== null): ?> class="differs"<?php endif; ?>>
        <td>
          <a href="<?= $e($h['link']) ?>"><?= $e($h['title'] ?? '(no title)') ?></a>
<?php if ($h['variant_title'] !== null): ?>
          <span class="muted">&middot; <?= $e($h['variant_title']) ?></span>
<?php endif; ?>
          <div class="muted"><?= $e($h['channel']) ?> <?= $e($h['variant']) ?> &middot; listing #<?= $e($h['listing_id']) ?> &middot; proposal #<?= $e($h['proposal_id']) ?></div>
        </td>
        <td><?= $e($h['reason']) ?></td>
        <td><?= $e($h['by'] ?? '') ?> <span class="muted"><?= $dt($h['at']) ?></span></td>
<?php if ($h['linked_by_batch'] !== null): ?>
<?php if (str_starts_with($h['linked_by_batch'], 'key_bulk:')): ?>
        <td><span class="tag bad">Linked by the bulk confirm <?= $e($h['linked_by_batch']) ?></span> A held listing should never be: check it on
          its review screen (undo: <code>bin/bulk_unlink.php --batch=<?= $e($h['linked_by_batch']) ?></code>).</td>
<?php else: ?>
        <td><span class="tag bad">Linked by the bulk batch <?= $e($h['linked_by_batch']) ?></span> Check it on its review screen.</td>
<?php endif; ?>
<?php elseif ($h['open']): ?>
        <td>waiting for a decision<?php if ($h['newer']): ?> <span class="muted">(now proposal #<?= $e($h['open_proposal_id']) ?>: still held)</span><?php endif; ?></td>
<?php else: ?>
        <td>decided since: listing <?= $e($h['listing_status']) ?></td>
<?php endif; ?>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>
</section>

<section>
  <h2>How this sample was drawn</h2>
  <dl class="wide">
    <dt>Drawn by</dt><dd><?= $e($s['created_by'] ?? '') ?> <span class="muted"><?= $dt($s['created_at']) ?></span></dd>
    <dt>Seed</dt><dd><code><?= $e($s['seed']) ?></code> <span class="muted">drawn by the server when the sample was stored</span></dd>
    <dt>Method</dt><dd><?= $e($s['method']) ?></dd>
    <dt>Band rules</dt><dd><?= $e($s['band_version']) ?></dd>
    <dt>Drawn from</dt><dd><?= $n($s['population']) ?> Key proposals that a bulk confirm could link at that moment</dd>
<?php foreach ($s['strata'] as $st): ?>
    <dt>Confidence <?= $e($st['min_confidence'] ?? '') ?>&ndash;<?= $e($st['max_confidence'] ?? '') ?></dt><dd><?= $n($st['sample'] ?? 0) ?> of <?= $n($st['population'] ?? 0) ?></dd>
<?php endforeach; ?>
<?php foreach ($s['overrides'] as $o): ?>
    <dt>Override</dt><dd>may hold listings of the failed sample <?= $e($o['name'] ?? '') ?> again (proposals made after it only): <?= $e($o['why'] ?? '') ?></dd>
<?php endforeach; ?>
<?php if ($s['excluded'] !== []): ?>
    <dt>Left out</dt><dd><?php foreach ($s['excluded'] as $why => $count): ?><span class="tag"><?= $e($why) ?>: <?= $n($count) ?></span><?php endforeach; ?></dd>
<?php endif; ?>
    <dt>Bulk confirm</dt><dd><?php if ($s['bulk_linked'] > 0): ?><?= $n($s['bulk_linked']) ?> listings linked in batch <code><?= $e($s['batch']) ?></code><?php if ($s['bulk_undone'] > 0): ?>, <?= $n($s['bulk_undone']) ?> undone<?php endif; ?><?php else: ?>not run<?php endif; ?></dd>
  </dl>
</section>
