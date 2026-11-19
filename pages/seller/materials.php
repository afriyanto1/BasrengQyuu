<?php
require dirname(__DIR__, 2) . "/includes/config.php";
require_role("seller");

$action = $_GET['action'] ?? 'list';

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    check_csrf();
    
    if (isset($_POST["delete"])) {
        $id = (int)$_POST['delete'];
        $pdo->prepare('DELETE FROM materials WHERE id = ?')->execute([$id]);
        flash('Bahan baku berhasil dihapus.');
    } elseif (isset($_POST["new_material"])) {
        $name = trim($_POST["name"] ?? "");
        $unit = trim($_POST["unit"] ?? "kg");
        $unit_cost = (float) ($_POST["unit_cost"] ?? 0);

        if ($name !== "") {
            $stmt = $pdo->prepare(
                "INSERT INTO materials(name, unit, unit_cost, stock) VALUES(?, ?, ?, 0)"
            );
            $stmt->execute([$name, $unit, $unit_cost]);
            flash("Bahan baku berhasil ditambahkan.");
        } else {
            flash("Nama bahan tidak boleh kosong.", "danger");
        }
    }
    header("Location: materials.php");
    exit();
}

$perPage = 5;
$page = max(1, (int)($_GET['page'] ?? 1));
$totalRows = (int)$pdo->query("SELECT COUNT(*) FROM materials")->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$stmt = $pdo->prepare("SELECT * FROM materials ORDER BY id DESC LIMIT :limit OFFSET :offset");
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll();
?><!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Master Bahan Baku - Admin</title>
    <base href="<?= e(app_url('pages/seller/')) ?>">
    <link rel="icon" type="image/svg+xml" href="<?= e(app_favicon_url()) ?>">
    <link rel="stylesheet" href="<?= e(app_url('assets/css/style.css')) ?>?v=<?= (int) filemtime(app_path('assets/css/style.css')) ?>">
    <style>
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
        .btn-sm {
            padding: 6px 14px;
            font-size: 13px;
            border-radius: 6px;
            font-weight: 600;
            text-decoration: none;
            display: inline-block;
        }
        .pagination-footer {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            margin-top: 14px;
            padding: 14px 16px;
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.03);
        }
        .pagination-summary {
            color: #64748b;
            font-size: 13px;
        }
        .pagination-controls {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 6px;
        }
        .pagination-controls a,
        .pagination-controls span {
            display: inline-flex;
            min-width: 36px;
            height: 36px;
            align-items: center;
            justify-content: center;
            padding: 0 10px;
            border: 1px solid #cbd5e1;
            border-radius: 7px;
            background: #fff;
            color: #334155;
            font-size: 13px;
            font-weight: 600;
            text-decoration: none;
            transition: background 0.2s, border-color 0.2s, color 0.2s;
        }
        .pagination-controls a:hover {
            border-color: #2563eb;
            background: #eff6ff;
            color: #1d4ed8;
        }
        .pagination-controls a:focus-visible {
            outline: 3px solid rgba(37, 99, 235, 0.25);
            outline-offset: 2px;
        }
        .pagination-controls .current-page {
            border-color: #2563eb;
            background: #2563eb;
            color: #fff;
        }
        .pagination-controls .page-disabled {
            background: #f8fafc;
            color: #94a3b8;
            cursor: not-allowed;
        }
        .pagination-controls .page-ellipsis {
            min-width: 20px;
            padding: 0 2px;
            border-color: transparent;
            background: transparent;
            color: #64748b;
        }
        @media (max-width: 600px) {
            .pagination-footer {
                align-items: stretch;
                flex-direction: column;
                gap: 12px;
                padding: 14px;
            }
            .pagination-summary {
                text-align: center;
            }
            .pagination-controls {
                justify-content: center;
                flex-wrap: wrap;
            }
            .pagination-controls a,
            .pagination-controls span {
                min-width: 34px;
                height: 34px;
                padding: 0 8px;
            }
        }
    </style>
</head>
<body>
<?php include dirname(__DIR__, 2) . "/includes/partials/seller_nav.php"; ?>
<main>
    <?php show_flash(); ?>

    <?php if ($action === 'create'): ?>
        <!-- ================= HALAMAN TAMBAH BAHAN BAKU ================= -->
        <div class="materials-page-header">
            <div>
                <h1 style="margin: 0 0 4px 0;">Tambah Bahan Baku Baru</h1>
                <p class="muted" style="margin: 0;">Masukkan informasi bahan baku, satuan, dan estimasi harga per satuan.</p>
            </div>
            <a href="materials.php" class="btn btn-outline materials-back-link">← Kembali</a>
        </div>

        <div class="form-card">
            <form method="post">
                <input type="hidden" name="csrf" value="<?= csrf() ?>">
                
                <div class="form-grid">
                    <div class="form-group full">
                        <label>Nama Bahan *</label>
                        <input name="name" placeholder="Contoh: Tepung Tapioka, Cabai" required>
                    </div>
                    
                    <div class="form-group">
                        <label>Satuan *</label>
                        <select name="unit" required>
                            <option value="kg">kg (Kilogram)</option>
                            <option value="pcs">pcs (Pieces / Biji)</option>
                            <option value="liter">liter</option>
                            <option value="bal">bal</option>
                            <option value="gram">gram</option>
                            <option value="pack">pack</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>Harga per Satuan (Rp) *</label>
                        <input type="number" min="0" step="100" name="unit_cost" placeholder="Contoh: 15000" required>
                    </div>
                </div>

                <div style="display: flex; gap: 12px; justify-content: flex-end; margin-top: 10px; border-top: 1px solid #e2e8f0; padding-top: 20px;">
                    <a href="materials.php" class="btn btn-outline" style="padding: 10px 20px; line-height: 20px; border-radius: 8px;">Batal</a>
                    <button class="btn btn-primary" name="new_material" style="padding: 10px 24px; border-radius: 8px; font-weight: 600;">Simpan Bahan</button>
                </div>
            </form>
        </div>

    <?php else: ?>
        <!-- ================= HALAMAN UTAMA DAFTAR BAHAN BAKU ================= -->
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; flex-wrap: wrap; gap: 16px;">
            <div>
                <h1 style="margin: 0 0 6px 0;">Master Bahan Baku</h1>
                <p class="muted" style="margin: 0;">Kelola daftar jenis bahan baku, satuan barang, dan harga per satuan.</p>
            </div>
            <a href="materials.php?action=create" class="btn btn-primary" style="height: 42px; line-height: 40px; padding: 0 20px; border-radius: 8px; font-weight: 600; text-decoration: none;">+ Tambah Bahan Baku</a>
        </div>

        <div class="table-wrap">
            <table class="table-modern">
                <thead>
                    <tr>
                        <th>Nama Bahan</th>
                        <th>Satuan</th>
                        <th>Harga Satuan</th>
                        <th style="text-align: right;"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $m): ?>
                    <tr>
                        <td><strong><?= e($m["name"]) ?></strong></td>
                        <td><span class="pill"><?= e($m["unit"]) ?></span></td>
                        <td><?= rupiah($m["unit_cost"] ?? 0) ?></td>
                        <td style="text-align: right;">
                            <div style="display: flex; gap: 6px; justify-content: flex-end; align-items: center;">
                                <a href="edit_material.php?id=<?= $m['id'] ?>" class="btn-sm btn-outline" style="border-color: #3182ce; color: #3182ce;">Edit</a>
                                <form method="post" style="margin: 0;">
                                    <input type="hidden" name="csrf" value="<?= csrf() ?>">
                                    <button class="btn-sm" name="delete" value="<?= $m['id'] ?>" style="background: #fee2e2; color: #dc2626; border: 1px solid #fecaca; cursor: pointer;" onclick="return confirm('Hapus bahan baku ini?')">Hapus</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($rows)): ?>
                    <tr><td colspan="4" style="text-align: center; padding: 40px; color: #64748b;">Belum ada data bahan baku.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php if ($totalPages > 1): ?>
        <div class="pagination-footer">
            <?php
            $firstRow = $offset + 1;
            $lastRow = min($offset + $perPage, $totalRows);
            $startPage = max(1, $page - 2);
            $endPage = min($totalPages, $page + 2);
            ?>
            <span class="pagination-summary">
                Menampilkan <?= $firstRow ?>–<?= $lastRow ?> dari <?= $totalRows ?> bahan baku
            </span>
            <nav class="pagination-controls" aria-label="Navigasi halaman bahan baku">
            <?php if ($page > 1): ?>
                <a href="materials.php?page=<?= $page - 1 ?>" aria-label="Halaman sebelumnya">&lsaquo;</a>
            <?php else: ?>
                <span class="page-disabled" aria-disabled="true" aria-label="Tidak ada halaman sebelumnya">&lsaquo;</span>
            <?php endif; ?>

            <?php if ($startPage > 1): ?>
                <a href="materials.php?page=1" aria-label="Halaman 1">1</a>
                <?php if ($startPage > 2): ?><span class="page-ellipsis" aria-hidden="true">&hellip;</span><?php endif; ?>
            <?php endif; ?>

            <?php for ($pageNumber = $startPage; $pageNumber <= $endPage; $pageNumber++): ?>
                <?php if ($pageNumber === $page): ?>
                    <span class="current-page" aria-current="page" aria-label="Halaman <?= $pageNumber ?>"><?= $pageNumber ?></span>
                <?php else: ?>
                    <a href="materials.php?page=<?= $pageNumber ?>" aria-label="Halaman <?= $pageNumber ?>"><?= $pageNumber ?></a>
                <?php endif; ?>
            <?php endfor; ?>

            <?php if ($endPage < $totalPages): ?>
                <?php if ($endPage < $totalPages - 1): ?><span class="page-ellipsis" aria-hidden="true">&hellip;</span><?php endif; ?>
                <a href="materials.php?page=<?= $totalPages ?>" aria-label="Halaman <?= $totalPages ?>"><?= $totalPages ?></a>
            <?php endif; ?>

            <?php if ($page < $totalPages): ?>
                <a href="materials.php?page=<?= $page + 1 ?>" aria-label="Halaman berikutnya">&rsaquo;</a>
            <?php else: ?>
                <span class="page-disabled" aria-disabled="true" aria-label="Tidak ada halaman berikutnya">&rsaquo;</span>
            <?php endif; ?>
            </nav>
        </div>
        <?php endif; ?>
    <?php endif; ?>
</main>
</body>
</html>