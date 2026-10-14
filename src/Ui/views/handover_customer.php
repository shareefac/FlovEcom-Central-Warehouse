<p class="crumbs"><a href="/ui/stock/handovers/customers"><?= $word('SEGMENT', 'handover_customers') ?></a></p>
<h1><?= $e($c['name']) ?> <?php if ((int) $c['is_active'] === 1): ?><?= $chip('done', \CW\Ui\Words::CUSTOMER['on']) ?><?php else: ?><?= $chip('off', \CW\Ui\Words::CUSTOMER['off']) ?><?php endif; ?></h1>
<?= $intro('handover_customer', $lookOnly) ?>
<?php if ($error !== null): ?>
<p class="error" role="alert" data-code="<?= $e($errorCode) ?>"><?= $e($error) ?></p>
<?php endif; ?>

<section aria-labelledby="facts-h">
  <h2 id="facts-h" class="visually-hidden"><?= $word('CUSTOMER', 'details') ?></h2>
  <dl class="wide">
    <dt><?= $word('CUSTOMER', 'kind') ?></dt><dd><?= $word('CUSTOMER_KIND', (string) $c['kind']) ?></dd>
    <dt><?= $word('CUSTOMER', 'f_address') ?></dt><dd class="pre"><?php if ((string) $c['address'] === ''): ?><span class="muted"><?= $word('CUSTOMER', 'not_given') ?></span><?php else: ?><?= $e($c['address']) ?><?php endif; ?></dd>
    <dt><?= $word('CUSTOMER', 'f_company_number') ?></dt><dd><?php if ((string) $c['company_number'] === ''): ?><span class="muted"><?= $word('CUSTOMER', 'not_given') ?></span><?php else: ?><?= $e($c['company_number']) ?><?php endif; ?></dd>
    <dt><?= $word('CUSTOMER', 'f_vat_number') ?></dt><dd><?php if ((string) $c['vat_number'] === ''): ?><span class="muted"><?= $word('CUSTOMER', 'not_given') ?></span><?php else: ?><?= $e(\CW\Company\CompanyDetails::formatVat((string) $c['vat_number'])) ?><?php endif; ?></dd>
    <dt><?= $word('CUSTOMER', 'vat_code') ?></dt><dd><?php if ($c['vat_code'] === null): ?><?= $word('CUSTOMER', 'vat_company') ?><?php else: ?><?= $e($c['vat_code']) ?><?php endif; ?></dd>
    <dt><?= $word('CUSTOMER', 'f_email') ?></dt><dd><?= $e($c['email']) ?></dd>
    <dt><?= $word('CUSTOMER', 'f_phone') ?></dt><dd><?= $e($c['phone']) ?></dd>
<?php if ($c['note'] !== null): ?>
    <dt><?= $word('CUSTOMER', 'f_note') ?></dt><dd><?= $e($c['note']) ?></dd>
<?php endif; ?>
    <dt><?= $word('CUSTOMER', 'balance') ?></dt><dd><?= $money($balance) ?> &middot; <a href="<?= $u($balanceHref) ?>"><?= $word('CUSTOMER', 'balance_link') ?></a></dd>
    <dt><?= $word('CUSTOMER', 'changed') ?></dt><dd><?= $say('CUSTOMER', 'by', \CW\Ui\Html::when((string) $c['updated_at']), (string) ($c['updated_by_name'] ?? $c['created_by_name'] ?? '')) ?></dd>
  </dl>
  <p><a href="<?= $e($listHref) ?>"><?= $word('CUSTOMER', 'its_handovers') ?></a></p>
</section>

<?php if ($canManage): ?>
<details class="fold"<?php if ($error !== null): ?> open<?php endif; ?>>
  <summary><?= $word('CUSTOMER', 'change') ?></summary>
  <form class="record customer" method="post" action="<?= $u('/ui/stock/handovers/customers/' . $c['id']) ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="version" value="<?= $e($version) ?>">
<?= $partial('handover_customer_fields', ['v' => $v, 'errors' => $errors, 'kinds' => $kinds, 'vatCodes' => $vatCodes]) ?>
    <p class="actions"><button type="submit" class="primary"><?= $word('CUSTOMER', 'save') ?></button></p>
  </form>
</details>
<form class="inline" method="post" action="<?= $u('/ui/stock/handovers/customers/' . $c['id'] . '/active') ?>">
  <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
  <input type="hidden" name="version" value="<?= $e($version) ?>">
<?php if ((int) $c['is_active'] === 1): ?>
  <input type="hidden" name="on" value="0">
  <p class="muted"><?= $word('CUSTOMER', 'switch_off_text') ?></p>
  <button type="submit" class="danger"><?= $word('CUSTOMER', 'switch_off') ?></button>
<?php else: ?>
  <input type="hidden" name="on" value="1">
  <button type="submit"><?= $word('CUSTOMER', 'switch_on') ?></button>
<?php endif; ?>
</form>
<?php endif; ?>

<section aria-labelledby="prices-h">
  <h2 id="prices-h"><?= $word('CUSTOMER', 'prices') ?></h2>
  <p class="muted"><?= $word('CUSTOMER', 'prices_text') ?></p>
<?php if ($prices === []): ?>
  <p class="muted"><?= $word('CUSTOMER', 'no_prices') ?></p>
<?php else: ?>
  <div class="table-wrap">
  <table class="stack list prices">
    <thead>
      <tr>
        <th scope="col"><?= $word('CUSTOMER', 'price_item') ?></th>
        <th scope="col" class="num"><?= $word('CUSTOMER', 'price') ?></th>
        <th scope="col"><?= $word('CUSTOMER', 'price_day') ?></th>
        <th scope="col"><?= $word('CUSTOMER', 'price_from') ?></th>
      </tr>
    </thead>
    <tbody>
<?php foreach ($prices as $p): ?>
      <tr>
        <th scope="row" class="c-head"><a href="<?= $u('/ui/items/' . $p['sku_id']) ?>"><?= $e($p['name']) ?></a><span class="o-sub"><?= $e($p['code']) ?></span></th>
        <td class="num" data-label="<?= $word('CUSTOMER', 'price') ?>"><?= $money($p['unit_price']) ?></td>
        <td data-label="<?= $word('CUSTOMER', 'price_day') ?>"><?= $day($p['priced_on']) ?></td>
        <td data-label="<?= $word('CUSTOMER', 'price_from') ?>"><?php if ($p['source'] === 'handover' && $p['document_id'] !== null): ?><a href="<?= $u('/ui/stock/handovers/' . $p['document_id']) ?>"><?= $e($p['number']) ?></a><?php else: ?><?= $say('CUSTOMER', 'price_imported', (string) ($p['source_ref'] ?? '')) ?><?php endif; ?></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?php endif; ?>
</section>

<section aria-labelledby="recent-h">
  <h2 id="recent-h"><?= $word('CUSTOMER', 'recent') ?></h2>
<?php if ($handovers === []): ?>
  <p class="muted"><?= $word('CUSTOMER', 'no_handovers') ?></p>
<?php else: ?>
  <ul class="plain">
<?php foreach (array_slice($handovers, 0, 10) as $h): ?>
    <li><a href="<?= $u('/ui/stock/handovers/' . $h['id']) ?>"><?php if ($h['number'] === null): ?><?= $word('HANDOVER', 'no_number') ?><?php else: ?><?= $e($h['number']) ?><?php endif; ?></a> &middot; <?= $word('DOC_STATUS', (string) $h['status']) ?><?php if ($h['gross_amount'] !== null): ?> &middot; <?= $money($h['gross_amount']) ?><?php endif; ?></li>
<?php endforeach; ?>
  </ul>
<?php endif; ?>
</section>
