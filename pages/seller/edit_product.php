<?php
require dirname(__DIR__, 2) . "/includes/config.php";
require_role("seller");

$id = (int) ($_GET["id"] ?? 0);
$stmt = $pdo->prepare("SELECT * FROM products WHERE id = ?");
$stmt->execute([$id]);
$p = $stmt->fetch();

if (!$p) {
    http_response_code(404);
    exit("Produk tidak ditemukan");
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    check_csrf();
    $name = trim($_POST["name"] ?? "");
    $desc = trim($_POST["description"] ?? "");
    $price = (float) ($_POST["price"] ?? 0);
    $stock = (int) ($_POST["stock"] ?? 0);
    $imagePath = $p["image"]; // Pertahankan gambar lama jika tidak diunggah baru

    // Handle upload foto baru jika ada
    if (!empty($_FILES["image"]["name"])) {
        $ext = strtolower(
            pathinfo($_FILES["image"]["name"], PATHINFO_EXTENSION)
        );
        if (in_array($ext, ["png", "jpg", "jpeg", "webp"])) {
            if ($_FILES["image"]["size"] <= 3 * 1024 * 1024) {
                $dir = app_path("uploads");
                if (!is_dir($dir)) {
                    mkdir($dir, 0755, true);
                }
                $fileName =
                    "product-" .
                    time() .
                    "-" .
                    mt_rand(1000, 9999) .
                    "." .
                    $ext;
                if (
                    move_uploaded_file(
                        $_FILES["image"]["tmp_name"],
                        $dir . "/" . $fileName
                    )
                ) {
                    // Hapus foto lama jika ada
                    if (
                        $p["image"] &&
                        file_exists(app_path($p["image"]))
                    ) {
                        @unlink(app_path($p["image"]));
                    }
                    $imagePath = "uploads/" . $fileName;
                }
            } else {
                flash("Ukuran gambar maksimal 3 MB.");
            }
        } else {
            flash("Format gambar harus PNG, JPG, atau WEBP.");
        }
    }

    if ($name !== "" && $price >= 0 && $stock >= 0) {
        $pdo->prepare(
            "UPDATE products SET name=?, description=?, price=?, stock=?, image=? WHERE id=?"
        )->execute([$name, $desc, $price, $stock, $imagePath, $id]);
        flash("Produk berhasil diperbarui.");
        header("Location: products.php");
        exit();
    }
}
?><!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Edit Produk</title>
    <base href="<?= e(app_url('pages/seller/')) ?>">
    <link rel="icon" type="image/svg+xml" href="<?= e(app_favicon_url()) ?>">
    <link rel="stylesheet" href="<?= e(app_url('assets/css/style.css')) ?>?v=<?= (int) filemtime(app_path('assets/css/style.css')) ?>">
</head>
<body>
<?php include dirname(__DIR__, 2) . "/includes/partials/seller_nav.php"; ?>
<main>
    <h1>Edit Produk</h1>
    <p class="muted">Perbarui informasi, harga, stok, dan foto produk.</p>
    <?php show_flash(); ?>

    <div class="panel" style="max-width: 600px;">
        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="csrf" value="<?= csrf() ?>">
            
            <label>Nama Produk</label>
            <input name="name" value="<?= e($p["name"]) ?>" required>
            
            <label>Harga Jual (Rp)</label>
            <input type="number" name="price" min="0" step="100" value="<?= $p[
                "price"
            ] ?>" required>
            
            <label>Stok</label>
            <input type="number" name="stock" min="0" value="<?= $p[
                "stock"
            ] ?>" required>
            
            <label>Deskripsi</label>
            <input name="description" value="<?= e($p["description"]) ?>">
            
            <label>Foto Saat Ini</label>
            <div style="margin: 8px 0;">
                <?php if ($p["image"]): ?>
                    <img src="<?= e(
                        app_url($p["image"])
                    ) ?>" style="width: 80px; height: 80px; object-fit: cover; border-radius: 6px; border: 1px solid #ddd;">
                <?php else: ?>
                    <span class="muted">Belum ada foto</span>
                <?php endif; ?>
            </div>

            <label>Ganti Foto Produk (Opsional)</label>
            <input type="file" name="image" accept="image/png,image/jpeg,image/webp">
            
            <div style="margin-top: 20px; display: flex; gap: 10px;">
                <button class="btn" style="margin-top: 0;">Simpan Perubahan</button>
                <a href="products.php" class="btn" style="background: #718096; text-decoration: none; margin-top: 0; display: inline-flex; align-items: center;">Kembali</a>
            </div>
        </form>
    </div>
</main>
</body>
</html>