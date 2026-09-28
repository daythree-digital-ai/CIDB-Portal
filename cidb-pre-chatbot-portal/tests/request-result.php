<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/RpaClient.php';
require dirname(__DIR__).'/src/request-result.php';
require dirname(__DIR__).'/src/bootstrap.php';
function check(bool $condition,string $message): void { if (!$condition) throw new RuntimeException($message); }
function response(array $parsed,int $http=200): array {
    return ['parsed'=>$parsed,'raw'=>json_encode($parsed),'http_status'=>$http,'error'=>$http>=400?'HTTP error':null];
}
$client=new RpaClient();
$ack=['status'=>'inserted','schedule_id'=>'test-schedule','runner_note'=>'Assigned runner'];
foreach ([$ack,['status'=>'success','display_message'=>'Old message'],['status'=>'failed']] as $payload) {
    $normalized=$client->normalize(response($payload));
    check(!isset($normalized['status']) && !isset($normalized['message']),'HTTP results must not assign business status or display text');
}
check($client->normalize(response($ack))['reference']==='test-schedule','Preserve submission reference');
check($client->normalize(response($ack,500))['error_code']==='RPA_REQUEST_FAILED','Record transport diagnostics separately');

$row=['id'=>'00000000-0000-4000-8000-000000000001','status'=>'processing','rpa_display_message'=>'LEGACY TEXT MUST NOT DISPLAY'];
foreach (['form','email'] as $source) {
    foreach (['processing','pending','success','failed'] as $status) {
        $result=request_result(array_replace($row,['request_source'=>$source,'status'=>$status,'email_stage'=>'awaiting_result']));
        check($result['status']===$status,'Return actual database status');
        check($result['complete']===in_array($status,['success','failed'],true),'Only final database statuses finish the RPA wait');
        check($result['message']===match($status) {'success'=>'Success','failed'=>'Failed',default=>'In progress'},'Status-only presentation');
        check(!array_key_exists('rpa_display_message',$result),'Deprecated column absent from polling API');
    }
}
check(!request_result(array_replace($row,['error_code'=>'PROCESSING_ERROR']))['complete'],'Local form error cannot finalize RPA status');
check(request_result(array_replace($row,['status'=>'success','error_code'=>'PROCESSING_ERROR']))['message']==='Success','Final RPA status takes precedence over diagnostics');

$_SESSION=['user'=>['id'=>'test-user','username'=>'Test']];
$request=array_replace($row,['rpa_response'=>json_encode($ack),'rpa_request_payload'=>'{}','rpa_reference_id'=>null,'rpa_http_status'=>200,
    'error_code'=>null,'error_detail'=>null,'completed_at'=>null,'created_at'=>'2026-09-23 10:00:00','applicant_name'=>'Test',
    'id_number'=>'Test','applicant_email'=>'test@example.test','crim'=>'Test']);
foreach (['home','request-history','request-details'] as $view) {
    ob_start(); render($view,['request'=>$request,'requests'=>[$request]]); $html=ob_get_clean();
    check(str_contains($html,'data-request-id="'.$row['id'].'"'),'Poll correct request');
    check(str_contains($html,'data-request-complete="false"'),'Wait for RPA status');
    check(str_contains($html,'In progress'),'Pending UI label');
    check(!str_contains($html,'LEGACY TEXT MUST NOT DISPLAY'),'No legacy display message in UI');
}
echo "PHP status-only response and view regression checks passed.\n";
