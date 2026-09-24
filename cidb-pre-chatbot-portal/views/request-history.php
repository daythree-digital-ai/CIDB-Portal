<?php $requests ??= []; $labels=['processing'=>'Processing','pending'=>'In progress','success'=>'Completed','failed'=>'Needs attention']; ob_start(); ?>
<section class="page-heading">
  <a class="back-link" href="/">← Back to request form</a>
  <div class="panel-kicker">REQUEST HISTORY</div>
  <h1>All your requests</h1>
  <p class="muted">Review every request submitted through CIDB Digital Services and open one to see its full details.</p>
</section>
<section class="history-page-card">
  <?php if (empty($requests)) { ?>
    <div class="empty-state history-empty"><span class="empty-icon" aria-hidden="true">⌁</span><strong>No requests yet</strong><small>Your submitted requests will appear here.</small></div>
  <?php } else { ?>
    <div class="history-table-head"><span>Request</span><span>Date &amp; time</span><span>Detail</span><span>Status</span></div>
    <div class="history-list">
      <?php foreach ($requests as $request) { $result=request_result($request); ?>
        <a class="history-list-row" href="/request-history/<?= e($request['id']) ?>" data-request-id="<?= e($request['id']) ?>" data-request-complete="<?= $result['complete'] ? 'true' : 'false' ?>">
          <span class="history-request-id"><span class="status-dot <?= e($result['status']) ?>" data-request-status-dot></span><strong><?= e($request['id']) ?></strong></span>
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
