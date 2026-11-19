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
            display: inline-block;
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-align: center;
            text-transform: capitalize;
        }
        .badge-awaiting_seller { background: #e0f2fe; color: #0284c7; }
        .badge-paid { background: #d1fae5; color: #059669; }
        .badge-refunded { background: #fee2e2; color: #dc2626; }
        .badge-not_shipped { background: #fef3c7; color: #d97706; }
        .badge-shipping { background: #ede9fe; color: #7c3aed; }
        .badge-completed { background: #dcfce7; color: #15803d; }
        .badge-returned { background: #fee2e2; color: #dc2626; }
        
        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }
        .items-table td {
            padding: 8px 0;
            font-size: 14px;
            color: #334155;
            border-bottom: 1px dashed #f1f5f9;
        }
        .items-table tr:last-child td {
            border-bottom: none;
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
            <h1 style="margin: 0 0 6px 0; font-size: 24px; color: #0f172a;">Riwayat Pesanan</h1>
            <p class="muted" style="margin: 0; font-size: 14px;">Pantau status pesanan dan lakukan pembayaran atau konfirmasi pesanan Anda.</p>
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
                "unpaid" => "Belum Dibayar",
                "awaiting_seller" => "Menunggu Konfirmasi Pembayaran",
                "paid" => "Lunas",
                "refunded" => "Pengembalian Dana",
            ];
            $order_status_labels = [
                "not_shipped" => "Belum Dikirim",
                "shipping" => "Proses Pengiriman",
                "completed" => "Selesai",
                "returned" => "Dikembalikan",
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
                    <div>
                        <div style="display: flex; flex-direction: column; align-items: flex-end; gap: 6px;">
                            <span class="badge badge-<?= e($o["payment_status"]) ?>"><?= e($payment_status_labels[$o["payment_status"]] ?? $o["payment_status"]) ?></span>
                            <span class="badge badge-<?= e($o["status"]) ?>"><?= e($order_status_labels[$o["status"]] ?? $o["status"]) ?></span>
                        </div>
                    </div>
                </div>

                <div style="font-size: 13px; color: #64748b; margin-bottom: 8px;">
                    Metode Pembayaran: <strong style="color: #1e293b;"><?= $payment_method ?></strong>
                </div>
                <?php if (!empty($o["tracking_number"])): ?>
                    <div style="font-size: 13px; color: #64748b; margin-bottom: 8px;">
                        Nomor Resi: <strong style="color: #1e293b;"><?= e($o["tracking_number"]) ?></strong>
                    </div>
                <?php endif; ?>
                
                <table class="items-table">
                    <?php foreach ($order_items as $item): ?>
                        <tr>
                            <td>
                                <strong><?= e($item["product_name"]) ?></strong> 
                                <span class="muted">× <?= $item["qty"] ?></span>
                            </td>
                            <td style="text-align: right; font-weight: 600; color: #1e293b;">
                                <?= rupiah($item["subtotal"]) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </table>

                <div style="font-size: 13px; color: #64748b; margin-top: 12px;">
                    <div>Subtotal produk: <strong><?= rupiah((float) $o["total"] - (float) $o["shipping_fee"]) ?></strong></div>
                    <div>
                        Ongkir:
                        <strong>
                            <?php if ($o["shipping_area"] === null): ?>
                                Menunggu konfirmasi ongkir
                            <?php elseif ($o["shipping_area"] === "free_zone"): ?>
                                Gratis ongkir wilayah khusus
                            <?php else: ?>
                                <?= rupiah($o["shipping_fee"]) ?>
                            <?php endif; ?>
                        </strong>
                    </div>
                </div>

                <div class="order-footer">
                    <div>
                        <span style="font-size: 13px; color: #64748b;">Total Tagihan:</span>
                        <div style="font-size: 18px; font-weight: 700; color: #ea580c;"><?= rupiah($o["total"]) ?></div>
                    </div>
                    <div>
                        <a href="payment.php?id=<?= $o["id"] ?>" class="btn-detail">
                            Detail Pembayaran →
                        </a>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
        
        <?php if (empty($rows)): ?>
            <div class="panel" style="text-align: center; padding: 50px; background: #fff; border-radius: 14px; border: 1px solid #e2e8f0;">
                <p class="muted" style="margin: 0; font-size: 15px;">Belum ada riwayat pesanan yang dibuat.</p>
            </div>
        <?php endif; ?>
    </div>
</main>
</body>
</html>