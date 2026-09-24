<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/RpaClient.php';
require dirname(__DIR__).'/src/request-result.php';
require dirname(__DIR__).'/src/bootstrap.php';

function check(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
function response(array $parsed, int $http = 200): array {
    return ['parsed'=>$parsed,'raw'=>json_encode($parsed),'http_status'=>$http,'error'=>$http >= 400 ? 'HTTP error' : null];
}
$client = new RpaClient();
$ack = ['status'=>'inserted','schedule_id'=>'test-schedule','runner_note'=>'Assigned to available runner.'];
foreach ([$ack, ['status'=>'inserted'], ['status'=>'inserted','message'=>'Accepted'], ['data'=>$ack], ['status'=>'processing','message'=>'Working'], ['status'=>'accepted'], ['status'=>'queued']] as $payload) {
    $result = $client->normalize(response($payload));
    check($result['status'] === 'pending' && $result['message'] === '', 'Acknowledgements must wait without a display message');
}
check($client->normalize(response($ack))['reference'] === 'test-schedule', 'Keep the schedule reference');
check($client->normalize(response($ack, 500))['status'] === 'failed', 'HTTP failure must not keep polling');
check($client->normalize(response(['status'=>'completed']))['status'] === 'pending', 'Wait for the actual message even if a status says completed');
check($client->normalize(response(['status'=>'success','display_message'=>'Done']))['message'] === 'Done', 'Preserve an immediate final display message');
check($client->normalize(response(['detail'=>'Technical data'], 400))['message'] === '', 'Never use raw technical data as a display message');

$row = ['id'=>'00000000-0000-4000-8000-000000000001','status'=>'pending','rpa_display_message'=>null,'rpa_response_text'=>json_encode($ack)];
foreach ([null, '', " \n ", json_encode($ack)] as $message) {
    $result = request_result(array_replace($row, ['rpa_display_message'=>$message]));
    check(!$result['complete'] && $result['rpa_display_message'] === null, 'Empty messages and legacy acknowledgements must keep polling');
    check(!str_contains($result['message'], 'runner_note'), 'Do not leak the acknowledgement');
}
$final = "Your cancellation is complete.\nThank you <customer>.";
$result = request_result(array_replace($row, ['rpa_display_message'=>$final]));
check($result['complete'] && $result['message'] === $final && $result['status'] === 'success', 'A final message completes polling even before the external status update');
check(!request_result(array_replace($row, ['status'=>'success']))['complete'], 'Status alone does not replace a final message');
$result = request_result(array_replace($row, ['status'=>'failed','rpa_display_message'=>'Cancellation could not be completed.']));
check($result['complete'] && $result['status'] === 'failed' && $result['message'] === 'Cancellation could not be completed.', 'Preserve a final failure message');
check(!request_result(array_replace($row, ['status'=>'failed']))['complete'], 'An external failure status must still wait for its display message');
check(request_result(array_replace($row, ['status'=>'failed','error_code'=>'RPA_REQUEST_FAILED']))['complete'], 'Submission failures without a bot result must stop');
check(request_result(array_replace($row, ['rpa_display_message'=>'0']))['message'] === '0', 'A nonblank zero is a message');

// Render the real views: legacy data must not appear in the main result text,
// and all three pages must attach polling to the exact request ID.
$_SESSION = ['user'=>['id'=>'test-user','username'=>'Test']];
$request = array_replace($row, ['rpa_display_message'=>json_encode($ack),'rpa_response'=>json_encode($ack),'rpa_request_payload'=>'{}','rpa_reference_id'=>null,'rpa_http_status'=>200,'error_code'=>null,'error_detail'=>null,'completed_at'=>null,'created_at'=>'2026-09-23 10:00:00','applicant_name'=>'Test','id_number'=>'Test','applicant_email'=>'test@example.com','crim'=>'Test']);
foreach (['home','request-history','request-details'] as $view) {
    ob_start();
    render($view, ['request'=>$request,'requests'=>[$request]]);
    $html = ob_get_clean();
    check(str_contains($html, 'data-request-id="'.$row['id'].'"'), $view.' must poll its own request');
    check(str_contains($html, 'data-request-complete="false"'), $view.' must wait for legacy pending records');
    check(str_contains($html, 'Please wait for the result.'), $view.' must render a waiting state');
    if ($view !== 'request-details') check(!str_contains($html, 'runner_note'), $view.' must not render raw acknowledgement');
    else check(!str_contains(explode('<details class="response-raw">', $html)[0], 'runner_note'), 'Details must keep technical information in the existing raw disclosure only');
}
echo "PHP response and view regression checks passed.\n";
