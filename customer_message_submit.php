<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

header('Content-Type: application/json; charset=UTF-8');

function message_submit_respond(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function message_submit_random_token(int $length = 10): string
{
    try {
        return bin2hex(random_bytes((int) ceil($length / 2)));
    } catch (Throwable $error) {
        return substr(md5(uniqid('', true)), 0, max(4, $length));
    }
}

function message_submit_collect_files($filesField): array
{
    if (!is_array($filesField) || !isset($filesField['name'])) {
        return [];
    }

    $items = [];

    if (is_array($filesField['name'])) {
        $total = count($filesField['name']);

        for ($index = 0; $index < $total; $index++) {
            $items[] = [
                'name' => (string) ($filesField['name'][$index] ?? ''),
                'tmp_name' => (string) ($filesField['tmp_name'][$index] ?? ''),
                'error' => (int) ($filesField['error'][$index] ?? UPLOAD_ERR_NO_FILE),
                'size' => (int) ($filesField['size'][$index] ?? 0),
            ];
        }

        return $items;
    }

    $items[] = [
        'name' => (string) ($filesField['name'] ?? ''),
        'tmp_name' => (string) ($filesField['tmp_name'] ?? ''),
        'error' => (int) ($filesField['error'] ?? UPLOAD_ERR_NO_FILE),
        'size' => (int) ($filesField['size'] ?? 0),
    ];

    return $items;
}

function message_submit_store_attachments(array $files, string $projectRoot, int $maxAttachments = 5): array
{
    $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
    $allowedMimeTypes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
    $maxBytesPerFile = 8 * 1024 * 1024;

    $usableFiles = [];

    foreach ($files as $file) {
        $errorCode = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);

        if ($errorCode === UPLOAD_ERR_NO_FILE) {
            continue;
        }

        $usableFiles[] = $file;
    }

    if (count($usableFiles) > $maxAttachments) {
        return [
            'ok' => false,
            'paths' => [],
            'error' => 'You can upload up to 5 images only.',
        ];
    }

    if ($usableFiles === []) {
        return [
            'ok' => true,
            'paths' => [],
            'error' => '',
        ];
    }

    $targetDirRelative = 'assets/message_attachments';
    $targetDirAbsolute = $projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $targetDirRelative);

    if (!is_dir($targetDirAbsolute) && !mkdir($targetDirAbsolute, 0777, true) && !is_dir($targetDirAbsolute)) {
        return [
            'ok' => false,
            'paths' => [],
            'error' => 'Unable to prepare attachment storage right now.',
        ];
    }

    $finfo = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : false;

    $savedRelativePaths = [];
    $savedAbsolutePaths = [];

    foreach ($usableFiles as $file) {
        $errorCode = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);

        if ($errorCode !== UPLOAD_ERR_OK) {
            foreach ($savedAbsolutePaths as $savedAbsolutePath) {
                if (is_file($savedAbsolutePath)) {
                    @unlink($savedAbsolutePath);
                }
            }

            if ($finfo) {
                finfo_close($finfo);
            }

            return [
                'ok' => false,
                'paths' => [],
                'error' => 'One of the image uploads failed. Please try again.',
            ];
        }

        $tmpName = (string) ($file['tmp_name'] ?? '');
        $originalName = (string) ($file['name'] ?? 'attachment');
        $fileSize = (int) ($file['size'] ?? 0);

        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            foreach ($savedAbsolutePaths as $savedAbsolutePath) {
                if (is_file($savedAbsolutePath)) {
                    @unlink($savedAbsolutePath);
                }
            }

            if ($finfo) {
                finfo_close($finfo);
            }

            return [
                'ok' => false,
                'paths' => [],
                'error' => 'Invalid uploaded image detected.',
            ];
        }

        if ($fileSize <= 0 || $fileSize > $maxBytesPerFile) {
            foreach ($savedAbsolutePaths as $savedAbsolutePath) {
                if (is_file($savedAbsolutePath)) {
                    @unlink($savedAbsolutePath);
                }
            }

            if ($finfo) {
                finfo_close($finfo);
            }

            return [
                'ok' => false,
                'paths' => [],
                'error' => 'Each image must be smaller than 8 MB.',
            ];
        }

        $extension = strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));
        if (!in_array($extension, $allowedExtensions, true)) {
            foreach ($savedAbsolutePaths as $savedAbsolutePath) {
                if (is_file($savedAbsolutePath)) {
                    @unlink($savedAbsolutePath);
                }
            }

            if ($finfo) {
                finfo_close($finfo);
            }

            return [
                'ok' => false,
                'paths' => [],
                'error' => 'Only JPG, PNG, WEBP, and GIF images are allowed.',
            ];
        }

        if ($finfo) {
            $mimeType = (string) finfo_file($finfo, $tmpName);

            if ($mimeType !== '' && !in_array($mimeType, $allowedMimeTypes, true)) {
                foreach ($savedAbsolutePaths as $savedAbsolutePath) {
                    if (is_file($savedAbsolutePath)) {
                        @unlink($savedAbsolutePath);
                    }
                }

                finfo_close($finfo);

                return [
                    'ok' => false,
                    'paths' => [],
                    'error' => 'One of the uploaded files is not a valid image.',
                ];
            }
        }

        $baseName = trim((string) pathinfo($originalName, PATHINFO_FILENAME));
        $baseName = preg_replace('/[^a-zA-Z0-9_-]+/', '-', $baseName) ?? $baseName;
        $baseName = trim((string) $baseName, '-_');

        if ($baseName === '') {
            $baseName = 'attachment';
        }

        $baseName = substr($baseName, 0, 48);

        $targetFileName = gmdate('YmdHis') . '-' . message_submit_random_token(12) . '-' . $baseName . '.' . $extension;
        $targetAbsolutePath = $targetDirAbsolute . DIRECTORY_SEPARATOR . $targetFileName;
        $targetRelativePath = $targetDirRelative . '/' . rawurlencode($targetFileName);

        if (!move_uploaded_file($tmpName, $targetAbsolutePath)) {
            foreach ($savedAbsolutePaths as $savedAbsolutePath) {
                if (is_file($savedAbsolutePath)) {
                    @unlink($savedAbsolutePath);
                }
            }

            if ($finfo) {
                finfo_close($finfo);
            }

            return [
                'ok' => false,
                'paths' => [],
                'error' => 'Unable to save uploaded image attachments.',
            ];
        }

        $savedAbsolutePaths[] = $targetAbsolutePath;
        $savedRelativePaths[] = $targetRelativePath;
    }

    if ($finfo) {
        finfo_close($finfo);
    }

    return [
        'ok' => true,
        'paths' => $savedRelativePaths,
        'error' => '',
    ];
}

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    message_submit_respond(405, [
        'ok' => false,
        'message' => 'Method not allowed.',
    ]);
}

if (!isset($_SESSION['customer_id']) || isset($_SESSION['user_id'])) {
    message_submit_respond(401, [
        'ok' => false,
        'message' => 'Please log in to send a message.',
    ]);
}

require_once __DIR__ . '/config/message_notifications_repository.php';

$subject = trim((string) ($_POST['subject'] ?? ''));
$messageBody = trim((string) ($_POST['message'] ?? ''));

if ($subject === '' || $messageBody === '') {
    message_submit_respond(422, [
        'ok' => false,
        'message' => 'Subject and message are required.',
    ]);
}

$subjectLength = function_exists('mb_strlen') ? mb_strlen($subject) : strlen($subject);
$messageLength = function_exists('mb_strlen') ? mb_strlen($messageBody) : strlen($messageBody);

if ($subjectLength > 120) {
    message_submit_respond(422, [
        'ok' => false,
        'message' => 'Subject must not exceed 120 characters.',
    ]);
}

if ($messageLength > 4000) {
    message_submit_respond(422, [
        'ok' => false,
        'message' => 'Message must not exceed 4000 characters.',
    ]);
}

$uploadedFiles = message_submit_collect_files($_FILES['attachments'] ?? null);
$uploadResult = message_submit_store_attachments($uploadedFiles, __DIR__, 5);

if (!$uploadResult['ok']) {
    message_submit_respond(422, [
        'ok' => false,
        'message' => $uploadResult['error'],
    ]);
}

$senderName = trim((string) ($_SESSION['customer_name'] ?? 'Customer #' . (string) ($_SESSION['customer_id'] ?? '0')));
if ($senderName === '') {
    $senderName = 'Customer #' . (string) ($_SESSION['customer_id'] ?? '0');
}

$senderEmail = trim((string) ($_SESSION['customer_email'] ?? ''));

$newNotification = append_message_notification(
    $senderName,
    $senderEmail,
    $subject,
    $messageBody,
    $uploadResult['paths']
);

if ($newNotification === null) {
    foreach ($uploadResult['paths'] as $relativePath) {
        $decodedPath = rawurldecode((string) $relativePath);
        $absolutePath = __DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, ltrim($decodedPath, '/'));

        if (is_file($absolutePath)) {
            @unlink($absolutePath);
        }
    }

    message_submit_respond(500, [
        'ok' => false,
        'message' => 'Unable to save your message right now.',
    ]);
}

message_submit_respond(200, [
    'ok' => true,
    'message' => 'Your message has been sent.',
]);
