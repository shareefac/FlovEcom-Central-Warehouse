<section class="card item-groups" id="groups" aria-labelledby="groups-h">
  <h2 id="groups-h"><?= $word('FAMILY', 'item_box') ?></h2>
<?php if ($byHand !== null): ?>
  <p class="muted"><?= $e($byHand['line']) ?> <?= $stateChip('BY_HAND_STATE', $byHand['state']) ?></p>
<?php endif; ?>
<?php if ($family === null): ?>
  <p><?= $word('FAMILY', 'in_none') ?></p>
<?php else: ?>
  <p><a class="o-name" href="<?= $u('/ui/items/families/' . $family['id']) ?>"><?= $e($family['name']) ?></a><?php if (!$family['active']): ?> <?= $chip('off', \CW\Ui\Words::FAMILY['in_off']) ?><?php endif; ?>
    <span class="o-sub"><a href="<?= $u('/ui/items/families/' . $family['id']) ?>"><?= $word('FAMILY', 'open') ?></a></span></p>
<?php endif; ?>
<?php if ($canFamily): ?>
  <form class="inline item-family" method="post" action="<?= $u('/ui/items/' . $skuId . '/family') ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="seen" value="<?php if ($family !== null): ?><?= $e($family['id']) ?><?php endif; ?>">
    <label for="if-name"><?= $word('FAMILY', 'put_label') ?> <span class="hint"><?= $word('FAMILY', 'put_hint') ?></span></label>
    <input id="if-name" type="text" name="family" maxlength="255" list="if-families" value="<?php if ($family !== null): ?><?= $e($family['name']) ?><?php endif; ?>">
    <datalist id="if-families">
<?php foreach ($familyNames as $fname): ?>
      <option value="<?= $e($fname) ?>"></option>
<?php endforeach; ?>
    </datalist>
    <button type="submit" class="secondary sm"><?= $word('FAMILY', 'put_button') ?></button>
  </form>
<?php endif; ?>
  <h3><?= $word('PACKS', 'item_box') ?></h3>
<?php if ($pack === null && $packsOf === []): ?>
  <p class="muted"><?= $word('PACKS', 'item_none') ?></p>
<?php endif; ?>
<?php if ($pack !== null): ?>
  <p><?= $say('PACKS', 'pack_is', $pack['units']) ?> <a href="<?= $u('/ui/items/' . $pack['single']['id']) ?>"><?= $e($pack['single']['code']) ?></a> <?= $e($pack['single']['name']) ?></p>
<?php foreach ($pack['flags'] as $fl): ?>
  <span class="pack-flag"><?= $chip($fl['tone'], $fl['text']) ?></span>
<?php endforeach; ?>
<?php endif; ?>
<?php if ($packsOf !== []): ?>
  <p><?= $word('PACKS', 'packs_of') ?></p>
  <ul class="plain">
<?php foreach ($packsOf as $p): ?>
    <li><a href="<?= $u('/ui/items/' . $p['id']) ?>"><?= $e($p['code']) ?></a> <?= $e($p['name']) ?> · <?= $say('PACKS', 'equals', $p['units']) ?></li>
<?php endforeach; ?>
  </ul>
<?php endif; ?>
<?php if ($pack !== null || $packsOf !== []): ?>
  <p class="o-sub"><a href="/ui/items/packs"><?= $word('PAGE_TITLE', 'packs') ?></a></p>
<?php endif; ?>
</section>
