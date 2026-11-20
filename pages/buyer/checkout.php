<?php
require dirname(__DIR__, 2) . "/includes/config.php";
require_role("buyer");

$buyerId = (int) user()["id"];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    check_csrf();

    if (($_POST["intent"] ?? "") === "place_order") {
        $draft = $_SESSION["buyer_checkout"][$buyerId] ?? null;
        if (
            !is_array($draft) ||
            !in_array($draft["source"] ?? null, ["cart", "direct"], true) ||
            !is_array($draft["items"] ?? null) ||
            !$draft["items"]
        ) {
            flash("Data checkout tidak ditemukan. Silakan pilih produk kembali.");
            header("Location: shop.php");
            exit();
        }

        $buyerStmt = $pdo->prepare(
            "SELECT name, phone, address, province, city, district, village
             FROM users WHERE id = ? LIMIT 1"
        );
        $buyerStmt->execute([$buyerId]);
        $buyerAddress = $buyerStmt->fetch();
        if (!$buyerAddress) {
            http_response_code(400);
            exit("Alamat akun tidak ditemukan.");
        }

        try {
            $shippingArea = shipping_area_for_address($buyerAddress);
            $shippingFee = calculate_shipping_fee($shippingArea);
        } catch (InvalidArgumentException $e) {
            flash($e->getMessage());
            header("Location: checkout.php");
            exit();
        }
        if (trim((string) ($buyerAddress["village"] ?? "")) === "" ||
            trim((string) ($buyerAddress["address"] ?? "")) === "") {
            flash("Lengkapi kelurahan/desa dan alamat jalan pada profil sebelum membuat pesanan.");
            header("Location: checkout.php");
            exit();
        }

        $paymentMethod = $_POST["payment_method"] ?? "";
        if (!is_string($paymentMethod) || !in_array($paymentMethod, ["qris", "bca", "cod"], true)) {
            flash("Pilih metode pembayaran yang valid.");
            header("Location: checkout.php");
            exit();
        }
        if ($paymentMethod === "cod" && $shippingArea !== "free_zone") {
            flash("COD hanya tersedia untuk alamat di wilayah bebas ongkir.");
            header("Location: checkout.php");
            exit();
        }

        $requestedQuantities = [];
        foreach ($draft["items"] as $id => $quantity) {
            $id = filter_var($id, FILTER_VALIDATE_INT);
            $quantity = filter_var($quantity, FILTER_VALIDATE_INT);
            if (!$id || $quantity === false || $quantity < 1) {
                flash("Rincian produk checkout tidak valid. Silakan pilih produk kembali.");
                header("Location: " . (($draft["source"] ?? "") === "cart" ? "cart.php" : "shop.php"));
                exit();
            }
            $requestedQuantities[$id] = $quantity;
        }

        if (($draft["source"] ?? "") === "cart") {
            $sessionCart = $_SESSION["buyer_cart"][$buyerId] ?? [];
            foreach ($requestedQuantities as $id => $quantity) {
                if (!is_array($sessionCart) || $quantity > (int) ($sessionCart[$id] ?? 0)) {
                    flash("Isi keranjang berubah. Silakan periksa ulang produk yang akan dibeli.");
                    header("Location: cart.php");
                    exit();
                }
            }
        }

        $ids = array_keys($requestedQuantities);
        $placeholders = implode(",", array_fill(0, count($ids), "?"));
        $productStmt = $pdo->prepare(
            "SELECT * FROM products WHERE id IN ($placeholders) AND active = 1"
        );
        $productStmt->execute($ids);
        $productsById = [];
        foreach ($productStmt->fetchAll() as $product) {
            $productsById[(int) $product["id"]] = $product;
        }

        $items = [];
        $productTotal = 0;
        foreach ($requestedQuantities as $id => $quantity) {
            $product = $productsById[$id] ?? null;
            if (!$product || $quantity > (int) $product["stock"]) {
                flash("Stok produk berubah atau tidak mencukupi. Silakan periksa kembali.");
                header("Location: checkout.php");
                exit();
            }
            $subtotal = $quantity * (float) $product["price"];
            $items[] = [
                "product" => $product,
                "qty" => $quantity,
                "subtotal" => $subtotal,
            ];
            $productTotal += $subtotal;
        }

        try {
            $pdo->beginTransaction();

            $countStmt = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE buyer_id = ?");
            $countStmt->execute([$buyerId]);
            $orderSeq = (int) $countStmt->fetchColumn() + 1;
            $orderCode = mt_rand(1000000000, 9999999999);
            $orderTotal = $productTotal + $shippingFee;

            $orderStmt = $pdo->prepare(
                "INSERT INTO orders(
                    buyer_id, total, payment_method, order_seq, order_code,
                    shipping_distance_km, shipping_area, shipping_fee
                ) VALUES(?, ?, ?, ?, ?, NULL, ?, ?)"
            );
            $orderStmt->execute([
                $buyerId,
                $orderTotal,
                $paymentMethod,
                $orderSeq,
                $orderCode,
                $shippingArea,
                $shippingFee,
            ]);
            $orderId = (int) $pdo->lastInsertId();

            $itemStmt = $pdo->prepare(
                "INSERT INTO order_items(order_id, product_id, product_name, qty, price, subtotal)
                 VALUES(?, ?, ?, ?, ?, ?)"
            );
            foreach ($items as $item) {
                $product = $item["product"];
                $itemStmt->execute([
                    $orderId,
                    $product["id"],
                    $product["name"],
                    $item["qty"],
                    $product["price"],
                    $item["subtotal"],
                ]);
            }

            $pdo->commit();

            if (($draft["source"] ?? "") === "cart") {
                foreach ($requestedQuantities as $id => $quantity) {
                    $remaining = (int) ($_SESSION["buyer_cart"][$buyerId][$id] ?? 0) - $quantity;
                    if ($remaining > 0) {
                        $_SESSION["buyer_cart"][$buyerId][$id] = $remaining;
                    } else {
                        unset($_SESSION["buyer_cart"][$buyerId][$id]);
                    }
                }
                if (empty($_SESSION["buyer_cart"][$buyerId])) {
                    unset($_SESSION["buyer_cart"][$buyerId]);
                }
            }
            unset($_SESSION["buyer_checkout"][$buyerId]);

            header("Location: payment.php?id=" . $orderId);
            exit();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            flash("Gagal memproses pesanan. Silakan coba lagi.");
            header("Location: checkout.php");
            exit();
        }
    }

    $source = ($_POST["source"] ?? "") === "cart" ? "cart" : "direct";
    $returnUrl = $source === "cart" ? "cart.php" : "shop.php";
    $postedQuantities = $_POST["qty"] ?? [];
    if (!is_array($postedQuantities)) {
        $postedQuantities = [];
    }
    $requestedQuantities = [];

    if ($source === "cart") {
        $sessionCart = $_SESSION["buyer_cart"][$buyerId] ?? [];
        $selected = $_POST["selected"] ?? [];
        if (!is_array($sessionCart) || !is_array($selected)) {
            $sessionCart = [];
            $selected = [];
        }
        foreach ($selected as $selectedId) {
            if (!is_scalar($selectedId)) {
                flash("Pilihan produk di keranjang tidak valid.");
                header("Location: cart.php");
                exit();
            }
            $id = filter_var($selectedId, FILTER_VALIDATE_INT);
            $quantity = $id ? filter_var($postedQuantities[$id] ?? null, FILTER_VALIDATE_INT) : false;
            if (
                !$id ||
                $quantity === false ||
                $quantity < 1 ||
                $quantity > (int) ($sessionCart[$id] ?? 0)
            ) {
                flash("Pilihan atau jumlah produk di keranjang tidak valid.");
                header("Location: cart.php");
                exit();
            }
            $requestedQuantities[$id] = $quantity;
        }
    } else {
        $id = filter_var($_POST["buy_now"] ?? null, FILTER_VALIDATE_INT);
        $quantity = $id ? filter_var($postedQuantities[$id] ?? null, FILTER_VALIDATE_INT) : false;
        if (!$id || $quantity === false || $quantity < 1) {
            flash("Pilih jumlah produk yang valid sebelum checkout.");
            header("Location: shop.php");
            exit();
        }
        $requestedQuantities[$id] = $quantity;
    }

    if (!$requestedQuantities) {
        flash("Pilih minimal satu produk untuk checkout.");
        header("Location: " . $returnUrl);
        exit();
    }

    $ids = array_keys($requestedQuantities);
    $placeholders = implode(",", array_fill(0, count($ids), "?"));
    $productStmt = $pdo->prepare(
        "SELECT id, active, stock FROM products WHERE id IN ($placeholders)"
    );
    $productStmt->execute($ids);
    $productsById = [];
    foreach ($productStmt->fetchAll() as $product) {
        $productsById[(int) $product["id"]] = $product;
    }
    foreach ($requestedQuantities as $id => $quantity) {
        $product = $productsById[$id] ?? null;
        if (!$product || !(bool) $product["active"] || $quantity > (int) $product["stock"]) {
            flash("Stok produk berubah atau tidak mencukupi. Silakan periksa kembali.");
            header("Location: " . $returnUrl);
            exit();
        }
    }

    $_SESSION["buyer_checkout"][$buyerId] = [
        "source" => $source,
        "items" => $requestedQuantities,
    ];
    header("Location: checkout.php");
    exit();
}

$draft = $_SESSION["buyer_checkout"][$buyerId] ?? null;
if (
    !is_array($draft) ||
    !in_array($draft["source"] ?? null, ["cart", "direct"], true) ||
    !is_array($draft["items"] ?? null) ||
    !$draft["items"]
) {
    header("Location: shop.php");
    exit();
}

$requestedQuantities = $draft["items"];
$ids = array_map("intval", array_keys($requestedQuantities));
$placeholders = implode(",", array_fill(0, count($ids), "?"));
$productStmt = $pdo->prepare(
    "SELECT * FROM products WHERE id IN ($placeholders) AND active = 1 ORDER BY name"
);
$productStmt->execute($ids);
$productsById = [];
foreach ($productStmt->fetchAll() as $product) {
    $productsById[(int) $product["id"]] = $product;
}

$items = [];
$productTotal = 0;
foreach ($requestedQuantities as $id => $quantity) {
    $id = (int) $id;
    $quantity = (int) $quantity;
    $product = $productsById[$id] ?? null;
    if (!$product || $quantity < 1 || $quantity > (int) $product["stock"]) {
        unset($_SESSION["buyer_checkout"][$buyerId]);
        flash("Stok produk berubah atau tidak mencukupi. Silakan periksa kembali.");
        header("Location: " . ($draft["source"] === "cart" ? "cart.php" : "shop.php"));
        exit();
    }
    $subtotal = $quantity * (float) $product["price"];
    $items[] = [
        "product" => $product,
        "qty" => $quantity,
        "subtotal" => $subtotal,
    ];
    $productTotal += $subtotal;
}

$buyerStmt = $pdo->prepare(
    "SELECT name, phone, address, province, city, district, village
     FROM users WHERE id = ? LIMIT 1"
);
$buyerStmt->execute([$buyerId]);
$buyerAddress = $buyerStmt->fetch();
if (!$buyerAddress) {
    http_response_code(400);
    exit("Alamat akun tidak ditemukan.");
}

$shippingArea = null;
$shippingFee = 0;
$addressErrors = [];
try {
    $shippingArea = shipping_area_for_address($buyerAddress);
    $shippingFee = calculate_shipping_fee($shippingArea);
} catch (InvalidArgumentException $e) {
    $addressErrors[] = $e->getMessage();
}
if (trim((string) ($buyerAddress["village"] ?? "")) === "") {
    $addressErrors[] = "Lengkapi kelurahan/desa pada alamat akun.";
}
if (trim((string) ($buyerAddress["address"] ?? "")) === "") {
    $addressErrors[] = "Lengkapi alamat jalan pada alamat akun.";
}
$addressComplete = !$addressErrors;
$codAvailable = $shippingArea === "free_zone";
$orderTotal = $productTotal + $shippingFee;
?><!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Checkout - Basreng Store</title>
    <base href="<?= e(app_url('pages/buyer/')) ?>">
    <link rel="icon" type="image/svg+xml" href="<?= e(app_favicon_url()) ?>">
    <link rel="stylesheet" href="<?= e(app_url('assets/css/style.css')) ?>?v=<?= (int) filemtime(app_path('assets/css/style.css')) ?>">
    <style>
        .checkout-page-header { display:flex; align-items:center; justify-content:space-between; gap:16px; margin-bottom:18px; }
        .checkout-title { margin:0; }
        .checkout-back { display:inline-flex; align-items:center; justify-content:center; padding:10px 24px; border:1px solid #cbd5e1; border-radius:12px; background:#fff; color:#334155; font-weight:600; text-decoration:none; white-space:nowrap; }
        .checkout-back:hover { background:#f8fafc; color:#1e293b; }
        .checkout-section { margin-bottom:16px; padding:24px; border-radius:14px; }
        .checkout-section h2 { margin:0 0 18px; font-size:18px; }
        .address-section { border-top:4px solid #2563eb; }
        .address-heading { display:flex; align-items:center; gap:9px; margin-bottom:15px; color:#2563eb; font-size:17px; font-weight:700; }
        .address-content { display:grid; grid-template-columns:220px 1fr auto; align-items:start; gap:18px; }
        .address-contact { color:#0f172a; font-weight:700; }
        .address-text { color:#334155; line-height:1.6; }
        .address-edit { color:#2563eb; font-weight:600; text-decoration:none; }
        .checkout-address-error { margin-top:17px; }
        .checkout-product-head, .checkout-product-row { display:grid; grid-template-columns:minmax(260px, 1fr) 150px 100px 150px; align-items:center; gap:12px; }
        .checkout-product-head { padding:0 0 14px; color:#64748b; font-size:12px; font-weight:700; }
        .checkout-product-row { padding:16px 0; border-top:1px solid #edf2f7; }
        .checkout-product-info { display:flex; align-items:center; gap:13px; min-width:0; }
        .checkout-product-image { display:grid; place-items:center; width:68px; height:68px; flex:none; overflow:hidden; border-radius:10px; background:#eff6ff; color:#2563eb; font-size:26px; }
        .checkout-product-image img { width:100%; height:100%; object-fit:contain; }
        .checkout-product-name { display:block; color:#0f172a; font-weight:700; }
        .checkout-product-description { display:block; margin-top:4px; color:#64748b; font-size:12px; }
        .checkout-cell { color:#334155; }
        .checkout-subtotal { color:#2563eb; font-weight:700; text-align:right; }
        .checkout-payment-options { display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:12px; }
        .checkout-payment-option { display:flex; align-items:center; gap:10px; min-height:58px; padding:14px; border:1px solid #dbe3ee; border-radius:10px; cursor:pointer; font-weight:600; }
        .checkout-payment-option:has(input:checked) { border-color:#2563eb; background:#eff6ff; color:#1d4ed8; }
        .checkout-payment-option input { width:18px; height:18px; margin:0; accent-color:#2563eb; }
        .checkout-total { margin-top:18px; padding-top:16px; border-top:1px solid #e2e8f0; }
        .checkout-total-row { display:flex; justify-content:space-between; gap:16px; margin:8px 0; color:#475569; }
        .checkout-grand-total { padding-top:10px; color:#0f172a; font-size:17px; font-weight:700; }
        .checkout-grand-total strong { color:#2563eb; font-size:22px; }
        .checkout-actions { display:flex; justify-content:flex-end; align-items:center; margin-top:20px; }
        .checkout-submit { min-height:48px; padding:12px 22px; border:0; border-radius:9px; background:#2563eb; color:#fff; font-size:14px; font-weight:700; cursor:pointer; }
        .checkout-submit:hover { background:#1d4ed8; }
        .checkout-submit:disabled { background:#cbd5e1; cursor:not-allowed; }
        @media (max-width:760px) {
            .checkout-section { padding:18px; }
            .address-content { grid-template-columns:1fr auto; gap:8px 14px; }
            .address-text { grid-column:1 / -1; grid-row:2; }
            .checkout-product-head { display:none; }
            .checkout-product-row { grid-template-columns:1fr auto; gap:10px; }
            .checkout-product-info { grid-column:1 / -1; }
            .checkout-cell { padding-left:81px; }
            .checkout-subtotal { padding-left:0; }
            .checkout-actions { align-items:stretch; }
            .checkout-submit { width:100%; }
        }
    </style>
</head>
<body>
<?php include dirname(__DIR__, 2) . "/includes/partials/buyer_nav.php"; ?>
<main>
    <?php show_flash(); ?>
    <div class="checkout-page-header">
        <h1 class="checkout-title">Checkout</h1>
        <a class="checkout-back" href="<?= e(app_url($draft["source"] === "cart" ? "pages/buyer/cart.php" : "pages/buyer/shop.php")) ?>">← Kembali</a>
    </div>

    <section class="panel checkout-section address-section">
        <div class="address-heading"><span aria-hidden="true">📍</span> Alamat Pengiriman</div>
        <div class="address-content">
            <div class="address-contact">
                <?= e($buyerAddress["name"]) ?><br>
                <?= e($buyerAddress["phone"] ?: "Nomor telepon belum diisi") ?>
            </div>
            <div class="address-text">
                <?= e($buyerAddress["address"] ?: "Alamat jalan belum diisi") ?>,
                <?= e($buyerAddress["village"] ?: "Kelurahan/desa belum dipilih") ?>,
                <?= e($buyerAddress["district"] ?: "Kecamatan belum dipilih") ?>,
                <?= e($buyerAddress["city"] ?: "Kota/kabupaten belum dipilih") ?>,
                <?= e($buyerAddress["province"] ?: "Provinsi belum dipilih") ?>
            </div>
            <a class="address-edit" href="<?= e(app_url("pages/auth/profile.php?section=address")) ?>">Ubah</a>
        </div>
        <?php if ($addressErrors): ?>
            <div class="alert checkout-address-error" role="status">
                Alamat belum lengkap untuk membuat pesanan:
                <?= e(implode(" ", $addressErrors)) ?>
                <a href="<?= e(app_url("pages/auth/profile.php?section=address")) ?>">Lengkapi alamat</a>
            </div>
        <?php endif; ?>
    </section>

    <section class="panel checkout-section">
        <h2>Produk Dipesan</h2>
        <div class="checkout-product-head">
            <span>Produk</span><span>Harga Satuan</span><span>Jumlah</span><span style="text-align:right;">Subtotal Produk</span>
        </div>
        <?php foreach ($items as $item): ?>
            <div class="checkout-product-row">
                <div class="checkout-product-info">
                    <div class="checkout-product-image">
                        <?php if (!empty($item["product"]["image"])): ?>
                            <img src="<?= e(app_url($item["product"]["image"])) ?>" alt="<?= e($item["product"]["name"]) ?>">
                        <?php else: ?>
                            🌶️
                        <?php endif; ?>
                    </div>
                    <div>
                        <span class="checkout-product-name"><?= e($item["product"]["name"]) ?></span>
                        <?php if (!empty($item["product"]["description"])): ?>
                            <span class="checkout-product-description"><?= e($item["product"]["description"]) ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <span class="checkout-cell"><?= rupiah($item["product"]["price"]) ?></span>
                <span class="checkout-cell"><?= (int) $item["qty"] ?></span>
                <span class="checkout-subtotal"><?= rupiah($item["subtotal"]) ?></span>
            </div>
        <?php endforeach; ?>
    </section>

    <form method="post" action="checkout.php">
        <input type="hidden" name="csrf" value="<?= csrf() ?>">
        <input type="hidden" name="intent" value="place_order">
        <section class="panel checkout-section">
            <h2>Pilih Metode Pembayaran</h2>
            <div class="checkout-payment-options">
                <label class="checkout-payment-option">
                    <input type="radio" name="payment_method" value="qris" required>
                    <span>QRIS</span>
                </label>
                <label class="checkout-payment-option">
                    <input type="radio" name="payment_method" value="bca" required>
                    <span>Transfer Bank BCA</span>
                </label>
                <?php if ($codAvailable): ?>
                    <label class="checkout-payment-option">
                        <input type="radio" name="payment_method" value="cod" required>
                        <span>COD (Bayar di Tempat)</span>
                    </label>
                <?php endif; ?>
            </div>

            <div class="checkout-total">
                <div class="checkout-total-row">
                    <span>Subtotal produk (<?= count($items) ?> jenis)</span>
                    <strong><?= rupiah($productTotal) ?></strong>
                </div>
                <div class="checkout-total-row">
                    <span>Ongkir</span>
                    <strong>
                        <?php if ($shippingArea === "free_zone"): ?>
                            Gratis
                        <?php elseif ($shippingArea !== null): ?>
                            <?= rupiah($shippingFee) ?>
                        <?php else: ?>
                            Menunggu alamat lengkap
                        <?php endif; ?>
                    </strong>
                </div>
                <div class="checkout-total-row checkout-grand-total">
                    <span><?= $addressComplete ? "Total Pembayaran" : "Total sementara" ?></span>
                    <strong><?= rupiah($orderTotal) ?></strong>
                </div>
                <?php if (!$addressComplete): ?>
                    <div class="muted" style="text-align:right; font-size:12px;">Total akhir dihitung setelah alamat lengkap.</div>
                <?php endif; ?>
            </div>

            <div class="checkout-actions">
                <button class="checkout-submit" type="submit" <?= $addressComplete ? "" : "disabled" ?>>
                    Buat Pesanan &amp; Lanjut Pembayaran
                </button>
            </div>
        </section>
    </form>
</main>
</body>
</html>
