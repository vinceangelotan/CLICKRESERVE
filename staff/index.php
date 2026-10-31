<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (isset($_SESSION['customer_id'])) {
    header('Location: ../');
    exit;
}

if (isset($_SESSION['user_id']) || isset($_SESSION['staff_id'])) {
    header('Location: ../admin/dashboard/');
    exit;
}

header('Location: ../customer-login/');
exit;
