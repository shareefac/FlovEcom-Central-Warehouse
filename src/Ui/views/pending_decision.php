<?php if ($d['action'] === 'new_item'): ?>
<strong><?php if ($d['name'] !== null): ?><?= $say('PENDING', 'create_named', $d['name']) ?><?php else: ?><?= $word('PENDING', 'create') ?><?php endif; ?></strong><?php if ($d['sale'] !== null): ?> (<?= $e($d['sale']) ?>)<?php endif; ?>.
<span class="minicard"><span class="muted"><?= $word('PENDING', 'details') ?></span> <?php foreach ($d['card'] as $f): ?><span class="muted"><?= $e($f['label']) ?>:</span> <?= $e($f['value']) ?><span class="sep">;</span> <?php endforeach; ?></span>
<?php elseif ($d['action'] === 'split'): ?>
<strong><?= $word('PENDING', 'undo_join') ?></strong>
<?php if ($d['sku_id'] !== null): ?><?= $word('PENDING', 'back_to') ?> <a href="/ui/items/<?= $e($d['sku_id']) ?>"><?= $e($d['sku_code']) ?></a> <?= $e($d['sku_name']) ?>, <?= $word('PENDING', 'with_stock') ?><?php else: ?><?= $word('PENDING', 'own_new') ?><?php endif; ?><?php if ($d['prev_id'] !== null): ?> <span class="muted">(<a href="/ui/items/<?= $e($d['prev_id']) ?>"><?= $e($d['prev_code']) ?></a>)</span><?php endif; ?>
<?php elseif ($d['action'] === 'merge_skus'): ?>
<strong><?= $word('PENDING', 'join') ?></strong>
<?php if ($d['merge_from_id'] !== null): ?><a href="/ui/items/<?= $e($d['merge_from_id']) ?>"><?= $e($d['merge_from_code']) ?></a> <?= $e($d['merge_from_name']) ?> <?= $word('PENDING', 'joins') ?> <?php endif; ?><?php if ($d['sku_id'] !== null): ?><a href="/ui/items/<?= $e($d['sku_id']) ?>"><?= $e($d['sku_code']) ?></a> <?= $e($d['sku_name']) ?><?php endif; ?><?php if ($d['from_dups']): ?> <span class="muted"><?= $word('PENDING', 'from_dups') ?></span><?php endif; ?>
<?php elseif ($d['action'] === 'link'): ?>
<strong><?= $word('PENDING', 'match') ?></strong> <?php if ($d['sku_id'] !== null): ?><a href="/ui/items/<?= $e($d['sku_id']) ?>"><?= $e($d['sku_code']) ?></a> <?= $e($d['sku_name']) ?><?php endif; ?><?php if ($d['sale'] !== null): ?> (<?= $e($d['sale']) ?>)<?php endif; ?>
<?php elseif ($d['action'] === 'reject'): ?>
<strong><?= $word('PENDING', 'reject') ?></strong> <?php if ($d['sku_id'] !== null): ?><a href="/ui/items/<?= $e($d['sku_id']) ?>"><?= $e($d['sku_code']) ?></a> <?= $e($d['sku_name']) ?><?php endif; ?>
<?php elseif ($d['action'] === 'unlink'): ?>
<strong><?= $word('PENDING', 'unlink') ?></strong><?php if ($d['sku_id'] !== null): ?> <a href="/ui/items/<?= $e($d['sku_id']) ?>"><?= $e($d['sku_code']) ?></a> <?= $e($d['sku_name']) ?><?php endif; ?>
<?php elseif ($d['action'] === 'ignore'): ?>
<strong><?= $word('PENDING', 'ignore') ?></strong>
<?php else: ?>
<strong><?= $word('ACTION', $d['action']) ?></strong>
<?php endif; ?>
