<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

header('Content-Type: application/json; charset=UTF-8');

if (!isset($_SESSION['user_id']) || isset($_SESSION['customer_id'])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Unauthorized']);
    exit;
}

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Method not allowed']);
    exit;
}

require_once dirname(__DIR__, 2) . '/config/customer_terms_repository.php';

$payloadRaw = file_get_contents('php://input');
$payload = json_decode((string) $payloadRaw, true);

if (!is_array($payload)) {
    $payload = $_POST;
}

$contentHtml = (string) ($payload['contentHtml'] ?? $payload['content_html'] ?? '');
$contentText = trim((string) preg_replace('/\s+/', ' ', strip_tags($contentHtml)));

if ($contentText === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => 'TOC content cannot be empty.']);
    exit;
}

$currentRecord = load_customer_terms_repository();
$nextRecord = is_array($currentRecord) ? $currentRecord : default_customer_terms_repository_record();
$nextRecord['contentHtml'] = $contentHtml;

if (!save_customer_terms_repository($nextRecord)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Unable to save TOC content.']);
    exit;
}

$savedRecord = load_customer_terms_repository();
$savedContentHtml = (string) ($savedRecord['contentHtml'] ?? '');
$savedUpdatedAt = trim((string) ($savedRecord['updatedAt'] ?? ''));
$displayHtml = customer_terms_prepare_display_html($savedContentHtml);

$updatedAtLabel = $savedUpdatedAt;
if ($savedUpdatedAt !== '') {
    try {
        $parsed = new DateTimeImmutable($savedUpdatedAt);
        $parsed = $parsed->setTimezone(new DateTimeZone('Asia/Manila'));
        $updatedAtLabel = $parsed->format('M d, Y h:i A') . ' (Asia/Manila)';
    } catch (Throwable $error) {
        $updatedAtLabel = $savedUpdatedAt;
    }
}

echo json_encode([
    'ok' => true,
    'message' => 'TOC updated successfully.',
    'contentHtml' => $savedContentHtml,
    'displayHtml' => $displayHtml,
    'updatedAt' => $savedUpdatedAt,
    'updatedAtLabel' => $updatedAtLabel
], JSON_UNESCAPED_SLASHES);
