<?php
// Products › Simple matching (docs/decisions.md SM1-SM9; the owner's request of 25 Oct 2026): one row per warehouse product (its CW
// number and name), then one column per store in the owner's order (SimpleMatchController::STORE_ORDER; any other store after them).
// A store's cell lists the website products matched to the row there, each with its name on the store and its ID, and a match that
// waits for a second OK says so. For someone who may decide it has ONE box that is a plain dropdown (an <input list> with its own
// <datalist>, which app.js fills from the options fragment) and Apply: at once in an empty cell, behind "+" (a <details>: no script) in
// a cell that has a product. Without app.js the box takes the store's ID. On a phone each row is a card, each cell named by its store.
?>
<?= $head(\CW\Ui\Words::MENU['simple_match'], intro: 'simple_match', look: $lookOnly) ?>
<?php if ($stores === []): ?>
<?= $empty(\CW\Ui\Words::SIMPLE_MATCH['no_stores'], \CW\Ui\Words::BY_PRODUCT['no_stores_text']) ?>
<?php else: ?>
<form class="toolbar sm-find" method="get" action="<?= $e($path) ?>" role="search" aria-label="<?= $word('SIMPLE_MATCH', 'search') ?>">
  <div class="tb-search"><span class="ico ico-search" aria-hidden="true"></span><label class="visually-hidden" for="sm-q"><?= $word('SIMPLE_MATCH', 'search') ?></label><input id="sm-q" type="search" name="q" value="<?= $e($text) ?>" maxlength="100" placeholder="<?= $word('SIMPLE_MATCH', 'search_hint') ?>" enterkeyhint="search" data-page-search></div>
  <button type="submit" class="btn secondary"><?= $word('UI', 'search') ?></button>
</form>
<?php if ($saidTop !== null): ?>
<p class="error sm-said" role="alert"><?= $e($saidTop['text']) ?><?php if ($saidTop['link'] !== null): ?> <a href="<?= $e($saidTop['link']) ?>"><?= $word('SIMPLE_MATCH', 'open_page') ?></a><?php endif; ?></p>
<?php endif; ?>
<?php if ($rows === []): ?>
<?php if ($text !== ''): ?>
<?= $empty(\CW\Ui\Words::SIMPLE_MATCH['empty_search'], \CW\Ui\Words::FIND['try_items'], $path, \CW\Ui\Words::SIMPLE_MATCH['clear']) ?>
<?php else: ?>
<?= $empty(\CW\Ui\Words::SIMPLE_MATCH['empty'], \CW\Ui\Words::SIMPLE_MATCH['empty_text']) ?>
<?php endif; ?>
<?php else: ?>
<div class="table-wrap">
<table class="stack list simple-match">
  <thead>
    <tr>
      <th scope="col" class="c-item"><?= $word('SIMPLE_MATCH', 'item') ?></th>
<?php foreach ($stores as $s): ?>
      <th scope="col" class="c-store"><?= $e($s['name']) ?></th>
<?php endforeach; ?>
    </tr>
  </thead>
  <tbody>
<?php foreach ($rows as $r): ?>
    <tr id="m-<?= $e($r['id']) ?>"<?php if ($r['here']): ?> class="here"<?php endif; ?>>
      <th scope="row" class="c-head"><span class="sm-code"><?= $e($r['code']) ?></span> <span class="sm-name"><?= $e($r['name'] ?? '') ?></span></th>
<?php foreach ($r['cells'] as $c): ?>
      <td class="c-store" data-label="<?= $e($c['store']) ?>" data-channel="<?= $e($c['channel']) ?>">
<?php if ($c['items'] !== []): ?>
        <ul class="sm-matched">
<?php foreach ($c['items'] as $m): ?>
          <li class="sm-match<?php if ($m['waiting']): ?> waiting<?php endif; ?>" data-listing-id="<?= $e($m['listing_id']) ?>"><a class="sm-title" href="<?= $e($m['link']) ?>"><?= $e($m['name']) ?></a><?php if ($m['id'] !== null): ?> <span class="sm-id"><?= $e($m['id']) ?></span><?php endif; ?><?php if ($m['waiting']): ?> <span class="tag sm-wait"><?= $word('SIMPLE_MATCH', 'waiting') ?></span><?php endif; ?><?php foreach ($m['notes'] as $note): ?> <span class="sm-note"><?= $e($note) ?></span><?php endforeach; ?></li>
<?php endforeach; ?>
        </ul>
<?php endif; ?>
<?php if ($c['said'] !== null): ?>
        <p class="error sm-said" role="alert"><?= $e($c['said']['text']) ?><?php if ($c['said']['link'] !== null): ?> <a href="<?= $e($c['said']['link']) ?>"><?= $word('SIMPLE_MATCH', 'open_page') ?></a><?php endif; ?></p>
<?php endif; ?>
<?php if ($c['add'] !== null): ?>
<?php if ($c['add']['plus']): ?>
        <details class="sm-more"<?php if ($c['add']['open']): ?> open<?php endif; ?>>
          <summary class="btn sm secondary" title="<?= $e($c['add']['more']) ?>"><?= $word('SIMPLE_MATCH', 'add_more') ?><span class="visually-hidden"> <?= $e($c['add']['more']) ?></span></summary>
<?php endif; ?>
        <form class="sm-add" method="post" action="<?= $e($apply) ?>" data-sm-add data-options="<?= $e($c['add']['options']) ?>" data-loading="<?= $word('SIMPLE_MATCH', 'loading') ?>" data-failed="<?= $word('SIMPLE_MATCH', 'failed') ?>" data-choose="<?= $word('SIMPLE_MATCH', 'choose') ?>">
          <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
          <input type="hidden" name="sku_id" value="<?= $e($r['id']) ?>">
          <input type="hidden" name="channel" value="<?= $e($c['channel']) ?>">
<?php foreach ($keep as $key => $value): ?>
          <input type="hidden" name="<?= $e($key) ?>" value="<?= $e($value) ?>">
<?php endforeach; ?>
          <input type="hidden" name="listing_id" value="">
          <input type="hidden" name="expected_map_version" value="">
          <input type="hidden" name="proposal_id" value="">
          <label class="visually-hidden" for="<?= $e($c['add']['id']) ?>"><?= $e($c['add']['label']) ?></label>
          <span class="sm-box"><input id="<?= $e($c['add']['id']) ?>" type="text" name="pick" value="<?= $e($c['add']['typed']) ?>" list="<?= $e($c['add']['id']) ?>-list" maxlength="400" autocomplete="off" placeholder="<?= $e($c['add']['label']) ?>" data-sm-pick> <button type="submit" class="btn sm primary" title="<?= $e($c['add']['title']) ?>"><?= $word('SIMPLE_MATCH', 'apply') ?></button></span>
          <datalist id="<?= $e($c['add']['id']) ?>-list"></datalist>
          <p class="sm-note muted" data-sm-note aria-live="polite" hidden></p>
        </form>
<?php if ($c['add']['plus']): ?>
        </details>
<?php endif; ?>
<?php endif; ?>
      </td>
<?php endforeach; ?>
    </tr>
<?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>
<?php if ($pages > 1): ?>
<nav class="pager" aria-label="<?= $say('QUEUE', 'page', $page_no, $pages) ?>">
<?php if ($prev_link !== null): ?><a href="<?= $e($prev_link) ?>" rel="prev"><?= $word('QUEUE', 'previous') ?></a><?php endif; ?>
  <span><?= $say('QUEUE', 'page', $page_no, $pages) ?></span>
<?php if ($next_link !== null): ?><a href="<?= $e($next_link) ?>" rel="next"><?= $word('QUEUE', 'next') ?></a><?php endif; ?>
</nav>
<?php endif; ?>
<?php endif; ?>
