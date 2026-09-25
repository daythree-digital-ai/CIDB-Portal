<?php $request ??= []; $labels=['processing'=>'Processing','pending'=>'In progress','success'=>'Completed','failed'=>'Needs attention']; $result=request_result($request); $status=$result['status']; $responseDetail=$result['message']; $rawResponse=$request['rpa_response']; if (is_string($rawResponse)) { $decodedResponse=json_decode($rawResponse,true); if (json_last_error()===JSON_ERROR_NONE) $rawResponse=$decodedResponse; } $storedPayload=$request['rpa_request_payload']; if (is_string($storedPayload)) { $storedPayload=json_decode($storedPayload,true); } $payloadFields=is_array($storedPayload['fields']??null)?$storedPayload['fields']:[]; ob_start(); ?>
<section class="page-heading detail-heading">
  <a class="back-link" href="/request-history">← Back to request history</a>
  <div class="panel-kicker">REQUEST DETAILS</div>
  <h1>Request <span><?= e($request['id']) ?></span></h1>
  <p class="muted">A complete view of the information submitted and the response received for this request.</p>
</section>
<section class="details-grid" data-request-id="<?= e($request['id']) ?>" data-request-complete="<?= $result['complete'] ? 'true' : 'false' ?>">
  <article class="details-card details-summary">
    <div class="status-heading"><div><div class="panel-kicker">CURRENT STATUS</div><h2 data-request-status><?= e($labels[$status] ?? ucfirst($status)) ?></h2></div><span class="status-label <?= e($status) ?>" data-request-status><?= e($labels[$status] ?? ucfirst($status)) ?></span></div>
    <div class="detail-facts"><div><small>Request ID</small><strong><?= e($request['id']) ?></strong></div><div><small>Date</small><strong><?= e(date('d M Y', strtotime($request['created_at']))) ?></strong></div><div><small>Time</small><strong><?= e(date('H:i', strtotime($request['created_at']))) ?></strong></div><div><small>RPA status</small><strong><?= e($request['rpa_http_status'] ? 'HTTP '.$request['rpa_http_status'] : 'Not available') ?></strong></div><div><small>RPA reference ID</small><strong><?= e($request['rpa_reference_id'] ?: 'Not available') ?></strong></div><div><small>Completed</small><strong data-request-completed><?= e($result['completed_at']) ?></strong></div></div>
  </article>
  <article class="details-card">
    <div class="panel-kicker">SUBMITTED INFORMATION</div><h2>Your request fields</h2>
    <dl class="detail-fields"><div><dt>Name submitted</dt><dd><?= e($request['applicant_name']) ?></dd></div><div><dt>ID Number submitted</dt><dd><?= e($request['id_number']) ?></dd></div><div><dt>Email submitted</dt><dd><?= e($request['applicant_email']) ?></dd></div><div><dt>Location Area submitted</dt><dd><?= e($payloadFields['sLocationArea'] ?? 'Not available') ?></dd></div><div><dt>CRM submitted</dt><dd><?= e($request['crim'] ?? 'Not available') ?></dd></div><div><dt>Language submitted</dt><dd><?= e(($payloadFields['sLanguage'] ?? 'ms') === 'en' ? 'English' : 'Malay') ?></dd></div></dl>
  </article>
  <article class="details-card response-card">
    <div class="panel-kicker">RPA RESPONSE</div><h2>Response and detail</h2><p class="response-copy" data-request-message aria-live="polite" style="white-space: pre-line"><?= e($responseDetail) ?></p>
    <?php if (!empty($request['error_code'])) { ?><div class="technical-note"><small>Error code</small><strong><?= e($request['error_code']) ?></strong><?php if (!empty($request['error_detail'])) { ?><span><?= e($request['error_detail']) ?></span><?php } ?></div><?php } ?>
    <?php if (!empty($request['rpa_response'])) { ?><details class="response-raw"><summary>View raw RPA response</summary><pre><?= e(json_encode($rawResponse, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre></details><?php } ?>
  </article>
</section>
<?php $content=ob_get_clean(); $title='Request details · CIDB Digital Services'; require __DIR__.'/layout.php'; ?>
