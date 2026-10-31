<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (isset($_SESSION['customer_id'])) {
	header('Location: ../');
	exit;
}

if (isset($_SESSION['user_id'])) {
	header('Location: dashboard/');
	exit;
}

if (isset($_SESSION['staff_id'])) {
	header('Location: dashboard/');
	exit;
}

$target = '../customer-login/';
$query = $_SERVER['QUERY_STRING'] ?? '';

if (is_string($query) && trim($query) !== '') {
    $target .= '?' . $query;
}

header('Location: ' . $target);
exit;
