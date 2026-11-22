<?php
require dirname(__DIR__, 2) . "/includes/config.php";
require_role("cashier");

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    check_csrf();

    if (($_POST["action"] ?? "") !== "complete_sale") {
        flash("Aksi transaksi tidak valid.");
        header("Location: shop.php");
        exit();
    }

    $customerNameInput = $_POST["customer_name"] ?? "";
    $paymentMethod = $_POST["payment_method"] ?? "";
    $postedQuantities = $_POST["qty"] ?? [];
    if (
        !is_string($customerNameInput) ||
        !is_string($paymentMethod) ||
        !in_array($paymentMethod, ["cash", "qris", "bca"], true) ||
        !is_array($postedQuantities)
    ) {
        flash("Data transaksi tidak valid. Silakan periksa kembali.");
        header("Location: shop.php");
        exit();
    }

    $customerName = trim($customerNameInput);
    if ($customerName !== "" && preg_match('/\A.{1,120}\z/us', $customerName) !== 1) {
        flash("Nama pelanggan maksimal 120 karakter.");
        header("Location: shop.php");
        exit();
    }

    $proofTmpName = null;
    $proofExtension = null;
    $proofUploadDir = app_path("uploads");
    $proofFullPath = null;
    $proofWasMoved = false;

    try {
        $requestedQuantities = [];
        foreach ($postedQuantities as $postedProductId => $postedQuantity) {
            $productId = filter_var((string) $postedProductId, FILTER_VALIDATE_INT);
            $quantity = is_string($postedQuantity) || is_int($postedQuantity)
                ? filter_var($postedQuantity, FILTER_VALIDATE_INT)
                : false;
            if ($productId === false || $productId < 1 || $quantity === false || $quantity < 0) {
                throw new RuntimeException("Jumlah produk tidak valid.");
            }
            if ($quantity > 0) {
                $requestedQuantities[$productId] = $quantity;
            }
        }
        if (!$requestedQuantities) {
            throw new RuntimeException("Pilih minimal satu produk.");
        }

        if ($paymentMethod !== "cash") {
            $proofUpload = $_FILES["proof"] ?? null;
            if (!is_array($proofUpload) || ($proofUpload["error"] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                throw new RuntimeException(
                    "Pilih foto bukti pembayaran QRIS/Transfer yang valid (PNG, JPG, atau WEBP, maksimal 3 MB)."
                );
            }

            $proofTmpName = $proofUpload["tmp_name"] ?? null;
            if (!is_string($proofTmpName) || !is_uploaded_file($proofTmpName)) {
                throw new RuntimeException("File bukti pembayaran tidak valid.");
            }

            $proofSize = filesize($proofTmpName);
            if ($proofSize === false || $proofSize < 1 || $proofSize > 3 * 1024 * 1024) {
                throw new RuntimeException("Ukuran foto bukti pembayaran maksimal 3 MB.");
            }

            $allowedProofTypes = [
                "image/jpeg" => "jpg",
                "image/png" => "png",
                "image/webp" => "webp",
            ];
            $fileInfo = new finfo(FILEINFO_MIME_TYPE);
            $proofMimeType = $fileInfo->file($proofTmpName);
            if (!is_string($proofMimeType) || !isset($allowedProofTypes[$proofMimeType])) {
                throw new RuntimeException("Format foto harus PNG, JPG, atau WEBP.");
            }
            $proofImageInfo = getimagesize($proofTmpName);
            if ($proofImageInfo === false || ($proofImageInfo["mime"] ?? null) !== $proofMimeType) {
                throw new RuntimeException("File yang diunggah bukan foto yang valid.");
            }
            $proofExtension = $allowedProofTypes[$proofMimeType];

            if (!is_dir($proofUploadDir) && !mkdir($proofUploadDir, 0755, true) && !is_dir($proofUploadDir)) {
                throw new RuntimeException("Folder upload tidak tersedia. Silakan coba lagi.");
            }
            if (!is_writable($proofUploadDir)) {
                throw new RuntimeException("Folder upload tidak dapat ditulisi. Silakan hubungi Owner/Admin.");
            }
        }

        $pdo->beginTransaction();
        $items = [];
        $total = 0;
        $productStmt = $pdo->prepare(
            "SELECT id, name, price, stock FROM products WHERE id = ? AND active = 1 FOR UPDATE"
        );
        foreach ($requestedQuantities as $productId => $quantity) {
            $productStmt->execute([$productId]);
            $product = $productStmt->fetch();
            if (!$product || $quantity > (int) $product["stock"]) {
                throw new RuntimeException("Stok produk tidak mencukupi. Silakan periksa kembali.");
            }
            if ((float) $product["price"] < 0) {
                throw new RuntimeException("Harga produk tidak valid.");
            }

            $subtotal = $quantity * (float) $product["price"];
            $items[] = [
                "product" => $product,
                "quantity" => $quantity,
                "subtotal" => $subtotal,
            ];
            $total += $subtotal;
        }

        $orderCode = mt_rand(1000000000, 9999999999);
        $paymentStatus = $paymentMethod === "cash" ? "paid" : "awaiting_seller";
        $insertOrder = $pdo->prepare(
            "INSERT INTO orders
             (buyer_id, customer_name, customer_phone, total, payment_method, order_seq, order_code,
              status, payment_status, stock_deducted, shipping_area, shipping_fee)
             VALUES (NULL, ?, NULL, ?, ?, 1, ?, 'completed', ?, 1, 'pickup', 0)"
        );
        $insertOrder->execute([
            $customerName !== "" ? $customerName : "Pelanggan Umum",
            $total,
            $paymentMethod,
            $orderCode,
            $paymentStatus,
        ]);
        $orderId = (int) $pdo->lastInsertId();

        if ($proofTmpName !== null && $proofExtension !== null) {
            do {
                $proofFileName = "proof_cashier_" . $orderId . "_" . time() . "_" .
                    mt_rand(100000, 999999) . "." . $proofExtension;
                $proofFullPath = $proofUploadDir . DIRECTORY_SEPARATOR . $proofFileName;
            } while (file_exists($proofFullPath));

            if (!move_uploaded_file($proofTmpName, $proofFullPath)) {
                throw new RuntimeException("Foto bukti pembayaran gagal disimpan. Silakan coba lagi.");
            }
            $proofWasMoved = true;
            $proofPath = "uploads/" . $proofFileName;
            $updateProof = $pdo->prepare("UPDATE orders SET payment_proof = ? WHERE id = ?");
            $updateProof->execute([$proofPath, $orderId]);
        }

        $insertItem = $pdo->prepare(
            "INSERT INTO order_items (order_id, product_id, product_name, qty, price, subtotal)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        $deductStock = $pdo->prepare(
            "UPDATE products SET stock = stock - ? WHERE id = ? AND stock >= ?"
        );
        foreach ($items as $item) {
            $product = $item["product"];
            $insertItem->execute([
                $orderId,
                $product["id"],
                $product["name"],
                $item["quantity"],
                $product["price"],
                $item["subtotal"],
            ]);
            $deductStock->execute([
                $item["quantity"],
                $product["id"],
                $item["quantity"],
            ]);
            if ($deductStock->rowCount() !== 1) {
                throw new RuntimeException("Stok produk berubah. Silakan ulangi transaksi.");
            }
        }

        $pdo->commit();
        $successMessage = $paymentMethod === "cash"
            ? "Transaksi berhasil dan pembayaran tunai lunas. Nomor pesanan #" . $orderCode . "."
            : "Bukti pembayaran berhasil diunggah. Pesanan #" . $orderCode . " menunggu verifikasi Owner/Admin.";
        flash($successMessage, "success");
    } catch (RuntimeException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($proofWasMoved && $proofFullPath !== null && is_file($proofFullPath) && !unlink($proofFullPath)) {
            error_log("Gagal membersihkan foto bukti pembayaran sementara: " . $proofFullPath);
        }
        flash($e->getMessage());
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($proofWasMoved && $proofFullPath !== null && is_file($proofFullPath) && !unlink($proofFullPath)) {
            error_log("Gagal membersihkan foto bukti pembayaran sementara: " . $proofFullPath);
        }
        error_log("Gagal menyimpan transaksi kasir: " . $e->getMessage());
        flash("Transaksi gagal disimpan. Silakan coba lagi.");
    }

    header("Location: shop.php");
    exit();
}

$products = $pdo->query(
    "SELECT * FROM products WHERE active = 1 AND stock > 0 ORDER BY name"
)->fetchAll();
?><!doctype html>
<html lang="<?= e(app_language()) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= app_language() === "en" ? "Cashier" : "Kasir" ?> - BasrengQyuu Store</title>
    <link rel="icon" type="image/svg+xml" href="<?= e(app_favicon_url()) ?>">
    <link rel="stylesheet" href="<?= e(app_url('assets/css/style.css')) ?>?v=<?= (int) filemtime(app_path('assets/css/style.css')) ?>">
    <style>
        .cashier-page-header { margin-bottom: 20px; }
        .cashier-page-header h1 { margin: 0 0 5px; }
        .cashier-sale-panel { display: grid; grid-template-columns: minmax(0, 1fr) minmax(180px, auto) auto auto; align-items: end; gap: 16px; margin-bottom: 20px; padding: 18px; border: 1px solid #dbeafe; border-radius: 14px; background: #eff6ff; }
        .cashier-customer-field { min-width: 0; }
        .cashier-customer-field label { display: block; margin-bottom: 6px; color: #334155; font-size: 13px; font-weight: 600; }
        .cashier-customer-field input { width: 100%; margin: 0; }
        .cashier-payment-field { min-width: 180px; }
        .cashier-payment-field label { display: block; margin-bottom: 6px; color: #334155; font-size: 13px; font-weight: 600; }
        .cashier-payment-field select { width: 100%; margin: 0; }
        .cashier-step-error { grid-column: 1 / -1; margin: 0; color: #b91c1c !important; }
        .cashier-proof-step { margin: 0 0 20px; padding: 18px; border: 1px solid #e2e8f0; border-radius: 14px; background: #fff; }
        .cashier-sale-panel [hidden], .cashier-proof-step[hidden], .cashier-proof-step [hidden] { display: none !important; }
        .cashier-proof-step h2 { margin: 0 0 6px; font-size: 18px; }
        .cashier-proof-step p { margin: 0 0 12px; color: #64748b; font-size: 13px; }
        .cashier-proof-step label { display: block; margin-bottom: 6px; color: #334155; font-size: 13px; font-weight: 600; }
        .cashier-proof-step input[type="file"] { width: 100%; margin: 0 0 16px; padding: 10px; border: 1px solid #cbd5e1; border-radius: 8px; background: #f8fafc; }
        .cashier-proof-submit { margin: 0; }
        .cashier-total { display: flex; flex-direction: column; gap: 4px; white-space: nowrap; }
        .cashier-total span { color: #475569; font-size: 13px; }
        .cashier-total strong { color: #1d4ed8; font-size: 22px; }
        .cashier-sale-panel button { margin: 0; white-space: nowrap; }
        .cashier-product-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(230px, 1fr)); gap: 16px; }
        .cashier-product-card { display: flex; min-width: 0; flex-direction: column; justify-content: space-between; padding: 14px; border: 1px solid #e2e8f0; border-radius: 14px; background: #fff; box-shadow: 0 5px 16px rgba(15, 23, 42, .05); }
        .cashier-product-image { display: grid; height: 150px; place-items: center; overflow: hidden; margin-bottom: 12px; border-radius: 10px; background: linear-gradient(135deg, #eff6ff, #dbeafe); }
        .cashier-product-image img { width: 100%; height: 100%; object-fit: cover; }
        .cashier-product-image span { font-size: 36px; }
        .cashier-product-card h2 { margin: 0 0 5px; color: #0f172a; font-size: 16px; line-height: 1.35; }
        .cashier-product-card p { min-height: 32px; margin: 0 0 12px; color: #64748b; font-size: 13px; line-height: 1.4; }
        .cashier-product-price { margin-bottom: 2px; color: #2563eb; font-size: 19px; font-weight: 700; }
        .cashier-product-stock { margin-bottom: 12px; color: #64748b; font-size: 12px; }
        .cashier-qty-row { display: flex; align-items: center; justify-content: space-between; gap: 8px; padding: 9px 10px; border: 1px solid #e2e8f0; border-radius: 10px; }
        .cashier-qty-row label { color: #475569; font-size: 12px; font-weight: 600; }
        .qty-control { display: flex; align-items: center; gap: 5px; }
        .qty-step { display: inline-grid; width: 34px; height: 36px; place-items: center; padding: 0; border: 1px solid #93c5fd; border-radius: 6px; background: #eff6ff; color: #2563eb; font-size: 19px; font-weight: 700; line-height: 1; cursor: pointer; }
        .qty-step:disabled { opacity: .45; cursor: not-allowed; }
        .cashier-qty { width: 52px; height: 36px; margin: 0; padding: 6px 8px; border: 1px solid #cbd5e1; border-radius: 6px; background: #fff; font-size: 14px; text-align: center; -moz-appearance: textfield; }
        .cashier-qty::-webkit-inner-spin-button, .cashier-qty::-webkit-outer-spin-button { margin: 0; -webkit-appearance: none; }
        @media (max-width: 900px) {
            .cashier-sale-panel { grid-template-columns: minmax(0, 1fr) auto; }
            .cashier-customer-field { grid-column: 1; }
            .cashier-payment-field { grid-column: 2; }
            .cashier-total { grid-column: 1; }
            .cashier-sale-panel button { grid-column: 2; width: 100%; }
        }
        @media (max-width: 560px) {
            .cashier-sale-panel { grid-template-columns: minmax(0, 1fr); }
            .cashier-customer-field, .cashier-payment-field, .cashier-total, .cashier-sale-panel button { grid-column: 1; }
            .cashier-sale-panel button { width: 100%; }
            .cashier-product-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; }
            .cashier-product-card { padding: 10px; border-radius: 12px; }
            .cashier-product-image { height: 120px; }
            .cashier-product-card h2 { font-size: 14px; }
            .cashier-product-price { font-size: 16px; }
            .cashier-qty-row { align-items: stretch; flex-direction: column; gap: 5px; padding: 8px; }
            .qty-control { justify-content: space-between; gap: 3px; }
            .cashier-qty { flex: 1; width: auto; }
            .qty-step { width: 30px; height: 34px; }
            .cashier-total strong { font-size: 19px; }
        }
    </style>
</head>
<body>
<?php include dirname(__DIR__, 2) . "/includes/partials/cashier_nav.php"; ?>
<main>
    <div class="cashier-page-header">
        <h1><?= app_language() === "en" ? "Cashier" : "Kasir" ?></h1>
    </div>

    <?php show_flash(); ?>

    <?php if ($products): ?>
        <form method="post" enctype="multipart/form-data" action="<?= e(app_url('pages/cashier/shop.php')) ?>">
            <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
            <div class="cashier-sale-panel">
                <div class="cashier-customer-field">
                    <label for="customer-name"><?= app_language() === "en" ? "Customer name (optional)" : "Nama pelanggan (opsional)" ?></label>
                    <input id="customer-name" name="customer_name" type="text" maxlength="120" autocomplete="off">
                </div>
                <div class="cashier-payment-field">
                    <label for="payment-method"><?= app_language() === "en" ? "Payment method" : "Metode pembayaran" ?></label>
                    <select id="payment-method" name="payment_method" required>
                        <option value="cash"><?= app_language() === "en" ? "Cash" : "Tunai" ?></option>
                        <option value="qris">QRIS</option>
                        <option value="bca"><?= app_language() === "en" ? "BCA transfer" : "Transfer BCA" ?></option>
                    </select>
                </div>
                <div class="cashier-total">
                    <span><?= app_language() === "en" ? "Sale total" : "Total penjualan" ?></span>
                    <strong id="cashier-total">Rp0</strong>
                </div>
                <button id="cashier-next" class="btn" type="button" hidden>
                    <?= app_language() === "en" ? "Next to upload proof" : "Lanjut upload bukti" ?>
                </button>
                <button id="cashier-cash-submit" class="btn" type="submit" name="action" value="complete_sale">
                    <?= app_language() === "en" ? "Complete sale" : "Selesaikan Penjualan" ?>
                </button>
                <p id="cashier-step-error" class="cashier-step-error" role="alert" hidden></p>
            </div>

            <section id="cashier-proof-step" class="cashier-proof-step" aria-labelledby="cashier-proof-heading" hidden>
                <h2 id="cashier-proof-heading"><?= app_language() === "en" ? "Upload payment proof" : "Upload bukti pembayaran" ?></h2>
                <p><?= app_language() === "en" ? "Choose a photo or screenshot of the QRIS payment or BCA transfer (PNG, JPG, or WEBP, max. 3 MB)." : "Pilih foto atau screenshot pembayaran QRIS atau Transfer BCA (PNG, JPG, atau WEBP, maksimal 3 MB)." ?></p>
                <label for="payment-proof"><?= app_language() === "en" ? "Payment proof photo" : "Foto bukti pembayaran" ?></label>
                <input id="payment-proof" name="proof" type="file" accept="image/png,image/jpeg,image/webp">
                <button id="cashier-proof-submit" class="btn cashier-proof-submit" type="submit" name="action" value="complete_sale">
                    <?= app_language() === "en" ? "Complete sale" : "Selesaikan Penjualan" ?>
                </button>
            </section>

            <div class="cashier-product-grid">
                <?php foreach ($products as $product): ?>
                    <article class="cashier-product-card">
                        <div>
                            <div class="cashier-product-image">
                                <?php if (!empty($product["image"])): ?>
                                    <img src="<?= e(app_url($product["image"])) ?>" alt="<?= e($product["name"]) ?>">
                                <?php else: ?>
                                    <span aria-hidden="true">🌶️</span>
                                <?php endif; ?>
                            </div>
                            <h2><?= e($product["name"]) ?></h2>
                            <p><?= e($product["description"] ?: (app_language() === "en" ? "Crunchy and savory snack." : "Basreng renyah dan gurih.")) ?></p>
                        </div>
                        <div>
                            <div class="cashier-product-price"><?= rupiah($product["price"]) ?></div>
                            <div class="cashier-product-stock">
                                <?= app_language() === "en" ? "Available stock:" : "Stok tersedia:" ?>
                                <strong><?= (int) $product["stock"] ?></strong>
                            </div>
                            <div class="cashier-qty-row">
                                <label for="qty-<?= (int) $product["id"] ?>"><?= app_language() === "en" ? "Quantity" : "Jumlah" ?></label>
                                <div class="qty-control">
                                    <button type="button" class="qty-step" data-qty-step="-1" aria-label="<?= app_language() === "en" ? "Decrease quantity" : "Kurangi jumlah" ?>" disabled>-</button>
                                    <input
                                        class="cashier-qty"
                                        id="qty-<?= (int) $product["id"] ?>"
                                        type="number"
                                        name="qty[<?= (int) $product["id"] ?>]"
                                        min="0"
                                        max="<?= (int) $product["stock"] ?>"
                                        step="1"
                                        value="0"
                                        data-price="<?= e($product["price"]) ?>"
                                    >
                                    <button type="button" class="qty-step" data-qty-step="1" aria-label="<?= app_language() === "en" ? "Increase quantity" : "Tambah jumlah" ?>">+</button>
                                </div>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </form>
    <?php else: ?>
        <div class="panel" style="text-align: center; padding: 40px; border-radius: 12px;">
            <p class="muted" style="margin: 0;"><?= app_language() === "en" ? "No products are currently in stock." : "Belum ada produk dengan stok tersedia." ?></p>
        </div>
    <?php endif; ?>
</main>
<script src="<?= e(app_url('assets/js/shop.js')) ?>" defer></script>
<script>
    (function () {
        var totalElement = document.getElementById("cashier-total");
        var quantityInputs = document.querySelectorAll(".cashier-qty");
        var paymentMethod = document.getElementById("payment-method");
        var proofStep = document.getElementById("cashier-proof-step");
        var proofInput = document.getElementById("payment-proof");
        var nextButton = document.getElementById("cashier-next");
        var cashSubmitButton = document.getElementById("cashier-cash-submit");
        var proofSubmitButton = document.getElementById("cashier-proof-submit");
        var stepError = document.getElementById("cashier-step-error");
        if (!totalElement || !paymentMethod || !proofStep || !proofInput) {
            return;
        }

        var formatter = new Intl.NumberFormat(<?= json_encode(app_language() === "en" ? "en-US" : "id-ID") ?>, {
            style: "currency",
            currency: "IDR",
            maximumFractionDigits: 0
        });

        function updateTotal() {
            var total = 0;
            quantityInputs.forEach(function (input) {
                total += Number(input.value || 0) * Number(input.dataset.price || 0);
            });
            totalElement.textContent = formatter.format(total);
        }

        function resetProofStep() {
            proofStep.hidden = true;
            proofInput.disabled = true;
            proofInput.required = false;
            proofInput.value = "";
            nextButton.hidden = paymentMethod.value === "cash";
            cashSubmitButton.hidden = paymentMethod.value !== "cash";
            proofSubmitButton.hidden = true;
            stepError.hidden = true;
        }

        paymentMethod.addEventListener("change", resetProofStep);
        nextButton.addEventListener("click", function () {
            var hasSelectedProduct = Array.prototype.some.call(quantityInputs, function (input) {
                return Number(input.value || 0) > 0;
            });
            if (!hasSelectedProduct) {
                stepError.textContent = <?= json_encode(app_language() === "en" ? "Select at least one product first." : "Pilih minimal satu produk terlebih dahulu.") ?>;
                stepError.hidden = false;
                return;
            }

            proofStep.hidden = false;
            proofInput.disabled = false;
            proofInput.required = true;
            nextButton.hidden = true;
            cashSubmitButton.hidden = true;
            proofSubmitButton.hidden = false;
            stepError.hidden = true;
            proofInput.focus();
        });

        quantityInputs.forEach(function (input) {
            input.addEventListener("input", function () {
                updateTotal();
                if (!proofStep.hidden) {
                    resetProofStep();
                    stepError.textContent = <?= json_encode(app_language() === "en" ? "The product quantities changed. Continue again to upload the matching proof." : "Jumlah produk berubah. Lanjut kembali untuk mengunggah bukti pembayaran yang sesuai.") ?>;
                    stepError.hidden = false;
                }
            });
            input.addEventListener("change", updateTotal);
        });
        proofInput.addEventListener("change", function () {
            stepError.hidden = true;
        });
        resetProofStep();
        updateTotal();
    }());
</script>
</body>
</html>
