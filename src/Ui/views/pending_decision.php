<strong><?= $e($d['action']) ?></strong>
<?php if ($d['action'] === 'new_item'): ?>
a new item<?php if ($d['units'] !== null): ?>, <?= $e($d['units']) ?> per item<?php endif; ?>, with this identity card:
<span class="minicard"><?php foreach ($d['card'] as $f): ?><span class="muted"><?= $e($f['label']) ?>:</span> <?= $e($f['value']) ?><span class="sep">;</span> <?php endforeach; ?></span>
<?php elseif ($d['action'] === 'split'): ?>
the listing off <?php if ($d['prev_id'] !== null): ?><a href="/ui/items/<?= $e($d['prev_id']) ?>"><?= $e($d['prev_code']) ?></a><?php endif; ?>,
<?php if ($d['sku_id'] !== null): ?>back to <a href="/ui/items/<?= $e($d['sku_id']) ?>"><?= $e($d['sku_code']) ?></a> <?= $e($d['sku_name']) ?><?php else: ?>to a new item<?php endif; ?>, with the stock that came with it
<?php else: ?>
<?php if ($d['sku_id'] !== null): ?><?php if ($d['action'] === 'merge_skus'): ?>keep <?php endif; ?><a href="/ui/items/<?= $e($d['sku_id']) ?>"><?= $e($d['sku_code']) ?></a> <?= $e($d['sku_name']) ?><?php endif; ?>
<?php if ($d['merge_from_id'] !== null): ?>, fold <a href="/ui/items/<?= $e($d['merge_from_id']) ?>"><?= $e($d['merge_from_code']) ?></a> <?= $e($d['merge_from_name']) ?> into it<?php endif; ?>
<?php if ($d['units'] !== null): ?>, <?= $e($d['units']) ?> per item<?php endif; ?>
<?php endif; ?>
