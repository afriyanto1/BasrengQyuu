<?php
require dirname(__DIR__, 2) . "/includes/config.php"; 
require_role('seller');

$action = $_GET['action'] ?? 'list';

if($_SERVER['REQUEST_METHOD'] === 'POST'){
    check_csrf();
    
    if(isset($_POST['delete'])){
        $pdo->prepare('UPDATE products SET active=0 WHERE id=?')->execute([(int)$_POST['delete']]);
        flash('Produk berhasil dinonaktifkan.');
    } else {
        $name = trim($_POST['name'] ?? '');
        $desc = trim($_POST['description'] ?? '');
        $price = (float)($_POST['price'] ?? 0);
        $stock = (int)($_POST['stock'] ?? 0);
        $imagePath = null;

        if(!empty($_FILES['image']['name'])){
            $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
            if(in_array($ext, ['png', 'jpg', 'jpeg', 'webp'])){
                if($_FILES['image']['size'] <= 3 * 1024 * 1024){
                    $dir = app_path('uploads');
                    if(!is_dir($dir)) mkdir($dir, 0755, true);
                    $fileName = 'product-' . time() . '-' . mt_rand(1000, 9999) . '.' . $ext;
                    if(move_uploaded_file($_FILES['image']['tmp_name'], $dir . '/' . $fileName)){
                        $imagePath = 'uploads/' . $fileName;
                    }
                }
            }
        }

        if($name !== '' && $price >= 0 && $stock >= 0){
            $stmt = $pdo->prepare('INSERT INTO products(name, description, price, stock, image) VALUES(?, ?, ?, ?, ?)');
            $stmt->execute([$name, $desc, $price, $stock, $imagePath]);
            flash('Produk baru berhasil ditambahkan.');
        } else {
            flash('Nama produk dan harga/stok wajib diisi dengan benar.', 'danger');
        }
    }
    header('Location: products.php');
    exit;
}

// Fitur Pencarian Produk
$search = trim($_GET['q'] ?? '');
if ($search !== '') {
    $stmt = $pdo->prepare('SELECT * FROM products WHERE active=1 AND name LIKE ? ORDER BY id DESC');
    $stmt->execute(["%$search%"]);
    $rows = $stmt->fetchAll();
} else {
    $rows = $pdo->query('SELECT * FROM products WHERE active=1 ORDER BY id DESC')->fetchAll();
}
?><!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Manajemen Produk - Admin</title>
    <base href="<?= e(app_url('pages/seller/')) ?>">
    <link rel="icon" type="image/svg+xml" href="<?= e(app_favicon_url()) ?>">
    <link rel="stylesheet" href="<?= e(app_url('assets/css/style.css')) ?>?v=<?= (int) filemtime(app_path('assets/css/style.css')) ?>">
    <style>
        .card-filter {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 24px;
            box-shadow: 0 2px 6px rgba(0,0,0,0.02);
        }
        .table-modern {
            width: 100%;
            border-collapse: collapse;
            background: #fff;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,0.04);
            border: 1px solid #e2e8f0;
        }
        .table-modern th {
            background: #f8fafc;
            color: #475569;
            font-weight: 600;
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 14px 16px;
            border-bottom: 1px solid #e2e8f0;
        }
        .table-modern td {
            padding: 14px 16px;
            border-bottom: 1px solid #edf2f7;
            color: #1e293b;
            font-size: 14px;
            vertical-align: middle;
        }
        .table-modern tr:last-child td {
            border-bottom: none;
        }
        .form-card {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 32px;
            max-width: 1050px; /* Lebar form disesuaikan agar proporsional */
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
        .form-group input, .form-group textarea {
            width: 100%;
            padding: 11px 14px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            background: #fff;
            font-size: 14px;
            margin-top: 0;
        }
        .badge-active {
            display: inline-block;
            padding: 4px 10px;
            background: #dcfce7;
            color: #15803d;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
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
        .btn-sm {
            padding: 6px 14px;
            font-size: 13px;
            border-radius: 6px;
            font-weight: 600;
            text-decoration: none;
            display: inline-block;
        }
    </style>
</head>
<body>
<?php include dirname(__DIR__, 2) . "/includes/partials/seller_nav.php";?>
<main>
    <?php show_flash();?>

    <?php if ($action === 'create'): ?>
        <!-- ================= HALAMAN TAMBAH PRODUK ================= -->
        <div class="seller-form-header" style="max-width: 1050px;">
            <div>
                <h1 style="margin: 0 0 4px 0;">Tambah Produk Baru</h1>
                <p class="muted" style="margin: 0;">Lengkapi informasi detail produk dan foto katalog.</p>
            </div>
            <a href="products.php" class="btn btn-outline seller-form-back">← Kembali</a>
        </div>

        <div class="form-card">
            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="csrf" value="<?=csrf()?>">
                
                <div class="form-grid">
                    <div class="form-group">
                        <label>Nama Produk *</label>
                        <input type="text" name="name" placeholder="Contoh: Basreng Original 1Kg" required>
                    </div>
                    <div class="form-group">
                        <label>Harga Jual (Rp) *</label>
                        <input type="number" name="price" min="0" step="100" placeholder="50000" required>
                    </div>

                    <div class="form-group">
                        <label>Stok Awal *</label>
                        <input type="number" name="stock" min="0" placeholder="100" required>
                    </div>
                    <div class="form-group">
                        <label>Foto Produk (PNG/JPG/WEBP, Maks. 3MB)</label>
                        <input type="file" name="image" accept="image/png,image/jpeg,image/webp" style="padding: 8px; background: #fff;">
                    </div>

                    <div class="form-group full">
                        <label>Deskripsi</label>
                        <textarea name="description" rows="4" placeholder="Keterangan rasa, ukuran, atau detail produk"></textarea>
                    </div>
                </div>

                <div style="display: flex; gap: 12px; justify-content: flex-end; margin-top: 10px; border-top: 1px solid #e2e8f0; padding-top: 20px;">
                    <a href="products.php" class="btn btn-outline" style="padding: 10px 20px; line-height: 20px; border-radius: 8px;">Batal</a>
                    <button type="submit" class="btn btn-primary" style="padding: 10px 24px; border-radius: 8px; font-weight: 600;">Simpan Produk</button>
                </div>
            </form>
        </div>

    <?php else: ?>
        <!-- ================= HALAMAN UTAMA DAFTAR PRODUK ================= -->
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; flex-wrap: wrap; gap: 16px;">
            <div>
                <h1 style="margin: 0 0 6px 0;">Manajemen Produk</h1>
                <p class="muted" style="margin: 0;">Tambah, edit, dan kelola katalog produk beserta foto produk.</p>
            </div>
            <a href="products.php?action=create" class="btn btn-primary" style="height: 42px; line-height: 40px; padding: 0 20px; border-radius: 8px; font-weight: 600; text-decoration: none;">+ Tambah Produk</a>
        </div>

        <!-- Filter / Search Bar -->
        <div class="card-filter">
            <form method="get" style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
                <div style="flex: 1; min-width: 260px;">
                    <input type="text" name="q" value="<?=e($search)?>" placeholder="Cari nama produk" style="margin-top: 0; height: 42px; width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 8px;">
                </div>
                <div style="display: flex; gap: 8px;">
                    <button class="btn btn-primary" style="height: 42px; margin-top: 0; padding: 0 20px; border-radius: 8px; font-weight: 600;">Cari</button>
                    <?php if($search !== ''):?>
                        <a href="products.php" class="btn btn-outline" style="height: 42px; line-height: 40px; margin-top: 0; padding: 0 16px; border-radius: 8px; text-align: center;">Reset</a>
                    <?php endif;?>
                </div>
            </form>
        </div>

        <!-- Tabel Daftar Produk Modern -->
        <div class="table-wrap">
            <table class="table-modern">
                <thead>
                    <tr>
                        <th style="width: 80px;">Foto</th>
                        <th>Nama Produk</th>
                        <th>Harga</th>
                        <th>Stok</th>
                        <th>Status</th>
                        <th style="text-align: right;"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($rows as $p):?>
                    <tr>
                        <td>
                            <?php if(!empty($p['image'])):?>
                                <img src="<?= e(app_url($p['image'])) ?>" alt="Foto" style="width: 48px; height: 48px; object-fit: cover; border-radius: 6px; border: 1px solid #cbd5e1;">
                            <?php else:?>
                                <div style="width: 48px; height: 48px; background: #f1f5f9; border-radius: 6px; display: grid; place-items: center; font-size: 20px; border: 1px solid #cbd5e1;">🌶️</div>
                            <?php endif;?>
                        </td>
                        <td>
                            <strong style="font-size: 15px; color: #1e293b;"><?=e($p['name'])?></strong>
                            <div style="font-size: 12px; color: #64748b; margin-top: 2px;"><?=e(substr($p['description'] ?? '', 0, 60))?></div>
                        </td>
                        <td><strong><?=rupiah($p['price'])?></strong></td>
                        <td><span style="font-weight: 600; color: <?=$p['stock'] > 0 ? '#059669' : '#dc2626'?>;"><?=$p['stock']?></span></td>
                        <td><span class="badge-active">Aktif</span></td>
                        <td style="text-align: right;">
                            <div style="display: flex; gap: 6px; justify-content: flex-end; align-items: center;">
                                <a href="edit_product.php?id=<?=$p['id']?>" class="btn-sm btn-outline" style="border-color: #3182ce; color: #3182ce;">Edit</a>
                                <form method="post" style="margin: 0;">
                                    <input type="hidden" name="csrf" value="<?=csrf()?>">
                                    <button class="btn-sm" name="delete" value="<?=$p['id']?>" style="background: #fee2e2; color: #dc2626; border: 1px solid #fecaca; cursor: pointer;" onclick="return confirm('Nonaktifkan produk ini?')">Nonaktifkan</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach;?>
                    <?php if(empty($rows)):?>
                    <tr><td colspan="6" style="text-align: center; padding: 40px; color: #64748b;">Belum ada produk aktif yang ditemukan.</td></tr>
                    <?php endif;?>
                </tbody>
            </table>
        </div>
    <?php endif;?>
</main>
</body>
</html>