<?php
declare(strict_types=1);
// Explicit test DSN only. This file never loads the portal's environment/configuration.
require dirname(__DIR__).'/src/Email/autoload.php';
require dirname(__DIR__).'/src/RequestHistory.php';
require dirname(__DIR__).'/src/request-result.php';
require dirname(__DIR__).'/src/RpaSubmission.php';
require dirname(__DIR__).'/src/RpaClient.php';
use Cidb\Email\{PgStore,Processor,Config,FieldExtractor,MessageSelector,Mailbox,Rpa,Notifier};
function verify(bool $ok,string $message): void { if (!$ok) throw new RuntimeException($message); }
function rejected(PDO $pdo,string $sql): bool { try { $pdo->exec($sql); return false; } catch (PDOException) { return true; } }
class TestMailbox implements Mailbox {
    public array $messages=[]; public array $read=[]; public int $validity=100;
    public function connect(): array { return ['uid_validity'=>$this->validity,'next_uid'=>$this->messages?max(array_keys($this->messages))+1:1]; }
    public function discover(int $after,int $limit): array { $ids=array_filter(array_keys($this->messages),fn($id)=>$id>$after); sort($ids); return array_slice($ids,0,$limit); }
    public function read(int $uid): array { if ($this->messages[$uid] instanceof Throwable) throw $this->messages[$uid]; return $this->messages[$uid]; }
    public function markRead(int $uid): void { $this->read[]=$uid; }
    public function close(): void {}
}
class TestRpa implements Rpa {
    public array $sent=[]; public array $results=[]; public $hook=null;
    public function send(array $payload): array { $this->sent[]=$payload; if ($this->hook) ($this->hook)(); return array_shift($this->results) ?? ['outcome'=>'accepted']; }
}
class TestNotifier implements Notifier {
    public array $sent=[]; public array $results=[];
    public function send(array $job): array { $this->sent[]=$job; return array_shift($this->results) ?? ['outcome'=>'accepted']; }
}
$dsn=getenv('EMAIL_TEST_DSN');
if (!$dsn) { fwrite(STDERR,"Use the isolated node tests/email-database.cjs harness or set an explicit test DSN.\n"); exit(2); }
$pdo=new PDO($dsn,getenv('EMAIL_TEST_USER') ?: 'postgres',getenv('EMAIL_TEST_PASSWORD') ?: '',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$schema='email_test_'.bin2hex(random_bytes(6));
$pdo->exec('CREATE SCHEMA '.$schema);
try {
    $pdo->exec('SET search_path TO '.$schema.', public');
    $base=preg_replace('/^\\\\ir .*$/m','',file_get_contents(dirname(__DIR__).'/database/schema.sql'));
    $pdo->exec($base);
    $u1=$pdo->query("INSERT INTO portal_users(username,password_hash) VALUES ('one','unused') RETURNING id")->fetchColumn();
    $u2=$pdo->query("INSERT INTO portal_users(username,password_hash) VALUES ('two','unused') RETURNING id")->fetchColumn();
    $formSql="INSERT INTO portal_requests(user_id,submission_key,applicant_name,id_number,applicant_email,crim,rpa_request_payload) VALUES ('$u1',gen_random_uuid(),'One','ID1','one@example.test','CRM1','{}') RETURNING id";
    $form=$pdo->query($formSql)->fetchColumn();
    $legacy=null;
    if (($argv[1] ?? '')==='legacy') {
        $pdo->exec('ALTER TABLE portal_requests ALTER COLUMN crim DROP NOT NULL');
        $legacy=$pdo->query(str_replace("'CRM1'",'NULL',$formSql))->fetchColumn();
    }
    $before=new RequestHistory($pdo);
    verify($before->find($u2,$form)===null && $before->find($u1,$form)!==null,'Pre-migration history remains private');
    $migration=file_get_contents(dirname(__DIR__).'/database/migrations/20260925_email_reader.sql');
    $pdo->exec($migration); $pdo->exec($migration); // Safe to apply twice.
    if ($legacy) $pdo->exec("UPDATE portal_requests SET status='success' WHERE id='$legacy'");
    verify(rejected($pdo,str_replace("'CRM1'",'NULL',$formSql)),'New forms still require CRM');
    verify(rejected($pdo,str_replace("'$u1'",'NULL',$formSql)),'New forms still require an owner');
    verify(rejected($pdo,"UPDATE portal_requests SET request_source='email' WHERE id='$form'"),'Origin cannot be changed');

    $config=new Config(['EMAIL_SELECTION_MODE'=>'rules','EMAIL_ALLOWED_SENDERS'=>'agent@example.test','EMAIL_ALLOW_REPLIES'=>'false',
        'EMAIL_USERNAME'=>'support@example.test','EMAIL_TL_ADDRESS'=>'tl@example.test','EMAIL_NOTIFICATION_MAX_ATTEMPTS'=>'2']);
    $mailbox=new TestMailbox(); $rpa=new TestRpa(); $notifier=new TestNotifier(); $store=new PgStore($pdo,'test-mailbox');
    $processor=new Processor($store,$mailbox,new MessageSelector($config),new FieldExtractor(),$rpa,$notifier,$config);
    $body="Name: Sample\nEmail to Cancel ID: customer@example.test\nNRIC: B001\nState: Sarawak";
    $message=['text'=>$body,'sender'=>'agent@example.test','subject'=>'Request','message_id'=>'sample'];
    $mailbox->messages=[10=>$message]; $processor->activate();
    verify((int)$store->state()['activation_uid']===10,'Activation excludes older messages');
    try { $processor->activate(); throw new LogicException('Baseline reset'); } catch (RuntimeException $e) { verify(!($e instanceof LogicException),'No reactivation'); }
    $mailbox->messages += [11=>$message,12=>array_replace($message,['text'=>str_replace('NRIC: B001','NRIC:',$body)]),
        13=>new RuntimeException('Read failure'),14=>array_replace($message,['sender'=>'unrelated@example.test']),
        15=>array_replace($message,['text'=>$body."\nName: Duplicate"])];
    $rpa->results=[['outcome'=>'safe_failure','error_code'=>'CURL_7']];
    $notifier->results=[['outcome'=>'safe_failure','error_code'=>'SMTP_CONNECT_FAILED']];
    $counts=$processor->run();
    verify(count($rpa->sent)===1 && count($notifier->sent)===0,'Complete case sent; incomplete notification disabled');
    verify((int)$pdo->query('SELECT count(*) FROM portal_email_notifications')->fetchColumn()===0,'No notification jobs while disabled');
    verify($counts['ignored']===1 && $counts['errors']===1,'Errors do not block subsequent emails');
    verify((int)$pdo->query('SELECT count(*) FROM portal_email_intake')->fetchColumn()===5,'Older mail excluded');
    verify($pdo->query("SELECT stage FROM portal_email_intake WHERE message_uid=15")->fetchColumn()==='extraction_attention','Ambiguous identities not sent');
    $complete=$pdo->query('SELECT request_id FROM portal_email_intake WHERE message_uid=11')->fetchColumn();
    $missing=$pdo->query('SELECT request_id FROM portal_email_intake WHERE message_uid=12')->fetchColumn();
    $history=new RequestHistory($pdo);
    verify($history->find($u2,$form)===null && $history->find($u1,$form)!==null,'Form ownership unchanged');
    verify($history->find($u1,$missing)!==null && $history->find($u2,$missing)!==null,'Email shared with both users');
    verify($history->find('',$missing)===null,'No unauthenticated access');
    verify(count($history->list($u2,'email'))===4 && count($history->list($u2,'form'))===0,'Filters do not broaden form visibility');
    verify($history->find($u1,$missing)['rpa_request_payload']===null,'No invented RPA payload for missing fields');
    verify(str_contains(request_result($history->find($u2,$missing))['message'],'Missing fields'),'Incomplete history presentation');
    $processor->run();
    verify(count($rpa->sent)===1 && count($notifier->sent)===0,'No immediate retry or duplicate discovery');
    $pdo->exec("UPDATE portal_email_intake SET next_attempt_at=now()-interval '1 second'; UPDATE portal_email_notifications SET next_attempt_at=now()-interval '1 second'");
    $rpa->results=[['outcome'=>'safe_failure','error_code'=>'CURL_7']];
    $processor->run(); $processor->run();
    verify(count($rpa->sent)===2,'Exactly one safe RPA retry');
    verify(count($notifier->sent)===0,'No SMTP retries while disabled');
    verify(request_result($history->find($u1,$missing))['status']==='processing','Missing case does not write a final RPA status');
    verify($history->find($u1,$complete)['status']==='processing','Transport failures do not write a final RPA status');
    verify(request_result($history->find($u1,$complete))['complete'],'Exhausted request stays visible and stops polling');

    $mailbox->messages[16]=$message;
    $rpa->results=[['outcome'=>'uncertain','error_code'=>'TIMEOUT']];
    $processor->run(); $processor->run();
    verify(count($rpa->sent)===3,'No blind timeout retry');

    $mailbox->messages[17]=$message;
    $rpa->hook=function() use ($pdo) { $pdo->exec("UPDATE portal_requests SET status='success' WHERE id=(SELECT request_id FROM portal_email_intake WHERE message_uid=17)"); };
    $processor->run(); $rpa->hook=null;
    $race=$pdo->query('SELECT r.* FROM portal_requests r JOIN portal_email_intake i ON i.request_id=r.id WHERE message_uid=17')->fetch();
    verify($race['status']==='success' && $race['rpa_display_message']===null,'Late ACK preserves status-only final result');
    verify(request_result($race)['complete'],'Status alone finishes polling');

    // Form response persistence must preserve RPA's status too, even after an HTTP error.
    $formResponse=['http_status'=>500,'parsed'=>['status'=>'failed'],'raw'=>'{"status":"failed"}','error'=>'HTTP 500'];
    RpaSubmission::record($pdo,$form,$formResponse,(new RpaClient())->normalize($formResponse));
    verify($history->find($u1,$form)['status']==='processing','Form transport errors do not finalize status');
    $pdo->exec("UPDATE portal_requests SET status='success' WHERE id='$form'");
    RpaSubmission::record($pdo,$form,$formResponse,(new RpaClient())->normalize($formResponse));
    verify($history->find($u1,$form)['status']==='success','Form response cannot overwrite an early RPA result');

    // Simulate a process interruption between dispatch and outcome persistence.
    $store->discover([18]);
    $item=$store->work(10)[0];
    $item=$store->extracted($item,$message,(new FieldExtractor())->extract($message),'tl@example.test');
    $attempt=$store->beginAttempt($item); $store->recover();
    verify($pdo->query("SELECT outcome FROM portal_email_attempts WHERE id='".$attempt['id']."'")->fetchColumn()==='uncertain','Interrupted dispatch recovered as uncertain');
    verify($pdo->query("SELECT stage FROM portal_email_intake WHERE message_uid=18")->fetchColumn()==='submission_uncertain','Interrupted case visible');

    // SMTP interruptions must not be retried as though no send took place.
    $store->discover([19]);
    $item=$store->work(10)[0];
    $partial=array_replace($message,['text'=>str_replace('NRIC: B001','NRIC:',$body)]);
    $store->extracted($item,$partial,(new FieldExtractor())->extract($partial),'tl@example.test');
    // Simulate a notification queued by the earlier implementation. Do not send it.
    $pdo->exec("INSERT INTO portal_email_notifications(intake_id,recipient) VALUES ('".$item['id']."','tl@example.invalid')");
    $processor->run();
    verify(count($notifier->sent)===0,'Previously queued notifications also remain disabled');

    // Malformed body is a technical error, never a false missing-fields notification.
    $mailbox->messages[20]=array_replace($message,['text'=>$body."\0"]);
    $mailbox->messages[21]=$message;
    $rpaCount=count($rpa->sent); $notificationCount=count($notifier->sent);
    $processor->run();
    verify(count($rpa->sent)===$rpaCount+1,'Following email still processed after malformed body');
    verify(count($notifier->sent)===$notificationCount,'Parser error sends no missing-fields notification');
    verify($pdo->query("SELECT last_error FROM portal_email_intake WHERE message_uid=20")->fetchColumn()==='EMAIL_PARSE_ERROR','Parser failure recorded separately');

    // A final status written during dispatch suppresses the scheduled safe retry.
    $mailbox->messages[22]=$message;
    $rpa->results=[['outcome'=>'safe_failure','error_code'=>'CURL_7']];
    $rpa->hook=function() use ($pdo) { $pdo->exec("UPDATE portal_requests SET status='failed' WHERE id=(SELECT request_id FROM portal_email_intake WHERE message_uid=22)"); };
    $processor->run(); $rpa->hook=null;
    $afterFinal=count($rpa->sent);
    $pdo->exec("UPDATE portal_email_intake SET next_attempt_at=now()-interval '1 second' WHERE message_uid=22");
    $processor->run();
    verify(count($rpa->sent)===$afterFinal,'Final RPA status prevents retry dispatch');
    $finalId=$pdo->query('SELECT request_id FROM portal_email_intake WHERE message_uid=22')->fetchColumn();
    verify(request_result($history->find($u2,$finalId))['message']==='Failed','Failure shown without a display message');

    $mailbox->validity=200;
    try { $processor->run(); throw new LogicException('UID validity changed silently'); } catch (RuntimeException $e) { verify(!($e instanceof LogicException),'UID validity mismatch stops reader'); }
    echo 'Database migration, history access, processor, retry, and recovery checks passed ('.($argv[1] ?? 'test').").\n";
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $pdo->exec('SET search_path TO public');
    // Generated identifier only; never delete an application schema.
    if (preg_match('/^email_test_[a-f0-9]{12}$/',$schema)) $pdo->exec('DROP SCHEMA '.$schema.' CASCADE');
}
