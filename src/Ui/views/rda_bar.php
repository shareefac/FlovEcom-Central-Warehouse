<?php
// The head's right of an Items or Inventory list (the approved redesign of 30 Oct 2026: "title + ONE main button top right (More for the
// rest)"; docs/decisions.md RD-A2): More with what the artboard does not show (the filters of before in a fold, a GET form, open inside the
// menu when a filter the Show choice does not name is on; Clear, Import,
// the download, the other pages of before), then the ONE main button (the page's "+ New …"; a #new link opens the page's create fold).
// $links: list of ['label' => string, 'href' => string, 'download' => bool (optional)]; $filters: null or ['action' => path, 'keep' =>
// [name => value] (hidden: the search; the form sends to the page itself), 'open' => bool, 'selects' => list of ['name', 'label', 'options' => [value => label], 'value'],
// 'ticks' => list of ['name', 'label', 'checked']]; $main: null or ['label' => string, 'href' => string].
$links = $links ?? [];
$filters = $filters ?? null;
$main = $main ?? null;
?>
<?php if ($links !== [] || $filters !== null): ?>
<details class="more page-more">
  <summary class="btn"><?= $word('INV_LIST', 'more') ?></summary>
  <div class="more-menu">
<?php if ($filters !== null): ?>
    <details class="doc-act"<?php if ($filters['open']): ?> open<?php endif; ?>>
      <summary class="btn"><?= $word('INV_LIST', 'more_filters') ?></summary>
      <div class="doc-act-panel">
        <form class="record list-filters" method="get">
<?php foreach ($filters['keep'] as $k => $v): ?>
          <input type="hidden" name="<?= $e($k) ?>" value="<?= $e($v) ?>">
<?php endforeach; ?>
<?php foreach ($filters['selects'] as $s): ?>
          <label for="mf-<?= $e($s['name']) ?>"><?= $e($s['label']) ?>
            <select id="mf-<?= $e($s['name']) ?>" name="<?= $e($s['name']) ?>">
<?php foreach ($s['options'] as $value => $text): ?>
              <option value="<?= $e($value) ?>"<?php if ((string) $value === (string) $s['value']): ?> selected<?php endif; ?>><?= $e($text) ?></option>
<?php endforeach; ?>
            </select>
          </label>
<?php endforeach; ?>
<?php foreach ($filters['ticks'] ?? [] as $t): ?>
          <label class="choice tb-check"><input type="checkbox" name="<?= $e($t['name']) ?>" value="1"<?php if ($t['checked']): ?> checked<?php endif; ?>> <?= $e($t['label']) ?></label>
<?php endforeach; ?>
          <p class="actions"><button type="submit" class="btn sm"><?= $word('INV_LIST', 'filter_button') ?></button></p>
        </form>
      </div>
    </details>
<?php endif; ?>
<?php foreach ($links as $l): ?>
    <a class="menu-item" href="<?= $e($l['href']) ?>"<?php if ($l['download'] ?? false): ?> download<?php endif; ?>><?= $e($l['label']) ?></a>
<?php endforeach; ?>
  </div>
</details>
<?php endif; ?>
<?php if ($main !== null): ?>
<a class="btn primary" href="<?= $e($main['href']) ?>"><span class="ico ico-plus" aria-hidden="true"></span><span><?= $e($main['label']) ?></span></a>
<?php endif; ?>
