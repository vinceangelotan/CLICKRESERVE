<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (isset($_SESSION['user_id'])) {
	header('Location: ../admin/dashboard/');
	exit;
}

if (isset($_SESSION['staff_id'])) {
	header('Location: ../admin/dashboard/');
	exit;
}

if (isset($_SESSION['customer_id'])) {
	header('Location: ../');
	exit;
}

$routeBase = '../';
$assetBase = '../';
$customerLoginPath = './';
$customerSignupPath = '../customer-signup/';
$customerPrivacyPolicyPath = '../customer-privacy-policy/';
require dirname(__DIR__) . '/login_page_customer.php';
