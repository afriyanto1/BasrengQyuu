<?php
require dirname(__DIR__, 2) . "/includes/config.php"; 
require_role('seller');

$action = $_GET['action'] ?? 'list';

if($_SERVER['REQUEST_METHOD']==='POST'){
    check_csrf();
    $id = (int)$_POST['material_id'];
    $qty = (float)$_POST['qty'];
    $note = trim($_POST['note'] ?? '');
    
    if($qty > 0){
        $stmt_mat = $pdo->prepare('SELECT * FROM materials WHERE id = ?');
        $stmt_mat->execute([$id]);
        $mat = $stmt_mat->fetch();

        if($mat){
            $cost = (float)($mat['unit_cost'] ?? 0);
            $total = $qty * $cost;

            $pdo->beginTransaction();
            $pdo->prepare('UPDATE materials SET stock = stock + ? WHERE id = ?')->execute([$qty, $id]);
            $pdo->prepare('INSERT INTO purchases(material_id, qty, unit_cost, total, note) VALUES(?, ?, ?, ?, ?)')->execute([$id, $qty, $cost, $total, $note]);
            $pdo->commit();

            flash('Transaksi pembelian berhasil dicatat.', "success");
        } else {
            flash('Bahan baku tidak ditemukan.', 'danger');
        }
    } else {
        flash('Jumlah pembelian harus lebih dari 0.', 'danger');
    }
    header('Location: purchases.php');
    exit;
}

$rows = $pdo->query('SELECT * FROM materials ORDER BY name')->fetchAll();
$perPage = 5;
$page = max(1, (int)($_GET['page'] ?? 1));
$totalRows = (int)$pdo->query('SELECT COUNT(*) FROM purchases p JOIN materials m ON m.id = p.material_id')->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$stmt_hist = $pdo->prepare('SELECT p.*, m.name, m.unit FROM purchases p JOIN materials m ON m.id = p.material_id ORDER BY p.id DESC LIMIT :limit OFFSET :offset');
$stmt_hist->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt_hist->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt_hist->execute();
$hist = $stmt_hist->fetchAll();
?><!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Pencatatan Pembelian Bahan - Admin</title>
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
        @media (max-width: 800px) {
            .table-modern th:nth-child(4),
            .table-modern td:nth-child(4),
            .table-modern th:nth-child(6),
            .table-modern td:nth-child(6) {
                display: none !important;
            }
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
<?php include dirname(__DIR__, 2) . "/includes/partials/seller_nav.php";?>
<main>
    <?php show_flash();?>

    <?php if ($action === 'create'): ?>
        <!-- ================= HALAMAN CATAT PEMBELIAN BARU ================= -->
        <div class="seller-form-header" style="max-width: 900px;">
            <div>
                <h1 style="margin: 0 0 4px 0;">Catat Pembelian Baru</h1>
                <p class="muted" style="margin: 0;">Catat jumlah barang/bahan baku yang dibeli. Harga satuan mengacu pada master bahan baku.</p>
            </div>
            <a href="purchases.php" class="btn btn-outline seller-form-back">← Kembali</a>
        </div>

        <div class="form-card">
            <form method="post">
                <input type="hidden" name="csrf" value="<?=csrf()?>">
                
                <div class="form-grid">
                    <div class="form-group full">
                        <label>Pilih Bahan / Barang *</label>
                        <select name="material_id" required>
                            <option value="">-- Pilih Bahan Baku --</option>
                            <?php foreach($rows as $m):?>
                            <option value="<?=$m['id']?>">
                                <?=e($m['name'])?> (Satuan: <?=e($m['unit'])?> — <?=rupiah($m['unit_cost'] ?? 0)?>/<?=e($m['unit'])?>)
                            </option>
                            <?php endforeach;?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>Jumlah Pembelian (Qty) *</label>
                        <input type="number" step="0.01" min="0.01" name="qty" placeholder="Contoh: 10" required>
                    </div>
                    
                    <div class="form-group">
                        <label>Catatan (Opsional)</label>
                        <input name="note" placeholder="Nama toko / keterangan tambahan">
                    </div>
                </div>

                <div style="display: flex; gap: 12px; justify-content: flex-end; margin-top: 10px; border-top: 1px solid #e2e8f0; padding-top: 20px;">
                    <a href="purchases.php" class="btn btn-outline" style="padding: 10px 20px; line-height: 20px; border-radius: 8px;">Batal</a>
                    <button class="btn btn-primary" style="padding: 10px 24px; border-radius: 8px; font-weight: 600;">Simpan Pembelian</button>
                </div>
            </form>
        </div>

    <?php else: ?>
        <!-- ================= HALAMAN UTAMA RIWAYAT PEMBELIAN ================= -->
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; flex-wrap: wrap; gap: 16px;">
            <div>
                <h1 style="margin: 0 0 6px 0;">Pencatatan Pembelian Barang</h1>
                <p class="muted" style="margin: 0;">Catat jumlah barang/bahan baku yang dibeli dan pantau riwayatnya.</p>
            </div>
            <a href="purchases.php?action=create" class="btn btn-primary" style="height: 42px; line-height: 40px; padding: 0 20px; border-radius: 8px; font-weight: 600; text-decoration: none;">+ Catat Pembelian</a>
        </div>

        <div class="table-wrap">
            <table class="table-modern">
                <thead>
                    <tr>
                        <th>Tanggal</th>
                        <th>Bahan</th>
                        <th>Jumlah</th>
                        <th>Harga Satuan (Master)</th>
                        <th>Total Biaya</th>
                        <th>Catatan</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($hist as $h):?>
                    <tr>
                        <td style="font-size: 13px; color: #475569;"><?=e($h['purchased_at'])?></td>
                        <td><strong><?=e($h['name'])?></strong></td>
                        <td><?=$h['qty']?> <?=e($h['unit'])?></td>
                        <td><?=rupiah($h['unit_cost'])?></td>
                        <td><strong><?=rupiah($h['total'])?></strong></td>
                        <td><?=e($h['note'] ?: '-')?></td>
                    </tr>
                    <?php endforeach;?>
                    <?php if(empty($hist)):?>
                    <tr><td colspan="6" style="text-align: center; padding: 40px; color: #64748b;">Belum ada riwayat pembelian.</td></tr>
                    <?php endif;?>
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
                Menampilkan <?= $firstRow ?>–<?= $lastRow ?> dari <?= $totalRows ?> riwayat pembelian
            </span>
            <nav class="pagination-controls" aria-label="Navigasi halaman riwayat pembelian">
            <?php if ($page > 1): ?>
                <a href="purchases.php?page=<?= $page - 1 ?>" aria-label="Halaman sebelumnya">&lsaquo;</a>
            <?php else: ?>
                <span class="page-disabled" aria-disabled="true" aria-label="Tidak ada halaman sebelumnya">&lsaquo;</span>
            <?php endif; ?>

            <?php if ($startPage > 1): ?>
                <a href="purchases.php?page=1" aria-label="Halaman 1">1</a>
                <?php if ($startPage > 2): ?><span class="page-ellipsis" aria-hidden="true">&hellip;</span><?php endif; ?>
            <?php endif; ?>

            <?php for ($pageNumber = $startPage; $pageNumber <= $endPage; $pageNumber++): ?>
                <?php if ($pageNumber === $page): ?>
                    <span class="current-page" aria-current="page" aria-label="Halaman <?= $pageNumber ?>"><?= $pageNumber ?></span>
                <?php else: ?>
                    <a href="purchases.php?page=<?= $pageNumber ?>" aria-label="Halaman <?= $pageNumber ?>"><?= $pageNumber ?></a>
                <?php endif; ?>
            <?php endfor; ?>

            <?php if ($endPage < $totalPages): ?>
                <?php if ($endPage < $totalPages - 1): ?><span class="page-ellipsis" aria-hidden="true">&hellip;</span><?php endif; ?>
                <a href="purchases.php?page=<?= $totalPages ?>" aria-label="Halaman <?= $totalPages ?>"><?= $totalPages ?></a>
            <?php endif; ?>

            <?php if ($page < $totalPages): ?>
                <a href="purchases.php?page=<?= $page + 1 ?>" aria-label="Halaman berikutnya">&rsaquo;</a>
            <?php else: ?>
                <span class="page-disabled" aria-disabled="true" aria-label="Tidak ada halaman berikutnya">&rsaquo;</span>
            <?php endif; ?>
            </nav>
        </div>
        <?php endif; ?>
    <?php endif;?>
</main>
</body>
</html>