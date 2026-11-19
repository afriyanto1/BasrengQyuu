<?php
$current_page = basename($_SERVER['PHP_SELF']);
?>
<header>
    <a class="brand" href="seller.php">
        <span class="brand-name"><span class="brand-basreng">Basreng</span><span class="brand-qyuu">Qyuu&nbsp;</span><span class="brand-store">Store</span></span>
    </a>
    <button class="mobile-toggle" onclick="document.querySelector('nav').classList.toggle('show')">
        ☰
    </button>
    <nav>
        <a href="seller.php" class="<?= $current_page === 'seller.php' ? 'active' : '' ?>">Dashboard</a>
        <a href="products.php" class="<?= $current_page === 'products.php' || $current_page === 'edit_product.php' ? 'active' : '' ?>">Produk</a>
        <a href="materials.php" class="<?= $current_page === 'materials.php' || $current_page === 'edit_material.php' ? 'active' : '' ?>">Bahan Baku</a>
        <a href="purchases.php" class="<?= $current_page === 'purchases.php' ? 'active' : '' ?>">Pembelian</a>
        <a href="orders.php" class="<?= $current_page === 'orders.php' ? 'active' : '' ?>">Pesanan</a>
        <a href="settings.php" class="<?= $current_page === 'settings.php' ? 'active' : '' ?>">Pembayaran</a>
        <a href="reports.php" class="<?= $current_page === 'reports.php' ? 'active' : '' ?>">Laporan</a>
        <details class="profile-menu <?= $current_page === 'profile.php' ? 'active' : '' ?>">
            <summary class="profile-menu-toggle" aria-label="Buka menu akun">
                <svg aria-hidden="true" viewBox="0 0 24 24"><path d="M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm0 2c-4.42 0-8 2.24-8 5v1h16v-1c0-2.76-3.58-5-8-5Z"></path></svg>
                <span><?= e(user()["name"] ?? "Pengguna") ?></span>
            </summary>
            <div class="profile-menu-dropdown">
                <a href="../auth/profile.php?section=address">Ubah alamat</a>
                <a href="../auth/profile.php?section=password">Ganti password</a>
                <a href="../auth/logout.php" class="profile-menu-logout">Logout</a>
            </div>
        </details>
    </nav>
</header>