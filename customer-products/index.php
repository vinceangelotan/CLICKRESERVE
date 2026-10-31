<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (isset($_SESSION['user_id'])) {
	header('Location: ../admin/dashboard/');
	exit;
}

$assetBase = '../';
$homePath = '../';
$loginPath = '../customer-login/';
$productKey = $_GET['product'] ?? 'fuji-x-a3';
require dirname(__DIR__) . '/product_page_customer.php';