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
$accountSettingsPath = './';
$logoutPath = '../customer-logout/';
$cartPath = '../customer-cart/';
$eventsPath = '../customer-events/';
$servicesPath = '../customer-services/';
require dirname(__DIR__) . '/account_settings_page_customer.php';
