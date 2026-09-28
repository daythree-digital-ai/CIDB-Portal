<?php
declare(strict_types=1);

if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/src/bootstrap.php';
require dirname(__DIR__).'/src/Email/autoload.php';

use Cidb\Email\{Config,PgStore,Processor,WebklexMailbox,MessageSelector,FieldExtractor,EmailRpa,SmtpNotifier};

$command=$argv[1] ?? 'check';
if (!in_array($command,['check','activate','run'],true)) { fwrite(STDERR,"Usage: php bin/email-reader.php check|activate|run\n"); exit(2); }
$config=Config::environment();
if ($command==='run' && !$config->yes('EMAIL_ENABLED')) { echo "Email processing disabled; no connections opened.\n"; exit(0); }
$problems=$config->problems();
foreach (['openssl','mbstring','iconv','libxml','dom','zip','fileinfo','curl','pdo_pgsql'] as $extension) {
    if (!extension_loaded($extension)) $problems[]="PHP extension $extension is required";
}
foreach (['autoload.php','webklex/php-imap/src/ClientManager.php','phpmailer/phpmailer/src/PHPMailer.php'] as $dependency) {
    if (!is_file(dirname(__DIR__).'/vendor/'.$dependency)) {
        $problems[]='Email Composer dependencies are not installed';
        break;
    }
}
if (!filter_var(env_value('RPA_BOT_ENDPOINT'),FILTER_VALIDATE_URL) || env_value('RPA_BOT_API_KEY')==='') $problems[]='Existing RPA configuration is required';
if ($command==='check' || $problems) {
    foreach ($problems as $problem) echo '- '.$problem."\n";
    echo $problems ? "Not ready. No connections opened.\n" : "Local configuration checks passed. No connections opened; live contracts still require verification.\n";
    exit($problems?2:0);
}
if (!$config->yes('EMAIL_ENABLED')) { fwrite(STDERR,"EMAIL_ENABLED must explicitly be true before activation.\n"); exit(2); }
require dirname(__DIR__).'/vendor/autoload.php';
try {
    $processor=new Processor(new PgStore(db(),$config->mailboxKey()),new WebklexMailbox($config),new MessageSelector($config),new FieldExtractor(),
        new EmailRpa(env_value('RPA_BOT_ENDPOINT'),env_value('RPA_BOT_API_KEY'),(int)env_value('RPA_BOT_TIMEOUT_MS','15000'),(int)env_value('RPA_BOT_CONNECT_TIMEOUT_MS','5000')),
        new SmtpNotifier($config),$config);
    if ($command==='activate') { $processor->activate(); echo "Mailbox baseline recorded; no messages dispatched.\n"; }
    else echo json_encode($processor->run(),JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $e) {
    // External exceptions may include credentials/message contents; never print them.
    fwrite(STDERR,"Email task failed (".get_class($e)."). Check configuration, migration, and mailbox health records.\n");
    exit(1);
}
