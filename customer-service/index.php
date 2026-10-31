<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (isset($_SESSION['user_id'])) {
    $redirectTarget = '../admin/service/';
    $queryString = trim((string) ($_SERVER['QUERY_STRING'] ?? ''));

    if ($queryString !== '') {
        $redirectTarget .= '?' . $queryString;
    }

    header('Location: ' . $redirectTarget);
    exit;
}

$assetBase = '../';
$homePath = '../';
$loginPath = '../customer-login/';

require dirname(__DIR__) . '/service_page_customer.php';
