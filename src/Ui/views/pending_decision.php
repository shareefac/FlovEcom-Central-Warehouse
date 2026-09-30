<strong><?= $e($d['action']) ?></strong>
<?php if ($d['action'] === 'new_item'): ?>
a new item<?php if ($d['units'] !== null): ?>, <?= $e($d['units']) ?> per item<?php endif; ?>, with this identity card:
<span class="minicard"><?php foreach ($d['card'] as $f): ?><span class="muted"><?= $e($f['label']) ?>:</span> <?= $e($f['value']) ?><span class="sep">;</span> <?php endforeach; ?></span>
<?php else: ?>
<?php if ($d['sku_id'] !== null): ?><?php if ($d['action'] === 'merge_skus'): ?>keep <?php endif; ?><a href="/ui/items/<?= $e($d['sku_id']) ?>"><?= $e($d['sku_code']) ?></a> <?= $e($d['sku_name']) ?><?php endif; ?>
<?php if ($d['merge_from_id'] !== null): ?>, fold <a href="/ui/items/<?= $e($d['merge_from_id']) ?>"><?= $e($d['merge_from_code']) ?></a> <?= $e($d['merge_from_name']) ?> into it<?php endif; ?>
<?php if ($d['units'] !== null): ?>, <?= $e($d['units']) ?> per item<?php endif; ?>
<?php endif; ?>
