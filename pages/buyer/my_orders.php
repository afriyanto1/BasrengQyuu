<?php
require dirname(__DIR__, 2) . "/includes/config.php";
require_role("buyer");

$s = $pdo->prepare("SELECT * FROM orders WHERE buyer_id = ? ORDER BY id DESC");
$s->execute([user()["id"]]);
$rows = $s->fetchAll();
?><!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Pesanan Saya - Basreng Store</title>
    <base href="<?= e(app_url('pages/buyer/')) ?>">
    <link rel="icon" type="image/svg+xml" href="<?= e(app_favicon_url()) ?>">
    <link rel="stylesheet" href="<?= e(app_url('assets/css/style.css')) ?>?v=<?= (int) filemtime(app_path('assets/css/style.css')) ?>">
    <style>
        .order-container {
            max-width: 900px;
            margin: 0 auto;
        }
        .order-card {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            padding: 24px;
            margin-bottom: 20px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.03);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .order-card:hover {
            box-shadow: 0 6px 16px rgba(0,0,0,0.06);
        }
        .order-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            flex-wrap: wrap;
            gap: 12px;
            border-bottom: 1px solid #f1f5f9;
            padding-bottom: 16px;
            margin-bottom: 16px;
        }
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            border: 1px solid transparent;
            border-radius: 999px;
            font-size: 13px;
            font-weight: 700;
            line-height: 1.25;
        }
        .badge-unpaid { background: #fff7ed; border-color: #fed7aa; color: #c2410c; }
        .badge-awaiting_seller { background: #eff6ff; border-color: #bfdbfe; color: #1d4ed8; }
        .badge-paid { background: #ecfdf5; border-color: #a7f3d0; color: #047857; }
        .badge-refunded { background: #fef2f2; border-color: #fecaca; color: #b91c1c; }
        .badge-not_shipped { background: #fffbeb; border-color: #fde68a; color: #b45309; }
        .badge-shipping { background: #f5f3ff; border-color: #ddd6fe; color: #6d28d9; }
        .badge-completed { background: #ecfdf5; border-color: #a7f3d0; color: #047857; }
        .badge-returned { background: #fef2f2; border-color: #fecaca; color: #b91c1c; }
        .status-summary {
            display: flex;
            flex-wrap: wrap;
            justify-content: flex-end;
            gap: 10px;
        }
        .status-item {
            display: grid;
            gap: 6px;
        }
        .status-label {
            color: #64748b;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: .04em;
            text-transform: uppercase;
        }
        .status-dot {
            width: 8px;
            height: 8px;
            flex: none;
            border-radius: 50%;
            background: currentColor;
        }
        
        .order-items {
            margin-top: 14px;
            padding: 14px;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            background: #f8fafc;
        }
        .order-items-heading {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 10px;
        }
        .order-items-title {
            margin: 0;
            color: #334155;
            font-size: 13px;
            font-weight: 700;
        }
        .order-items-count {
            padding: 4px 9px;
            border: 1px solid #dbeafe;
            border-radius: 999px;
            background: #eff6ff;
            color: #1d4ed8;
            font-size: 11px;
            font-weight: 700;
            white-space: nowrap;
        }
        .order-items-list {
            display: grid;
            gap: 8px;
        }
        .order-items-columns,
        .order-item-row {
            display: grid;
            grid-template-columns: minmax(0, 1fr) 72px;
            align-items: center;
            gap: 12px;
        }
        .order-items-columns {
            padding: 0 12px 2px;
            color: #64748b;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: .04em;
            text-transform: uppercase;
        }
        .order-items-columns span:last-child {
            text-align: right;
        }
        .order-item-row {
            display: grid;
            align-items: center;
            min-width: 0;
            padding: 10px 12px;
            border: 1px solid #e2e8f0;
            border-radius: 9px;
            background: #fff;
        }
        .order-item-name {
            min-width: 0;
            color: #1e293b;
            font-size: 14px;
            font-weight: 700;
            overflow-wrap: anywhere;
        }
        .order-item-quantity {
            justify-self: end;
            padding: 5px 9px;
            border-radius: 7px;
            background: #f1f5f9;
            color: #475569;
            font-size: 12px;
            font-weight: 700;
            white-space: nowrap;
        }
        .order-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 18px;
            padding-top: 14px;
            border-top: 1px solid #f1f5f9;
            flex-wrap: wrap;
            gap: 12px;
        }
        .btn-detail {
            background: #2563eb;
            color: #fff;
            padding: 10px 20px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: background 0.2s;
        }
        .btn-detail:hover {
            background: #1d4ed8;
        }
    </style>
</head>
<body>

<?php include dirname(__DIR__, 2) . "/includes/partials/buyer_nav.php"; ?>

<main>
    <div class="order-container">
        <div style="margin-bottom: 24px;">
            <h1 style="margin: 0 0 6px 0; font-size: 24px; color: #0f172a;"><?= e(t("Riwayat Pesanan")) ?></h1>
        </div>

        <?php foreach ($rows as $o): ?>
            <?php
            $st_items = $pdo->prepare(
                "SELECT * FROM order_items WHERE order_id = ?"
            );
            $st_items->execute([$o["id"]]);
            $order_items = $st_items->fetchAll();
            $order_seq_label = "Pesanan #" . ($o["order_seq"] ?? 1);
            $order_unique_id = $o["order_code"] ?? $o["id"];
            $payment_status_labels = [
                "unpaid" => app_language() === "en" ? "Unpaid" : "Belum Dibayar",
                "awaiting_seller" => app_language() === "en" ? "Awaiting Payment Confirmation" : "Menunggu Konfirmasi Pembayaran",
                "paid" => app_language() === "en" ? "Paid" : "Lunas",
                "refunded" => app_language() === "en" ? "Refunded" : "Pengembalian Dana",
            ];
            $order_status_labels = [
                "not_shipped" => app_language() === "en" ? "Not Shipped" : "Belum Dikirim",
                "shipping" => app_language() === "en" ? "Shipping" : "Proses Pengiriman",
                "completed" => app_language() === "en" ? "Completed" : "Selesai",
                "returned" => app_language() === "en" ? "Returned" : "Dikembalikan",
            ];
            $payment_method = strtoupper($o["payment_method"] ?? 'QRIS');
            ?>
            <div class="order-card">
                <div class="order-header">
                    <div>
                        <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 4px;">
                            <h2 style="margin: 0; font-size: 18px; color: #1e293b;"><?= $order_seq_label ?></h2>
                            <span style="background: #f1f5f9; color: #475569; padding: 2px 8px; border-radius: 6px; font-size: 12px; font-weight: 600;">ID: #<?= $order_unique_id ?></span>
                        </div>
                        <p class="muted" style="margin: 0; font-size: 13px;"><?= e($o["created_at"]) ?></p>
                    </div>
                    <div class="status-summary" aria-label="<?= e(app_language() === "en" ? "Order statuses" : "Status pesanan") ?>">
                        <div class="status-item">
                            <span class="status-label"><?= e(app_language() === "en" ? "Payment" : "Pembayaran") ?></span>
                            <span class="badge badge-<?= e($o["payment_status"]) ?>">
                                <span class="status-dot" aria-hidden="true"></span>
                                <?= e($payment_status_labels[$o["payment_status"]] ?? $o["payment_status"]) ?>
                            </span>
                        </div>
                        <div class="status-item">
                            <span class="status-label"><?= e(app_language() === "en" ? "Order" : "Pesanan") ?></span>
                            <span class="badge badge-<?= e($o["status"]) ?>">
                                <span class="status-dot" aria-hidden="true"></span>
                                <?= e($order_status_labels[$o["status"]] ?? $o["status"]) ?>
                            </span>
                        </div>
                    </div>
                </div>

                <div style="font-size: 13px; color: #64748b; margin-bottom: 8px;">
                    <?= e(app_language() === "en" ? "Payment Method:" : "Metode Pembayaran:") ?> <strong style="color: #1e293b;"><?= $payment_method ?></strong>
                </div>
                <?php if (!empty($o["tracking_number"])): ?>
                    <div style="font-size: 13px; color: #64748b; margin-bottom: 8px;">
                        Nomor Resi: <strong style="color: #1e293b;"><?= e($o["tracking_number"]) ?></strong>
                    </div>
                <?php endif; ?>
                
                <section class="order-items" aria-label="<?= e(app_language() === "en" ? "Ordered products" : "Barang dalam pesanan") ?>">
                    <div class="order-items-heading">
                        <h3 class="order-items-title"><?= e(app_language() === "en" ? "Ordered products" : "Barang dalam pesanan") ?></h3>
                        <span class="order-items-count"><?= count($order_items) ?> <?= e(app_language() === "en" ? "items" : "produk") ?></span>
                    </div>
                    <div class="order-items-columns">
                        <span><?= e(app_language() === "en" ? "Product name" : "Nama produk") ?></span>
                        <span><?= e(app_language() === "en" ? "Quantity" : "Jumlah") ?></span>
                    </div>
                    <div class="order-items-list">
                        <?php foreach ($order_items as $item): ?>
                            <div class="order-item-row">
                                <strong class="order-item-name"><?= e($item["product_name"]) ?></strong>
                                <span class="order-item-quantity"><?= (int) $item["qty"] ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>

                <div class="order-footer">
                    <div>
                        <a href="payment.php?id=<?= $o["id"] ?>" class="btn-detail">
                            <?= e(app_language() === "en" ? "Order Details" : "Detail Pesanan") ?> →
                        </a>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
        
        <?php if (empty($rows)): ?>
            <div class="panel" style="text-align: center; padding: 50px; background: #fff; border-radius: 14px; border: 1px solid #e2e8f0;">
                <p class="muted" style="margin: 0; font-size: 15px;"><?= e(app_language() === "en" ? "No order history has been created." : "Belum ada riwayat pesanan yang dibuat.") ?></p>
            </div>
        <?php endif; ?>
    </div>
</main>
</body>
</html>