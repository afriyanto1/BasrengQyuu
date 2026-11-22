<?php
require dirname(__DIR__, 2) . "/includes/config.php"; 
require_role('seller');

$is_owner = is_owner();
if ($is_owner) {
    $total_revenue = (float)$pdo->query("SELECT COALESCE(SUM(total),0) FROM orders")->fetchColumn();
    $paid_revenue = (float)$pdo->query("SELECT COALESCE(SUM(total),0) FROM orders WHERE payment_status = 'paid'")->fetchColumn();
    $purchase = (float)$pdo->query("SELECT COALESCE(SUM(total),0) FROM purchases")->fetchColumn();
    $profit = $paid_revenue - $purchase;
}

// Hitung pesanan aktif (status belum selesai/dibatalkan)
$active_orders = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE status NOT IN ('completed', 'returned')")->fetchColumn();

// Ambil 5 pesanan terbaru
$recent = $pdo->query("SELECT o.*, COALESCE(o.customer_name, u.name) buyer FROM orders o LEFT JOIN users u ON u.id=o.buyer_id ORDER BY o.id DESC LIMIT 5")->fetchAll();

// Ambil 5 riwayat pembelian bahan baku terbaru
$recent_purchases = $pdo->query("SELECT p.*, m.name, m.unit FROM purchases p JOIN materials m ON m.id = p.material_id ORDER BY p.id DESC LIMIT 5")->fetchAll();
?><!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Dashboard Penjual - Dropship Store</title>
    <base href="<?= e(app_url('pages/seller/')) ?>">
    <link rel="icon" type="image/svg+xml" href="<?= e(app_favicon_url()) ?>">
    <link rel="stylesheet" href="<?= e(app_url('assets/css/style.css')) ?>?v=<?= (int) filemtime(app_path('assets/css/style.css')) ?>">
    <style>
        .welcome-text {
            font-size: 16px;
            color: #334155;
            margin-bottom: 24px;
            font-weight: 500;
        }
        /* Grid 4 Kartu Statistik Berwarna */
        .dash-stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        .dash-card {
            border-radius: 12px;
            padding: 24px;
            color: #fff;
            box-shadow: 0 4px 12px rgba(0,0,0,0.06);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }
        .dash-card.blue { background: linear-gradient(135deg, #2563eb, #1d4ed8); }
        .dash-card.green { background: linear-gradient(135deg, #059669, #047857); }
        .dash-card.yellow { background: linear-gradient(135deg, #f59e0b, #d97706); color: #fff; }
        .dash-card.cyan { background: linear-gradient(135deg, #06b6d4, #0891b2); }

        .dash-card-title {
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            opacity: 0.9;
            margin-bottom: 8px;
        }
        .dash-card-value {
            font-size: 26px;
            font-weight: 700;
            margin-bottom: 8px;
        }
        .dash-card-desc {
            font-size: 12px;
            opacity: 0.85;
        }
        .dash-card-link {
            color: #fff;
            font-size: 12px;
            font-weight: 600;
            text-decoration: underline;
            margin-top: 4px;
            display: inline-block;
        }

        /* Panel Section */
        .dash-panel {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 24px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.03);
            margin-bottom: 24px;
        }
        .dash-panel-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        .dash-panel-header h2 {
            margin: 0;
            font-size: 18px;
            color: #1e293b;
        }
        .btn-lihat-semua {
            background: #fff;
            border: 1px solid #2563eb;
            color: #2563eb;
            padding: 6px 16px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.2s;
        }
        .btn-lihat-semua:hover {
            background: #eff6ff;
        }

        /* Tabel Modern */
        .table-modern {
            width: 100%;
            border-collapse: collapse;
        }
        .table-modern th {
            background: #f8fafc;
            color: #475569;
            font-weight: 600;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 12px 16px;
            border-bottom: 1px solid #e2e8f0;
            text-align: left;
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
        @media (max-width: 800px) {
            .recent-orders-table th:nth-child(3),
            .recent-orders-table td:nth-child(3),
            .recent-purchases-table th:nth-child(4),
            .recent-purchases-table td:nth-child(4),
            .recent-purchases-table th:nth-child(6),
            .recent-purchases-table td:nth-child(6) {
                display: none;
            }
        }

        /* Badge Status & Pembayaran */
        .badge-status {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            text-align: center;
        }
        .badge-lunas { background: #059669; color: #fff; }
        .badge-belumbayar { background: #dc2626; color: #fff; }
        .badge-refunded { background: #7f1d1d; color: #fff; }
        .badge-selesai { background: #475569; color: #fff; }
        .badge-proses { background: #475569; color: #fff; }
        .badge-pending { background: #d97706; color: #fff; }

        .btn-detail {
            background: #fff;
            border: 1px solid #cbd5e1;
            color: #334155;
            padding: 6px 16px;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 600;
            text-decoration: none;
            display: inline-block;
        }
        .btn-detail:hover {
            background: #f1f5f9;
            border-color: #94a3b8;
        }
    </style>
</head>
<body>

<?php include dirname(__DIR__, 2) . "/includes/partials/seller_nav.php";?>

<main>
    <div class="welcome-text">
        Selamat datang kembali, <strong><?=e(user()['name'])?></strong>!
    </div>

    <?php show_flash();?>

    <!-- Kartu Statistik Atas -->
    <div class="dash-stats-grid">
        <?php if ($is_owner): ?>
        <div class="dash-card blue">
            <div>
                <div class="dash-card-title">Total Pendapatan</div>
                <div class="dash-card-value"><?=rupiah($total_revenue)?></div>
            </div>
            <div class="dash-card-desc">Total nilai seluruh pesanan</div>
        </div>

        <div class="dash-card green">
            <div>
                <div class="dash-card-title">Laba Bersih</div>
                <div class="dash-card-value"><?=rupiah($profit)?></div>
            </div>
            <div class="dash-card-desc">Pendapatan lunas - pembelian bahan baku</div>
        </div>
        <?php endif; ?>

        <div class="dash-card yellow">
            <div>
                <div class="dash-card-title">Pesanan Aktif</div>
                <div class="dash-card-value"><?=$active_orders?> Pesanan</div>
            </div>
            <a href="orders.php?active=1" class="dash-card-link">Lihat Pesanan Aktif →</a>
        </div>

        <?php if ($is_owner): ?>
        <div class="dash-card cyan">
            <div>
                <div class="dash-card-title">Total Pembelian Bahan</div>
                <div class="dash-card-value"><?=rupiah($purchase)?></div>
            </div>
            <div class="dash-card-desc">Akumulasi Biaya Bahan Baku Toko</div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Panel Pesanan Terbaru (5 Data) -->
    <div class="dash-panel">
        <div class="dash-panel-header">
            <h2>Pesanan Terbaru</h2>
            <a href="orders.php" class="btn-lihat-semua">Lihat Semua Pesanan</a>
        </div>

        <div class="table-wrap">
            <table class="table-modern recent-orders-table">
                <thead>
                    <tr>
                        <th>No. Pesanan</th>
                        <th>Pelanggan</th>
                        <th>Tanggal</th>
                        <th>Total</th>
                        <th>Status Pembayaran</th>
                        <th>Status Pesanan</th>
                        <th></th>
                        <th style="text-align: right;"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($recent as $o):?>
                    <?php 
                        $order_code = $o['order_code'] ?? ('DS' . str_pad($o['id'], 8, '0', STR_PAD_LEFT));
                        $payment_status_labels = [
                            'unpaid' => 'Belum Dibayar',
                            'awaiting_seller' => 'Menunggu Konfirmasi Pembayaran',
                            'paid' => 'Lunas',
                            'refunded' => 'Pengembalian Dana',
                        ];
                        $order_status_labels = [
                            'not_shipped' => 'Belum Dikirim',
                            'shipping' => 'Proses Pengiriman',
                            'completed' => 'Selesai',
                            'returned' => 'Dikembalikan',
                        ];
                    ?>
                    <tr>
                        <td><strong>#<?=e($order_code)?></strong></td>
                        <td><?=e($o['buyer'])?></td>
                        <td><?=date('d/m/y', strtotime($o['created_at']))?></td>
                        <td><strong><?=rupiah($o['total'])?></strong></td>
                        <td>
                            <?php
                            $payment_badge_class = $o['payment_status'] === 'paid'
                                ? 'badge-lunas'
                                : ($o['payment_status'] === 'refunded' ? 'badge-refunded' : 'badge-belumbayar');
                            ?>
                            <span class="badge-status <?= $payment_badge_class ?>"><?=e($payment_status_labels[$o['payment_status']] ?? $o['payment_status'])?></span>
                        </td>
                        <td>
                            <span class="badge-status <?= $o['status'] === 'completed' ? 'badge-selesai' : ($o['status'] === 'shipping' ? 'badge-proses' : 'badge-pending') ?>"><?=e($order_status_labels[$o['status']] ?? $o['status'])?></span>
                        </td>
                        <td style="text-align: right;">
                            <a href="orders.php" class="btn-detail">Detail</a>
                        </td>
                    </tr>
                    <?php endforeach;?>

                    <?php if(empty($recent)):?>
                    <tr>
                        <td colspan="8" style="text-align: center; padding: 40px; color: #64748b;">Belum ada pesanan terbaru.</td>
                    </tr>
                    <?php endif;?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Panel Pembelian Bahan Baku Terbaru (5 Data) -->
    <div class="dash-panel">
        <div class="dash-panel-header">
            <h2>Pembelian Bahan Baku Terbaru</h2>
            <a href="purchases.php" class="btn-lihat-semua">Lihat Semua Pembelian</a>
        </div>

        <div class="table-wrap">
            <table class="table-modern recent-purchases-table">
                <thead>
                    <tr>
                        <th>Tanggal</th>
                        <th>Bahan Baku</th>
                        <th>Jumlah</th>
                        <th>Harga Satuan</th>
                        <th>Total Biaya</th>
                        <th>Catatan</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($recent_purchases as $p):?>
                    <tr>
                        <td style="font-size: 13px; color: #64748b;"><?=e($p['purchased_at'])?></td>
                        <td><strong><?=e($p['name'])?></strong></td>
                        <td><?=$p['qty']?> <?=e($p['unit'])?></td>
                        <td><?=rupiah($p['unit_cost'])?></td>
                        <td><strong><?=rupiah($p['total'])?></strong></td>
                        <td><?=e($p['note'] ?: '-')?></td>
                    </tr>
                    <?php endforeach;?>

                    <?php if(empty($recent_purchases)):?>
                    <tr>
                        <td colspan="6" style="text-align: center; padding: 40px; color: #64748b;">Belum ada riwayat pembelian bahan baku.</td>
                    </tr>
                    <?php endif;?>
                </tbody>
            </table>
        </div>
    </div>
</main>

</body>
</html>