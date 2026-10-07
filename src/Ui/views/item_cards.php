<h1>Item cards</h1>
<p class="muted">The legal and buying fields of every item, the items holding the most stock first. A card that breaks a rule is a warning until a person
  confirms its fields; after that it blocks the item until a person confirms the card again. A block stops reorder suggestions, purchase order approvals
  and receiving, and CW writes it Out-Of-Stock on every website whose site stock writer is on; on the others take a blocked item off sale by hand.</p>
<ul class="plain summary-line">
  <li><?= $n($summary['items']) ?> items, <?= $n($summary['with_stock']) ?> holding stock</li>
  <li><?= $n($summary['cards']) ?> with a card, <?= $n($summary['confirmed']) ?> confirmed (<?= $n($summary['confirmed_with_stock']) ?> of the items holding stock)</li>
  <li><?= $n($summary['warned']) ?> warned, <?= $n($summary['blocked']) ?> blocked, <?= $n($summary['discontinued']) ?> discontinued</li>
</ul>
<div class="crumbs">
  <form class="filters" method="get" action="/ui/items/cards">
    <label>Code, name, brand or flavour <input type="search" name="q" value="<?= $e($filters['q']) ?>" maxlength="100"></label>
    <label>Card
      <select name="state">
<?php foreach ($states as $code => $label): ?>
        <option value="<?= $e($code === 'all' ? '' : $code) ?>"<?php if ($filters['state'] === $code): ?> selected<?php endif; ?>><?= $e($label) ?></option>
<?php endforeach; ?>
      </select>
    </label>
    <label>Product type
      <select name="type">
        <option value="">Any</option>
        <option value="none"<?php if ($filters['type'] === 'none'): ?> selected<?php endif; ?>>Not set</option>
<?php foreach ($types as $code => $label): ?>
        <option value="<?= $e($code) ?>"<?php if ($filters['type'] === $code): ?> selected<?php endif; ?>><?= $e($label) ?></option>
<?php endforeach; ?>
      </select>
    </label>
    <label class="choice"><input type="checkbox" name="stock" value="1"<?php if ($filters['stock']): ?> checked<?php endif; ?>> Holds stock</label>
    <label class="choice"><input type="checkbox" name="warnings" value="1"<?php if ($filters['warnings']): ?> checked<?php endif; ?>> Warned or blocked</label>
    <label class="choice"><input type="checkbox" name="blocked" value="1"<?php if ($filters['blocked']): ?> checked<?php endif; ?>> Blocked <span class="muted">(take these off sale on the website by hand)</span></label>
    <label class="choice"><input type="checkbox" name="discontinued" value="1"<?php if ($filters['discontinued']): ?> checked<?php endif; ?>> Discontinued</label>
    <button type="submit">Filter</button>
  </form>
  <p class="actions">
    <a href="<?= $u('/ui/items/cards.csv', $query) ?>">Download these (CSV)</a>
<?php if ($canEdit): ?>
    <a class="button" href="/ui/items/cards/import">Import a CSV file</a>
<?php endif; ?>
  </p>
</div>
<?php if ($rows === []): ?>
<p class="note">No item matches.</p>
<?php else: ?>
<p class="muted"><?= $n($total) ?> items match<?php if ($pages > 1): ?>; page <?= $n($page) ?> of <?= $n($pages) ?><?php endif; ?>.</p>
<div class="scroll">
<table class="stack item-cards">
  <thead>
    <tr>
      <th scope="col">Item</th>
      <th scope="col" class="num">Stock held</th>
      <th scope="col">Type</th>
      <th scope="col" class="num">ml</th>
      <th scope="col" class="num">mg/ml</th>
      <th scope="col">Duty</th>
      <th scope="col">Single-use</th>
      <th scope="col">Flavour</th>
      <th scope="col">Card</th>
    </tr>
  </thead>
  <tbody>
<?php foreach ($rows as $r): ?>
    <tr<?php if ($r['level'] === 'block'): ?> class="blocked"<?php endif; ?>>
      <th scope="row"><a href="<?= $u('/ui/items/' . $r['id']) ?>"><?= $e($r['code']) ?></a> <span class="muted"><?= $e($r['name']) ?></span></th>
      <td data-label="Stock held" class="num"><?= $n($r['held']) ?></td>
      <td data-label="Type"><?= $e($r['type']) ?></td>
      <td data-label="ml" class="num"><?= $e($r['ml']) ?></td>
      <td data-label="mg/ml" class="num"><?= $e($r['mg']) ?></td>
      <td data-label="Duty"><?= $e($r['duty']) ?></td>
      <td data-label="Single-use"><?= $e($r['single_use']) ?></td>
      <td data-label="Flavour"><?= $e($r['flavour']) ?><?php if ($r['flavour_proposed']): ?> <span class="tag warn">proposed</span><?php endif; ?></td>
      <td data-label="Card">
<?php if ($r['state'] === 'confirmed'): ?><span class="tag ok">confirmed</span><?php elseif ($r['state'] === 'changed'): ?><span class="tag warn">changed since confirmed</span><?php elseif ($r['state'] === 'unconfirmed'): ?><span class="tag warn">not confirmed</span><?php else: ?><span class="tag">no card</span><?php endif; ?>
<?php if ($r['blocked'] !== ''): ?> <span class="tag bad">blocked: <?= $e($r['blocked']) ?></span><?php endif; ?><?php if ($r['warnings'] !== ''): ?> <span class="tag warn">warning: <?= $e($r['warnings']) ?></span><?php endif; ?>
<?php if ($r['discontinued']): ?> <span class="tag">discontinued</span><?php endif; ?>
      </td>
    </tr>
<?php endforeach; ?>
  </tbody>
</table>
</div>
<?php if ($pages > 1): ?>
<nav class="pager" aria-label="Pages">
<?php if ($page > 1): ?>
  <a href="<?= $u('/ui/items/cards', $query + ['page' => $page - 1]) ?>">Previous</a>
<?php endif; ?>
<?php if ($page < $pages): ?>
  <a href="<?= $u('/ui/items/cards', $query + ['page' => $page + 1]) ?>">Next</a>
<?php endif; ?>
</nav>
<?php endif; ?>
<?php endif; ?>
