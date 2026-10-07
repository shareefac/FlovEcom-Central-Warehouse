<?php $v = $typed ?? []; ?>
<p class="crumbs"><a href="/ui/purchasing/reorder"><?= $word('MENU', 'reorder') ?></a></p>
<h1><?= $word('PAGE_TITLE', 'reorder_anomalies') ?></h1>
<?= $intro('reorder_anomalies') ?>
<p><?= $say('ANOMALIES', 'intro', $maxDays) ?></p>
<?php if ($lookOnly !== null): ?>
<p class="hint"><?= $say('UI', 'look_only', $lookOnly) ?></p>
<?php endif; ?>
<?php if ($error !== null): ?>
<p class="error" role="alert"><?= $e($error) ?></p>
<?php endif; ?>
<?php if ($canManage): ?>
<section class="card box" aria-labelledby="add-h">
  <h2 id="add-h"><?= $word('ANOMALIES', 'add') ?></h2>
  <form class="record" method="post" action="/ui/purchasing/reorder/anomalies">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="form_key" value="<?= $e($formKey) ?>">
    <fieldset>
      <legend class="visually-hidden"><?= $word('ANOMALIES', 'add') ?></legend>
      <label><?= $word('ANOMALIES', 'from') ?> <input type="date" name="date_from" value="<?= $e($v['date_from'] ?? '') ?>" required></label>
      <label><?= $word('ANOMALIES', 'to') ?> <input type="date" name="date_to" value="<?= $e($v['date_to'] ?? '') ?>" required></label>
      <label><?= $word('ANOMALIES', 'website') ?>
        <select name="channel_id">
          <option value=""><?= $word('ANOMALIES', 'every_website') ?></option>
<?php foreach ($channels as $c): ?>
          <option value="<?= $e($c['id']) ?>"<?php if ((string) ($v['channel_id'] ?? '') === (string) $c['id']): ?> selected<?php endif; ?>><?= $e($c['name']) ?></option>
<?php endforeach; ?>
        </select>
      </label>
      <label><?= $word('ANOMALIES', 'brand') ?> <span class="hint"><?= $word('ANOMALIES', 'brand_hint') ?></span> <input type="text" name="brand" value="<?= $e($v['brand'] ?? '') ?>" maxlength="128"></label>
      <label><?= $word('ANOMALIES', 'label') ?> <span class="hint"><?= $word('ANOMALIES', 'label_hint') ?></span> <input type="text" name="label" value="<?= $e($v['label'] ?? '') ?>" maxlength="200" required></label>
    </fieldset>
    <p class="actions"><button type="submit" class="primary"><?= $word('ANOMALIES', 'save') ?></button></p>
  </form>
</section>
<?php endif; ?>
<?php if ($rows === []): ?>
<?= $empty(\CW\Ui\Words::ANOMALIES['none']) ?>
<?php else: ?>
<div class="table-wrap">
<table class="stack list anomalies">
  <thead>
    <tr>
      <th scope="col"><?= $word('ANOMALIES', 'what') ?></th>
      <th scope="col"><?= $word('ANOMALIES', 'state') ?></th>
      <th scope="col"><?= $word('ANOMALIES', 'days') ?></th>
      <th scope="col"><?= $word('ANOMALIES', 'which') ?></th>
      <th scope="col"><?= $word('ANOMALIES', 'added') ?></th>
<?php if ($canManage): ?>
      <th scope="col"><span class="visually-hidden"><?= $word('ANOMALIES', 'end') ?></span></th>
<?php endif; ?>
    </tr>
  </thead>
  <tbody>
<?php foreach ($rows as $r): ?>
    <tr<?php if ((int) $r['is_active'] !== 1): ?> class="inactive"<?php endif; ?>>
      <th scope="row" class="c-head"><?= $e($r['label']) ?></th>
      <td class="c-status"><?php if ((int) $r['is_active'] === 1): ?><?= $chip('info', \CW\Ui\Words::ANOMALIES['active']) ?><?php elseif ($r['ended_by_name'] !== null): ?><?= $chip('off', \CW\Ui\Words::say('ANOMALIES', 'ended_by', \CW\Ui\Html::day((string) $r['ended_at']), (string) $r['ended_by_name'])) ?><?php else: ?><?= $chip('off', \CW\Ui\Words::say('ANOMALIES', 'ended', \CW\Ui\Html::day((string) $r['ended_at']))) ?><?php endif; ?></td>
      <td data-label="<?= $word('ANOMALIES', 'days') ?>"><?= $say('ANOMALIES', 'days_line', \CW\Ui\Html::day($r['date_from']), \CW\Ui\Html::day($r['date_to'])) ?></td>
      <td data-label="<?= $word('ANOMALIES', 'which') ?>"><?= $e($r['channel_code'] === null ? \CW\Ui\Words::ANOMALIES['all_websites'] : ($channelNames[$r['channel_code']] ?? $r['channel_code'])) ?> · <?= $e($r['brand'] ?? \CW\Ui\Words::ANOMALIES['all_brands']) ?></td>
      <td data-label="<?= $word('ANOMALIES', 'added') ?>"><?= $say('ANOMALIES', 'by', \CW\Ui\Html::when($r['created_at']), (string) ($r['created_by_name'] ?? \CW\Ui\Words::ANOMALIES['set_up'])) ?></td>
<?php if ($canManage): ?>
      <td class="c-next"><?php if ((int) $r['is_active'] === 1): ?>
        <form class="inline" method="post" action="<?= $u('/ui/purchasing/reorder/anomalies/' . $r['id'] . '/end') ?>">
          <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
          <button type="submit"><?= $word('ANOMALIES', 'end') ?></button>
          <span class="hint"><?= $word('ANOMALIES', 'end_hint') ?></span>
        </form><?php endif; ?></td>
<?php endif; ?>
    </tr>
<?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>
