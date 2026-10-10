<p class="crumbs"><a href="/ui/reference/warehouses"><?= $word('MENU', 'warehouses') ?></a></p>
<h1><?= $e($w['name']) ?> <?php if ($w['is_active']): ?><?= $chip('done', \CW\Ui\Words::WAREHOUSES['status_on']) ?><?php else: ?><?= $chip('off', \CW\Ui\Words::WAREHOUSES['status_off']) ?><?php endif; ?></h1>
<?php if ($lookOnly !== null): ?>
<p class="lede"><?= $e(\CW\Ui\Words::PAGE_INTRO['warehouse'][0]) ?> <?= $e($lookOnly) ?></p>
<?php else: ?>
<?= $intro('warehouse') ?>
<?php endif; ?>
<?php if ($error !== null): ?>
<p class="error" role="alert" data-code="<?= $e($errorCode) ?>"><?= $e($error) ?></p>
<?php endif; ?>
<dl class="wide">
  <dt><?= $word('WAREHOUSES', 'code_label') ?></dt><dd><code><?= $e($w['code']) ?></code></dd>
  <dt><?= $word('WAREHOUSES', 'owner') ?></dt><dd><?php if ($w['stock_owner'] === 'other'): ?><?= $say('WAREHOUSES', 'theirs', (string) $w['owner_entity']) ?> <a href="<?= $u('/ui/stock/accounts') ?>#account-<?= $e($w['id']) ?>"><?= $word('WAREHOUSES', 'balance_link') ?></a><?php else: ?><?= $word('WAREHOUSES', 'ours') ?><?php endif; ?></dd>
  <dt><?= $word('WAREHOUSES', 'sold_from') ?></dt><dd><?php if ($w['is_sellable']): ?><?= $word('CONFIG', 'yes') ?><?php else: ?><?= $word('CONFIG', 'no') ?><?php endif; ?></dd>
  <dt><?= $word('WAREHOUSES', 'websites') ?></dt><dd><?php if ($w['selling'] === []): ?><span class="muted"><?= $word('WAREHOUSES', 'none') ?></span><?php else: ?><?= $e(implode(', ', $w['selling'])) ?><?php endif; ?></dd>
<?php if ($w['assigned'] !== []): ?>
  <dt><?= $word('WAREHOUSES', 'assigned') ?></dt><dd><?= $e(implode(', ', $w['assigned'])) ?></dd>
<?php endif; ?>
  <dt><?= $word('WAREHOUSES', 'in_building') ?></dt><dd><?= $n($w['stock']['on_hand']) ?></dd>
  <dt><?= $word('WAREHOUSES', 'sold_waiting') ?></dt><dd><?= $n($w['stock']['allocated']) ?></dd>
  <dt><?= $word('WAREHOUSES', 'reserved') ?></dt><dd><?= $n($w['stock']['held']) ?></dd>
  <dt><?= $word('WAREHOUSES', 'with_stock') ?></dt><dd><?= $n($w['stock']['items']) ?></dd>
<?php if ($w['note'] !== null): ?>
  <dt><?= $word('WAREHOUSES', 'note') ?></dt><dd><?= $e($w['note']) ?></dd>
<?php endif; ?>
</dl>
<?php if ($w['is_system']): ?>
<p class="note read-only"><?= $word('WAREHOUSES', 'built_in') ?></p>
<?php endif; ?>
<?php if ($canEdit): ?>
<section aria-labelledby="name-h">
  <h2 id="name-h"><?= $word('WAREHOUSES', 'rename') ?></h2>
  <form class="record" method="post" action="<?= $u('/ui/reference/warehouses/' . $w['id']) ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="do" value="rename">
    <input type="hidden" name="seen" value="<?= $e($seen) ?>">
    <label><?= $word('WAREHOUSES', 'wh_name') ?>
      <input type="text" name="name" value="<?= $e($typed['name']) ?>" maxlength="100" required>
    </label>
    <label><?= $word('WAREHOUSES', 'note') ?>
      <input type="text" name="note" value="<?= $e($typed['note']) ?>" maxlength="255">
    </label>
    <label><?= $word('CONFIG', 'reason') ?>
      <span class="hint"><?= $word('CONFIG', 'reason_hint') ?></span>
      <textarea name="reason" rows="2" minlength="3" maxlength="500" required><?php if ($typed['do'] === 'rename'): ?><?= $e($typed['reason']) ?><?php endif; ?></textarea>
    </label>
    <p class="actions"><button type="submit" class="primary"><?= $word('WAREHOUSES', 'rename_button') ?></button></p>
  </form>
</section>
<?php if (!$w['is_system']): ?>
<section aria-labelledby="owner-h">
  <div class="head-help">
    <h2 id="owner-h"><?= $word('WAREHOUSES', 'owner_title') ?></h2>
    <?= $explain('stock_owner', \CW\Ui\Words::WAREHOUSES['owner']) ?>
  </div>
<?php if ($w['not_empty'] !== []): ?>
  <p class="note read-only"><?= $say('WAREHOUSES', 'owner_not_empty', $notEmpty) ?></p>
<?php else: ?>
  <p class="muted"><?= $word('WAREHOUSES', 'owner_text') ?></p>
  <form class="record" method="post" action="<?= $u('/ui/reference/warehouses/' . $w['id']) ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="do" value="owner">
    <input type="hidden" name="seen" value="<?= $e($seen) ?>">
    <fieldset>
      <legend><?= $word('WAREHOUSES', 'owner_q') ?></legend>
      <label class="choice"><input type="radio" name="owner" value="own"<?php if ($typed['owner'] !== 'other'): ?> checked<?php endif; ?>> <?= $word('WAREHOUSES', 'owner_ours') ?></label>
      <label class="choice"><input type="radio" name="owner" value="other"<?php if ($typed['owner'] === 'other'): ?> checked<?php endif; ?>> <?= $word('WAREHOUSES', 'owner_other') ?></label>
      <label><?= $word('WAREHOUSES', 'owner_name') ?>
        <input type="text" name="owner_name" value="<?= $e($typed['owner_name']) ?>" maxlength="64">
      </label>
    </fieldset>
    <label class="choice"><input type="checkbox" name="confirm" value="1" required> <?= $word('WAREHOUSES', 'owner_confirm') ?></label>
    <label><?= $word('CONFIG', 'reason') ?>
      <span class="hint"><?= $word('CONFIG', 'reason_hint') ?></span>
      <textarea name="reason" rows="2" minlength="3" maxlength="500" required><?php if ($typed['do'] === 'owner'): ?><?= $e($typed['reason']) ?><?php endif; ?></textarea>
    </label>
    <p class="actions"><button type="submit" class="primary"><?= $word('WAREHOUSES', 'owner_button') ?></button></p>
  </form>
<?php endif; ?>
</section>
<section aria-labelledby="sell-h">
  <h2 id="sell-h"><?= $word('WAREHOUSES', 'sellable_title') ?></h2>
  <p><?php if ($w['is_sellable']): ?><?= $word('WAREHOUSES', 'sellable_now_yes') ?><?php else: ?><?= $word('WAREHOUSES', 'sellable_now_no') ?><?php endif; ?> <?= $word('WAREHOUSES', 'sellable_text') ?></p>
<?php if ($w['selling'] !== [] || $w['assigned'] !== []): ?>
  <p class="note read-only"><?= $say('WAREHOUSES', 'in_use', implode(', ', array_merge($w['selling'], $w['assigned']))) ?></p>
<?php elseif ($w['stock_owner'] !== 'other' && $w['is_active']): ?>
  <form class="record" method="post" action="<?= $u('/ui/reference/warehouses/' . $w['id']) ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="do" value="sellable">
    <input type="hidden" name="seen" value="<?= $e($seen) ?>">
    <input type="hidden" name="sellable" value="<?php if ($w['is_sellable']): ?>0<?php else: ?>1<?php endif; ?>">
    <label class="choice"><input type="checkbox" name="confirm" value="1" required> <?php if ($w['is_sellable']): ?><?= $word('WAREHOUSES', 'sellable_confirm_off') ?><?php else: ?><?= $word('WAREHOUSES', 'sellable_confirm') ?><?php endif; ?></label>
    <label><?= $word('CONFIG', 'reason') ?>
      <span class="hint"><?= $word('CONFIG', 'reason_hint') ?></span>
      <textarea name="reason" rows="2" minlength="3" maxlength="500" required><?php if ($typed['do'] === 'sellable'): ?><?= $e($typed['reason']) ?><?php endif; ?></textarea>
    </label>
    <p class="actions"><button type="submit" class="danger"><?php if ($w['is_sellable']): ?><?= $word('WAREHOUSES', 'sellable_off_button') ?><?php else: ?><?= $word('WAREHOUSES', 'sellable_on_button') ?><?php endif; ?></button></p>
  </form>
<?php endif; ?>
</section>
<section aria-labelledby="switch-h">
  <h2 id="switch-h"><?= $word('WAREHOUSES', 'switch_title') ?></h2>
<?php if ($w['is_active']): ?>
  <p class="muted"><?= $word('WAREHOUSES', 'switch_off_text') ?></p>
<?php if ($notEmpty !== ''): ?>
  <p class="note read-only"><?= $say('WAREHOUSES', 'not_empty', $notEmpty) ?></p>
<?php else: ?>
  <form class="record" method="post" action="<?= $u('/ui/reference/warehouses/' . $w['id']) ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="do" value="switch_off">
    <input type="hidden" name="seen" value="<?= $e($seen) ?>">
    <label class="choice"><input type="checkbox" name="confirm" value="1" required> <?= $word('WAREHOUSES', 'switch_off_confirm') ?></label>
    <label><?= $word('CONFIG', 'reason') ?>
      <span class="hint"><?= $word('CONFIG', 'reason_hint') ?></span>
      <textarea name="reason" rows="2" minlength="3" maxlength="500" required><?php if ($typed['do'] === 'switch_off'): ?><?= $e($typed['reason']) ?><?php endif; ?></textarea>
    </label>
    <p class="actions"><button type="submit" class="danger"><?= $word('WAREHOUSES', 'switch_off_button') ?></button></p>
  </form>
<?php endif; ?>
<?php else: ?>
  <form class="record" method="post" action="<?= $u('/ui/reference/warehouses/' . $w['id']) ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="do" value="switch_on">
    <input type="hidden" name="seen" value="<?= $e($seen) ?>">
    <label><?= $word('CONFIG', 'reason') ?>
      <span class="hint"><?= $word('CONFIG', 'reason_hint') ?></span>
      <textarea name="reason" rows="2" minlength="3" maxlength="500" required><?php if ($typed['do'] === 'switch_on'): ?><?= $e($typed['reason']) ?><?php endif; ?></textarea>
    </label>
    <p class="actions"><button type="submit"><?= $word('WAREHOUSES', 'switch_on_button') ?></button></p>
  </form>
<?php endif; ?>
</section>
<?php endif; ?>
<?php endif; ?>

<section id="places" aria-labelledby="places-h">
  <div class="head-help">
    <h2 id="places-h"><?= $word('WAREHOUSES', 'places_title') ?></h2>
    <?= $explain('places', \CW\Ui\Words::WAREHOUSES['places']) ?>
  </div>
  <p class="muted"><?= $word('WAREHOUSES', 'places_text') ?></p>
<?php if ($places === []): ?>
  <p class="muted"><?= $word('WAREHOUSES', 'places_none') ?></p>
<?php else: ?>
  <div class="table-wrap">
  <table class="stack list places">
    <thead>
      <tr>
        <th scope="col"><?= $word('WAREHOUSES', 'place_name') ?></th>
        <th scope="col"><?= $word('WAREHOUSES', 'status') ?></th>
        <th scope="col"><?= $word('WAREHOUSES', 'place_code') ?></th>
        <th scope="col"><?= $word('WAREHOUSES', 'note') ?></th>
      </tr>
    </thead>
    <tbody>
<?php foreach ($places as $p): ?>
      <tr class="<?php if ($p['is_active']): ?>done<?php else: ?>inactive off<?php endif; ?>">
        <th scope="row" class="c-head"><?= $e($p['name']) ?></th>
        <td class="c-status"><?php if ($p['is_active']): ?><?= $chip('done', \CW\Ui\Words::WAREHOUSES['status_on']) ?><?php else: ?><?= $chip('off', \CW\Ui\Words::WAREHOUSES['status_off']) ?><?php endif; ?></td>
        <td data-label="<?= $word('WAREHOUSES', 'place_code') ?>"><code><?= $e($p['code']) ?></code></td>
        <td data-label="<?= $word('WAREHOUSES', 'note') ?>"><?= $e($p['note'] ?? '') ?></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?php if ($canEdit): ?>
<?php foreach ($places as $p): ?>
  <details class="fold place-change"<?php if ($p['open']): ?> open<?php endif; ?>>
    <summary><?= $word('WAREHOUSES', 'place_change') ?>: <?= $e($p['name']) ?></summary>
    <form class="record" method="post" action="<?= $u('/ui/reference/warehouses/' . $w['id']) ?>">
      <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
      <input type="hidden" name="do" value="place_rename">
      <input type="hidden" name="place" value="<?= $e($p['id']) ?>">
      <input type="hidden" name="seen" value="<?= $e($p['version']) ?>">
      <label><?= $word('WAREHOUSES', 'place_name') ?>
        <input type="text" name="name" value="<?= $e($p['typed']['name']) ?>" maxlength="100" required>
      </label>
      <label><?= $word('WAREHOUSES', 'note') ?>
        <input type="text" name="note" value="<?= $e($p['typed']['note']) ?>" maxlength="255">
      </label>
      <label><?= $word('CONFIG', 'reason') ?>
        <textarea name="reason" rows="2" minlength="3" maxlength="500" required></textarea>
      </label>
      <p class="actions"><button type="submit"><?= $word('WAREHOUSES', 'place_rename_button') ?></button></p>
    </form>
    <form class="record" method="post" action="<?= $u('/ui/reference/warehouses/' . $w['id']) ?>">
      <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
      <input type="hidden" name="do" value="<?php if ($p['is_active']): ?>place_off<?php else: ?>place_on<?php endif; ?>">
      <input type="hidden" name="place" value="<?= $e($p['id']) ?>">
      <input type="hidden" name="seen" value="<?= $e($p['version']) ?>">
      <label><?= $word('CONFIG', 'reason') ?>
        <textarea name="reason" rows="2" minlength="3" maxlength="500" required></textarea>
      </label>
      <p class="actions"><button type="submit"<?php if ($p['is_active']): ?> class="danger"<?php endif; ?>><?php if ($p['is_active']): ?><?= $word('WAREHOUSES', 'place_off_button') ?><?php else: ?><?= $word('WAREHOUSES', 'place_on_button') ?><?php endif; ?></button></p>
    </form>
    <?= $partial('config_history', ['rows' => $p['history']]) ?>
  </details>
<?php endforeach; ?>
<?php endif; ?>
<?php endif; ?>
<?php if ($canEdit && $w['is_active']): ?>
  <details class="fold place-add"<?php if ($typed['do'] === 'place_add'): ?> open<?php endif; ?>>
    <summary><?= $word('WAREHOUSES', 'place_add') ?></summary>
    <form class="record" method="post" action="<?= $u('/ui/reference/warehouses/' . $w['id']) ?>">
      <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
      <input type="hidden" name="do" value="place_add">
      <label><?= $word('WAREHOUSES', 'place_name') ?>
        <input type="text" name="name" value="<?= $e($typed['place_name']) ?>" maxlength="100" required>
      </label>
      <label><?= $word('WAREHOUSES', 'place_code') ?>
        <span class="hint"><?= $word('WAREHOUSES', 'place_code_hint') ?></span>
        <input type="text" name="code" value="<?= $e($typed['place_code']) ?>" maxlength="31" autocapitalize="characters" spellcheck="false" required>
      </label>
      <label><?= $word('WAREHOUSES', 'note') ?>
        <input type="text" name="note" value="<?= $e($typed['place_note']) ?>" maxlength="255">
      </label>
      <label><?= $word('CONFIG', 'reason') ?>
        <span class="hint"><?= $word('CONFIG', 'reason_hint') ?></span>
        <textarea name="reason" rows="2" minlength="3" maxlength="500" required><?php if ($typed['do'] === 'place_add'): ?><?= $e($typed['reason']) ?><?php endif; ?></textarea>
      </label>
      <p class="actions"><button type="submit" class="primary"><?= $word('WAREHOUSES', 'place_add_button') ?></button></p>
    </form>
  </details>
<?php endif; ?>
</section>

<section aria-labelledby="history-h">
  <h2 id="history-h"><?= $word('WAREHOUSES', 'history') ?></h2>
<?= $partial('config_history', ['rows' => $history]) ?>
</section>
