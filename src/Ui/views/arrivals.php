<?= $head(\CW\Ui\Words::SEGMENT['arriving'], intro: 'arriving') ?>
<?php if ($error !== null): ?>
<p class="error" role="alert" data-code="<?= $e($errorCode) ?>"><?= $e($error) ?></p>
<?php endif; ?>
<?php if ($rows === []): ?>
<?= $empty(\CW\Ui\Words::ARRIVALS['none'], \CW\Ui\Words::ARRIVALS['none_text']) ?>
<?php else: ?>
<div class="table-wrap">
<table class="stack list board">
  <thead>
    <tr>
      <th scope="col" class="c-item"><?= $word('ARRIVALS', 'invoice') ?></th>
      <th scope="col"><?= $word('ARRIVALS', 'supplier') ?></th>
      <th scope="col"><?= $word('ARRIVALS', 'since') ?></th>
      <th scope="col" class="num"><?= $word('ARRIVALS', 'units_on_way') ?></th>
      <th scope="col" class="num"><?= $word('ARRIVALS', 'units_sold') ?></th>
      <th scope="col" class="c-next"></th>
    </tr>
  </thead>
  <tbody>
<?php foreach ($rows as $r): ?>
    <tr<?php if ($r['late']): ?> class="needs"<?php endif; ?>>
      <th scope="row" class="c-head c-item"><a class="o-name" href="<?= $u($r['href']) ?>"><?= $e($r['number']) ?></a><span class="o-sub"><?= $e($r['external_ref'] ?? '') ?></span></th>
      <td data-label="<?= $word('ARRIVALS', 'supplier') ?>"><?= $e($r['supplier_name']) ?></td>
      <td data-label="<?= $word('ARRIVALS', 'since') ?>"><?= $when($r['posted_at']) ?><span class="o-sub"><?= $say('ARRIVALS', 'hours', $r['hours']) ?></span><?php if ($r['late']): ?> <?= $chip('needs', \CW\Ui\Words::ARRIVALS['late']) ?><?php endif; ?></td>
      <td class="num" data-label="<?= $word('ARRIVALS', 'units_on_way') ?>"><?= $n($r['units']) ?></td>
      <td class="num" data-label="<?= $word('ARRIVALS', 'units_sold') ?>"><?= $n($r['sellable_units']) ?></td>
      <td class="c-next"><?php if ($r['receipt_href'] !== null): ?><a href="<?= $u($r['receipt_href']) ?>"><?= $word('ARRIVALS', 'open_receipt') ?></a><?php elseif ($canReceive): ?>
        <form class="inline" method="post" action="<?= $u('/ui/receiving/arriving/' . $r['invoice_id'] . '/receive') ?>">
          <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
          <button type="submit" class="btn sm"><?= $word('ARRIVALS', 'receive') ?></button>
        </form><?php endif; ?></td>
    </tr>
<?php endforeach; ?>
  </tbody>
</table>
</div>
<?php if (count($rows) >= $limit): ?>
<p class="muted"><?= $say('ARRIVALS', 'more', $limit) ?></p>
<?php endif; ?>
<?php endif; ?>
