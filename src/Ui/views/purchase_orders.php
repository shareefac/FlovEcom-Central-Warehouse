<div class="head-help">
  <h1><?= $word('MENU', 'orders') ?></h1>
  <?= $explain('second_ok', \CW\Ui\Words::THING['second']) ?>
</div>
<?= $intro('orders', $lookOnly) ?>
<?php if ($error !== null): ?>
<p class="error" role="alert"><?= $e($error) ?></p>
<?php endif; ?>
<details class="fold how">
  <summary><?= $word('ORDERS', 'how') ?></summary>
  <ol>
    <li><?= $word('ORDERS', 'how_1') ?></li>
    <li><?= $word('ORDERS', 'how_2') ?></li>
    <li><?= $word('ORDERS', 'how_3') ?></li>
    <li><?= $say('ORDERS', 'how_4', $approvalLimit) ?></li>
  </ol>
  <p><?= $word('ORDERS', 'how_fix') ?></p>
</details>

<?php if ($formKey !== null): ?>
<section class="card box new-order" aria-labelledby="new-h" id="new">
  <h2 id="new-h"><?= $word('ORDERS', 'new') ?></h2>
<?php if ($newSuppliers === []): ?>
  <?= $empty(\CW\Ui\Words::ORDERS['no_supplier'], \CW\Ui\Words::ORDERS['no_supplier_text'], '/ui/purchasing/suppliers/new', \CW\Ui\Words::ORDERS['add_supplier']) ?>
<?php else: ?>
  <p class="hint"><?= $word('ORDERS', 'new_text') ?></p>
  <form class="inline" method="post" action="/ui/purchasing/orders">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="form_key" value="<?= $e($formKey) ?>">
    <label><?= $word('ORDERS', 'supplier') ?>
      <select name="supplier_id" required>
        <option value=""><?= $word('ORDERS', 'choose_supplier') ?></option>
<?php foreach ($newSuppliers as $s): ?>
        <option value="<?= $e($s['id']) ?>"<?php if ($filters['supplier'] === (int) $s['id']): ?> selected<?php endif; ?>><?php if ($s['status'] === 'active'): ?><?= $e($s['name']) ?><?php else: ?><?= $say('ORDERS', 'not_ready', (string) $s['name'], $s['status'] === 'draft' ? \CW\Ui\Words::ORDERS['not_approved'] : \CW\Ui\Words::ORDERS['waiting_ok']) ?><?php endif; ?></option>
<?php endforeach; ?>
      </select>
    </label>
    <button type="submit" class="primary"><?= $word('ORDERS', 'new') ?></button>
  </form>
<?php endif; ?>
</section>
<?php endif; ?>

<form class="filters" method="get" action="/ui/purchasing/orders">
  <label><?= $word('ORDERS', 'show') ?>
    <select name="state">
      <option value=""><?= $word('ORDERS', 'all_orders') ?></option>
<?php foreach ($states as $code => $label): ?>
      <option value="<?= $e($code) ?>"<?php if ($filters['state'] === $code): ?> selected<?php endif; ?>><?= $e($label) ?></option>
<?php endforeach; ?>
    </select>
  </label>
  <label><?= $word('ORDERS', 'supplier') ?>
    <select name="supplier">
      <option value=""><?= $word('ORDERS', 'any_supplier') ?></option>
<?php foreach ($suppliers as $s): ?>
      <option value="<?= $e($s['id']) ?>"<?php if ($filters['supplier'] === (int) $s['id']): ?> selected<?php endif; ?>><?= $e($s['name']) ?></option>
<?php endforeach; ?>
    </select>
  </label>
  <label><?= $word('ORDERS', 'search') ?> <input type="search" name="q" value="<?= $e($filters['q']) ?>" maxlength="100"></label>
  <label class="choice"><input type="checkbox" name="rejected" value="1"<?php if ($filters['rejected']): ?> checked<?php endif; ?>> <?= $word('ORDERS', 'rejected') ?></label>
  <label class="choice"><input type="checkbox" name="show" value="all"<?php if ($filters['all']): ?> checked<?php endif; ?>> <?= $word('ORDERS', 'all') ?></label>
<?php if ($draftsChoice): ?>
  <label class="choice"><input type="checkbox" name="drafts" value="1"<?php if ($filters['drafts']): ?> checked<?php endif; ?>> <?= $word('ORDERS', 'drafts') ?></label>
<?php endif; ?>
  <button type="submit"><?= $word('ORDERS', 'filter') ?></button>
</form>
<?php if ($draftsHidden): ?>
<p class="note drafts-hidden"><?= $word('ORDERS', 'drafts_hidden') ?> <a href="<?= $u('/ui/purchasing/orders', ['supplier' => $filters['supplier'], 'q' => $filters['q'] === '' ? null : $filters['q'], 'rejected' => $filters['rejected'] ? '1' : null, 'show' => $filters['all'] ? 'all' : null, 'drafts' => '1']) ?>"><?= $word('ORDERS', 'drafts_show') ?></a></p>
<?php endif; ?>

<?php if ($rows === [] && $filtered): ?>
<?= $empty(\CW\Ui\Words::ORDERS['none_filter'], \CW\Ui\Words::ORDERS['none_filter_text'], '/ui/purchasing/orders', \CW\Ui\Words::ORDERS['clear']) ?>
<?php elseif ($rows === []): ?>
<?= $empty(\CW\Ui\Words::ORDERS['none'], \CW\Ui\Words::ORDERS[$canPost ? 'none_buyer' : 'none_text']) ?>
<?php else: ?>
<div class="crumbs">
  <p class="muted"><?php if (count($rows) === 1): ?><?= $word('ORDERS', 'total_one') ?><?php else: ?><?= $say('ORDERS', 'total_many', count($rows)) ?><?php endif; ?></p>
  <p class="actions"><a href="<?= $u('/ui/purchasing/orders.csv', ['state' => $filters['state'], 'supplier' => $filters['supplier'], 'q' => $filters['q'], 'rejected' => $filters['rejected'] ? '1' : null, 'show' => $filters['all'] ? 'all' : null, 'drafts' => $filters['drafts'] ? null : '0']) ?>"><?= $word('ORDERS', 'download') ?></a></p>
</div>
<div class="table-wrap">
<table class="stack list orders">
  <thead>
    <tr>
      <th scope="col"><?= $word('ORDERS', 'order') ?></th>
      <th scope="col"><?= $word('ORDERS', 'status') ?></th>
      <th scope="col"><?= $word('ORDERS', 'dates') ?></th>
      <th scope="col"><?= $word('ORDERS', 'size') ?></th>
      <th scope="col" class="num"><?= $word('ORDERS', 'total') ?></th>
      <th scope="col"><?= $word('ORDERS', 'check') ?></th>
      <th scope="col"><span class="visually-hidden"><?= $word('ORDERS', 'open') ?></span></th>
    </tr>
  </thead>
  <tbody>
<?php foreach ($rows as $r): ?>
    <tr class="<?= $e($r['state_tone']) ?>">
      <th scope="row" class="c-head"><a class="o-name" href="<?= $u($r['href']) ?>"><?= $e($r['name']) ?></a><span class="o-no"><?= $e($r['number_line']) ?></span></th>
      <td class="c-status"><?= $chip($r['state_tone'], $r['state_word']) ?></td>
      <td class="c-wide" data-label="<?= $word('ORDERS', 'status') ?>"><?= $e($r['next']) ?></td>
      <td data-label="<?= $word('ORDERS', 'dates') ?>"><?php if ($r['date_line'] !== null): ?><span class="o-sub"><?= $e($r['date_line']) ?></span><?php endif; ?><?php if ($r['expected_line'] !== null): ?><span class="o-sub"><?= $e($r['expected_line']) ?></span><?php endif; ?><?php if ($r['sent_line'] !== null): ?><span class="o-sub"><?= $e($r['sent_line']) ?></span><?php endif; ?></td>
      <td data-label="<?= $word('ORDERS', 'size') ?>"><?= $e($r['size_line']) ?> <span class="o-sub"><?= $e($r['items_line']) ?></span></td>
      <td class="num" data-label="<?= $word('ORDERS', 'total') ?>"><?= $e($r['total']) ?></td>
      <td data-label="<?= $word('ORDERS', 'check') ?>"><?php if ($r['check'] !== null): ?><?= $chip($r['check_tone'], $r['check']) ?><?php endif; ?></td>
      <td class="c-next"><a class="btn secondary" href="<?= $u($r['href']) ?>"><?= $word('ORDERS', 'open') ?></a></td>
    </tr>
<?php endforeach; ?>
  </tbody>
</table>
</div>
<?php if (count($rows) >= $limit): ?>
<p class="muted"><?= $say('ORDERS', 'limit', $limit) ?></p>
<?php endif; ?>
<?php endif; ?>
