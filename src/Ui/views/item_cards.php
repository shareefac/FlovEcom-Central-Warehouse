<form class="toolbar" method="get" aria-label="<?= $word('UI', 'filter') ?>">
  <div class="tb-search"><span class="ico ico-search" aria-hidden="true"></span><label class="visually-hidden" for="tb-q"><?= $word('CARDS', 'search') ?></label><input id="tb-q" type="search" name="q" value="<?= $e($filters['q']) ?>" maxlength="100" placeholder="<?= $word('UI', 'search') ?>"></div>
  <details class="tb-pop">
    <summary class="btn ghost sm"><span class="ico ico-filter" aria-hidden="true"></span><span><?= $word('UI', 'filter') ?></span><?php if ($filtered): ?> <span class="count">&#10003;</span><?php endif; ?></summary>
    <div class="pop pop-form">
      <label><?= $word('CARDS', 'card') ?>
        <select name="state">
<?php foreach ($states as $code => $label): ?>
          <option value="<?= $e($code === 'all' ? '' : $code) ?>"<?php if ($filters['state'] === $code): ?> selected<?php endif; ?>><?= $e($label) ?></option>
<?php endforeach; ?>
        </select>
      </label>
      <label><?= $word('CARDS', 'kind') ?>
        <select name="type">
          <option value=""><?= $word('CARDS', 'any') ?></option>
          <option value="none"<?php if ($filters['type'] === 'none'): ?> selected<?php endif; ?>><?= $word('CARDS', 'not_set') ?></option>
<?php foreach ($types as $code => $label): ?>
          <option value="<?= $e($code) ?>"<?php if ($filters['type'] === $code): ?> selected<?php endif; ?>><?= $e($label) ?></option>
<?php endforeach; ?>
        </select>
      </label>
      <label class="choice"><input type="checkbox" name="stock" value="1"<?php if ($filters['stock']): ?> checked<?php endif; ?>> <?= $word('CARDS', 'stock') ?></label>
      <label class="choice"><input type="checkbox" name="warnings" value="1"<?php if ($filters['warnings']): ?> checked<?php endif; ?>> <?= $word('CARDS', 'warnings') ?></label>
      <label class="choice"><input type="checkbox" name="blocked" value="1"<?php if ($filters['blocked']): ?> checked<?php endif; ?>> <?= $word('CARDS', 'blocked') ?> <span class="hint"><?= $word('CARDS', 'blocked_hint') ?></span></label>
      <label class="choice"><input type="checkbox" name="discontinued" value="1"<?php if ($filters['discontinued']): ?> checked<?php endif; ?>> <?= $word('CARDS', 'discontinued') ?></label>
      <div class="pop-actions"><?php if ($filtered): ?><a class="btn ghost sm" href="/ui/items/cards"><?= $word('CARDS', 'clear') ?></a><?php endif; ?><button type="submit" class="btn primary sm"><?= $word('UI', 'apply') ?></button></div>
    </div>
  </details>
  <div class="tb-end">
<?php if ($canEdit): ?>
    <a class="btn ghost sm" href="/ui/items/cards/import"><?= $word('CARDS', 'import') ?></a>
<?php endif; ?>
    <a class="btn ghost sm" href="<?= $u('/ui/items/cards.csv', $query) ?>" title="<?= $word('CARDS', 'download') ?>"><span class="ico ico-export" aria-hidden="true"></span><span><?= $word('UI', 'export') ?></span></a>
  </div>
</form>
<h1><?= $word('MENU', 'cards') ?></h1>
<?= $intro('cards', $canEdit ? null : \CW\Ui\Words::whoCan('catalogue.edit')) ?>
<p class="hint"><?= $word('CARDS', 'rules') ?></p>
<ul class="plain summary-line">
  <li><?= $say('CARDS', 'summary_products', $summary['items'], $summary['with_stock']) ?></li>
  <li><?= $say('CARDS', 'summary_cards', $summary['cards'], $summary['confirmed'], $summary['confirmed_with_stock']) ?></li>
  <li><?= $say('CARDS', 'summary_states', $summary['warned'], $summary['blocked'], $summary['discontinued']) ?></li>
</ul>
<?php if ($rows === [] && $filtered): ?>
<?= $empty(\CW\Ui\Words::CARDS['none'], \CW\Ui\Words::CARDS['none_text'], '/ui/items/cards', \CW\Ui\Words::CARDS['clear']) ?>
<?php elseif ($rows === []): ?>
<?= $empty(\CW\Ui\Words::CARDS['none_all']) ?>
<?php else: ?>
<div class="board-head"><h2 class="board-title"><?= $word('MENU', 'cards') ?></h2><p class="board-note"><?php if ($total === 1): ?><?= $word('CARDS', 'total_one') ?><?php else: ?><?= $say('CARDS', 'total_many', $total) ?><?php endif; ?><?php if ($pages > 1): ?> <?= $say('CARDS', 'page', $page, $pages) ?><?php endif; ?></p></div>
<div class="scroll">
<table class="stack item-cards board row-strips">
  <thead>
    <tr>
      <th scope="col"><?= $word('CARDS', 'product') ?></th>
      <th scope="col"><?= $word('CARDS', 'state') ?></th>
      <th scope="col" class="num"><?= $word('CARDS', 'held') ?></th>
      <th scope="col"><?= $word('CARDS', 'kind') ?></th>
      <th scope="col" class="num"><?= $word('CARDS', 'ml') ?></th>
      <th scope="col" class="num"><?= $word('CARDS', 'mg') ?></th>
      <th scope="col"><?= $word('CARDS', 'duty') ?></th>
      <th scope="col"><?= $word('CARDS', 'single_use') ?></th>
      <th scope="col"><?= $word('CARDS', 'flavour') ?></th>
    </tr>
  </thead>
  <tbody>
<?php foreach ($rows as $r): ?>
    <tr class="<?php if ($r['level'] === 'block'): ?>blocked<?php else: ?><?= $e(\CW\Ui\Words::tone('CARD_STATE', $r['state'])) ?><?php endif; ?>">
      <th scope="row" class="c-head"><a href="<?= $u('/ui/items/' . $r['id']) ?>"><?= $e($r['code']) ?></a> <span class="muted"><?= $e($r['name']) ?></span></th>
      <td class="c-status"><?= $stateChip('CARD_STATE', $r['state']) ?>
<?php if ($r['blocked'] !== ''): ?> <?= $chip('blocked', \CW\Ui\Words::say('CARDS', 'blocked_line', $r['blocked'])) ?><?php endif; ?><?php if ($r['warnings'] !== ''): ?> <?= $chip('needs', \CW\Ui\Words::say('CARDS', 'warning_line', $r['warnings'])) ?><?php endif; ?>
<?php if ($r['discontinued']): ?> <?= $chip('off', \CW\Ui\Words::CARDS['discontinued']) ?><?php endif; ?></td>
      <td data-label="<?= $word('CARDS', 'held') ?>" class="num"><?= $n($r['held']) ?></td>
      <td data-label="<?= $word('CARDS', 'kind') ?>"><?= $e($r['type']) ?></td>
      <td data-label="<?= $word('CARDS', 'ml') ?>" class="num"><?= $e($r['ml']) ?></td>
      <td data-label="<?= $word('CARDS', 'mg') ?>" class="num"><?= $e($r['mg']) ?></td>
      <td data-label="<?= $word('CARDS', 'duty') ?>"><?= $e($r['duty']) ?></td>
      <td data-label="<?= $word('CARDS', 'single_use') ?>"><?= $e($r['single_use']) ?></td>
      <td data-label="<?= $word('CARDS', 'flavour') ?>"><?= $e($r['flavour']) ?><?php if ($r['flavour_proposed']): ?> <span class="tag warn"><?= $word('CARDS', 'from_file') ?></span><?php endif; ?></td>
    </tr>
<?php endforeach; ?>
  </tbody>
</table>
</div>
<?php if ($pages > 1): ?>
<nav class="pager" aria-label="<?= $say('CARDS', 'page', $page, $pages) ?>">
<?php if ($page > 1): ?>
  <a href="<?= $u('/ui/items/cards', $query + ['page' => $page - 1]) ?>" rel="prev"><?= $word('CARDS', 'previous') ?></a>
<?php endif; ?>
<?php if ($page < $pages): ?>
  <a href="<?= $u('/ui/items/cards', $query + ['page' => $page + 1]) ?>" rel="next"><?= $word('CARDS', 'next') ?></a>
<?php endif; ?>
</nav>
<?php endif; ?>
<?php endif; ?>
