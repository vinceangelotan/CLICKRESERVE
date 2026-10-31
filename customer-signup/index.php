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
$customerSignupPath = './';
$customerPrivacyPolicyPath = '../customer-privacy-policy/';
require dirname(__DIR__) . '/signup_page_customer.php';