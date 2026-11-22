<?php
require dirname(__DIR__, 2) . "/includes/config.php"; 
require_role('buyer');

$buyerId = (int) user()["id"];
$cart = sync_buyer_cart($pdo, $buyerId);
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    check_csrf();

    $productId = filter_var($_POST["add_to_cart"] ?? null, FILTER_VALIDATE_INT);
    $postedQuantities = $_POST["qty"] ?? [];
    $quantity = is_array($postedQuantities)
        ? filter_var($postedQuantities[$productId] ?? null, FILTER_VALIDATE_INT)
        : false;
    if (!$productId || !$quantity || $quantity < 1) {
        flash("Pilih jumlah produk yang valid sebelum menambahkan ke keranjang.");
        header("Location: shop.php");
        exit();
    }

    $productStmt = $pdo->prepare(
        "SELECT id, stock FROM products WHERE id = ? AND active = 1"
    );
    $productStmt->execute([$productId]);
    $product = $productStmt->fetch();
    if (!$product || $quantity > (int) $product["stock"]) {
        flash("Stok produk tidak mencukupi. Silakan periksa kembali.");
        header("Location: shop.php");
        exit();
    }

    $currentQuantity = (int) ($cart[$productId] ?? 0);
    if ($currentQuantity + $quantity > (int) $product["stock"]) {
        flash("Jumlah di keranjang melebihi stok tersedia.");
        header("Location: shop.php");
        exit();
    }

    persist_buyer_cart_item($pdo, $buyerId, $productId, $currentQuantity + $quantity);
    flash("Produk berhasil ditambahkan ke keranjang.", "success");
    header("Location: shop.php");
    exit();
}

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
            grid-template-columns: repeat(auto-fill, minmax(230px, 1fr));
            gap: 18px;
            margin-top: 16px;
        }
        .product-card {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            padding: 14px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            min-width: 0;
            box-shadow: 0 5px 16px rgba(15, 23, 42, .05);
            transition: transform .18s ease, box-shadow .18s ease;
        }
        .product-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 12px 26px rgba(15, 23, 42, .1);
        }
        .product-img-wrap {
            width: 100%;
            height: 205px;
            border-radius: 12px;
            background: linear-gradient(135deg, #eff6ff, #dbeafe);
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            margin-bottom: 16px;
        }
        .product-img-wrap img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            object-position: center;
        }
        .product-title {
            font-size: 16px;
            font-weight: 700;
            color: #0f172a;
            margin: 0 0 7px;
            line-height: 1.35;
        }
        .product-desc {
            font-size: 13px;
            color: #64748b;
            margin: 0 0 16px;
            line-height: 1.4;
            min-height: 36px;
        }
        .product-price {
            font-size: 20px;
            font-weight: 700;
            color: #2563eb;
            margin-bottom: 2px;
        }
        .product-stock {
            font-size: 12px;
            color: #64748b;
            margin-bottom: 13px;
        }
        .qty-box {
            padding: 10px 12px;
            border-radius: 10px;
            border: 1px solid #e2e8f0;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
        }
        .qty-box label {
            font-size: 12px;
            font-weight: 600;
            color: #475569;
        }
        .qty-box input {
            width: 76px;
            height: 36px;
            padding: 6px 8px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            font-size: 14px;
            text-align: center;
            background: #fff;
            margin-top: 0;
        }
        .product-actions {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
            margin-top: 10px;
        }
        .product-actions button {
            min-height: 40px;
            margin: 0;
            padding: 8px;
            border-radius: 9px;
            font-size: 12px;
            font-weight: 700;
            line-height: 1.2;
        }
        .btn-cart {
            color: #2563eb;
            background: #eff6ff;
            border: 1px solid #93c5fd;
            cursor: pointer;
        }
        .btn-cart:hover {
            background: #dbeafe;
        }
        .btn-buy {
            color: #fff;
            background: #2563eb;
            border: 1px solid #2563eb;
            cursor: pointer;
        }
        .btn-buy:hover {
            background: #1d4ed8;
            border-color: #1d4ed8;
        }
        @media (max-width: 560px) {
            .product-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; }
            .product-card { padding: 10px; border-radius: 12px; }
            .product-img-wrap { height: 145px; }
            .product-actions { grid-template-columns: 1fr; }
            .product-title { font-size: 14px; }
            .product-price { font-size: 17px; }
        }
    </style>
</head>
<body>

<?php include dirname(__DIR__, 2) . "/includes/partials/buyer_nav.php"; ?>

<main>
    <div style="display:flex; justify-content:space-between; align-items:flex-end; gap:16px; flex-wrap:wrap; margin-bottom:20px;">
        <div>
            <h1 style="margin: 0 0 4px; font-size: 26px;">Menu <?= app_language() === "en" ? "Snacks" : "Basreng" ?></h1>
            <p class="muted" style="margin: 0;"><?= app_language() === "en" ? "Welcome," : "Selamat datang," ?> <strong><?=e(user()['name'])?></strong>. <?= app_language() === "en" ? "Choose your favorite snack." : "Pilih camilan favoritmu." ?></p>
        </div>
        <a href="cart.php" class="btn" style="margin:0; padding:10px 16px; background:#fff; color:#2563eb; border:1px solid #93c5fd; border-radius:10px; text-decoration:none; font-weight:700;">
            🛒 <?= app_language() === "en" ? "Cart" : "Keranjang" ?> <span style="display:inline-grid; place-items:center; min-width:22px; height:22px; margin-left:5px; padding:0 6px; border-radius:999px; background:#2563eb; color:#fff; font-size:12px;"><?=array_sum($_SESSION["buyer_cart"][$buyerId] ?? [])?></span>
        </a>
    </div>

    <?php show_flash();?>

    <form method="post" action="shop.php">
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
                        <h2 class="product-title"><?= e($p["name"]) ?></h2>
                        <p class="product-desc"><?= e($p["description"] ?: 'Basreng renyah dan gurih pilihan terbaik.') ?></p>
                    </div>
                    <div>
                        <div class="product-price"><?=rupiah($p['price'])?></div>
                        <div class="product-stock"><?= app_language() === "en" ? "Available stock:" : "Stok tersedia:" ?> <strong><?=$p['stock']?></strong></div>
                        <div class="qty-box">
                            <label for="qty-<?=$p['id']?>"><?= app_language() === "en" ? "Quantity" : "Jumlah" ?></label>
                            <input id="qty-<?=$p['id']?>" type="number" name="qty[<?=$p['id']?>]" min="0" max="<?=$p['stock']?>" value="0" required>
                        </div>
                        <div class="product-actions">
                            <button type="submit" class="btn-cart" name="add_to_cart" value="<?=$p['id']?>">                            🛒 <?= app_language() === "en" ? "Add to Cart" : "Masukkan" ?></button>
                            <button type="submit" class="btn-buy" name="buy_now" value="<?=$p['id']?>" formaction="checkout.php"><?= app_language() === "en" ? "Buy Now" : "Beli Sekarang" ?></button>
                        </div>
                    </div>
                </div>
                <?php endforeach;?>
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