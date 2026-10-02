<h1>Number series</h1>
<p class="muted">Every document type has one continuous series (no yearly restart). A number is given when a document is posted, inside the
  same transaction, so the numbers of a series are always 1, 2, 3 ... without a gap; drafts and cancelled requests have none. The review and
  approval limits are placeholders until the owner decides them (decision 11).</p>
<table class="series">
  <thead>
    <tr>
      <th scope="col">Document type</th>
      <th scope="col">Prefix</th>
      <th scope="col">Last number given</th>
      <th scope="col">Next number</th>
      <th scope="col">Second-person review</th>
      <th scope="col">Approval before posting</th>
      <th scope="col" class="num">Review due (days)</th>
      <th scope="col">Screens</th>
    </tr>
  </thead>
  <tbody>
<?php foreach ($series as $s): ?>
    <tr>
      <th scope="row"><?= $e($s['name']) ?> (<?= $e($s['code']) ?>)</th>
      <td><code><?= $e($s['prefix']) ?></code></td>
      <td><?php if ($s['last'] === null): ?><span class="muted">none yet</span><?php else: ?><code><?= $e($s['last']) ?></code><?php endif; ?></td>
      <td><code><?= $e($s['next']) ?></code></td>
      <td><?= $e($s['review']) ?></td>
      <td><?= $e($s['approval']) ?></td>
      <td class="num"><?= $n($s['due_days']) ?></td>
      <td><?php if ($s['live']): ?>live<?php else: ?><span class="soon">coming in Phase <?= $e($s['phase']) ?></span><?php endif; ?></td>
    </tr>
<?php endforeach; ?>
  </tbody>
</table>
