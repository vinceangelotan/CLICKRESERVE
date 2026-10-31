<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (isset($_SESSION['customer_id']) && !isset($_SESSION['user_id'])) {
	header('Location: ../');
	exit;
}

$routeBase = './';
require dirname(__DIR__) . '/logout_page_admin.php';