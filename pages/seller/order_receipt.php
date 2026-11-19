<?php
require dirname(__DIR__, 2) . "/includes/config.php";
require_role("seller");

$orderId = filter_var($_GET["id"] ?? null, FILTER_VALIDATE_INT);
if ($orderId === false || $orderId === null || $orderId < 1) {
    http_response_code(400);
    exit("Nomor pesanan tidak valid.");
}

$orderQuery = $pdo->prepare(
    "SELECT o.*, COALESCE(o.customer_name, u.name) AS recipient_name,
            u.province, u.city, u.district, u.village, u.address
     FROM orders o
     LEFT JOIN users u ON u.id = o.buyer_id
     WHERE o.id = ?
     LIMIT 1"
);
$orderQuery->execute([$orderId]);
$order = $orderQuery->fetch();
if (!$order) {
    http_response_code(404);
    exit("Pesanan tidak ditemukan.");
}
if (
    !in_array($order["shipping_area"], ["jabodetabek", "outside"], true) ||
    (float) $order["shipping_fee"] <= 0
) {
    http_response_code(403);
    exit("Resi hanya tersedia untuk pesanan dengan ongkir di luar wilayah gratis ongkir.");
}

$itemsQuery = $pdo->prepare(
    "SELECT product_name, qty FROM order_items WHERE order_id = ? ORDER BY id"
);
$itemsQuery->execute([$orderId]);
$items = $itemsQuery->fetchAll();
$address = implode(", ", array_filter([
    $order["address"],
    $order["village"],
    $order["district"],
    $order["city"],
    $order["province"],
], static fn($part): bool => is_string($part) && trim($part) !== ""));
$orderCode = $order["order_code"] ?? $order["id"];
$shippingArea = $order["shipping_area"] === "jabodetabek" ? "Jabodetabek" : "Luar Jabodetabek";
?><!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Label Pengiriman #<?= e($orderCode) ?> - BasrengQyuu Store</title>
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            padding: 24px 12px;
            background: #f1f5f9;
            color: #0f172a;
            font-family: Arial, Helvetica, sans-serif;
        }
        .shipping-label {
            width: 100%;
            max-width: 380px;
            margin: 0 auto;
            padding: 18px;
            border: 1px solid #cbd5e1;
            border-radius: 4px;
            background: #fff;
        }
        .label-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 20px;
            padding-bottom: 12px;
            border-bottom: 2px solid #0f172a;
        }
        .brand { margin: 0; font-size: 17px; font-weight: 800; letter-spacing: -0.5px; }
        .brand-basreng { color: #ca8a04; }
        .brand-qyuu { color: #dc2626; }
        .label-kind { margin-top: 4px; color: #64748b; font-size: 10px; font-weight: 700; letter-spacing: 1px; }
        .order-reference { text-align: right; }
        .order-reference-label { color: #64748b; font-size: 9px; font-weight: 700; }
        .order-code { margin-top: 3px; font-size: 20px; font-weight: 800; letter-spacing: 1px; }
        .order-date { margin-top: 4px; color: #64748b; font-size: 10px; }
        .route {
            display: flex;
            justify-content: space-between;
            gap: 10px;
            margin: 10px 0;
            padding: 8px 0;
            border-bottom: 1px solid #e2e8f0;
        }
        .route-label { color: #64748b; font-size: 9px; font-weight: 700; }
        .route-value { margin-top: 3px; font-size: 12px; font-weight: 700; }
        .recipient {
            padding: 10px 0 12px;
            border-bottom: 2px dashed #94a3b8;
        }
        .section-label { margin-bottom: 6px; color: #64748b; font-size: 9px; font-weight: 700; letter-spacing: 0.8px; }
        .recipient-name { font-size: 15px; font-weight: 800; }
        .recipient-phone { margin-top: 3px; font-size: 11px; }
        .recipient-address { margin-top: 6px; font-size: 11px; line-height: 1.45; overflow-wrap: anywhere; }
        .items-section { padding: 11px 0; border-bottom: 1px solid #e2e8f0; }
        .items { width: 100%; border-collapse: collapse; }
        .items th, .items td {
            padding: 6px 4px;
            border-bottom: 1px solid #e2e8f0;
            text-align: left;
            font-size: 10px;
            vertical-align: top;
        }
        .items th { background: #f8fafc; font-size: 9px; }
        .items th:last-child, .items td:last-child { width: 42px; text-align: right; }
        .items tr:last-child td { border-bottom: 0; }
        .sender {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            padding-top: 10px;
        }
        .sender-name { font-size: 11px; font-weight: 700; }
        .tracking-field {
            min-height: 24px;
            margin-top: 5px;
            border-bottom: 1px solid #64748b;
        }
        .label-note {
            margin-top: 10px;
            padding-top: 7px;
            border-top: 1px dashed #cbd5e1;
            color: #64748b;
            font-size: 8px;
            line-height: 1.4;
        }
        .print-actions { margin: 0 auto 14px; text-align: center; }
        .print-actions button {
            padding: 10px 18px;
            border: 0;
            border-radius: 8px;
            background: #2563eb;
            color: #fff;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
        }
        @media print {
            @page { size: 100mm 150mm; margin: 4mm; }
            body { padding: 0; background: #fff; }
            .shipping-label {
                width: 92mm;
                max-width: none;
                margin: 0;
                padding: 3mm;
                border: 0;
                border-radius: 0;
            }
            .print-actions { display: none; }
        }
    </style>
</head>
<body>
    <div class="print-actions">
        <button type="button" onclick="window.print()">Cetak Resi</button>
    </div>
    <main class="shipping-label">
        <header class="label-header">
            <div>
                <p class="brand"><span class="brand-basreng">Basreng</span><span class="brand-qyuu">Qyuu</span> Store</p>
                <div class="label-kind">LABEL PENGIRIMAN</div>
            </div>
            <div class="order-reference">
                <div class="order-reference-label">NO. REFERENSI PESANAN</div>
                <div class="order-code"><?= e($orderCode) ?></div>
                <div class="order-date"><?= e($order["created_at"]) ?></div>
            </div>
        </header>

        <section class="route">
            <div>
                <div class="route-label">LAYANAN</div>
                <div class="route-value"><?= e($shippingArea) ?></div>
            </div>
            <div style="text-align: right;">
                <div class="route-label">ONGKIR</div>
                <div class="route-value"><?= e(rupiah($order["shipping_fee"])) ?></div>
            </div>
        </section>

        <section class="recipient">
            <div class="section-label">KEPADA / PENERIMA</div>
            <div class="recipient-name"><?= e($order["recipient_name"] ?? "Pelanggan") ?></div>
            <div class="recipient-phone"><?= e($order["customer_phone"] ?: "Telepon: ____________________") ?></div>
            <?php if ($address !== ""): ?>
                <div class="recipient-address"><?= e($address) ?></div>
            <?php else: ?>
                <div class="recipient-address">Alamat: __________________________________________</div>
                <div class="recipient-address">________________________________________________</div>
            <?php endif; ?>
        </section>

        <section class="items-section">
            <div class="section-label">ISI PAKET</div>
            <table class="items">
                <thead>
                    <tr><th>PRODUK</th><th>QTY</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $item): ?>
                        <tr>
                            <td><?= e($item["product_name"]) ?></td>
                            <td><?= (int) $item["qty"] ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </section>

        <section class="sender">
            <div>
                <div class="section-label">PENGIRIM</div>
                <div class="sender-name">BasrengQyuu Store</div>
            </div>
            <div>
                <div class="section-label">KURIR / NO. RESI</div>
                <div class="tracking-field"></div>
            </div>
        </section>
        <div class="label-note">
            Referensi pesanan ini bukan nomor resi resmi kurir. Isi kurir dan nomor resi setelah pengiriman didaftarkan.
        </div>
    </main>
</body>
</html>
