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
require dirname(__DIR__, 2) . '/dashboard_page_admin.php';