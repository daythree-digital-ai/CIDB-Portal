<?php $requests ??= []; $source ??= 'all'; $labels=['processing'=>'In progress','pending'=>'In progress','success'=>'Success','failed'=>'Failed']; ob_start(); ?>
<section class="page-heading">
  <a class="back-link" href="/">← Back to request form</a>
  <div class="panel-kicker">REQUEST HISTORY</div>
  <h1>All your requests</h1>
  <p class="muted">Review your form requests and shared email requests, and open one to see its full details.</p>
  <nav class="history-filters" aria-label="Request type">
    <?php foreach (['all'=>'All','form'=>'Form','email'=>'Email'] as $value=>$label): ?>
      <a href="/request-history?source=<?= e($value) ?>" class="history-filter <?= $source===$value?'active':'' ?>" <?= $source===$value?'aria-current="page"':'' ?>><?= e($label) ?></a>
    <?php endforeach; ?>
  </nav>
</section>
<section class="history-page-card">
  <?php if (empty($requests)) { ?>
    <div class="empty-state history-empty"><span class="empty-icon" aria-hidden="true">⌁</span><strong>No requests yet</strong><small>Your submitted requests will appear here.</small></div>
  <?php } else { ?>
    <div class="history-table-head"><span>Request</span><span>Date &amp; time</span><span>Detail</span><span>Status</span></div>
    <div class="history-list">
      <?php foreach ($requests as $request) { $result=request_result($request); ?>
        <a class="history-list-row" href="/request-history/<?= e($request['id']) ?>" data-request-id="<?= e($request['id']) ?>" data-request-complete="<?= $result['complete'] ? 'true' : 'false' ?>">
          <span class="history-request-id"><span class="status-dot <?= e($result['status']) ?>" data-request-status-dot></span><span><span class="request-source"><?= e(strtoupper($request['request_source'] ?? 'form')) ?></span><strong><?= e($request['id']) ?></strong></span></span>
          <span class="history-date"><strong><?= e(date('d M Y', strtotime($request['created_at']))) ?></strong><small><?= e(date('H:i', strtotime($request['created_at']))) ?></small></span>
          <span class="history-detail" data-request-message aria-live="polite"><?= e($result['message']) ?></span>
          <span class="status-label <?= e($result['status']) ?>" data-request-status><?= e($labels[$result['status']]) ?></span>
          <span class="row-arrow" aria-hidden="true">→</span>
        </a>
      <?php } ?>
    </div>
  <?php } ?>
</section>
<?php $content=ob_get_clean(); $title='Request history · CIDB Digital Services'; require __DIR__.'/layout.php'; ?>
