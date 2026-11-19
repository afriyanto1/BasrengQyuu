<?php
require dirname(__DIR__, 2) . "/includes/config.php";
require_role("seller");
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    check_csrf();
    if (!empty($_FILES["qr"]["name"])) {
        $ext = strtolower(pathinfo($_FILES["qr"]["name"], PATHINFO_EXTENSION));
        if (!in_array($ext, ["png", "jpg", "jpeg", "webp"])) {
            flash("Format QR harus PNG, JPG, atau WEBP.");
        } elseif ($_FILES["qr"]["size"] > 3 * 1024 * 1024) {
            flash("Ukuran maksimal 3 MB.");
        } else {
            $dir = app_path("uploads");
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            $name = "seller-qr-" . time() . "." . $ext;
            move_uploaded_file($_FILES["qr"]["tmp_name"], $dir . "/" . $name);
            $pdo->prepare(
                "INSERT INTO settings(setting_key,setting_value) VALUES('seller_qr_path',?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)"
            )->execute(["uploads/" . $name]);
            flash("QR pembayaran berhasil diperbarui.");
        }
    }
    header("Location: settings.php");
    exit();
}
$qr = $pdo
    ->query(
        "SELECT setting_value FROM settings WHERE setting_key='seller_qr_path'"
    )
    ->fetchColumn();
?><!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Pembayaran</title><base href="<?= e(app_url('pages/seller/')) ?>">
    <link rel="icon" type="image/svg+xml" href="<?= e(app_favicon_url()) ?>">
    <link rel="stylesheet" href="<?= e(app_url('assets/css/style.css')) ?>?v=<?= (int) filemtime(app_path('assets/css/style.css')) ?>"></head><body><?php include dirname(__DIR__, 2) . "/includes/partials/seller_nav.php"; ?><main><h1>Pengaturan Pembayaran</h1><?php show_flash(); ?><div class="panel"><h2>QR Code / Barcode pembayaran penjual</h2><p>Unggah gambar QRIS atau barcode pembayaran yang diberikan bank/e-wallet Anda. Gambar akan ditampilkan kepada pembeli setelah checkout.</p><?php if (
    $qr
): ?><img class="qr-preview" src="<?= e(
    app_url($qr)
) ?>" alt="QR pembayaran penjual"><?php else: ?><p class="muted">Belum ada QR pembayaran diunggah.</p><?php endif; ?><form method="post" enctype="multipart/form-data"><input type="hidden" name="csrf" value="<?= csrf() ?>"><label>Pilih gambar QR (PNG/JPG/WEBP, maks. 3 MB)</label><input type="file" name="qr" accept="image/png,image/jpeg,image/webp" required><button class="btn">Simpan QR</button></form></div></main></body></html>
