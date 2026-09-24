<?php
declare(strict_types=1);

// Shared by the initial page render and polling: technical responses are never
// a fallback for the user-facing message.
function request_result(array $request): array
{
    $message = (string)($request['rpa_display_message'] ?? '');
    $decoded = json_decode($message, true);
    // Older submissions stored the entire acknowledgement in this column.
    if (is_array($decoded) && in_array(strtolower((string)($decoded['status'] ?? $decoded['data']['status'] ?? '')), ['inserted','accepted','queued','pending','processing'], true)) {
        $message = '';
    }
    $ready = trim($message) !== '';
    $failed = ($request['status'] ?? '') === 'failed';
    $submissionFailed = $failed && in_array($request['error_code'] ?? '', ['RPA_REQUEST_FAILED','PROCESSING_ERROR'], true);
    $status = $failed && ($ready || $submissionFailed) ? 'failed' : ($ready ? 'success' : (($request['status'] ?? '') === 'processing' ? 'processing' : 'pending'));
    return [
        'id' => $request['id'],
        'status' => $status,
        'complete' => $ready || $submissionFailed,
        'rpa_display_message' => $ready ? $message : null,
        'message' => $ready ? $message : ($submissionFailed ? 'We could not process your request. Please try again later.' : 'Your request is being processed. Please wait for the result.'),
        'completed_at' => ($ready || $submissionFailed) && !empty($request['completed_at']) ? date('d M Y · H:i', strtotime($request['completed_at'])) : ($ready ? 'Completed' : ($submissionFailed ? 'Not available' : 'In progress')),
    ];
}
