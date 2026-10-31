<?php

if (!function_exists('build_customer_notification_center')) {
    function build_customer_notification_center($assetBase, $isEnabled)
    {
        $normalizedAssetBase = (string) $assetBase;
        $payload = [
            'enabled' => false,
            'notifications' => [],
            'unreadCount' => 0,
            'liveEndpoint' => $normalizedAssetBase . 'customer_notifications_live_updates.php',
            'markReadEndpoint' => $normalizedAssetBase . 'customer_notifications_mark_read.php',
            'cartPath' => $normalizedAssetBase . 'customer-cart/',
        ];

        if (!$isEnabled) {
            return $payload;
        }

        $customerId = trim((string) ($_SESSION['customer_id'] ?? ''));
        if ($customerId === '') {
            return $payload;
        }

        require_once __DIR__ . '/config/customer_notifications_repository.php';

        $records = load_customer_notifications_for_customer($customerId, null, 20);
        $frontendNotifications = [];

        foreach ($records as $notificationRecord) {
            if (!is_array($notificationRecord)) {
                continue;
            }

            $frontendNotifications[] = map_customer_notification_for_frontend($notificationRecord);
        }

        $payload['enabled'] = true;
        $payload['notifications'] = $frontendNotifications;
        $payload['unreadCount'] = count_unread_customer_notifications_for_customer($customerId, $records);

        return $payload;
    }
}

if (!function_exists('render_customer_notification_trigger_button')) {
    function render_customer_notification_trigger_button($notificationCenter, $assetBase)
    {
        if (!is_array($notificationCenter) || empty($notificationCenter['enabled'])) {
            return;
        }

        $normalizedAssetBase = (string) $assetBase;
        $unreadCount = max(0, (int) ($notificationCenter['unreadCount'] ?? 0));
        ?>
        <button
            class="topbar-notification-button topbar-notification-button-icon-only"
            type="button"
            aria-label="Notifications"
            title="Notifications"
            data-customer-notification-trigger
        >
            <span class="topbar-notification-icon-wrap" aria-hidden="true">
                <img class="topbar-notification-icon" src="<?php echo htmlspecialchars($normalizedAssetBase, ENT_QUOTES, 'UTF-8'); ?>assets/icons/notifications.svg" alt="">
                <span class="cart-count topbar-notification-count" data-customer-notification-count aria-hidden="true"><?php echo htmlspecialchars((string) $unreadCount, ENT_QUOTES, 'UTF-8'); ?></span>
            </span>
        </button>
        <?php
    }
}

if (!function_exists('render_customer_notification_modal')) {
    function render_customer_notification_modal($notificationCenter)
    {
        if (!is_array($notificationCenter) || empty($notificationCenter['enabled'])) {
            return;
        }
        ?>
        <section class="profile-modal cart-customer-notification-modal" data-customer-notification-modal hidden>
            <div class="profile-modal-backdrop" data-customer-notification-close></div>
            <div class="profile-modal-dialog cart-customer-notification-dialog" role="dialog" aria-modal="true" aria-labelledby="cart-customer-notification-title">
                <div class="cart-customer-notification-head">
                    <h3 id="cart-customer-notification-title">Notifications</h3>
                    <button type="button" class="profile-order-action" data-customer-notification-close>Close</button>
                </div>

                <div class="cart-customer-notification-list" data-customer-notification-list role="list"></div>
                <p class="cart-customer-notification-empty" data-customer-notification-empty hidden>No notifications yet.</p>
            </div>
        </section>
        <?php
    }
}

if (!function_exists('render_customer_notification_center_bootstrap_script')) {
    function render_customer_notification_center_bootstrap_script($notificationCenter, $assetBase)
    {
        if (!is_array($notificationCenter) || empty($notificationCenter['enabled'])) {
            return;
        }

        $normalizedAssetBase = (string) $assetBase;
        $notifications = is_array($notificationCenter['notifications'] ?? null)
            ? $notificationCenter['notifications']
            : [];
        $unreadCount = max(0, (int) ($notificationCenter['unreadCount'] ?? 0));
        $liveEndpoint = (string) ($notificationCenter['liveEndpoint'] ?? '');
        $markReadEndpoint = (string) ($notificationCenter['markReadEndpoint'] ?? '');
        $cartPath = (string) ($notificationCenter['cartPath'] ?? ($normalizedAssetBase . 'customer-cart/'));
        ?>
        <script>
            window.__creatyAssetBase = <?php echo json_encode($normalizedAssetBase, JSON_UNESCAPED_SLASHES); ?>;
            window.__creatyCustomerNotifications = <?php echo json_encode($notifications, JSON_UNESCAPED_SLASHES); ?>;
            window.__creatyCustomerNotificationUnreadCount = <?php echo json_encode($unreadCount, JSON_UNESCAPED_SLASHES); ?>;
            window.__creatyCustomerNotificationLiveEndpoint = <?php echo json_encode($liveEndpoint, JSON_UNESCAPED_SLASHES); ?>;
            window.__creatyCustomerNotificationMarkReadEndpoint = <?php echo json_encode($markReadEndpoint, JSON_UNESCAPED_SLASHES); ?>;
            window.__creatyCustomerCartPath = <?php echo json_encode($cartPath, JSON_UNESCAPED_SLASHES); ?>;
        </script>
        <?php
    }
}
