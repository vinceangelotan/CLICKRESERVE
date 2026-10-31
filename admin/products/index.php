<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (isset($_SESSION['customer_id'])) {
    header('Location: ../../');
    exit;
}

if (!isset($_SESSION['user_id'])) {
    header('Location: ../');
    exit;
}

$isAdminView = true;
$assetBase = '../../';
$homePath = '../dashboard/';
$loginPath = '../';
$productKey = $_GET['product'] ?? 'fuji-x-a3';

require dirname(__DIR__, 2) . '/product_page_customer.php';
