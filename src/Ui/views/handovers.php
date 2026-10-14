<div class="head-help">
  <h1><?= $word('SEGMENT', 'handovers') ?></h1>
</div>
<?= $intro('handovers', $lookOnly) ?>
<?php if ($error !== null): ?>
<p class="error" role="alert" data-code="<?= $e($errorCode) ?>"><?= $e($error) ?></p>
<?php endif; ?>
<details class="fold how">
  <summary><?= $word('HANDOVER', 'how') ?></summary>
  <ol>
<?php foreach (\CW\Ui\Words::HANDOVER_HOW as $step): ?>
    <li><?= $e($step) ?></li>
<?php endforeach; ?>
  </ol>
</details>

<form class="toolbar" method="get" aria-label="<?= $word('UI', 'filter') ?>">
  <div class="tb-search"><span class="ico ico-search" aria-hidden="true"></span><label class="visually-hidden" for="tb-q"><?= $word('HANDOVER', 'search') ?></label><input id="tb-q" type="search" name="q" value="<?= $e($f['q']) ?>" maxlength="100" placeholder="<?= $word('UI', 'search') ?>"></div>
  <details class="tb-pop">
    <summary class="btn ghost sm"><span class="ico ico-filter" aria-hidden="true"></span><span><?= $word('UI', 'filter') ?></span><?php if ($filtered): ?> <span class="count">&#10003;</span><?php endif; ?></summary>
    <div class="pop pop-form">
      <label><?= $word('HANDOVER', 'state') ?>
        <select name="state">
          <option value=""><?= $word('HANDOVER', 'any_state') ?></option>
<?php foreach ($states as $code => $label): ?>
          <option value="<?= $e($code) ?>"<?php if ($f['state'] === $code): ?> selected<?php endif; ?>><?= $e($label) ?></option>
<?php endforeach; ?>
        </select>
      </label>
      <label><?= $word('HANDOVER', 'customer') ?>
        <select name="counterparty">
          <option value=""><?= $word('HANDOVER', 'any_customer') ?></option>
<?php foreach ($customers as $c): ?>
          <option value="<?= $e($c['id']) ?>"<?php if ($f['counterparty'] === $c['id']): ?> selected<?php endif; ?>><?= $e($c['name']) ?></option>
<?php endforeach; ?>
        </select>
      </label>
      <label class="choice"><input type="checkbox" name="cancelled" value="1"<?php if ($f['cancelled']): ?> checked<?php endif; ?>> <?= $word('HANDOVER', 'stopped') ?></label>
      <div class="pop-actions"><?php if ($filtered): ?><a class="btn ghost sm" href="/ui/stock/handovers"><?= $word('HANDOVER', 'clear') ?></a><?php endif; ?><button type="submit" class="btn primary sm"><?= $word('UI', 'apply') ?></button></div>
    </div>
  </details>
<?php if ($rowsN > 0): ?>
  <div class="tb-end"><a class="btn ghost sm" href="<?= $e($csv) ?>" title="<?= $word('HANDOVER', 'download') ?>"><span class="ico ico-export" aria-hidden="true"></span><span><?= $word('UI', 'export') ?></span></a></div>
<?php endif; ?>
</form>

<?php if ($formKey !== null): ?>
<details class="fold new-order" id="new"<?php if ($error !== null || $rowsN === 0): ?> open<?php endif; ?>>
  <summary><?= $word('HANDOVER', 'start') ?></summary>
<?php if ($form['noCustomers']): ?>
  <?= $empty(\CW\Ui\Words::HANDOVER['no_customers'], \CW\Ui\Words::HANDOVER['no_customers_text'], '/ui/stock/handovers/customers#new', \CW\Ui\Words::HANDOVER['no_customers_link']) ?>
<?php else: ?>
  <p class="hint"><?= $word('HANDOVER', 'start_text') ?></p>
  <form class="record handover-new" method="post" action="/ui/stock/handovers">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="form_key" value="<?= $e($formKey) ?>">
<?= $partial('handover_fields', ['form' => $form]) ?>
    <p class="actions"><button type="submit" class="primary"><?= $word('HANDOVER', 'start_button') ?></button></p>
  </form>
<?php endif; ?>
</details>
<?php endif; ?>

<?php if ($groups === [] && $filtered): ?>
<?= $empty(\CW\Ui\Words::HANDOVER['none_filter'], \CW\Ui\Words::HANDOVER['none_filter_text'], '/ui/stock/handovers', \CW\Ui\Words::HANDOVER['clear']) ?>
<?php elseif ($groups === []): ?>
<?= $empty(\CW\Ui\Words::HANDOVER['none'], \CW\Ui\Words::HANDOVER['none_text']) ?>
<?php else: ?>
<?php foreach ($groups as $g => $list): ?>
<?php $tone = $list[0]['tone']; $name = $g === 'reversal' ? \CW\Ui\Words::HANDOVER['group_reversal'] : \CW\Ui\Words::of('DOC_STATUS', $g); ?>
<div class="grp-block">
  <h3 class="grp-title <?= $e($tone) ?>"><button class="grp-toggle" type="button" aria-expanded="true" aria-controls="g-<?= $e($g) ?>"><?= $e($name) ?></button><span class="grp-count"><?= $say('HANDOVER', 'group_count', count($list)) ?></span></h3>
  <div class="table-wrap" id="g-<?= $e($g) ?>">
  <table class="stack list board handover-records <?= $e($tone) ?>">
    <thead>
      <tr>
        <th scope="col" class="c-item"><?= $word('HANDOVER', 'record') ?></th>
        <th scope="col"><?= $word('HANDOVER', 'customer') ?></th>
        <th scope="col" class="c-status"><?= $word('HANDOVER', 'status') ?></th>
        <th scope="col" class="num"><?= $word('HANDOVER', 'products') ?></th>
        <th scope="col" class="num"><?= $word('HANDOVER', 'units') ?></th>
        <th scope="col" class="num"><?= $word('HANDOVER', 'value') ?></th>
        <th scope="col"><?= $word('HANDOVER', 'made_by') ?></th>
        <th scope="col"><?= $word('HANDOVER', 'next') ?></th>
      </tr>
    </thead>
    <tbody>
<?php foreach ($list as $r): ?>
      <tr class="<?= $e($r['tone']) ?>">
        <th scope="row" class="c-head c-item"><a class="o-name" href="<?= $u($r['href']) ?>"><?= $e($r['number_line']) ?></a><span class="o-sub"><?= $e($r['date_line']) ?></span></th>
        <td data-label="<?= $word('HANDOVER', 'customer') ?>"><?= $e($r['customer']) ?><span class="o-sub"><?= $e($r['kind']) ?></span></td>
        <td class="c-status"><?= $chip($r['tone'], $r['state_word']) ?></td>
        <td class="num" data-label="<?= $word('HANDOVER', 'products') ?>"><?= $n($r['products']) ?></td>
        <td class="num" data-label="<?= $word('HANDOVER', 'units') ?>"><?= $n($r['units']) ?></td>
        <td class="num" data-label="<?= $word('HANDOVER', 'value') ?>"><?php if ($r['value'] !== null): ?><?= $money($r['value']) ?><?php if (!$r['final']): ?><span class="o-sub"><?= $word('HANDOVER', 'before_vat') ?></span><?php endif; ?><?php endif; ?></td>
        <td data-label="<?= $word('HANDOVER', 'made_by') ?>"><?= $e($r['made_by']) ?></td>
        <td class="c-wide" data-label="<?= $word('HANDOVER', 'next') ?>"><?= $e($r['next']) ?></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<?php endforeach; ?>
<?php if ($rowsN >= $limit): ?>
<p class="muted"><?= $say('HANDOVER', 'limit', $limit) ?></p>
<?php endif; ?>
<?php endif; ?>
