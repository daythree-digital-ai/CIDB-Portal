<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/Email/autoload.php';
require dirname(__DIR__).'/src/bootstrap.php';
require dirname(__DIR__).'/src/request-result.php';
require dirname(__DIR__).'/src/RpaClient.php';
require dirname(__DIR__).'/vendor/autoload.php';

use Cidb\Email\{FieldExtractor,EmailRpa,Config,MessageSelector,BodyMessage};
function expect(bool $condition,string $why): void { if (!$condition) throw new RuntimeException($why); }

$extractor=new FieldExtractor();
$body="Hi support@example.test,\nTitle: Sample request\nState: Sarawak\nRemarks: For testing\nName: Sample Person\nNRIC: B0012345\nEmail to Cancel ID: customer@example.test";
$result=$extractor->extract(['text'=>$body]);
expect(!$result['attention'] && !$result['missing'],'Complete template');
expect(EmailRpa::payload($result['fields'])===['sEmail'=>'customer@example.test','sCustomerName'=>'Sample Person','sIdentificationNumber'=>'B0012345','sLocationArea'=>'Sarawak','sChannel'=>'Email'],'Exact flat payload');
expect(!array_key_exists('sCRMID',EmailRpa::payload($result['fields'])),'No CRM');
foreach (FieldExtractor::LABELS as $field=>$label) {
    $partial=preg_replace('/^'.preg_quote($label,'/').':.*$/m',$label.':',$body);
    $missing=$extractor->extract(['text'=>$partial]);
    expect($missing['missing']===[$label] && $missing['fields'][$field]===null,"Blank $label must not capture next line");
}
$partial=$extractor->extract(['text'=>"Name:\nNRIC: 001234\nState: Sarawak\nEmail to Cancel ID: a@example.test"]);
expect($partial['fields']['name']===null && $partial['fields']['nric']==='001234','Blank line and leading zero');
expect($extractor->extract(['text'=>$body."\nName: Another Person"])['attention'],'Duplicate field requires review');
expect($extractor->extract(['text'=>'> '.$body])['attention'],'Quoted input requires review');
expect($extractor->extract(['text'=>$body."\nFrom: previous@example.test"])['attention'],'Forwarded content requires review');
$html='<html><body><p>Hi support@example.test</p><table><tr><td>Name:</td><td>Sample Person</td></tr><tr><td>NRIC:</td><td>B0012345</td></tr><tr><td>State:</td><td>Sarawak</td></tr><tr><td>Email to Cancel ID:</td><td><a href="mailto:customer@example.test">customer@example.test</a></td></tr></table></body></html>';
expect($extractor->extract(['html'=>$html])['fields']===$result['fields'],'HTML table and mailto');
expect($extractor->extract(['html'=>'<blockquote>'.$html.'</blockquote>'])['attention'],'HTML quote detection');

$raw="From: Agent <agent@example.test>\r\nSubject: =?UTF-8?B?VGVzdCByZXF1ZXN0?=\r\nMIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=x\r\n\r\n".
    "--x\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n".base64_encode($body)."\r\n".
    "--x\r\nContent-Type: text/plain\r\nContent-Disposition: attachment; filename=untrusted.txt\r\n\r\nName: Wrong Name\r\n--x--\r\n";
$mime=new BodyMessage($raw); $message=$mime->data();
expect($message['sender']==='agent@example.test' && $message['subject']==='Test request','Decode sender and header');
expect($extractor->extract($message)['fields']===$result['fields'],'Attachment cannot replace body');
expect($mime->getAttachments()->count()===0,'No attachment objects');
// Exercise the installed IMAP fetch parser with server tokens (no sockets).
// BODY.PEEK[] requests are returned by IMAP servers under the BODY[] key.
class FixtureImapProtocol extends \Webklex\PHPIMAP\Connection\Protocols\ImapProtocol {
    public array $commands=[];
    private array $reply=[];
    public function __construct(private readonly string $raw) { parent::__construct(\Webklex\PHPIMAP\Config::make()); }
    public function connected(): bool { return true; }
    public function sendRequest(string $command,array $tokens=[],?string &$tag=null): \Webklex\PHPIMAP\Connection\Protocols\Response {
        $tag='fixture'; $this->commands[]=[$command,$tokens];
        $size=str_contains(implode(' ',$tokens),'RFC822.SIZE');
        $this->reply=[1,'FETCH',['UID',42,$size?'RFC822.SIZE':'BODY[]',$size?strlen($this->raw):$this->raw]];
        return \Webklex\PHPIMAP\Connection\Protocols\Response::empty();
    }
    public function readLine(\Webklex\PHPIMAP\Connection\Protocols\Response $response,array|string &$tokens=[],string $wantedTag='*',bool $dontParse=false): bool {
        if (!$this->reply) return true;
        $tokens=$this->reply; $this->reply=[]; return false;
    }
    public function __destruct() {} // There is no connection to log out of.
}
$protocol=new FixtureImapProtocol($raw);
$client=(new \Webklex\PHPIMAP\ClientManager())->make([]);
$client->connection=$protocol;
$reader=new \Cidb\Email\WebklexMailbox(new Config([]));
(new ReflectionProperty($reader,'client'))->setValue($reader,$client);
expect($reader->read(42)['text']===$message['text'],'Actual IMAP fetch parser handles BODY[] response to PEEK');
expect(str_contains(implode(' ',$protocol->commands[1][1]),'BODY.PEEK[]'),'Read does not mark seen');
$client->connection=null;
$latin=new BodyMessage("From: a@example.test\r\nContent-Type: text/plain; charset=ISO-8859-1\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\nName: Andr=E9\r\n");
expect(str_contains($latin->data()['text'],'André'),'Declared charset decoding');

$config=new Config(['EMAIL_SELECTION_MODE'=>'rules','EMAIL_ALLOWED_SENDERS'=>'agent@example.test','EMAIL_SUBJECT_PATTERN'=>'/request/i','EMAIL_ALLOW_REPLIES'=>'false','EMAIL_USERNAME'=>'support@example.test']);
$selector=new MessageSelector($config);
expect($selector->accepts($message),'Approved sender and subject');
expect(!$selector->accepts(array_replace($message,['sender'=>'other@example.test'])),'Unapproved sender');
expect(!$selector->accepts(array_replace($message,['auto_submitted'=>'auto-generated'])),'Ignore automated notification');
expect(!$selector->accepts(array_replace($message,['in_reply_to'=>'thread'])),'Reply policy');
expect((new Config([]))->get('EMAIL_FOLDER')==='CIDB','Confirmed dedicated folder default');
$folderSelector=new MessageSelector(new Config([]));
foreach (['agent@example.test','other@example.test','support@example.test',''] as $sender) {
    expect($folderSelector->accepts(array_replace($message,['sender'=>$sender,'auto_submitted'=>'auto-generated','in_reply_to'=>'thread'])),'Folder-only selection ignores sender and reply metadata');
}
expect(count((new Config([]))->problems())>0,'Incomplete configuration cannot activate');
expect(!array_filter((new Config([]))->problems(),fn($p)=>str_contains($p,'NOTIFICATION') || str_contains($p,'TL_ADDRESS') || str_contains($p,'SMTP') || str_contains($p,'FROM_ADDRESS')),'Disabled notifications require no settings');
$launch=new Config(['EMAIL_IMAP_HOST'=>'mail.example.test','EMAIL_USERNAME'=>'support@example.test','EMAIL_PASSWORD'=>'fixture-only',
    'EMAIL_EXTRACTION_APPROVED'=>'true','EMAIL_RPA_CONTRACT_APPROVED'=>'true','EMAIL_RETENTION_APPROVED'=>'true']);
expect($launch->problems()===[],'Folder-only launch is ready without SMTP, TL templates, retry rules, or sender filters');

expect(EmailRpa::interpret(0,'',CURLE_COULDNT_CONNECT)['outcome']==='safe_failure','Pre-dispatch failure safe');
expect(EmailRpa::interpret(0,'',CURLE_OPERATION_TIMEDOUT)['outcome']==='uncertain','Timeout is uncertain');
expect(EmailRpa::interpret(500,'error')['outcome']==='uncertain','HTTP 500 is not proof of non-acceptance');
expect(EmailRpa::interpret(200,'invalid')['outcome']==='uncertain','Invalid response not success');
expect(EmailRpa::interpret(200,'{"status":"inserted","message":"Accepted"}')['outcome']==='accepted','Acknowledgement waits');
expect(EmailRpa::interpret(200,'{"status":"failed","display_message":"Not successful"}')['outcome']==='accepted','HTTP status cannot replace database outcome');

$row=['id'=>'test','request_source'=>'email','status'=>'processing','email_stage'=>'missing_fields','email_missing_fields'=>'["NRIC"]','rpa_display_message'=>null,'notification_state'=>'pending'];
expect(str_contains(request_result($row)['message'],'Missing fields: NRIC'),'Missing-fields presentation');
expect(request_result($row)['complete'],'No notification waiting while disabled');
$row['notification_state']='accepted';
expect(request_result($row)['complete'] && request_result($row)['status']==='processing','Local missing-field case does not invent RPA status');
expect(request_result(array_replace($row,['email_stage'=>'awaiting_result','status'=>'pending','rpa_display_message'=>'Result']))['status']==='pending','Message alone does not invent an outcome');
expect(request_result(array_replace($row,['email_stage'=>'awaiting_result','status'=>'success','rpa_display_message'=>'Result']))['status']==='success','Confirmed final result');
expect(request_result(array_replace($row,['email_stage'=>'awaiting_result','status'=>'success','rpa_display_message'=>'Result']))['completed_at']==='Not provided by RPA','No invented completion timestamp');

$_SESSION=['user'=>['id'=>'test','username'=>'Test']];
$request=array_replace($row,['id'=>'00000000-0000-4000-8000-000000000001','created_at'=>'2026-09-25 10:00:00','completed_at'=>null,
    'rpa_response'=>null,'rpa_request_payload'=>null,'rpa_http_status'=>null,'rpa_reference_id'=>null,'error_code'=>null,'error_detail'=>null,
    'applicant_name'=>'<script>bad()</script>','applicant_email'=>'a@example.test','id_number'=>null,'email_location_area'=>'Sarawak']);
ob_start(); render('request-details',['request'=>$request]); $output=ob_get_clean();
expect(!str_contains($output,'<script>bad()') && str_contains($output,'&lt;script&gt;bad()'),'Escaped customer content');
expect(!str_contains($output,'CRM submitted') && !str_contains($output,'Language submitted'),'No form-only email fields');
expect(str_contains($output,'RPA not submitted') && str_contains($output,'TL notification'),'Missing branch details');
ob_start(); render('request-history',['requests'=>[$request],'source'=>'email']); $output=ob_get_clean();
expect(str_contains($output,'?source=form') && str_contains($output,'?source=email') && str_contains($output,'EMAIL'),'History filters and source badge');
echo "Email extraction, MIME, selection, response, and view checks passed.\n";
