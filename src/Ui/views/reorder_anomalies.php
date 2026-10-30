<?php
// Purchasing › Reorder › Days left out, a report (the owner's approved TP_Report; docs/decisions.md W9.12): the figures strip (left out now,
// used again, in all, the most days at a time), the days to add in two columns (reorder.manage; FormOnce), then the periods grouped left out
// and used again, each group a coloured heading with its count over a card with its colour on the left edge, the state cell filled.
$v = $typed ?? [];
foreach ($rows as &$r) {
    $r['group'] = (int) $r['is_active'] === 1 ? 'active' : 'ended';
}
unset($r);
$groups = \CW\Ui\Html::groups($rows, ['active' => ['info', \CW\Ui\Words::ANOMALIES['group_active']], 'ended' => ['off', \CW\Ui\Words::ANOMALIES['group_ended']]]);
$active = count(array_filter($rows, static fn (array $r): bool => $r['group'] === 'active'));
?>
<?= $head(\CW\Ui\Words::PAGE_TITLE['reorder_anomalies'], intro: 'reorder_anomalies', look: $lookOnly) ?>
<?php if ($error !== null): ?>
<p class="error" role="alert"><?= $e($error) ?></p>
<?php endif; ?>
<section class="doc-progress rp-figs" aria-label="<?= $word('ANOMALIES', 'figures') ?>">
  <div class="doc-step"><p class="doc-step-label"><?= $word('ANOMALIES', 'fig_active') ?></p><p class="doc-step-big"><?= $n($active) ?></p></div>
  <div class="doc-step"><p class="doc-step-label"><?= $word('ANOMALIES', 'fig_ended') ?></p><p class="doc-step-big"><?= $n(count($rows) - $active) ?></p></div>
  <div class="doc-step"><p class="doc-step-label"><?= $word('ANOMALIES', 'fig_all') ?></p><p class="doc-step-big"><?= $n(count($rows)) ?></p></div>
  <div class="doc-step"><p class="doc-step-label"><?= $word('ANOMALIES', 'fig_max') ?></p><p class="doc-step-big"><?= $n($maxDays) ?></p></div>
</section>
<?php if ($canManage): ?>
<form class="doc-details" method="post" action="/ui/purchasing/reorder/anomalies" data-unsaved="1" data-unsaved-text="<?= $word('DOC', 'unsaved') ?>" data-leaves="1">
  <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
  <input type="hidden" name="form_key" value="<?= $e($formKey) ?>">
  <fieldset class="doc-grid">
    <legend><?= $word('ANOMALIES', 'add') ?></legend>
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
    <label class="doc-wide"><?= $word('ANOMALIES', 'label') ?> <span class="hint"><?= $word('ANOMALIES', 'label_hint') ?></span> <input type="text" name="label" value="<?= $e($v['label'] ?? '') ?>" maxlength="200" required></label>
    <p class="actions doc-wide"><button type="submit" class="btn primary"><?= $word('ANOMALIES', 'save') ?></button></p>
  </fieldset>
</form>
<?php endif; ?>
<?php if ($rows === []): ?>
<?= $empty(\CW\Ui\Words::ANOMALIES['none']) ?>
<?php else: ?>
<?php foreach ($groups as $g): ?>
<div class="grp-block doc-group <?= $e($g['tone']) ?>">
  <h2 class="grp-title"><button class="grp-toggle" type="button" aria-expanded="true" aria-controls="g-<?= $e($g['key']) ?>"><?= $e($g['name']) ?></button> <span class="grp-count"><?php if (count($g['rows']) === 1): ?><?= $word('ANOMALIES', 'group_one') ?><?php else: ?><?= $say('ANOMALIES', 'group_many', count($g['rows'])) ?><?php endif; ?></span></h2>
  <div class="table-wrap doc-board" id="g-<?= $e($g['key']) ?>">
  <table class="stack list board anomalies">
    <thead>
      <tr>
        <th scope="col"><?= $word('ANOMALIES', 'what') ?></th>
        <th scope="col" class="c-status"><?= $word('ANOMALIES', 'state') ?></th>
        <th scope="col"><?= $word('ANOMALIES', 'days') ?></th>
        <th scope="col"><?= $word('ANOMALIES', 'which') ?></th>
        <th scope="col"><?= $word('ANOMALIES', 'added') ?></th>
<?php if ($canManage && $g['key'] === 'active'): ?>
        <th scope="col"><span class="visually-hidden"><?= $word('ANOMALIES', 'end') ?></span></th>
<?php endif; ?>
      </tr>
    </thead>
    <tbody>
<?php foreach ($g['rows'] as $r): ?>
      <tr>
        <th scope="row" class="c-head"><?= $e($r['label']) ?></th>
        <td class="c-status doc-st <?= $e($g['tone']) ?>"><span class="chip"><?php if ((int) $r['is_active'] === 1): ?><?= $word('ANOMALIES', 'active') ?><?php elseif ($r['ended_by_name'] !== null): ?><?= $say('ANOMALIES', 'ended_by', \CW\Ui\Html::day((string) $r['ended_at']), (string) $r['ended_by_name']) ?><?php else: ?><?= $say('ANOMALIES', 'ended', \CW\Ui\Html::day((string) $r['ended_at'])) ?><?php endif; ?></span></td>
        <td data-label="<?= $word('ANOMALIES', 'days') ?>"><?= $say('ANOMALIES', 'days_line', \CW\Ui\Html::day($r['date_from']), \CW\Ui\Html::day($r['date_to'])) ?></td>
        <td data-label="<?= $word('ANOMALIES', 'which') ?>"><?= $e($r['channel_code'] === null ? \CW\Ui\Words::ANOMALIES['all_websites'] : ($channelNames[$r['channel_code']] ?? $r['channel_code'])) ?> · <?= $e($r['brand'] ?? \CW\Ui\Words::ANOMALIES['all_brands']) ?></td>
        <td data-label="<?= $word('ANOMALIES', 'added') ?>"><?= $say('ANOMALIES', 'by', \CW\Ui\Html::when($r['created_at']), (string) ($r['created_by_name'] ?? \CW\Ui\Words::ANOMALIES['set_up'])) ?></td>
<?php if ($canManage && $g['key'] === 'active'): ?>
        <td class="c-next">
          <form class="inline" method="post" action="<?= $u('/ui/purchasing/reorder/anomalies/' . $r['id'] . '/end') ?>" data-leaves="1">
            <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
            <button class="btn sm" type="submit" title="<?= $word('ANOMALIES', 'end_hint') ?>"><?= $word('ANOMALIES', 'end') ?></button>
          </form>
        </td>
<?php endif; ?>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<?php endforeach; ?>
<?php endif; ?>
