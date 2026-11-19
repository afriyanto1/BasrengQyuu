<?php
require dirname(__DIR__, 2) . "/includes/config.php";
require_role("buyer");

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: shop.php");
    exit();
}

check_csrf();

$buyer_stmt = $pdo->prepare(
    "SELECT province, city, district, village FROM users WHERE id = ?"
);
$buyer_stmt->execute([user()["id"]]);
$buyer_address = $buyer_stmt->fetch();
if (!$buyer_address) {
    http_response_code(400);
    exit("Alamat akun tidak ditemukan. Lengkapi alamat sebelum checkout.");
}

try {
    $shipping_area = shipping_area_for_address($buyer_address);
    $shipping_fee = calculate_shipping_fee($shipping_area);
} catch (InvalidArgumentException $e) {
    flash($e->getMessage());
    header("Location: shop.php");
    exit();
}

$payment_method = $_POST["payment_method"] ?? "qris";
if (!is_string($payment_method) || !in_array($payment_method, ["qris", "bca", "cod"], true)) {
    flash("Metode pembayaran tidak valid. Silakan pilih kembali.");
    header("Location: shop.php");
    exit();
}
if ($payment_method === "cod" && $shipping_area !== "free_zone") {
    flash("COD hanya tersedia untuk alamat di wilayah gratis ongkir khusus.");
    header("Location: shop.php");
    exit();
}

$cart = $_POST["qty"] ?? [];
$items = [];
$total = 0;

foreach ($cart as $id => $qty) {
    $id = (int) $id;
    $qty = (int) $qty;

    if ($qty < 1) {
        continue;
    }

    $s = $pdo->prepare("SELECT * FROM products WHERE id = ? AND active = 1");
    $s->execute([$id]);
    $p = $s->fetch();

    if (!$p || $qty > $p["stock"]) {
        flash(
            "Stok produk berubah atau tidak mencukupi. Silakan periksa kembali."
        );
        header("Location: shop.php");
        exit();
    }

    $sub = $qty * (float) $p["price"];
    $items[] = [
        "product" => $p,
        "qty" => $qty,
        "subtotal" => $sub,
    ];
    $total += $sub;
}

if (empty($items)) {
    flash("Pilih minimal satu produk dengan jumlah lebih dari 0.");
    header("Location: shop.php");
    exit();
}

try {
    $pdo->beginTransaction();

    // 1. Hitung nomor urut pesanan khusus untuk user yang sedang login (berurutan 1, 2, 3...)
    $stmt_count = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE buyer_id = ?");
    $stmt_count->execute([user()["id"]]);
    $order_seq = (int)$stmt_count->fetchColumn() + 1;

    // 2. Buat Nomor ID Unik acak (contoh: 10 digit angka unik seperti 2472181921)
    $order_code = mt_rand(1000000000, 9999999999);

    // Insert data master pesanan
    $total += $shipping_fee;
    $s = $pdo->prepare(
        "INSERT INTO orders(buyer_id, total, payment_method, order_seq, order_code, shipping_distance_km, shipping_area, shipping_fee) VALUES(?, ?, ?, ?, ?, NULL, ?, ?)"
    );
    $s->execute([
        user()["id"],
        $total,
        $payment_method,
        $order_seq,
        $order_code,
        $shipping_area,
        $shipping_fee,
    ]);
    $orderId = (int) $pdo->lastInsertId();

    $ins = $pdo->prepare(
        "INSERT INTO order_items(order_id, product_id, product_name, qty, price, subtotal) VALUES(?, ?, ?, ?, ?, ?)"
    );

    foreach ($items as $item) {
        $p = $item["product"];
        $ins->execute([
            $orderId,
            $p["id"],
            $p["name"],
            $item["qty"],
            $p["price"],
            $item["subtotal"],
        ]);
    }

    $pdo->commit();

    header("Location: payment.php?id=" . $orderId);
    exit();
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    flash("Gagal memproses pesanan. Silakan coba lagi.");
    header("Location: shop.php");
    exit();
}