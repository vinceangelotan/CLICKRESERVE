<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (isset($_SESSION['customer_id'])) {
	header('Location: ../../');
	exit;
}

$routeBase = '../';
$assetBase = '../../';
$unifiedLoginPath = '../../customer-login/';
require dirname(__DIR__, 2) . '/signup_page_admin.php';
