<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (isset($_SESSION['user_id'])) {
    header('Location: ../admin/services/');
    exit;
}

$assetBase = '../';
$homePath = '../';
$loginPath = '../customer-login/';
require dirname(__DIR__) . '/services_page_customer.php';
