<?php
require dirname(__DIR__, 2) . "/includes/config.php";
require_role('seller');

// Tangkap parameter filter
$date_from   = trim($_GET['date_from'] ?? date('Y-m-01'));
$date_to     = trim($_GET['date_to'] ?? date('Y-m-d'));
$pay_status  = trim($_GET['pay_status'] ?? '');
$order_status= trim($_GET['order_status'] ?? '');
$is_print = ($_GET['print'] ?? '') === '1';
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

// Konstruksi query SQL dinamis berdasarkan filter
$where = ["DATE(o.created_at) BETWEEN ? AND ?"];
$params = [$date_from, $date_to];

if ($pay_status !== '') {
    if (in_array($pay_status, ['unpaid', 'awaiting_seller', 'paid', 'refunded'], true)) {
        $where[] = "o.payment_status = ?";
        $params[] = $pay_status;
    }
}

if ($order_status !== '') {
    if (in_array($order_status, ['not_shipped', 'shipping', 'completed', 'returned'], true)) {
        $where[] = "o.status = ?";
        $params[] = $order_status;
    }
}

$whereSql = implode(' AND ', $where);
$perPage = 5;
$page = max(1, (int) ($_GET['page'] ?? 1));
$countStmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM orders o
    WHERE $whereSql
");
$countStmt->execute($params);
$totalRows = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalRows / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$sql = "
    SELECT o.*, COALESCE(o.customer_name, u.name) AS buyer_name
    FROM orders o
    LEFT JOIN users u ON u.id = o.buyer_id
    WHERE $whereSql
    ORDER BY o.created_at DESC, o.id DESC
";
if (!$is_print) {
    $sql .= " LIMIT ? OFFSET ?";
}
$stmt = $pdo->prepare($sql);
foreach ($params as $index => $param) {
    $stmt->bindValue($index + 1, $param);
}
if (!$is_print) {
    $stmt->bindValue(count($params) + 1, $perPage, PDO::PARAM_INT);
    $stmt->bindValue(count($params) + 2, $offset, PDO::PARAM_INT);
}
$stmt->execute();
$orders = $stmt->fetchAll();

$order_items = [];
if ($orders) {
    $order_ids = array_column($orders, 'id');
    $placeholders = implode(',', array_fill(0, count($order_ids), '?'));
    $stmt_items = $pdo->prepare("
        SELECT order_id, product_name, SUM(qty) AS qty
        FROM order_items
        WHERE order_id IN ($placeholders)
        GROUP BY order_id, product_name
        ORDER BY order_id, product_name
    ");
    $stmt_items->execute($order_ids);
    foreach ($stmt_items->fetchAll() as $item) {
        $order_items[$item['order_id']][] = $item;
    }
}

// Hitung Statistik Kartu Atas (Berdasarkan filter tanggal yang dipilih)
$stmt_rev = $pdo->prepare("
    SELECT COALESCE(SUM(total), 0) FROM orders
    WHERE DATE(created_at) BETWEEN ? AND ?
");
$stmt_rev->execute([$date_from, $date_to]);
$total_revenue = (float)$stmt_rev->fetchColumn();

$stmt_paid_rev = $pdo->prepare("
    SELECT COALESCE(SUM(total), 0) FROM orders
    WHERE DATE(created_at) BETWEEN ? AND ?
");
$stmt_paid_rev->execute([$date_from, $date_to]);
$paid_revenue = (float)$stmt_paid_rev->fetchColumn();

$stmt_exp = $pdo->prepare("
    SELECT COALESCE(SUM(total), 0) FROM purchases 
    WHERE DATE(purchased_at) BETWEEN ? AND ?
");
$stmt_exp->execute([$date_from, $date_to]);
$clean_revenue =  (float)$stmt_all->fetchColumn();

$stmt_all = $pdo->prepare("
    SELECT COALESCE(SUM(total), 0) FROM orders
    WHERE payment_status = 'paid' AND DATE(created_at) BETWEEN ? AND ?
");
$stmt_all->execute([$date_from, $date_to]);
$total_expense = (float)$stmt_exp->fetchColumn();

$net_profit = $paid_revenue - $total_expense;

// Export laporan ke format XLS yang kompatibel dengan Excel
$export = $_GET['export'] ?? '';
if ($export === 'xls') {
    $filename_from = preg_replace('/[^0-9-]/', '', $date_from);
    $filename_to = preg_replace('/[^0-9-]/', '', $date_to);
    $filename = "Laporan_Pesanan_{$filename_from}_sd_{$filename_to}.xls";

    $export_stmt = $pdo->prepare("
        SELECT o.*
        FROM orders o
        WHERE $whereSql
        ORDER BY o.created_at DESC, o.id DESC
    ");
    $export_stmt->execute($params);
    $export_orders = $export_stmt->fetchAll();
    $export_order_items = [];
    if ($export_orders) {
        $export_order_ids = array_column($export_orders, 'id');
        $export_placeholders = implode(',', array_fill(0, count($export_order_ids), '?'));
        $export_items_stmt = $pdo->prepare("
            SELECT order_id, product_name, SUM(qty) AS qty
            FROM order_items
            WHERE order_id IN ($export_placeholders)
            GROUP BY order_id, product_name
            ORDER BY order_id, product_name
        ");
        $export_items_stmt->execute($export_order_ids);
        foreach ($export_items_stmt->fetchAll() as $item) {
            $export_order_items[$item['order_id']][] = $item;
        }
    }

    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');

    $excel_escape = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $sum_qty = 0;
    $sum_shipping = 0.0;
    $sum_revenue = 0.0;
    $sum_expense = 0.0;
    $sum_profit = 0.0;
    ?>
    <!doctype html>
    <html lang="id">
    <head>
        <meta charset="utf-8">
        <style>
            table { border-collapse: collapse; font-family: Arial, sans-serif; }
            th, td { border: 1px solid #94a3b8; padding: 8px 10px; }
            th { background: #1e3a8a; color: #fff; font-weight: bold; text-align: center; }
            td { vertical-align: middle; }
            .text { mso-number-format: "\@"; }
            .number { text-align: right; mso-number-format: "#,##0.00"; }
            .quantity { text-align: center; mso-number-format: "0"; }
            .total { background: #e2e8f0; font-weight: bold; }
            .status-paid { color: #047857; font-weight: bold; }
            .status-unpaid { color: #b91c1c; font-weight: bold; }
        </style>
    </head>
    <body>
        <table>
            <thead>
                <tr>
                    <th>No. Pesanan</th>
                    <th>Tanggal</th>
                    <th>Barang yang Dipesan</th>
                    <th>Quantity</th>
                    <th>Ongkir</th>
                    <th>Total Pendapatan</th>
                    <th>Biaya Pembelian (Alokasi)</th>
                    <th>Laba Bersih</th>
                    <th>Status Pembayaran</th>
                    <th>Status Pesanan</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($export_orders as $o): ?>
                    <?php
                    $code = $o['order_code'] ?? ('DS' . str_pad($o['id'], 8, '0', STR_PAD_LEFT));
                    $items = $export_order_items[$o['id']] ?? [];
                    $total_qty = (int) array_sum(array_column($items, 'qty'));
                    $products = implode(', ', array_map(
                        static fn($item) => $item['product_name'],
                        $items
                    ));
                    $is_paid = $o['payment_status'] === 'paid';
                    $revenue = (float) $o['total'];
                    $allocated_expense = $is_paid && $paid_revenue > 0
                        ? $total_expense * (float) $o['total'] / $paid_revenue
                        : 0.0;
                    $profit = $is_paid ? (float) $o['total'] - $allocated_expense : 0.0;
                    $sum_qty += $total_qty;
                    $sum_shipping += (float) $o['shipping_fee'];
                    $sum_revenue += $revenue;
                    $sum_expense += $allocated_expense;
                    $sum_profit += $profit;
                    ?>
                    <tr>
                        <td class="text"><?= $excel_escape($code) ?></td>
                        <td class="text"><?= $excel_escape(date('d/m/Y H:i', strtotime($o['created_at']))) ?></td>
                        <td><?= $excel_escape($products ?: '-') ?></td>
                        <td class="quantity"><?= $total_qty ?></td>
                        <td class="<?= $o['shipping_area'] === 'free_zone' ? 'text' : 'number' ?>"><?= $o['shipping_area'] === 'free_zone' ? 'Gratis (wilayah khusus)' : number_format((float) $o['shipping_fee'], 2, '.', '') ?></td>
                        <td class="number"><?= number_format($revenue, 2, '.', '') ?></td>
                        <td class="number"><?= number_format($allocated_expense, 2, '.', '') ?></td>
                        <td class="number"><?= number_format($is_paid ? $profit : 0, 2, '.', '') ?></td>
                        <td class="<?= $is_paid ? 'status-paid' : 'status-unpaid' ?>"><?= $excel_escape($payment_status_labels[$o['payment_status']] ?? $o['payment_status']) ?></td>
                        <td><?= $excel_escape($order_status_labels[$o['status']] ?? $o['status']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <tr class="total">
                    <td colspan="3" style="text-align: right;">TOTAL KESELURUHAN:</td>
                    <td class="quantity"><?= $sum_qty ?></td>
                    <td class="number"><?= number_format($sum_shipping, 2, '.', '') ?></td>
                    <td class="number"><?= number_format($sum_revenue, 2, '.', '') ?></td>
                    <td class="number"><?= number_format($sum_expense, 2, '.', '') ?></td>
                    <td class="number"><?= number_format($sum_profit, 2, '.', '') ?></td>
                    <td colspan="2"></td>
                </tr>
            </tbody>
        </table>
    </body>
    </html>
    <?php
    exit;
}
?><!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Laporan Pesanan - Admin</title>
    <base href="<?= e(app_url('pages/seller/')) ?>">
    <link rel="icon" type="image/svg+xml" href="<?= e(app_favicon_url()) ?>">
    <link rel="stylesheet" href="<?= e(app_url('assets/css/style.css')) ?>?v=<?= (int) filemtime(app_path('assets/css/style.css')) ?>">
    <style>
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 16px;
            margin-bottom: 24px;
        }
        .export-group {
            display: flex;
            gap: 8px;
        }
        .btn-export {
            padding: 9px 16px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            cursor: pointer;
            border: 1px solid transparent;
            transition: all 0.2s;
        }
        .btn-xls { background: #ecfdf5; color: #047857; border-color: #a7f3d0; }
        .btn-xls:hover { background: #d1fae5; }
        .btn-pdf { background: #fef2f2; color: #dc2626; border-color: #fecaca; }
        .btn-pdf:hover { background: #fee2e2; }

        /* Kartu Filter Model Kolom */
        .card-filter {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 24px;
            margin-bottom: 28px;
            box-shadow: 0 2px 6px rgba(0,0,0,0.02);
        }
        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 16px;
            align-items: end;
            margin-bottom: 16px;
        }
        .filter-group label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: #475569;
            margin-bottom: 6px;
        }
        .filter-group input, .filter-group select {
            width: 100%;
            height: 42px;
            padding: 8px 12px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            font-size: 14px;
            background: #fff;
            margin-top: 0;
        }
        .filter-actions {
            display: flex;
            gap: 10px;
        }

        /* 3 Kartu Statistik Atas */
        .dash-stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 20px;
            margin-bottom: 28px;
        }
        .print-only { display: none; }
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
        .dash-card.cyan { background: linear-gradient(135deg, #06b6d4, #0891b2); }
        .dash-card.green { background: linear-gradient(135deg, #059669, #047857); }

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

        /* Tabel Laporan */
        .report-panel {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 24px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.03);
        }
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
            padding: 14px 16px;
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
        .badge-selesai { background: #475569; color: #fff; }
        .badge-proses { background: #0284c7; color: #fff; }
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
            .dash-stats-grid {
                grid-template-columns: minmax(0, 1fr);
                gap: 12px;
                margin-bottom: 20px;
            }
            .report-panel .table-modern th:nth-child(2),
            .report-panel .table-modern td:nth-child(2) {
                display: none;
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

        @media print {
            header, nav, .card-filter, .export-group, .no-print {
                display: none !important;
            }
            .report-panel .table-modern th:nth-child(2),
            .report-panel .table-modern td:nth-child(2) {
                display: table-cell !important;
            }
            body { background: #fff !important; }
            @page { size: landscape; margin: 8mm; }
            main { max-width: 100% !important; margin: 0 !important; padding: 0 !important; }
            .dash-stats-grid { display: none !important; }
            .print-only { display: block; }
            .report-panel { border: 0; border-radius: 0; padding: 0; box-shadow: none; }
            .table-wrap { overflow: visible !important; border: 0; }
            .table-modern { width: 100% !important; table-layout: fixed; }
            .table-modern th, .table-modern td {
                padding: 4px 5px;
                font-size: 8px;
                white-space: normal;
                overflow-wrap: anywhere;
            }
            .table-modern .product-cell { min-width: 0; }
            .table-modern .date-cell { font-size: 7px !important; }
            .table-modern .screen-only { display: none !important; }
            .table-modern tfoot th,
            .table-modern tfoot td {
                border-top: 1px solid #475569;
                font-weight: 700;
            }
        }
    </style>
</head>
<body>

<?php include dirname(__DIR__, 2) . "/includes/partials/seller_nav.php"; ?>

<main>
    <div class="page-header">
        <div>
            <h1 style="margin: 0 0 4px 0; font-size: 24px; color: #0f172a;">Laporan Pesanan</h1>
            <p class="muted" style="margin: 0; font-size: 14px;">Kelola dan pantau rekapitulasi pesanan pelanggan.</p>
            <p class="print-only" style="margin: 4px 0 0; font-size: 12px;">Periode: <?= e(date('d/m/Y', strtotime($date_from))) ?> - <?= e(date('d/m/Y', strtotime($date_to))) ?></p>
        </div>

        <div class="export-group no-print">
            <?php
            $printParams = $_GET;
            unset($printParams['page'], $printParams['export']);
            $printParams['print'] = '1';
            ?>
            <a href="reports.php?date_from=<?= urlencode($date_from) ?>&date_to=<?= urlencode($date_to) ?>&pay_status=<?= urlencode($pay_status) ?>&order_status=<?= urlencode($order_status) ?>&export=xls" class="btn-export btn-xls">
                Export Excel (.xls)
            </a>
            <a href="reports.php?<?= e(http_build_query($printParams)) ?>" target="_blank" rel="noopener" class="btn-export btn-pdf">
                Cetak PDF
            </a>
        </div>
    </div>

    <!-- Filter 4 Kolom di Atas (Tanpa Ikon pada Tombol) -->
    <div class="card-filter no-print">
        <form method="get">
            <div class="filter-grid">
                <!-- Kolom 1: Dari Tanggal -->
                <div class="filter-group">
                    <label>Dari Tanggal</label>
                    <input type="date" name="date_from" value="<?= e($date_from) ?>">
                </div>

                <!-- Kolom 2: Sampai Tanggal -->
                <div class="filter-group">
                    <label>Sampai Tanggal</label>
                    <input type="date" name="date_to" value="<?= e($date_to) ?>">
                </div>

                <!-- Kolom 3: Status Pembayaran -->
                <div class="filter-group">
                    <label>Status Pembayaran</label>
                    <select name="pay_status">
                        <option value="">Semua Pembayaran</option>
                        <option value="unpaid" <?= $pay_status === 'unpaid' ? 'selected' : '' ?>>Belum Dibayar</option>
                        <option value="awaiting_seller" <?= $pay_status === 'awaiting_seller' ? 'selected' : '' ?>>Menunggu Konfirmasi Pembayaran</option>
                        <option value="paid" <?= $pay_status === 'paid' ? 'selected' : '' ?>>Lunas</option>
                        <option value="refunded" <?= $pay_status === 'refunded' ? 'selected' : '' ?>>Pengembalian Dana</option>
                    </select>
                </div>

                <!-- Kolom 4: Status Pesanan -->
                <div class="filter-group">
                    <label>Status Pesanan</label>
                    <select name="order_status">
                        <option value="">Semua Status</option>
                        <option value="not_shipped" <?= $order_status === 'not_shipped' ? 'selected' : '' ?>>Belum Dikirim</option>
                        <option value="shipping" <?= $order_status === 'shipping' ? 'selected' : '' ?>>Proses Pengiriman</option>
                        <option value="completed" <?= $order_status === 'completed' ? 'selected' : '' ?>>Selesai</option>
                        <option value="returned" <?= $order_status === 'returned' ? 'selected' : '' ?>>Dikembalikan</option>
                    </select>
                </div>
            </div>

            <!-- Tombol Aksi Filter (Tanpa Ikon) -->
            <div class="filter-actions">
                <button type="submit" class="btn" style="background: #2563eb; height: 40px; padding: 0 24px; font-weight: 600; border-radius: 8px;">Terapkan Filter</button>
                <a href="reports.php" class="btn" style="background: #f1f5f9; color: #334155; border: 1px solid #cbd5e1; height: 40px; line-height: 38px; padding: 0 20px; border-radius: 8px; text-decoration: none; text-align: center;">Reset</a>
            </div>
        </form>
    </div>

    <!-- 3 Kartu Statistik Berwarna -->
    <div class="dash-stats-grid">
        <div class="dash-card blue">
            <div>
                <div class="dash-card-title">Total Pendapatan</div>
                <div class="dash-card-value"><?= rupiah($total_revenue) ?></div>
            </div>
            <div class="dash-card-desc">Total nilai seluruh pesanan pada rentang tanggal</div>
        </div>

        <div class="dash-card cyan">
            <div>
                <div class="dash-card-title">Pendapatan Kotor</div>
                <div class="dash-card-value"><?= rupiah($clean_revenue) ?></div>
            </div>
            <div class="dash-card-desc">Total Pendapatan pesanan lunas</div>
        </div>

        <div class="dash-card green">
            <div>
                <div class="dash-card-title">Laba Bersih</div>
                <div class="dash-card-value"><?= rupiah($net_profit) ?></div>
            </div>
            <div class="dash-card-desc">Pendapatan lunas - pembelian bahan</div>
        </div>
    </div>

    <!-- Tabel Rincian Laporan Pesanan -->
    <div class="report-panel">
        <h2 style="margin: 0 0 20px 0; font-size: 18px; color: #1e293b;">Rincian Laporan Pesanan</h2>
        <p style="margin: -12px 0 16px; color: #64748b; font-size: 12px;">Laba bersih per pesanan merupakan estimasi: biaya pembelian bahan pada periode ini dialokasikan secara proporsional ke pesanan lunas.</p>

        <div class="table-wrap">
            <table class="table-modern">
                <thead>
                    <tr>
                        <th>No. Pesanan</th>
                        <th>Tanggal Dibuat</th>
                        <th>Produk</th>
                        <th>Jumlah Produk</th>
                        <th>Ongkir</th>
                        <th>Total Pendapatan</th>
                        <th>Status Pembayaran</th>
                        <th>Status Pesanan</th>
                        <th>Laba Bersih</th>
                        <th class="screen-only" style="text-align: right;">Aksi</th>
                    </tr>
                </thead>
                <?php
                $report_total_qty = 0;
                $report_total_shipping = 0.0;
                $report_total_revenue = 0.0;
                $report_total_profit = 0.0;
                ?>
                <tbody>
                    <?php foreach ($orders as $o): ?>
                    <?php 
                        $order_code = $o['order_code'] ?? ('DS' . str_pad($o['id'], 8, '0', STR_PAD_LEFT));
                        $is_paid = $o['payment_status'] === 'paid';
                        $items = $order_items[$o['id']] ?? [];
                        $items_summary = implode(', ', array_map(
                            static fn($item) => $item['product_name'] . ' (' . $item['qty'] . 'x)',
                            $items
                        ));
                        $total_qty = array_sum(array_column($items, 'qty'));
                        $order_profit = $is_paid && $paid_revenue > 0
                            ? (float) $o['total'] - ($total_expense * (float) $o['total'] / $paid_revenue)
                            : 0;
                        $report_total_qty += $total_qty;
                        $report_total_shipping += (float) $o['shipping_fee'];
                        $report_total_revenue += (float) $o['total'];
                        $report_total_profit += $order_profit;
                    ?>
                    <tr>
                        <td><strong>#<?= e($order_code) ?></strong></td>
                        <td class="date-cell" style="color: #64748b; font-size: 13px;"><?= date('d/m/Y H:i', strtotime($o['created_at'])) ?></td>
                        <td class="product-cell"><?= e($items_summary ?: '-') ?></td>
                        <td><?= (int) $total_qty ?></td>
                        <td><?= $o['shipping_area'] === 'free_zone' ? 'Gratis (wilayah khusus)' : rupiah($o['shipping_fee']) ?></td>
                        <td><strong><?= rupiah($o['total']) ?></strong></td>
                        <td><span class="badge-status <?= $is_paid ? 'badge-lunas' : 'badge-belumbayar' ?>"><?=e($payment_status_labels[$o['payment_status']] ?? $o['payment_status'])?></span></td>
                        <td><?=e($order_status_labels[$o['status']] ?? $o['status'])?></td>
                        <td><strong><?= $is_paid ? rupiah($order_profit) : '-' ?></strong></td>
                        <td class="screen-only" style="text-align: right;">
                            <a href="orders.php" class="btn-detail">Detail</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>

                    <?php if (empty($orders)): ?>
                    <tr>
                        <td colspan="10" style="text-align: center; padding: 40px; color: #64748b;">Tidak ada data pesanan yang sesuai dengan filter.</td>
                    </tr>
                    <?php endif; ?>
                </tbody>
                <?php if ($is_print): ?>
                <tfoot>
                    <tr>
                        <td colspan="3"><strong>Total (<?= count($orders) ?> pesanan)</strong></td>
                        <td><?= $report_total_qty ?></td>
                        <td><?= rupiah($report_total_shipping) ?></td>
                        <td><?= rupiah($report_total_revenue) ?></td>
                        <td></td>
                        <td></td>
                        <td><?= rupiah($report_total_profit) ?></td>
                        <td class="screen-only"></td>
                    </tr>
                </tfoot>
                <?php endif; ?>
            </table>
        </div>
        <?php if (!$is_print && $totalPages > 1): ?>
        <div class="pagination-footer no-print">
            <?php
            $firstRow = $offset + 1;
            $lastRow = min($offset + $perPage, $totalRows);
            $startPage = max(1, $page - 2);
            $endPage = min($totalPages, $page + 2);
            $paginationParams = $_GET;
            unset($paginationParams['export'], $paginationParams['print']);
            ?>
            <span class="pagination-summary">
                Menampilkan <?= $firstRow ?>–<?= $lastRow ?> dari <?= $totalRows ?> pesanan
            </span>
            <nav class="pagination-controls" aria-label="Navigasi halaman laporan pesanan">
                <?php if ($page > 1): ?>
                    <?php $paginationParams['page'] = $page - 1; ?>
                    <a href="reports.php?<?= e(http_build_query($paginationParams)) ?>" aria-label="Halaman sebelumnya">&lsaquo;</a>
                <?php else: ?>
                    <span class="page-disabled" aria-disabled="true" aria-label="Tidak ada halaman sebelumnya">&lsaquo;</span>
                <?php endif; ?>

                <?php if ($startPage > 1): ?>
                    <?php $paginationParams['page'] = 1; ?>
                    <a href="reports.php?<?= e(http_build_query($paginationParams)) ?>" aria-label="Halaman 1">1</a>
                    <?php if ($startPage > 2): ?><span class="page-ellipsis" aria-hidden="true">&hellip;</span><?php endif; ?>
                <?php endif; ?>

                <?php for ($pageNumber = $startPage; $pageNumber <= $endPage; $pageNumber++): ?>
                    <?php if ($pageNumber === $page): ?>
                        <span class="current-page" aria-current="page" aria-label="Halaman <?= $pageNumber ?>"><?= $pageNumber ?></span>
                    <?php else: ?>
                        <?php $paginationParams['page'] = $pageNumber; ?>
                        <a href="reports.php?<?= e(http_build_query($paginationParams)) ?>" aria-label="Halaman <?= $pageNumber ?>"><?= $pageNumber ?></a>
                    <?php endif; ?>
                <?php endfor; ?>

                <?php if ($endPage < $totalPages): ?>
                    <?php if ($endPage < $totalPages - 1): ?><span class="page-ellipsis" aria-hidden="true">&hellip;</span><?php endif; ?>
                    <?php $paginationParams['page'] = $totalPages; ?>
                    <a href="reports.php?<?= e(http_build_query($paginationParams)) ?>" aria-label="Halaman <?= $totalPages ?>"><?= $totalPages ?></a>
                <?php endif; ?>

                <?php if ($page < $totalPages): ?>
                    <?php $paginationParams['page'] = $page + 1; ?>
                    <a href="reports.php?<?= e(http_build_query($paginationParams)) ?>" aria-label="Halaman berikutnya">&rsaquo;</a>
                <?php else: ?>
                    <span class="page-disabled" aria-disabled="true" aria-label="Tidak ada halaman berikutnya">&rsaquo;</span>
                <?php endif; ?>
            </nav>
        </div>
        <?php endif; ?>
    </div>
</main>

<?php if ($is_print): ?>
<script>
    window.addEventListener('load', () => {
        window.addEventListener('afterprint', () => window.close(), { once: true });
        window.print();
    });
</script>
<?php endif; ?>

</body>
</html>