<h1>Reason codes</h1>
<p class="muted">Why stock was adjusted, written off, counted differently, returned or reversed. The list is provisional until the ERPNext
  reconciliation history has been examined (Phase I-0 data copy). Codes marked "set by CW" are never offered on a form. A free gift is reported
  apart; vaping and nicotine products may not be given away free to the public from 29 Oct 2026.</p>
<p><a href="/ui/reference/reasons.csv">Download as CSV (opens in Excel)</a></p>
<table class="reasons">
  <thead>
    <tr>
      <th scope="col">Code</th>
      <th scope="col">Label</th>
      <th scope="col">Used for</th>
      <th scope="col">Direction</th>
      <th scope="col">Note needed</th>
      <th scope="col">Free gift</th>
      <th scope="col">Set by CW</th>
      <th scope="col">In use</th>
    </tr>
  </thead>
  <tbody>
<?php foreach ($reasons as $r): ?>
    <tr<?php if ($r['is_active'] !== 'yes'): ?> class="inactive"<?php endif; ?>>
      <th scope="row"><code><?= $e($r['code']) ?></code></th>
      <td><?= $e($r['label']) ?></td>
      <td><?= $e(str_replace('_', ' ', $r['applies_to'])) ?></td>
      <td><?= $e($r['direction']) ?></td>
      <td><?= $e($r['needs_note']) ?></td>
      <td><?= $e($r['is_gift']) ?></td>
      <td><?= $e($r['system_only']) ?></td>
      <td><?= $e($r['is_active']) ?></td>
    </tr>
<?php endforeach; ?>
  </tbody>
</table>
