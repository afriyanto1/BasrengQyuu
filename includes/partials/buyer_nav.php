<?php
$current_page = basename($_SERVER['PHP_SELF']);
$unread_notification_stmt = $pdo->prepare(
    "SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0"
);
$unread_notification_stmt->execute([user()["id"]]);
$unread_notification_count = (int) $unread_notification_stmt->fetchColumn();
$buyer_cart = sync_buyer_cart($pdo, (int) user()["id"]);
$buyer_cart_count = is_array($buyer_cart) ? array_sum(array_map("intval", $buyer_cart)) : 0;
?>
<header>
    <a class="brand" href="<?= e(app_url('pages/buyer/shop.php')) ?>"><span class="brand-name"><span class="brand-basreng">Basreng</span><span class="brand-qyuu">Qyuu&nbsp;</span><span class="brand-store">Store</span></span></a>
    <button class="mobile-toggle" type="button" aria-label="Buka navigasi" onclick="this.parentElement.querySelector('nav').classList.toggle('show')">
        ☰
    </button>
    <nav>
        <a href="<?= e(app_url('pages/buyer/shop.php')) ?>" class="<?= $current_page === 'shop.php' ? 'active' : '' ?>">Menu</a>
        <a href="<?= e(app_url('pages/buyer/cart.php')) ?>" class="<?= $current_page === 'cart.php' ? 'active' : '' ?>">
            Keranjang<?php if ($buyer_cart_count > 0): ?>
                <span class="notification-count"><?= $buyer_cart_count ?></span>
            <?php endif; ?>
        </a>
        <a href="<?= e(app_url('pages/buyer/my_orders.php')) ?>" class="<?= $current_page === 'my_orders.php' || $current_page === 'payment.php' ? 'active' : '' ?>">Pesanan Saya</a>
        <a href="<?= e(app_url('pages/buyer/notifications.php')) ?>" class="<?= $current_page === 'notifications.php' ? 'active' : '' ?>">
            Notifikasi<?php if ($unread_notification_count > 0): ?>
                <span class="notification-count"><?= $unread_notification_count ?></span>
            <?php endif; ?>
        </a>
        <details class="profile-menu <?= $current_page === 'profile.php' ? 'active' : '' ?>">
            <summary class="profile-menu-toggle" aria-label="Buka menu akun">
                <svg aria-hidden="true" viewBox="0 0 24 24"><path d="M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm0 2c-4.42 0-8 2.24-8 5v1h16v-1c0-2.76-3.58-5-8-5Z"></path></svg>
                <span><?= e(user()["name"] ?? "Pengguna") ?></span>
            </summary>
            <div class="profile-menu-dropdown">
                <a href="<?= e(app_url('pages/auth/profile.php?section=profile')) ?>">Data profil</a>
                <a href="<?= e(app_url('pages/auth/profile.php?section=address')) ?>">Ubah alamat</a>
                <a href="<?= e(app_url('pages/auth/profile.php?section=password')) ?>">Ganti password</a>
                <div class="profile-language-setting">
                    <?php include __DIR__ . "/language_switch.php"; ?>
                </div>
                <a href="<?= e(app_url('pages/auth/logout.php')) ?>" class="profile-menu-logout">Logout</a>
            </div>
        </details>
    </nav>
</header>