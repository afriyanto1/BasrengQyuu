<?php
require dirname(__DIR__, 2) . "/includes/config.php";
require_role('seller');

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    flash('ID bahan baku tidak valid.', 'danger');
    header('Location: materials.php');
    exit;
}

// Ambil data bahan baku berdasarkan ID
$stmt = $pdo->prepare('SELECT * FROM materials WHERE id = ?');
$stmt->execute([$id]);
$material = $stmt->fetch();

if (!$material) {
    flash('Bahan baku tidak ditemukan.', 'danger');
    header('Location: materials.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    
    $name = trim($_POST['name'] ?? '');
    $unit = trim($_POST['unit'] ?? 'kg');
    
    // Membersihkan format ribuan (.) dan mengubah koma desimal (,) menjadi titik (.) agar valid disimpan ke database float
    $raw_cost = str_replace('.', '', $_POST['unit_cost'] ?? '0'); // Hapus titik ribuan
    $raw_cost = str_replace(',', '.', $raw_cost);                 // Ubah koma desimal jadi titik
    $unit_cost = (float)$raw_cost;

    if ($name !== '') {
        $stmt_update = $pdo->prepare('UPDATE materials SET name = ?, unit = ?, unit_cost = ? WHERE id = ?');
        $stmt_update->execute([$name, $unit, $unit_cost, $id]);
        
        flash('Bahan baku berhasil diperbarui.');
        header('Location: materials.php');
        exit;
    } else {
        flash('Nama bahan tidak boleh kosong.', 'danger');
    }
}
?><!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Edit Bahan Baku - Admin</title>
    <base href="<?= e(app_url('pages/seller/')) ?>">
    <link rel="icon" type="image/svg+xml" href="<?= e(app_favicon_url()) ?>">
    <link rel="stylesheet" href="<?= e(app_url('assets/css/style.css')) ?>?v=<?= (int) filemtime(app_path('assets/css/style.css')) ?>">
    <style>
        .form-card {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 32px;
            max-width: 900px;
            margin: 0 auto;
            box-shadow: 0 4px 12px rgba(0,0,0,0.03);
        }
        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
        }
        .form-group {
            margin-bottom: 20px;
        }
        .form-group.full {
            grid-column: 1 / -1;
        }
        .form-group label {
            font-weight: 600;
            font-size: 13px;
            color: #475569;
            display: block;
            margin-bottom: 6px;
        }
        .form-group input, .form-group select {
            width: 100%;
            padding: 11px 14px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            background: #fff;
            font-size: 14px;
            margin-top: 0;
        }
        .btn-primary {
            background: #2563eb;
            color: #fff;
            border: none;
            cursor: pointer;
        }
        .btn-primary:hover {
            background: #1d4ed8;
        }
        .btn-outline {
            background: #fff;
            border: 1px solid #cbd5e1;
            color: #334155;
            text-decoration: none;
        }
        .btn-outline:hover {
            background: #f1f5f9;
        }
    </style>
</head>
<body>
<?php include dirname(__DIR__, 2) . "/includes/partials/seller_nav.php";?>
<main>
    <?php show_flash();?>

    <div style="max-width: 900px; margin: 0 auto 20px auto; display: flex; justify-content: space-between; align-items: center;">
        <div>
            <h1 style="margin: 0 0 4px 0;">Edit Bahan Baku</h1>
            <p class="muted" style="margin: 0;">Perbarui informasi nama, satuan, atau harga per satuan bahan baku.</p>
        </div>
        <a href="materials.php" class="btn btn-outline" style="height: 38px; line-height: 36px; padding: 0 16px; border-radius: 8px;">← Kembali</a>
    </div>

    <div class="form-card">
        <form method="post">
            <input type="hidden" name="csrf" value="<?=csrf()?>">
            
            <div class="form-grid">
                <div class="form-group full">
                    <label>Nama Bahan *</label>
                    <input name="name" value="<?=e($material['name'])?>" placeholder="Contoh: Tepung Tapioka, Cabai" required>
                </div>
                
                <div class="form-group">
                    <label>Satuan *</label>
                    <select name="unit" required>
                        <option value="kg" <?=$material['unit'] === 'kg' ? 'selected' : ''?>>kg (Kilogram)</option>
                        <option value="pcs" <?=$material['unit'] === 'pcs' ? 'selected' : ''?>>pcs (Pieces / Biji)</option>
                        <option value="liter" <?=$material['unit'] === 'liter' ? 'selected' : ''?>>liter</option>
                        <option value="bal" <?=$material['unit'] === 'bal' ? 'selected' : ''?>>bal</option>
                        <option value="gram" <?=$material['unit'] === 'gram' ? 'selected' : ''?>>gram</option>
                        <option value="pack" <?=$material['unit'] === 'pack' ? 'selected' : ''?>>pack</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Harga per Satuan (Rp) *</label>
                    <!-- Ditampilkan dalam format 21.000,00 menggunakan number_format -->
                    <input type="text" name="unit_cost" value="<?=e(number_format($material['unit_cost'], 2, ',', '.'))?>" placeholder="Contoh: 21.000,00" required>
                </div>
            </div>

            <div style="display: flex; gap: 12px; justify-content: flex-end; margin-top: 10px; border-top: 1px solid #e2e8f0; padding-top: 20px;">
                <a href="materials.php" class="btn btn-outline" style="padding: 10px 20px; line-height: 20px; border-radius: 8px;">Batal</a>
                <button class="btn btn-primary" style="padding: 10px 24px; border-radius: 8px; font-weight: 600;">Simpan Perubahan</button>
            </div>
        </form>
    </div>
</main>
</body>
</html>