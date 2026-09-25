<?php
declare(strict_types=1);

final class RpaClient
{
    public function payload(array $input): array
    {
        $language = in_array($input['language'] ?? null, ['en', 'ms'], true) ? $input['language'] : 'ms';
        return [
            'company' => env_value('RPA_COMPANY', 'CIDB'),
            'scenario_key' => env_value('RPA_SCENARIO_KEY', 'cidb_masterbot'),
            'channel' => 'Email',
            'fields' => [
                'sCustomerType' => 'Individual',
                'sEmail' => $input['email'],
                'sCustomerName' => $input['name'],
                'sIdentificationNumber' => $input['id_number'],
                'sLanguage' => $language,
                'sChannel' => 'Email',
                'sLocationArea' => $input['location_area'],
                'sCRMID' => $input['crm'],
            ],
        ];
    }

    public function send(array $payload): array
    {
        $endpoint = env_value('RPA_BOT_ENDPOINT');
        $key = env_value('RPA_BOT_API_KEY');
        if (!filter_var($endpoint, FILTER_VALIDATE_URL) || $key === '') throw new RuntimeException('RPA integration is not configured.');
        $ch = curl_init($endpoint);
        if ($ch === false) throw new RuntimeException('Unable to start the RPA request.');
        curl_setopt_array($ch, [CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>json_encode($payload, JSON_THROW_ON_ERROR), CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_HTTPHEADER=>['Content-Type: application/json','Accept: application/json','X-API-Key: '.$key],
            CURLOPT_CONNECTTIMEOUT_MS=>max(1000,(int)env_value('RPA_BOT_CONNECT_TIMEOUT_MS','5000')),
            CURLOPT_TIMEOUT_MS=>max(1000,(int)env_value('RPA_BOT_TIMEOUT_MS','15000'))]);
        $body = curl_exec($ch); $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $curlError = curl_error($ch); curl_close($ch);
        if ($body === false) return ['http_status'=>$status ?: null,'raw'=>'','parsed'=>null,'error'=>'RPA network request failed: '.$curlError];
        $parsed = json_decode((string)$body, true);
        return ['http_status'=>$status ?: null,'raw'=>(string)$body,'parsed'=>is_array($parsed)?$parsed:null,'error'=>($status < 200 || $status >= 300) ? 'RPA returned HTTP '.$status : null];
    }

    public function normalize(array $response): array
    {
        $p = $response['parsed']; $status = strtolower((string)($p['result_status'] ?? $p['status'] ?? $p['verification_status'] ?? $p['outcome'] ?? $p['data']['result_status'] ?? $p['data']['status'] ?? ''));
        $message = (string)($p['rpa_display_message'] ?? $p['display_message'] ?? $p['response_message'] ?? $p['message'] ?? $p['reply_message'] ?? $p['data']['rpa_display_message'] ?? $p['data']['display_message'] ?? $p['data']['response_message'] ?? $p['data']['message'] ?? '');
        $scheduleId = $p['schedule_id'] ?? $p['data']['schedule_id'] ?? $p['inserts'][0]['schedule_id'] ?? $p['data']['inserts'][0]['schedule_id'] ?? null;
        $ack = strtolower((string)($p['status'] ?? $p['data']['status'] ?? '')) === 'inserted';
        $ref = $p['external_reference_no'] ?? $p['reference_no'] ?? $p['ticket_no'] ?? $p['ticket_number'] ?? $p['data']['external_reference_no'] ?? $p['data']['reference_no'] ?? $p['data']['ticket_no'] ?? $p['data']['ticket_number'] ?? null;
        if ($response['error'] !== null || !$response['http_status']) $final = 'failed';
        elseif ($ack || in_array($status, ['inserted','accepted','queued','pending','processing'], true)) $final = 'pending';
        elseif (in_array($status, ['deleted','linked','norecord','approved','success','successful','completed'], true)) $final = 'success';
        elseif (in_array($status, ['error','failed','failure','rejected'], true)) $final = 'failed';
        elseif ($response['error'] !== null || !$response['http_status'] || $response['parsed'] === null) $final = 'failed';
        elseif ((int)$response['http_status'] >= 200 && (int)$response['http_status'] < 300) {
            $messageLower=strtolower($message);
            $final=(str_contains($messageLower,'failed') || str_contains($messageLower,'error') || str_contains($messageLower,'could not complete')) ? 'failed' : 'success';
        } else $final = 'failed';
        if ($final === 'success' && trim($message) === '') $final = 'pending';
        if ($final === 'pending') $message = '';
        $ref ??= $scheduleId;
        return ['status'=>$final,'message'=>trim($message),'reference'=>is_scalar($ref) ? (string)$ref : null,'acknowledgement'=>$ack];
    }
}
