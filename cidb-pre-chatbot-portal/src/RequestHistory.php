<?php
declare(strict_types=1);

/** One access rule for history, details, recent activity, and status polling. */
final class RequestHistory {
    private ?bool $emailSchema=null;
    public function __construct(private readonly PDO $pdo) {}
    private function hasEmailSchema(): bool {
        return $this->emailSchema ??= (bool)$this->pdo->query("SELECT EXISTS (SELECT 1 FROM pg_attribute WHERE attrelid=to_regclass('portal_requests') AND attname='request_source' AND NOT attisdropped)")->fetchColumn();
    }
    private function select(): string {
        if (!$this->hasEmailSchema()) return "SELECT r.*, 'form' AS request_source FROM portal_requests r";
        return 'SELECT r.*,i.stage AS email_stage,i.location_area AS email_location_area,i.missing_fields AS email_missing_fields,
            i.sender AS email_sender,i.subject AS email_subject,n.state AS notification_state,n.recipient AS notification_recipient,n.last_error AS notification_error
            FROM portal_requests r LEFT JOIN portal_email_intake i ON i.request_id=r.id
            LEFT JOIN portal_email_notifications n ON n.intake_id=i.id';
    }
    private function visibility(): string {
        return $this->hasEmailSchema()?"(r.request_source='email' OR (r.request_source='form' AND r.user_id=:uid))":'r.user_id=:uid';
    }
    public function list(string $userId, string $source='all', ?int $limit=null): array {
        if ($userId==='') return [];
        if (!in_array($source,['all','form','email'],true)) $source='all';
        if (!$this->hasEmailSchema() && $source==='email') return [];
        $sql=$this->select().' WHERE '.$this->visibility(); $params=['uid'=>$userId];
        if ($source!=='all' && $this->hasEmailSchema()) { $sql.=' AND r.request_source=:source'; $params['source']=$source; }
        $sql.=' ORDER BY r.created_at DESC,r.id';
        if ($limit!==null) $sql.=' LIMIT '.max(1,$limit);
        $q=$this->pdo->prepare($sql); $q->execute($params); return $q->fetchAll();
    }
    public function find(string $userId, string $id, bool $attempts=false): ?array {
        if ($userId==='') return null;
        $q=$this->pdo->prepare($this->select().' WHERE r.id=:id AND '.$this->visibility().' LIMIT 1');
        $q->execute(['id'=>$id,'uid'=>$userId]); $row=$q->fetch();
        if (!$row) return null;
        if ($attempts && $row['request_source']==='email') {
            $q=$this->pdo->prepare('SELECT a.attempt_no,a.outcome,a.http_status,a.error_code,a.started_at,a.finished_at FROM portal_email_attempts a JOIN portal_email_intake i ON i.id=a.intake_id WHERE i.request_id=:id ORDER BY a.attempt_no');
            $q->execute(['id'=>$id]); $row['email_attempts']=$q->fetchAll();
        }
        return $row;
    }
}
