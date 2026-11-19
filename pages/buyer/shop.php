<?php
require dirname(__DIR__, 2) . "/includes/config.php"; 
require_role('buyer');

$addressQuery = $pdo->prepare(
    "SELECT province, city, district, village FROM users WHERE id = ? LIMIT 1"
);
$addressQuery->execute([user()["id"]]);
$buyerAddress = $addressQuery->fetch();
$codAvailable = $buyerAddress && is_free_shipping_location(
    $buyerAddress["province"],
    $buyerAddress["city"],
    $buyerAddress["district"],
    $buyerAddress["village"]
);
$products = $pdo->query('SELECT * FROM products WHERE active=1 AND stock>0 ORDER BY name')->fetchAll();
?><!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Katalog Basreng - Basreng Store</title>
    <base href="<?= e(app_url('pages/buyer/')) ?>">
    <link rel="icon" type="image/svg+xml" href="<?= e(app_favicon_url()) ?>">
    <link rel="stylesheet" href="<?= e(app_url('assets/css/style.css')) ?>?v=<?= (int) filemtime(app_path('assets/css/style.css')) ?>">
    <style>
        .product-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 20px;
            margin-top: 16px;
        }
        .product-card {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 20px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            box-shadow: 0 2px 6px rgba(0,0,0,0.02);
        }
        .product-img-wrap {
            width: 100%;
            height: 180px;
            border-radius: 8px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            margin-bottom: 14px;
        }
        .product-img-wrap img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .product-title {
            font-size: 18px;
            font-weight: 700;
            color: #1e293b;
            margin: 0 0 6px 0;
        }
        .product-desc {
            font-size: 13px;
            color: #64748b;
            margin: 0 0 14px 0;
            line-height: 1.4;
        }
        .product-price {
            font-size: 18px;
            font-weight: 700;
            color: #ea580c;
            margin-bottom: 4px;
        }
        .product-stock {
            font-size: 12px;
            color: #64748b;
            margin-bottom: 12px;
        }
        .qty-box {
            background: #f8fafc;
            padding: 10px;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
        }
        .qty-box label {
            display: block;
            font-size: 12px;
            font-weight: 600;
            color: #475569;
            margin-bottom: 4px;
        }
        .qty-box input {
            width: 100%;
            height: 38px;
            padding: 6px 10px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            font-size: 16px;
            text-align: center;
            background: #fff;
            margin-top: 0;
        }
    </style>
</head>
<body>

<?php include dirname(__DIR__, 2) . "/includes/partials/buyer_nav.php"; ?>

<main>
    <div style="margin-bottom: 20px;">
        <h1 style="margin: 0 0 4px 0; font-size: 24px;">Menu Basreng</h1>
        <p class="muted" style="margin: 0;">Selamat datang, <strong><?=e(user()['name'])?></strong></p>
    </div>

    <?php show_flash();?>

    <form method="post" action="checkout.php">
        <input type="hidden" name="csrf" value="<?=csrf()?>">
        
        <?php if($products):?>
            <div class="product-grid">
                <?php foreach($products as $p):?>
                <div class="product-card">
                    <div>
                        <div class="product-img-wrap">
                            <?php if(!empty($p['image'])):?>
                                <img src="<?= e(app_url($p['image'])) ?>" alt="<?=e($p['name'])?>">
                            <?php else:?>
                                <span style="font-size: 36px;">🌶️</span>
                            <?php endif;?>
                        </div>
                        <h2 class="product-title"><?=e($p['name'])?></h2>
                        <p class="product-desc"><?=e($p['description'] ?: 'Basreng renyah dan gurih pilihan terbaik.')?></p>
                    </div>
                    <div>
                        <div class="product-price"><?=rupiah($p['price'])?></div>
                        <div class="product-stock">Stok tersedia: <strong><?=$p['stock']?></strong></div>
                        <div class="qty-box">
                            <label>Jumlah Pesan</label>
                            <input type="number" name="qty[<?=$p['id']?>]" min="0" max="<?=$p['stock']?>" value="0">
                        </div>
                    </div>
                </div>
                <?php endforeach;?>
            </div>

            <!-- Pilihan Metode Pembayaran (Termasuk COD) -->
            <div class="panel" style="margin-top: 24px; padding: 20px; border-radius: 12px;">
                <h3 style="margin-top:0; font-size: 16px; color: #1e293b;">Pilih Metode Pembayaran</h3>
                <div style="display: flex; gap: 20px; margin-top: 12px; flex-wrap: wrap;">
                    <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; font-weight: normal; font-size: 14px;">
                        <input type="radio" name="payment_method" value="qris" checked style="width: auto; margin:0;">
                        <span><strong>QRIS</strong></span>
                    </label>
                    <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; font-weight: normal; font-size: 14px;">
                        <input type="radio" name="payment_method" value="bca" style="width: auto; margin:0;">
                        <span><strong>Transfer Bank BCA</strong></span>
                    </label>
                    <?php if ($codAvailable): ?>
                        <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; font-weight: normal; font-size: 14px;">
                            <input type="radio" name="payment_method" value="cod" style="width: auto; margin:0;">
                            <span><strong>COD (Bayar di Tempat)</strong></span>
                        </label>
                    <?php endif; ?>
                </div>
            </div>

            <div style="margin-top: 24px; text-align: center;">
                <button class="btn" style="width: 100%; font-size: 16px; padding: 14px; border-radius: 10px; background: #2563eb; font-weight: 600;">Checkout & Bayar Pesanan →</button>
            </div>
        <?php else:?>
            <div class="panel" style="text-align: center; padding: 40px; border-radius: 12px;">
                <p class="muted" style="margin: 0;">Belum ada produk atau stok sedang kosong.</p>
            </div>
        <?php endif;?>
    </form>
</main>
</body>
</html>