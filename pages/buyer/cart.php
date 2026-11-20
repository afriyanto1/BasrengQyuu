<?php
require dirname(__DIR__, 2) . "/includes/config.php";
require_role("buyer");

$buyerId = (int) user()["id"];
if (!isset($_SESSION["buyer_cart"][$buyerId]) || !is_array($_SESSION["buyer_cart"][$buyerId])) {
    $_SESSION["buyer_cart"][$buyerId] = [];
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    check_csrf();
    $action = $_POST["action"] ?? "";
    $productId = filter_var($_POST["product_id"] ?? null, FILTER_VALIDATE_INT);

    if ($action === "remove" && $productId) {
        unset($_SESSION["buyer_cart"][$buyerId][$productId]);
        if (empty($_SESSION["buyer_cart"][$buyerId])) {
            unset($_SESSION["buyer_cart"][$buyerId]);
        }
        flash("Produk dihapus dari keranjang.", "success");
    } else {
        flash("Permintaan keranjang tidak valid.");
    }

    header("Location: cart.php");
    exit();
}

$cart = $_SESSION["buyer_cart"][$buyerId];
$productIds = array_values(array_filter(
    array_map("intval", array_keys($cart)),
    static fn (int $id): bool => $id > 0
));
$products = [];
if ($productIds) {
    $placeholders = implode(",", array_fill(0, count($productIds), "?"));
    $stmt = $pdo->prepare("SELECT * FROM products WHERE id IN ($placeholders) ORDER BY name");
    $stmt->execute($productIds);
    $products = $stmt->fetchAll();
}

$cartCount = array_sum(array_map("intval", $cart));
?><!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Keranjang Belanja - Basreng Store</title>
    <base href="<?= e(app_url('pages/buyer/')) ?>">
    <link rel="icon" type="image/svg+xml" href="<?= e(app_favicon_url()) ?>">
    <link rel="stylesheet" href="<?= e(app_url('assets/css/style.css')) ?>?v=<?= (int) filemtime(app_path('assets/css/style.css')) ?>">
    <style>
        .cart-heading { display:flex; align-items:flex-end; justify-content:space-between; gap:16px; flex-wrap:wrap; margin-bottom:20px; }
        .cart-heading p { margin:4px 0 0; }
        .cart-card, .cart-summary { background:#fff; border:1px solid #e2e8f0; border-radius:14px; box-shadow:0 5px 16px rgba(15,23,42,.05); }
        .cart-card { overflow:hidden; }
        .cart-table { width:100%; border-collapse:collapse; }
        .cart-table th { padding:14px 18px; background:#f8fafc; color:#64748b; font-size:12px; font-weight:700; text-align:left; }
        .cart-table td { padding:16px 18px; border-top:1px solid #edf2f7; vertical-align:middle; }
        .cart-product { display:flex; align-items:center; gap:14px; min-width:250px; }
        .cart-check { width:18px; height:18px; accent-color:#2563eb; }
        .cart-image { width:76px; height:76px; flex:none; display:grid; place-items:center; overflow:hidden; border-radius:10px; background:#eff6ff; color:#2563eb; font-size:28px; }
        .cart-image img { width:100%; height:100%; object-fit:contain; }
        .cart-product-name { display:block; margin-bottom:4px; color:#0f172a; font-size:14px; font-weight:700; }
        .cart-price { color:#2563eb; font-weight:700; white-space:nowrap; }
        .cart-qty { width:88px; height:38px; padding:6px; border:1px solid #cbd5e1; border-radius:8px; text-align:center; }
        .cart-remove { border:0; background:none; color:#dc2626; font:inherit; font-size:13px; cursor:pointer; }
        .cart-summary { display:grid; grid-template-columns:1fr auto; align-items:center; gap:18px; margin-top:18px; padding:20px; }
        .cart-total { text-align:right; }
        .cart-total strong { display:block; color:#2563eb; font-size:22px; }
        .cart-checkout { min-width:210px; padding:13px 20px; border:0; border-radius:9px; background:#2563eb; color:#fff; font-size:14px; font-weight:700; cursor:pointer; }
        .cart-checkout:hover { background:#1d4ed8; }
        .cart-checkout:disabled { background:#cbd5e1; cursor:not-allowed; }
        .empty-cart { padding:54px 20px; text-align:center; }
        .empty-cart-icon { display:grid; place-items:center; width:70px; height:70px; margin:0 auto 15px; border-radius:50%; background:#eff6ff; font-size:32px; }
        @media (max-width:700px) {
            .cart-table thead { display:none; }
            .cart-table, .cart-table tbody, .cart-table tr, .cart-table td { display:block; width:100%; }
            .cart-table tr { position:relative; padding:16px; border-top:1px solid #edf2f7; }
            .cart-table td { padding:6px 0; border:0; }
            .cart-table td:first-child { padding-right:52px; }
            .cart-product { min-width:0; }
            .cart-table td:not(:first-child) { display:inline-block; width:auto; margin:8px 14px 0 32px; }
            .cart-remove-cell { position:absolute; top:14px; right:14px; margin:0 !important; }
            .cart-summary { grid-template-columns:1fr; }
            .cart-total { text-align:left; }
            .cart-checkout { width:100%; }
        }
        @media (max-width:440px) {
            .cart-image { width:58px; height:58px; }
            .cart-product-name { font-size:13px; }
            .cart-table td:not(:first-child) { margin-left:32px; }
        }
    </style>
</head>
<body>
<?php include dirname(__DIR__, 2) . "/includes/partials/buyer_nav.php"; ?>
<main>
    <?php show_flash(); ?>
    <div class="cart-heading">
        <div>
            <h1>Keranjang Saya</h1>
            <p class="muted"><?= $cartCount ?> barang tersimpan. Pilih barang yang ingin dibeli sekarang.</p>
        </div>
        <a href="shop.php" class="btn" style="margin:0; padding:10px 16px; background:#fff; color:#2563eb; border:1px solid #93c5fd; border-radius:9px; text-decoration:none; font-weight:700;">← Lanjut Belanja</a>
    </div>

    <?php if ($products): ?>
        <form id="cart-checkout-form" method="post" action="checkout.php">
            <input type="hidden" name="csrf" value="<?= csrf() ?>">
            <input type="hidden" name="source" value="cart">
        </form>

        <section class="cart-card" aria-label="Daftar produk di keranjang">
            <table class="cart-table">
                <thead>
                    <tr>
                        <th>Produk</th>
                        <th>Harga Satuan</th>
                        <th>Jumlah</th>
                        <th>Subtotal</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($products as $product):
                        $productId = (int) $product["id"];
                        $savedQuantity = max(1, (int) ($cart[$productId] ?? 1));
                        $available = (bool) $product["active"] && (int) $product["stock"] > 0;
                        $checkoutQuantity = $available ? min($savedQuantity, (int) $product["stock"]) : $savedQuantity;
                    ?>
                    <tr class="cart-row" data-price="<?= e($product["price"]) ?>">
                        <td>
                            <div class="cart-product">
                                <input class="cart-check cart-selection" type="checkbox" name="selected[]" value="<?= $productId ?>" form="cart-checkout-form" <?= $available ? "checked" : "disabled" ?> aria-label="Pilih <?= e($product["name"]) ?>">
                                <div class="cart-image">
                                    <?php if (!empty($product["image"])): ?>
                                        <img src="<?= e(app_url($product["image"])) ?>" alt="<?= e($product["name"]) ?>">
                                    <?php else: ?>
                                        🌶️
                                    <?php endif; ?>
                                </div>
                                <div>
                                    <span class="cart-product-name"><?= e($product["name"]) ?></span>
                                    <span class="muted" style="font-size:12px;">
                                        <?= $available ? "Stok tersedia: " . (int) $product["stock"] : "Produk sedang tidak tersedia" ?>
                                    </span>
                                </div>
                            </div>
                        </td>
                        <td class="cart-price"><?= rupiah($product["price"]) ?></td>
                        <td>
                            <input class="cart-qty cart-quantity" type="number" name="qty[<?= $productId ?>]" value="<?= $checkoutQuantity ?>" min="1" max="<?= max(1, (int) $product["stock"]) ?>" form="cart-checkout-form" <?= $available ? "" : "disabled" ?> aria-label="Jumlah <?= e($product["name"]) ?>">
                        </td>
                        <td class="cart-price cart-subtotal"><?= rupiah($product["price"] * $checkoutQuantity) ?></td>
                        <td class="cart-remove-cell">
                            <form method="post" action="cart.php" style="margin:0;">
                                <input type="hidden" name="csrf" value="<?= csrf() ?>">
                                <input type="hidden" name="product_id" value="<?= $productId ?>">
                                <button class="cart-remove" type="submit" name="action" value="remove">Hapus</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </section>

        <section class="cart-summary">
            <div>
                <strong id="cart-selected-count">0 barang dipilih</strong>
            </div>
            <div class="cart-total">
                <span class="muted">Total produk dipilih</span>
                <strong id="cart-selected-total">Rp 0</strong>
                <button class="cart-checkout" id="cart-checkout-button" type="submit" form="cart-checkout-form">Checkout Sekarang →</button>
            </div>
        </section>
    <?php else: ?>
        <section class="cart-card empty-cart">
            <div class="empty-cart-icon">🛒</div>
            <h2 style="margin:0 0 6px;">Keranjang masih kosong</h2>
            <p class="muted" style="margin:0 0 20px;">Tambahkan produk dari menu, lalu pilih mana yang ingin di-checkout.</p>
            <a href="shop.php" class="btn" style="display:inline-block; margin:0; padding:12px 20px; background:#2563eb; border-radius:9px; color:#fff; text-decoration:none; font-weight:700;">Mulai Belanja</a>
        </section>
    <?php endif; ?>
</main>
<?php if ($products): ?>
<script>
    (function () {
        const rows = Array.from(document.querySelectorAll(".cart-row"));
        const countLabel = document.getElementById("cart-selected-count");
        const totalLabel = document.getElementById("cart-selected-total");
        const checkoutButton = document.getElementById("cart-checkout-button");
        const currency = new Intl.NumberFormat("id-ID", {
            style: "currency",
            currency: "IDR",
            maximumFractionDigits: 0
        });

        function updateSummary() {
            let count = 0;
            let total = 0;
            rows.forEach(function (row) {
                const checkbox = row.querySelector(".cart-selection");
                const quantity = row.querySelector(".cart-quantity");
                const subtotal = row.querySelector(".cart-subtotal");
                const amount = Number(quantity.value) || 0;
                const lineTotal = Number(row.dataset.price) * amount;
                subtotal.textContent = currency.format(lineTotal);
                if (checkbox.checked && !checkbox.disabled) {
                    count += amount;
                    total += lineTotal;
                }
            });
            countLabel.textContent = count + " barang dipilih";
            totalLabel.textContent = currency.format(total);
            checkoutButton.disabled = count === 0;
        }

        rows.forEach(function (row) {
            row.querySelector(".cart-selection").addEventListener("change", updateSummary);
            row.querySelector(".cart-quantity").addEventListener("input", updateSummary);
        });
        updateSummary();
    })();
</script>
<?php endif; ?>
</body>
</html>
