<?= $head(\CW\Ui\Words::MENU['cards'], intro: 'cards', look: $canEdit ? null : \CW\Ui\Words::whoCan('catalogue.edit'), aside: $explain('card_rules', \CW\Ui\Words::CARDS['rules_label'])) ?>
<form class="toolbar cards-tools" method="get" aria-label="<?= $word('UI', 'filter') ?>">
  <div class="tb-search<?php if ($searchClear !== null): ?> has-clear<?php endif; ?>"><span class="ico ico-search" aria-hidden="true"></span><label class="visually-hidden" for="tb-q"><?= $word('CARDS', 'search') ?></label><input id="tb-q" type="search" name="q" value="<?= $e($filters['q']) ?>" maxlength="100" placeholder="<?= $word('CARDS', 'search_placeholder') ?>" enterkeyhint="search"><?php if ($searchClear !== null): ?><a class="tb-clear" href="<?= $u('/ui/items/cards', $searchClear) ?>"><span class="ico ico-close" aria-hidden="true"></span><span class="visually-hidden"><?= $word('CARDS', 'clear_search') ?></span></a><?php endif; ?></div>
  <details class="tb-pop">
    <summary class="btn ghost sm"><span class="ico ico-filter" aria-hidden="true"></span><span><?= $word('UI', 'filter') ?></span><?php if ($chips !== []): ?> <span class="count" aria-hidden="true"><?= $n(count($chips)) ?></span><?php endif; ?></summary>
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
      <label class="choice"><input type="checkbox" name="stock" value="1"<?php if ($filters['stock']): ?> checked<?php endif; ?>> <?= $word('CARDS', 'only_stock') ?></label>
      <label class="choice"><input type="checkbox" name="warnings" value="1"<?php if ($filters['warnings']): ?> checked<?php endif; ?>> <?= $word('CARDS', 'only_warnings') ?></label>
      <label class="choice"><input type="checkbox" name="blocked" value="1"<?php if ($filters['blocked']): ?> checked<?php endif; ?>> <?= $word('CARDS', 'only_blocked') ?> <span class="hint"><?= $word('CARDS', 'blocked_hint') ?></span></label>
      <label class="choice"><input type="checkbox" name="discontinued" value="1"<?php if ($filters['discontinued']): ?> checked<?php endif; ?>> <?= $word('CARDS', 'only_discontinued') ?></label>
      <div class="pop-actions"><?php if ($filtered): ?><a class="btn ghost sm" href="/ui/items/cards"><?= $word('CARDS', 'clear') ?></a><?php endif; ?><button type="submit" class="btn primary sm"><?= $word('CARDS', 'apply') ?></button></div>
    </div>
  </details>
  <div class="tb-end">
<?php if ($canEdit): ?>
    <a class="btn ghost sm" href="/ui/items/cards/import"><?= $word('CARDS', 'import') ?></a>
<?php endif; ?>
    <a class="btn ghost sm" href="<?= $u('/ui/items/cards.csv', $query) ?>" title="<?= $word('CARDS', 'download') ?>"><span class="ico ico-export" aria-hidden="true"></span><span><?= $word('UI', 'export') ?></span></a>
  </div>
</form>
<?php if ($chips !== []): ?>
<div class="filters-on">
  <p class="pills-label" id="cards-on"><?= $word('CARDS', 'showing') ?></p>
  <ul class="pills" aria-labelledby="cards-on">
<?php foreach ($chips as $c): ?>
    <li><a href="<?= $u('/ui/items/cards', $c['query']) ?>"><span><?= $e($c['label']) ?></span><span class="ico ico-close" aria-hidden="true"></span><span class="visually-hidden"><?= $word('CARDS', 'remove_filter') ?></span></a></li>
<?php endforeach; ?>
  </ul>
  <a class="btn ghost sm" href="/ui/items/cards"><?= $word('CARDS', 'clear') ?></a>
</div>
<?php endif; ?>
<ul class="plain summary-line">
  <li><?= $say('CARDS', 'summary_products', $summary['items'], $summary['with_stock']) ?></li>
  <li><?= $say('CARDS', 'summary_cards', $summary['cards'], $summary['confirmed'], $summary['confirmed_with_stock']) ?></li>
<?php if ($summary['warned'] + $summary['blocked'] + $summary['discontinued'] > 0): ?>
  <li><?= $say('CARDS', 'summary_states', $summary['warned'], $summary['blocked'], $summary['discontinued']) ?></li>
<?php endif; ?>
</ul>
<?php if ($rows === [] && $filters['q'] !== ''): ?>
<?= $empty(\CW\Ui\Words::say('CARDS', 'none_search', $filters['q']), \CW\Ui\Words::CARDS['none_search_text'], \CW\Ui\Html::url('/ui/items/cards', $searchClear ?? []), \CW\Ui\Words::CARDS['clear_search']) ?>
<?php elseif ($rows === [] && $filtered): ?>
<?= $empty(\CW\Ui\Words::CARDS['none'], \CW\Ui\Words::CARDS['none_text'], '/ui/items/cards', \CW\Ui\Words::CARDS['clear']) ?>
<?php elseif ($rows === []): ?>
<?= $empty(\CW\Ui\Words::CARDS['none_all']) ?>
<?php else: ?>
<div class="board-head"><h2 class="board-title"><?php if ($filtered): ?><?= $word('CARDS', 'list_found') ?><?php else: ?><?= $word('CARDS', 'list') ?><?php endif; ?></h2><p class="board-note"><?php if ($total === 1): ?><?= $word('CARDS', 'total_one') ?><?php else: ?><?= $say('CARDS', 'total_many', $total) ?><?php endif; ?> <?= $word('CARDS', 'order') ?><?php if ($pages > 1): ?> <?= $say('CARDS', 'page', $page, $pages) ?><?php endif; ?></p></div>
<div class="table-wrap">
<table class="stack item-cards board row-strips">
  <thead>
    <tr>
      <th scope="col" class="c-product"><?= $word('CARDS', 'product') ?></th>
      <th scope="col" class="num"><?= $word('CARDS', 'held') ?></th>
      <th scope="col"><?= $word('CARDS', 'state') ?></th>
<?php if ($details): ?>
      <th scope="col"><?= $word('CARDS', 'kind') ?></th>
      <th scope="col" class="num"><?= $word('CARDS', 'ml') ?></th>
      <th scope="col" class="num"><?= $word('CARDS', 'mg') ?></th>
      <th scope="col"><?= $word('CARDS', 'duty') ?></th>
      <th scope="col"><?= $word('CARDS', 'single_use') ?></th>
      <th scope="col"><?= $word('CARDS', 'flavour') ?></th>
<?php endif; ?>
    </tr>
  </thead>
  <tbody>
<?php foreach ($rows as $r): ?>
    <tr class="<?php if ($r['level'] === 'block'): ?>blocked<?php else: ?><?= $e(\CW\Ui\Words::tone('CARD_STATE', $r['state'])) ?><?php endif; ?>">
      <th scope="row" class="c-head"><a href="<?= $u('/ui/items/' . $r['id']) ?>"><span class="ic-code"><?= $e($r['code']) ?></span> <span class="ic-name"><?= $e($r['name']) ?></span></a></th>
      <td data-label="<?= $word('CARDS', 'held') ?>" class="num"><?= $n($r['held']) ?></td>
<?php if ($r['state'] === 'none'): ?>
      <td class="c-status c-nocard"<?php if ($details): ?> colspan="7"<?php endif; ?>><span class="tag"><?= $word('CARD_STATE', 'none') ?></span><?php if ($canEdit): ?> <a class="c-fill" href="<?= $u('/ui/items/' . $r['id'] . '/card') ?>"><?= $word('CARD', 'fill_in') ?><span class="visually-hidden"> <?= $e($r['code']) ?></span></a><?php endif; ?></td>
<?php else: ?>
      <td class="c-status"><?= $stateChip('CARD_STATE', $r['state']) ?>
<?php if ($r['blocked'] !== ''): ?> <?= $chip('blocked', \CW\Ui\Words::say('CARDS', 'blocked_line', $r['blocked'])) ?><?php endif; ?><?php if ($r['warnings'] !== ''): ?> <?= $chip('needs', \CW\Ui\Words::say('CARDS', 'warning_line', $r['warnings'])) ?><?php endif; ?>
<?php if ($r['discontinued']): ?> <?= $chip('off', \CW\Ui\Words::CARDS['discontinued']) ?><?php endif; ?></td>
      <td data-label="<?= $word('CARDS', 'kind') ?>"><?= $e($r['type']) ?></td>
      <td data-label="<?= $word('CARDS', 'ml') ?>" class="num"><?= $e($r['ml']) ?></td>
      <td data-label="<?= $word('CARDS', 'mg') ?>" class="num"><?= $e($r['mg']) ?></td>
      <td data-label="<?= $word('CARDS', 'duty') ?>"><?= $e($r['duty']) ?></td>
      <td data-label="<?= $word('CARDS', 'single_use') ?>"><?= $e($r['single_use']) ?></td>
      <td data-label="<?= $word('CARDS', 'flavour') ?>"><?= $e($r['flavour']) ?><?php if ($r['flavour_proposed']): ?> <span class="tag warn"><?= $word('CARDS', 'from_file') ?></span><?php endif; ?></td>
<?php endif; ?>
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
