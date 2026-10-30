<?php
// Products › All Products, a board (the approved design of 29 Oct 2026, GI_List; docs/decisions.md W11.1): one search (name, brand, flavour, CW
// number) and ONE Show control holding the filters (the card, the kind of product, four ticks and its Show button, a details element: no script
// needed; its count says how many are on; DC27); Clear, Import and Export under the toolbar's one More and "+ Add an item" at the head's right
// (DC4, DC27); then the page's products grouped by their card (blocked, not confirmed yet, confirmed, no card yet), most stock first in
// each, each group a coloured heading with its count over a card with its colour on the left edge, the status cell filled with it. A row's
// number and name open the item; a block's rules are the line under the name, a warning and "not sold any more" soft tags; a product without a
// card has no card columns, and the catalogue team's "Fill in the card" in its row. The rules about warnings and blocks are the "?".
?>
<?php
// All Products' main create action is Add Item, in the head (DC27; a board's "+ New" is beside its title): the Add Item page is the form, so
// Sections gives the board no create action of its own (its create buttons lead to their own page's form).
$addItem = $who !== null && $who->can('items.create') ? $partial('split', ['actions' => [['label' => \CW\Ui\Words::NEW['add_item'], 'href' => '/ui/items/new']]]) : '';
?>
<?= $head(\CW\Ui\Words::MENU['cards'], side: $addItem, intro: 'cards', look: $canEdit ? null : \CW\Ui\Words::whoCan('catalogue.edit'), aside: $explain('card_rules', \CW\Ui\Words::CARDS['rules_label'])) ?>
<form class="toolbar cards-tools" method="get" aria-label="<?= $word('UI', 'filter') ?>">
  <div class="tb-search"><span class="ico ico-search" aria-hidden="true"></span><label class="visually-hidden" for="tb-q"><?= $word('CARDS', 'search') ?></label><input id="tb-q" type="search" name="q" value="<?= $e($filters['q']) ?>" maxlength="100" placeholder="<?= $word('CARDS', 'search_placeholder') ?>" enterkeyhint="search"></div>
<?php $on = ($filters['state'] !== 'all' ? 1 : 0) + ($filters['type'] !== '' ? 1 : 0) + ($filters['stock'] ? 1 : 0) + ($filters['warnings'] ? 1 : 0) + ($filters['blocked'] ? 1 : 0) + ($filters['discontinued'] ? 1 : 0); ?>
  <div class="tb-state">
    <details class="tb-pop tb-show">
      <summary class="btn"><?= $word('CARDS', 'filter') ?><?php if ($on > 0): ?> <span class="count"><?= $n($on) ?><span class="visually-hidden"> <?= $word('CARDS', 'filters_on') ?></span></span><?php endif; ?></summary>
      <div class="pop pop-form">
        <label for="tb-show"><?= $word('CARDS', 'card') ?>
          <select id="tb-show" name="state">
<?php foreach ($states as $code => $label): ?>
            <option value="<?= $e($code === 'all' ? '' : $code) ?>"<?php if ($filters['state'] === $code && $code !== 'all'): ?> selected<?php endif; ?>><?= $e($label) ?></option>
<?php endforeach; ?>
          </select>
        </label>
        <label for="tb-kind"><?= $word('CARDS', 'kind') ?>
          <select id="tb-kind" name="type">
            <option value=""><?= $word('CARDS', 'any_kind') ?></option>
            <option value="none"<?php if ($filters['type'] === 'none'): ?> selected<?php endif; ?>><?= $word('CARDS', 'not_set') ?></option>
<?php foreach ($types as $code => $label): ?>
            <option value="<?= $e($code) ?>"<?php if ($filters['type'] === $code): ?> selected<?php endif; ?>><?= $e($label) ?></option>
<?php endforeach; ?>
          </select>
        </label>
        <label class="choice tb-check"><input type="checkbox" name="stock" value="1"<?php if ($filters['stock']): ?> checked<?php endif; ?>> <?= $word('CARDS', 'stock') ?></label>
        <label class="choice tb-check"><input type="checkbox" name="warnings" value="1"<?php if ($filters['warnings']): ?> checked<?php endif; ?>> <?= $word('CARDS', 'warnings') ?></label>
        <label class="choice tb-check"><input type="checkbox" name="blocked" value="1"<?php if ($filters['blocked']): ?> checked<?php endif; ?>> <?= $word('CARDS', 'blocked') ?></label>
        <label class="choice tb-check"><input type="checkbox" name="discontinued" value="1"<?php if ($filters['discontinued']): ?> checked<?php endif; ?>> <?= $word('CARDS', 'discontinued') ?></label>
        <div class="pop-actions"><button type="submit" class="btn sm"><?= $word('DOC_LIST', 'show') ?></button></div>
      </div>
    </details>
  </div>
  <div class="tb-end">
    <details class="more tb-more">
      <summary class="btn ghost sm"><?= $word('UI', 'more') ?></summary>
      <div class="more-menu">
<?php if ($filtered): ?>
        <a class="menu-item" href="/ui/items/cards"><?= $word('CARDS', 'clear_filters') ?></a>
<?php endif; ?>
<?php if ($canEdit): ?>
        <a class="menu-item" href="/ui/items/cards/import"><?= $word('CARDS', 'import') ?></a>
<?php endif; ?>
        <a class="menu-item" href="<?= $u('/ui/items/cards.csv', $query) ?>" download><?= $word('CARDS', 'download') ?></a>
      </div>
    </details>
  </div>
</form>
<?php if ($rows === [] && $filters['q'] !== ''): ?>
<?= $empty(\CW\Ui\Words::say('CARDS', 'none_search', $filters['q']), \CW\Ui\Words::CARDS['none_search_text'], \CW\Ui\Html::url('/ui/items/cards', $searchClear ?? []), \CW\Ui\Words::CARDS['clear_search']) ?>
<?php elseif ($rows === [] && $filtered): ?>
<?= $empty(\CW\Ui\Words::CARDS['none'], \CW\Ui\Words::CARDS['none_text'], '/ui/items/cards', \CW\Ui\Words::CARDS['clear']) ?>
<?php elseif ($rows === []): ?>
<?= $empty(\CW\Ui\Words::CARDS['none_all']) ?>
<?php else: ?>
<?php foreach ($groups as $g): ?>
<?php $card = $g['key'] !== 'none'; ?>
<div class="grp-block doc-group <?= $e($g['tone']) ?>">
  <h3 class="grp-title"><button class="grp-toggle" type="button" aria-expanded="true" aria-controls="g-<?= $e($g['key']) ?>"><?= $e($g['name']) ?></button> <span class="grp-count"><?php if (count($g['rows']) === 1): ?><?= $word('CARDS', 'group_one') ?><?php else: ?><?= $say('CARDS', 'group_many', count($g['rows'])) ?><?php endif; ?></span></h3>
  <div class="table-wrap doc-board" id="g-<?= $e($g['key']) ?>">
  <table class="stack list board doc-list item-cards">
    <thead>
      <tr>
        <th scope="col" class="c-item"><?= $word('CARDS', 'product') ?></th>
        <th scope="col" class="c-status"><?= $word('CARDS', 'state') ?></th>
        <th scope="col" class="num"><?= $word('CARDS', 'held') ?></th>
<?php if ($card): ?>
        <th scope="col"><?= $word('CARDS', 'kind') ?></th>
        <th scope="col" class="num"><?= $word('CARDS', 'ml') ?></th>
        <th scope="col" class="num"><?= $word('CARDS', 'mg') ?></th>
        <th scope="col"><?= $word('CARDS', 'duty') ?></th>
        <th scope="col"><?= $word('CARDS', 'single_use') ?></th>
        <th scope="col"><?= $word('CARDS', 'flavour') ?></th>
<?php elseif ($canEdit): ?>
        <th scope="col" class="c-next"><span class="visually-hidden"><?= $word('CARDS', 'fill_col') ?></span></th>
<?php endif; ?>
      </tr>
    </thead>
    <tbody>
<?php foreach ($g['rows'] as $r): ?>
      <tr>
        <th scope="row" class="c-head c-item"><a class="o-name row-link" href="<?= $u('/ui/items/' . $r['id']) ?>"><span class="ic-code"><?= $e($r['code']) ?></span> <span class="ic-name"><?= $e($r['name']) ?></span></a><?php if ($r['blocked'] !== '' && $g['key'] === 'blocked'): ?><span class="o-sub"><?= $e($r['blocked']) ?></span><?php endif; ?><?php if ($r['warnings'] !== '' || $r['discontinued']): ?><span class="o-sub"><?php if ($r['warnings'] !== ''): ?><span class="tag needs"><?= $say('CARDS', 'warning_line', $r['warnings']) ?></span><?php endif; ?><?php if ($r['discontinued']): ?> <span class="tag off"><?= $word('CARDS', 'discontinued') ?></span><?php endif; ?></span><?php endif; ?></th>
        <td class="c-status doc-st <?= $e($g['tone']) ?>"><?= $e($r['stateWord']) ?></td>
        <td class="num" data-label="<?= $word('CARDS', 'held') ?>"><?= $n($r['held']) ?></td>
<?php if ($card): ?>
        <td data-label="<?= $word('CARDS', 'kind') ?>"><?= $e($r['type']) ?></td>
        <td class="num" data-label="<?= $word('CARDS', 'ml') ?>"><?= $e($r['ml']) ?></td>
        <td class="num" data-label="<?= $word('CARDS', 'mg') ?>"><?= $e($r['mg']) ?></td>
        <td data-label="<?= $word('CARDS', 'duty') ?>"><?= $e($r['duty']) ?></td>
        <td data-label="<?= $word('CARDS', 'single_use') ?>"><?= $e($r['single_use']) ?></td>
        <td data-label="<?= $word('CARDS', 'flavour') ?>"><?= $e($r['flavour']) ?><?php if ($r['flavour_proposed']): ?> <span class="tag needs"><?= $word('CARDS', 'from_file') ?></span><?php endif; ?></td>
<?php elseif ($canEdit): ?>
        <td class="c-next"><a class="btn sm" href="<?= $u('/ui/items/' . $r['id'] . '/card') ?>"><?= $word('CARDS', 'fill_button') ?><span class="visually-hidden"> <?= $e($r['code']) ?></span></a></td>
<?php endif; ?>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<?php endforeach; ?>
<?php if ($pages > 1): ?>
<nav class="pager" aria-label="<?= $say('CARDS', 'page', $page, $pages) ?>">
<?php if ($page > 1): ?>
  <a href="<?= $u('/ui/items/cards', $query + ['page' => $page - 1]) ?>" rel="prev"><?= $word('CARDS', 'previous') ?></a>
<?php endif; ?>
  <span><?= $say('CARDS', 'page', $page, $pages) ?></span>
<?php if ($page < $pages): ?>
  <a href="<?= $u('/ui/items/cards', $query + ['page' => $page + 1]) ?>" rel="next"><?= $word('CARDS', 'next') ?></a>
<?php endif; ?>
</nav>
<?php endif; ?>
<?php endif; ?>
