<?php
require dirname(__DIR__, 2) . "/includes/config.php";
require_role("buyer");

$buyerId = (int) user()["id"];
$cart = sync_buyer_cart($pdo, $buyerId);

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    check_csrf();
    $action = $_POST["action"] ?? "";
    $productId = filter_var($_POST["product_id"] ?? null, FILTER_VALIDATE_INT);

    if ($action === "update_quantity") {
        $quantity = filter_var($_POST["quantity"] ?? null, FILTER_VALIDATE_INT);
        $stmt = $pdo->prepare("SELECT stock, active FROM products WHERE id = ?");
        $stmt->execute([$productId]);
        $product = $stmt->fetch();

        if (
            !$productId ||
            $quantity === false ||
            $quantity === null ||
            $quantity < 1 ||
            !array_key_exists($productId, $_SESSION["buyer_cart"][$buyerId]) ||
            !$product ||
            ((bool) $product["active"] && $quantity > (int) $product["stock"])
        ) {
            http_response_code(422);
            header("Content-Type: application/json; charset=utf-8");
            echo json_encode(["success" => false, "message" => "Jumlah produk tidak valid."]);
            exit();
        }

        persist_buyer_cart_item($pdo, $buyerId, $productId, $quantity);
        header("Content-Type: application/json; charset=utf-8");
        echo json_encode(["success" => true]);
        exit();
    }

    if ($action === "remove" && $productId) {
        persist_buyer_cart_item($pdo, $buyerId, $productId, null);
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
        .cart-continue { display:inline-flex; align-items:center; justify-content:center; flex:none; margin:0; padding:10px 16px; border:1px solid #93c5fd; border-radius:9px; background:#fff; color:#2563eb; text-decoration:none; font-weight:700; white-space:nowrap; }
        .cart-card, .cart-summary { background:#fff; border:1px solid #e2e8f0; border-radius:14px; box-shadow:0 5px 16px rgba(15,23,42,.05); }
        .cart-card { overflow:hidden; }
        .cart-table { width:100%; border-collapse:collapse; }
        .cart-table th { padding:14px 18px; background:#f8fafc; color:#64748b; font-size:12px; font-weight:700; text-align:left; }
        .cart-table td { padding:16px 18px; border-top:1px solid #edf2f7; vertical-align:middle; }
        .cart-product { display:flex; align-items:center; gap:14px; min-width:250px; }
        .cart-check { width:18px; height:18px; accent-color:#2563eb; }
        .cart-image { width:76px; height:76px; flex:none; display:grid; place-items:center; overflow:hidden; border-radius:10px; background:#eff6ff; color:#2563eb; font-size:28px; }
        .cart-image img { width:100%; height:100%; object-fit:cover; object-position:center; }
        .cart-product-name { display:block; margin-bottom:4px; color:#0f172a; font-size:14px; font-weight:700; }
        .cart-price { color:#2563eb; font-weight:700; white-space:nowrap; }
        .cart-qty-control { display:inline-flex; align-items:center; gap:3px; }
        .cart-qty { width:32px; height:26px; padding:0 2px; border:1px solid #cbd5e1; border-radius:6px; text-align:center; text-align-last:center; font:inherit; appearance:textfield; -moz-appearance:textfield; }
        .cart-qty::-webkit-inner-spin-button, .cart-qty::-webkit-outer-spin-button { margin:0; appearance:none; }
        .cart-qty-button { display:grid; place-items:center; width:22px; height:22px; padding:0; border:1px solid #cbd5e1; border-radius:6px; background:#fff; color:#1e293b; font-size:14px; line-height:1; cursor:pointer; }
        .cart-qty-button:hover:not(:disabled) { border-color:#2563eb; color:#2563eb; }
        .cart-qty-button:disabled { opacity:.45; cursor:not-allowed; }
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
            .cart-table tr { position:relative; display:grid; grid-template-columns:repeat(3, minmax(0, 1fr)); align-items:start; gap:10px 6px; padding:16px; border-top:1px solid #edf2f7; }
            .cart-table td { min-width:0; padding:0; border:0; }
            .cart-table td:first-child { grid-column:1 / -1; padding-right:56px; }
            .cart-product { min-width:0; }
            .cart-table td[data-label] { display:flex; flex-direction:column; align-items:center; gap:3px; text-align:center; font-size:12px; }
            .cart-table td[data-label]::before { content:attr(data-label); color:#64748b; font-size:10px; font-weight:600; }
            .cart-table td[data-label="Jumlah"] { padding:0 2px; }
            .cart-qty-control { gap:3px; }
            .cart-qty { width:28px; height:28px; }
            .cart-qty-button { width:24px; height:26px; font-size:15px; }
            .cart-summary { grid-template-columns:1fr; gap:10px; padding:16px; }
            .cart-total { text-align:left; }
            .cart-checkout { width:100%; }
        }
        @media (max-width:440px) {
            .cart-heading { display:grid; grid-template-columns:minmax(0, 1fr) auto; align-items:start; gap:8px; }
            .cart-heading h1 { font-size:21px; line-height:1.25; }
            .cart-heading > div { min-width:0; }
            .cart-heading p { font-size:12px; }
            .cart-continue { padding:8px 10px; font-size:12px; }
            .cart-image { width:58px; height:58px; }
            .cart-product-name { font-size:13px; }
            .cart-table tr { gap:8px 4px; padding:14px 12px; }
            .cart-table td[data-label] { font-size:11px; }
            .cart-price { font-size:11px; }
        }
    </style>
</head>
<body>
<?php include dirname(__DIR__, 2) . "/includes/partials/buyer_nav.php"; ?>
<main>
    <?php show_flash(); ?>
    <div class="cart-heading">
        <div>
            <h1><?= e(t("Keranjang Saya")) ?></h1>
            <p class="muted"><span id="cart-saved-count"><?= $cartCount ?></span> <?= e(app_language() === "en" ? "items saved. Choose items you want to buy now." : "barang tersimpan. Pilih barang yang ingin dibeli sekarang.") ?></p>
        </div>
        <a href="shop.php" class="cart-continue">← <?= e(t("Lanjut Belanja")) ?></a>
    </div>

    <?php if ($products): ?>
        <form id="cart-quantity-form" method="post" action="cart.php" hidden>
            <input type="hidden" name="csrf" value="<?= csrf() ?>">
            <input type="hidden" name="action" value="update_quantity">
            <input type="hidden" name="product_id">
            <input type="hidden" name="quantity">
        </form>
        <form id="cart-checkout-form" method="post" action="checkout.php">
            <input type="hidden" name="csrf" value="<?= csrf() ?>">
            <input type="hidden" name="source" value="cart">
        </form>

        <section class="cart-card" aria-label="Daftar produk di keranjang">
            <table class="cart-table">
                <thead>
                    <tr>
                        <th><?= e(t("Produk")) ?></th>
                        <th><?= e(t("Harga Satuan")) ?></th>
                        <th><?= e(t("Jumlah")) ?></th>
                        <th><?= e(t("Subtotal")) ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $selectedCount = 0;
                    $selectedTotal = 0;
                    foreach ($products as $product):
                        $productId = (int) $product["id"];
                        $savedQuantity = max(1, (int) ($cart[$productId] ?? 1));
                        $available = (bool) $product["active"] && (int) $product["stock"] > 0;
                        $checkoutQuantity = $available ? min($savedQuantity, (int) $product["stock"]) : $savedQuantity;
                        if ($available) {
                            $selectedCount += $checkoutQuantity;
                            $selectedTotal += (float) $product["price"] * $checkoutQuantity;
                        }
                    ?>
                    <tr class="cart-row" data-product-id="<?= $productId ?>" data-price="<?= e($product["price"]) ?>">
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
                        <td class="cart-price" data-label="Harga"><?= rupiah($product["price"]) ?></td>
                        <td data-label="Jumlah">
                            <div class="cart-qty-control">
                                <button class="cart-qty-button cart-decrease" type="button" aria-label="Kurangi jumlah <?= e($product["name"]) ?>">&minus;</button>
                                <input class="cart-qty cart-quantity" type="number" name="qty[<?= $productId ?>]" value="<?= $checkoutQuantity ?>" min="1" max="<?= max(1, (int) $product["stock"]) ?>" readonly form="cart-checkout-form" <?= $available ? "" : "disabled" ?> aria-label="Jumlah <?= e($product["name"]) ?>">
                                <button class="cart-qty-button cart-increase" type="button" aria-label="Tambah jumlah <?= e($product["name"]) ?>" <?= !$available || $checkoutQuantity >= (int) $product["stock"] ? "disabled" : "" ?>>&plus;</button>
                            </div>
                            <form class="cart-remove-form" method="post" action="cart.php" hidden>
                                <input type="hidden" name="csrf" value="<?= csrf() ?>">
                                <input type="hidden" name="product_id" value="<?= $productId ?>">
                                <input type="hidden" name="action" value="remove">
                            </form>
                        </td>
                        <td class="cart-price cart-subtotal" data-label="Subtotal"><?= rupiah($product["price"] * $checkoutQuantity) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </section>

        <section class="cart-summary">
            <div>
                <strong id="cart-selected-count"><?= $selectedCount ?> barang dipilih</strong>
            </div>
            <div class="cart-total">
                <span class="muted">Total produk dipilih</span>
                <strong id="cart-selected-total"><?= e(rupiah($selectedTotal)) ?></strong>
                <button class="cart-checkout" id="cart-checkout-button" type="submit" form="cart-checkout-form" <?= $selectedCount === 0 ? "disabled" : "" ?>>Checkout Sekarang →</button>
            </div>
        </section>
    <?php else: ?>
        <section class="cart-card empty-cart">
            <div class="empty-cart-icon">🛒</div>
            <h2 style="margin:0 0 6px;"><?= e(t("Keranjang masih kosong")) ?></h2>
            <p class="muted" style="margin:0 0 20px;"><?= e(app_language() === "en" ? "Add products from the shop, then choose which ones to checkout." : "Tambahkan produk dari menu, lalu pilih mana yang ingin di-checkout.") ?></p>
            <a href="shop.php" class="btn" style="display:inline-block; margin:0; padding:12px 20px; background:#2563eb; border-radius:9px; color:#fff; text-decoration:none; font-weight:700;"><?= e(t("Mulai Belanja")) ?></a>
        </section>
    <?php endif; ?>
</main>
<?php if ($products): ?>
<script>
    (function () {
        const rows = Array.from(document.querySelectorAll(".cart-row"));
        const countLabel = document.getElementById("cart-selected-count");
        const totalLabel = document.getElementById("cart-selected-total");
        const savedCountLabel = document.getElementById("cart-saved-count");
        const checkoutButton = document.getElementById("cart-checkout-button");
        const quantityForm = document.getElementById("cart-quantity-form");
        let pendingUpdates = 0;
        const currency = new Intl.NumberFormat("id-ID", {
            style: "currency",
            currency: "IDR",
            maximumFractionDigits: 0
        });

        function updateSummary() {
            let count = 0;
            let savedCount = 0;
            let total = 0;
            rows.forEach(function (row) {
                const checkbox = row.querySelector(".cart-selection");
                const quantity = row.querySelector(".cart-quantity");
                const subtotal = row.querySelector(".cart-subtotal");
                const amount = Number(quantity.value) || 0;
                const lineTotal = Number(row.dataset.price) * amount;
                savedCount += amount;
                subtotal.textContent = currency.format(lineTotal);
                if (checkbox.checked && !checkbox.disabled) {
                    count += amount;
                    total += lineTotal;
                }
            });
            countLabel.textContent = count + " barang dipilih";
            savedCountLabel.textContent = String(savedCount);
            totalLabel.textContent = currency.format(total);
            checkoutButton.disabled = count === 0 || pendingUpdates > 0;
        }

        function setQuantity(row, quantity) {
            const input = row.querySelector(".cart-quantity");
            input.value = String(quantity);
            input.dispatchEvent(new Event("input", { bubbles: true }));
        }

        async function saveQuantity(row, quantity, previousQuantity) {
            const controls = Array.from(row.querySelectorAll(".cart-qty-button"));
            const formData = new FormData(quantityForm);
            formData.set("product_id", row.dataset.productId);
            formData.set("quantity", String(quantity));
            pendingUpdates += 1;
            controls.forEach(function (button) {
                button.disabled = true;
            });
            checkoutButton.disabled = true;

            try {
                const response = await fetch(quantityForm.getAttribute("action"), {
                    method: "POST",
                    body: formData,
                    credentials: "same-origin",
                    headers: { "X-Requested-With": "XMLHttpRequest" }
                });
                const result = await response.json();
                if (!response.ok || !result.success) {
                    throw new Error(result.message || "Jumlah gagal disimpan.");
                }
            } catch (error) {
                setQuantity(row, previousQuantity);
                alert(error.message || "Jumlah gagal disimpan. Silakan coba lagi.");
            } finally {
                pendingUpdates -= 1;
                controls.forEach(function (button) {
                    button.disabled = false;
                });
                const input = row.querySelector(".cart-quantity");
                row.querySelector(".cart-increase").disabled = input.disabled || Number(input.value) >= Number(input.max);
                updateSummary();
            }
        }

        rows.forEach(function (row) {
            row.querySelector(".cart-selection").addEventListener("change", updateSummary);
            const quantity = row.querySelector(".cart-quantity");
            const decreaseButton = row.querySelector(".cart-decrease");
            const increaseButton = row.querySelector(".cart-increase");
            quantity.addEventListener("input", updateSummary);

            decreaseButton.addEventListener("click", function () {
                const previousQuantity = Number(quantity.value);
                if (previousQuantity <= 1) {
                    if (confirm("Jumlah menjadi 0. Yakin ingin menghapus produk ini dari keranjang?")) {
                        row.querySelector(".cart-remove-form").submit();
                    }
                    return;
                }
                const nextQuantity = previousQuantity - 1;
                setQuantity(row, nextQuantity);
                saveQuantity(row, nextQuantity, previousQuantity);
            });

            increaseButton.addEventListener("click", function () {
                const previousQuantity = Number(quantity.value);
                const nextQuantity = Math.min(previousQuantity + 1, Number(quantity.max));
                if (nextQuantity === previousQuantity) {
                    return;
                }
                setQuantity(row, nextQuantity);
                saveQuantity(row, nextQuantity, previousQuantity);
            });
        });
        window.addEventListener("pageshow", updateSummary);
        updateSummary();
    })();
</script>
<?php endif; ?>
</body>
</html>
