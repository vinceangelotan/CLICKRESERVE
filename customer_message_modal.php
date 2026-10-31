<?php
$assetBase = $assetBase ?? '';
$messageSubmitEndpoint = $assetBase . 'customer_message_submit.php';
$messageLoginPath = $loginPath ?? ($assetBase . 'customer-login/');
$isMessageSenderLoggedIn = isset($_SESSION['customer_id']);
$messageSenderName = trim((string) ($_SESSION['customer_name'] ?? 'Guest'));
$messageSenderEmail = trim((string) ($_SESSION['customer_email'] ?? ''));
?>
<section class="customer-message-modal" data-message-modal hidden>
    <div class="customer-message-modal-backdrop" data-message-modal-close></div>

    <section class="customer-message-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="customer-message-title">
        <div class="customer-message-modal-head">
            <h2 id="customer-message-title">Message Us</h2>
            <button class="customer-message-modal-close" type="button" aria-label="Close message form" data-message-modal-close>&times;</button>
        </div>

        <p class="customer-message-sender">
            Sending as
            <strong><?php echo htmlspecialchars($messageSenderName !== '' ? $messageSenderName : 'Guest', ENT_QUOTES, 'UTF-8'); ?></strong>
            <?php if ($messageSenderEmail !== ''): ?>
                <span>(<?php echo htmlspecialchars($messageSenderEmail, ENT_QUOTES, 'UTF-8'); ?>)</span>
            <?php endif; ?>
        </p>

        <?php if (!$isMessageSenderLoggedIn): ?>
            <p class="customer-message-login-note">
                Please <a href="<?php echo htmlspecialchars($messageLoginPath, ENT_QUOTES, 'UTF-8'); ?>">log in</a> first before sending a message.
            </p>
        <?php endif; ?>

        <form
            class="customer-message-form"
            action="<?php echo htmlspecialchars($messageSubmitEndpoint, ENT_QUOTES, 'UTF-8'); ?>"
            method="post"
            enctype="multipart/form-data"
            data-message-form
            novalidate
        >
            <label class="customer-message-label" for="customer-message-subject">Subject / Title</label>
            <input
                id="customer-message-subject"
                class="customer-message-input"
                type="text"
                name="subject"
                maxlength="120"
                required
                placeholder="Enter a short subject"
            >

            <label class="customer-message-label" for="customer-message-body">Message</label>
            <textarea
                id="customer-message-body"
                class="customer-message-textarea"
                name="message"
                rows="6"
                maxlength="4000"
                required
                placeholder="Type your message here"
            ></textarea>

            <label class="customer-message-label" for="customer-message-attachments">Attachments (Images only)</label>
            <input
                id="customer-message-attachments"
                class="customer-message-file"
                type="file"
                name="attachments[]"
                accept="image/png,image/jpeg,image/webp,image/gif"
                multiple
                data-message-attachments
            >
            <p class="customer-message-file-note">Maximum of 5 images.</p>

            <p class="customer-message-feedback" data-message-feedback hidden></p>

            <div class="customer-message-actions">
                <button class="customer-message-cancel" type="button" data-message-modal-close>Cancel</button>
                <button
                    class="customer-message-submit"
                    type="submit"
                    data-message-submit
                    <?php echo !$isMessageSenderLoggedIn ? 'disabled' : ''; ?>
                >
                    Send Message
                </button>
            </div>
        </form>
    </section>
</section>
