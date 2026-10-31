<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (isset($_SESSION['user_id'])) {
	header('Location: ../admin/dashboard/');
	exit;
}

$routeBase = '../';
$assetBase = '../';
$customerLoginPath = '../customer-login/';
$customerSignupPath = '../customer-signup/';
require dirname(__DIR__) . '/verify_email_page_customer.php';