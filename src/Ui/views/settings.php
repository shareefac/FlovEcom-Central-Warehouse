<h1>Settings</h1>
<p class="muted">How CW is set up. A setting marked <span class="tag warn">provisional</span> is a default the owner has not confirmed yet (the decision
  number refers to the owner's decisions in the inventory plan). Settings are changed on the server by an engineer with
  <code>bin/settings.php --set=&lt;key&gt; --value=&lt;value&gt; --reason="..." --admin</code>; every change is audited.</p>
<table class="settings">
  <thead>
    <tr>
      <th scope="col">Setting</th>
      <th scope="col">Value</th>
      <th scope="col">Status</th>
      <th scope="col">Decision</th>
      <th scope="col">What it does</th>
      <th scope="col">Changed (UTC)</th>
    </tr>
  </thead>
  <tbody>
<?php foreach ($settings as $s): ?>
    <tr>
      <th scope="row"><code><?= $e($s['key']) ?></code></th>
      <td class="pre"><?php if ($s['display'] === '(not set)'): ?><span class="muted">(not set)</span><?php else: ?><?= $e($s['display']) ?><?php endif; ?></td>
      <td><?php if ($s['provisional']): ?><span class="tag warn">provisional</span><?php else: ?>confirmed<?php endif; ?></td>
      <td><?= $e($s['decision']) ?></td>
      <td><?= $e($s['description']) ?></td>
      <td><?= $dt($s['updated_at']) ?> <span class="muted"><?= $e($s['updated_actor']) ?></span></td>
    </tr>
<?php endforeach; ?>
  </tbody>
</table>

<h2>Document rules</h2>
<p class="muted">Post first, a second person reviews; a few things wait for a blocking approval before anything is booked. The limits are placeholders
  until the owner decides them (decision 11).</p>
<table class="rules">
  <thead>
    <tr>
      <th scope="col">Document type</th>
      <th scope="col">Second-person review</th>
      <th scope="col" class="num">Review due (days)</th>
      <th scope="col">Approval before posting</th>
      <th scope="col">Rejected at review</th>
    </tr>
  </thead>
  <tbody>
<?php foreach ($rules as $r): ?>
    <tr>
      <th scope="row"><?= $e($r['name']) ?> (<?= $e($r['code']) ?>)</th>
      <td><?= $e($r['review']) ?></td>
      <td class="num"><?= $n($r['due_days']) ?></td>
      <td><?= $e($r['approval']) ?></td>
      <td><?= $e($r['reject']) ?></td>
    </tr>
<?php endforeach; ?>
  </tbody>
</table>

<h2>VAT codes</h2>
<table class="vat">
  <thead><tr><th scope="col">Code</th><th scope="col">Meaning</th><th scope="col" class="num">Rate %</th><th scope="col">In use</th></tr></thead>
  <tbody>
<?php foreach ($vat as $c): ?>
    <tr>
      <th scope="row"><code><?= $e($c['code']) ?></code></th>
      <td><?= $e($c['label']) ?></td>
      <td class="num"><?= $dec($c['rate_percent']) ?></td>
      <td><?php if ((int) $c['is_active'] === 1): ?>yes<?php else: ?>no<?php endif; ?></td>
    </tr>
<?php endforeach; ?>
  </tbody>
</table>
<p class="muted">Suppliers: a new or re-activated supplier is used only after a second person approves it, and so is an overseas supplier's import
  route (decision 11). No supplier bank details are kept in CW (decision 25). CW does not write its costs into the sites (decision 12).</p>
