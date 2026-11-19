<?php
require dirname(__DIR__, 2) . "/includes/config.php";
require_once dirname(__DIR__, 2) . "/includes/email.php";
require_role("seller");

$action = $_GET["action"] ?? "list";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    check_csrf();

    if (($_POST["action"] ?? "") === "manual_order") {
        $customer_name_input = $_POST["customer_name"] ?? "";
        $customer_phone_input = $_POST["customer_phone"] ?? "";
        $payment_method = $_POST["payment_method"] ?? "";
        $status = $_POST["manual_status"] ?? "";
        $payment_status = $_POST["manual_payment_status"] ?? "";
        $shipping_area = $_POST["shipping_area"] ?? "";
        $qty_by_product = $_POST["qty"] ?? [];
        $valid_payment_methods = ["qris", "bca", "cod", "cash"];
        $valid_statuses = ["not_shipped", "shipping", "completed", "returned"];
        $valid_payment_statuses = ["unpaid", "paid", "refunded"];

        if (!is_string($customer_name_input) || !is_string($customer_phone_input)) {
            flash("Data pelanggan tidak valid. Silakan periksa kembali.");
            header("Location: orders.php");
            exit();
        }
        $customer_name = trim($customer_name_input);
        $customer_phone = trim($customer_phone_input);
        if (preg_match('/\A.{1,120}\z/us', $customer_name) !== 1) {
            flash("Nama pelanggan wajib diisi (maksimal 120 karakter).");
            header("Location: orders.php");
            exit();
        }
        if ($customer_phone !== "" && preg_match('/\A.{1,30}\z/us', $customer_phone) !== 1) {
            flash("Nomor telepon maksimal 30 karakter.");
            header("Location: orders.php");
            exit();
        }
        if (!is_string($payment_method) ||
            !is_string($status) ||
            !is_string($payment_status) ||
            !in_array($payment_method, $valid_payment_methods, true) ||
            !in_array($status, $valid_statuses, true) ||
            !in_array($payment_status, $valid_payment_statuses, true) ||
            !is_array($qty_by_product)) {
            flash("Data pesanan tidak valid. Silakan periksa kembali.");
            header("Location: orders.php");
            exit();
        }
        if (
            !is_string($shipping_area) ||
            !in_array($shipping_area, ["pickup", "jabodetabek", "outside", "free_zone"], true)
        ) {
            flash("Data pengiriman tidak valid. Silakan periksa kembali.");
            header("Location: orders.php?action=create");
            exit();
        }
        if ($payment_method === "cod" && $shipping_area !== "free_zone") {
            flash("COD hanya dapat digunakan untuk wilayah gratis ongkir khusus.");
            header("Location: orders.php?action=create");
            exit();
        }
        $shipping_fee = calculate_shipping_fee($shipping_area);

        try {
            $pdo->beginTransaction();
            $items = [];
            $total = 0;

            foreach ($qty_by_product as $product_id => $qty) {
                $product_id = (int) $product_id;
                if (!is_string($qty) && !is_int($qty)) {
                    throw new RuntimeException("Jumlah produk tidak valid.");
                }
                $qty = filter_var($qty, FILTER_VALIDATE_INT);
                if ($product_id < 1 || $qty === false || $qty < 0) {
                    throw new RuntimeException("Jumlah produk tidak valid.");
                }
                if ($qty === 0) {
                    continue;
                }

                $product_stmt = $pdo->prepare(
                    "SELECT id, name, price, stock FROM products WHERE id = ? AND active = 1 FOR UPDATE"
                );
                $product_stmt->execute([$product_id]);
                $product = $product_stmt->fetch();
                if (!$product || $qty > (int) $product["stock"]) {
                    throw new RuntimeException("Stok produk tidak mencukupi.");
                }

                $subtotal = $qty * (float) $product["price"];
                $items[] = [
                    "product" => $product,
                    "qty" => $qty,
                    "subtotal" => $subtotal,
                ];
                $total += $subtotal;
            }

            if (!$items) {
                throw new RuntimeException("Pilih minimal satu produk.");
            }

            $order_code = mt_rand(1000000000, 9999999999);
            $stock_deducted = in_array($status, ["shipping", "completed"], true) ? 1 : 0;
            $insert_order = $pdo->prepare(
                "INSERT INTO orders (buyer_id, customer_name, customer_phone, total, payment_method, order_seq, order_code, status, payment_status, stock_deducted, shipping_area, shipping_fee) VALUES (NULL, ?, ?, ?, ?, 1, ?, ?, ?, ?, ?, ?)"
            );
            $insert_order->execute([
                $customer_name,
                $customer_phone !== "" ? $customer_phone : null,
                $total + $shipping_fee,
                $payment_method,
                $order_code,
                $status,
                $payment_status,
                $stock_deducted,
                $shipping_area,
                $shipping_fee,
            ]);
            $order_id = (int) $pdo->lastInsertId();

            $insert_item = $pdo->prepare(
                "INSERT INTO order_items (order_id, product_id, product_name, qty, price, subtotal) VALUES (?, ?, ?, ?, ?, ?)"
            );
            foreach ($items as $item) {
                $product = $item["product"];
                $insert_item->execute([
                    $order_id,
                    $product["id"],
                    $product["name"],
                    $item["qty"],
                    $product["price"],
                    $item["subtotal"],
                ]);
            }

            if ($stock_deducted) {
                $deduct_stock = $pdo->prepare(
                    "UPDATE products SET stock = stock - ? WHERE id = ? AND stock >= ?"
                );
                foreach ($items as $item) {
                    $deduct_stock->execute([
                        $item["qty"],
                        $item["product"]["id"],
                        $item["qty"],
                    ]);
                    if ($deduct_stock->rowCount() !== 1) {
                        throw new RuntimeException("Stok produk berubah. Silakan ulangi.");
                    }
                }
            }

            $pdo->commit();
            flash("Pesanan manual berhasil ditambahkan.");
        } catch (RuntimeException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            flash($e->getMessage());
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log("Gagal menyimpan pesanan manual: " . $e->getMessage());
            flash("Pesanan gagal disimpan. Silakan coba lagi.");
        }

        header("Location: orders.php");
        exit();
    }

    $id = filter_var($_POST["id"] ?? null, FILTER_VALIDATE_INT);
    $post_action = $_POST["action"] ?? "update_order_details";

    if ($id === false || $id < 1 || !is_string($post_action)) {
        flash("Data pembaruan tidak valid. Silakan coba lagi.");
    } elseif ($post_action === "update_order_details") {
        $payment_status = $_POST["payment_status"] ?? "";
        $status = $_POST["status"] ?? "";
        $allowed_payment_statuses = ["unpaid", "awaiting_seller", "paid", "refunded"];
        $allowed_statuses = ["not_shipped", "shipping", "completed", "returned"];

        if (
            !is_string($payment_status) ||
            !in_array($payment_status, $allowed_payment_statuses, true) ||
            !is_string($status) ||
            !in_array($status, $allowed_statuses, true)
        ) {
            flash("Data status pembayaran atau pesanan tidak valid.");
        } else {
            try {
                $pdo->beginTransaction();
                $email_notifications = [];
                $order_stmt = $pdo->prepare(
                    "SELECT id, order_code, buyer_id, payment_method, payment_status, payment_proof, status, tracking_number, stock_deducted FROM orders WHERE id = ? FOR UPDATE"
                );
                $order_stmt->execute([$id]);
                $order = $order_stmt->fetch();

                if (!$order) {
                    throw new RuntimeException("Pesanan tidak ditemukan.");
                }
                if ($payment_status === "awaiting_seller" && empty($order["payment_proof"])) {
                    throw new RuntimeException(
                        "Status menunggu verifikasi hanya dapat dipilih jika bukti pembayaran sudah diunggah."
                    );
                }

                $payment_status_changed = $order["payment_status"] !== $payment_status;
                $update = $pdo->prepare("UPDATE orders SET payment_status = ? WHERE id = ?");
                $update->execute([$payment_status, $id]);
                if ($payment_status_changed && in_array($payment_status, ["paid", "refunded"], true)) {
                    $display_order_id = $order["order_code"] ?? $order["id"];
                    $notification_type = $payment_status === "paid"
                        ? "payment_paid"
                        : "payment_refunded";
                    $notification_message = $payment_status === "paid"
                        ? "Pembayaran pesanan #{$display_order_id} telah lunas."
                        : "Pengembalian dana untuk pesanan #{$display_order_id} telah diproses.";
                    $email_notification = create_order_notification(
                        $pdo,
                        $order,
                        $notification_type,
                        $notification_message
                    );
                    if ($email_notification !== null) {
                        $email_notifications[] = $email_notification;
                    }
                }
                $email_notification = save_order_status(
                    $pdo,
                    $order,
                    $status,
                    $order["tracking_number"] !== "" ? $order["tracking_number"] : null
                );
                if ($email_notification !== null) {
                    $email_notifications[] = $email_notification;
                }
                $pdo->commit();
                foreach ($email_notifications as $email_notification) {
                    send_order_notification_email(
                        $email_notification["email"],
                        $email_notification["name"],
                        $email_notification["message"],
                        $email_notification["type"]
                    );
                }
                flash("Status pembayaran dan pesanan berhasil diperbarui.", "success");
            } catch (RuntimeException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                flash($e->getMessage());
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log("Gagal memperbarui status pembayaran: " . $e->getMessage());
                flash("Perubahan status gagal disimpan. Silakan coba lagi.");
            }
        }
    } elseif ($post_action === "update_tracking_number") {
        $tracking_number_input = $_POST["tracking_number"] ?? "";

        if (!is_string($tracking_number_input)) {
            flash("Nomor resi tidak valid.");
        } else {
            $tracking_number = trim($tracking_number_input);
            if (preg_match('/\A[^\x00-\x1F\x7F]{0,100}\z/u', $tracking_number) !== 1) {
                flash("Nomor resi maksimal 100 karakter dan tidak boleh berisi karakter kontrol.");
            } else {
                try {
                    $pdo->beginTransaction();
                    $old = $pdo->prepare(
                        "SELECT id, order_code, buyer_id, payment_method, status, tracking_number, stock_deducted, shipping_area, shipping_fee FROM orders WHERE id = ? FOR UPDATE"
                    );
                    $old->execute([$id]);
                    $old_order = $old->fetch();
                    if (!$old_order) {
                        throw new RuntimeException("Pesanan tidak ditemukan.");
                    }
                    if (
                        !in_array($old_order["shipping_area"], ["jabodetabek", "outside"], true) ||
                        (float) $old_order["shipping_fee"] <= 0
                    ) {
                        throw new RuntimeException("Nomor resi hanya dapat diisi untuk pesanan dengan ongkir berbayar.");
                    }

                    $tracking_number_changed = $tracking_number !== (string) ($old_order["tracking_number"] ?? "");
                    $status = $old_order["status"];
                    if ($tracking_number !== "" && $tracking_number_changed) {
                        $status = "shipping";
                    }

                    $email_notification = save_order_status(
                        $pdo,
                        $old_order,
                        $status,
                        $tracking_number !== "" ? $tracking_number : null
                    );
                    $pdo->commit();
                    if ($email_notification !== null) {
                        send_order_notification_email(
                            $email_notification["email"],
                            $email_notification["name"],
                            $email_notification["message"],
                            $email_notification["type"]
                        );
                    }
                    flash(
                        $tracking_number !== "" && $tracking_number_changed
                            ? "Nomor resi disimpan dan status pesanan diperbarui menjadi Proses Pengiriman."
                            : "Nomor resi berhasil diperbarui."
                    );
                } catch (RuntimeException $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    flash($e->getMessage());
                } catch (PDOException $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    error_log("Gagal memperbarui status pesanan: " . $e->getMessage());
                    flash("Status pesanan gagal diperbarui. Silakan coba lagi.");
                }
            }
        }
    } else {
        flash("Aksi pembaruan tidak dikenal.");
    }
    
    // Pertahankan parameter filter saat redirect setelah update status
    $query_params = $_GET;
    $redirect_q = !empty($query_params) ? '?' . http_build_query($query_params) : '';
    header("Location: orders.php" . $redirect_q);
    exit();
}

// Tangkap parameter filter (Kolom 1, Kolom 2, Kolom 3 / Date range)
$search = trim($_GET['q'] ?? '');
$status_filter = trim($_GET['status'] ?? '');
$payment_status_filter = trim($_GET['payment_status'] ?? '');
$date_from = trim($_GET['date_from'] ?? '');
$date_to = trim($_GET['date_to'] ?? '');

// Konstruksi query pencarian dinamis
$where = ["1=1"];
$params = [];

if ($search !== '') {
    $where[] = "(o.order_code LIKE ? OR COALESCE(o.customer_name, u.name) LIKE ? OR u.email LIKE ? OR o.customer_phone LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if ($status_filter !== '') {
    $where[] = "o.status = ?";
    $params[] = $status_filter;
}

if ($payment_status_filter !== '') {
    $where[] = "o.payment_status = ?";
    $params[] = $payment_status_filter;
}

if ($date_from !== '' && $date_to !== '') {
    $where[] = "DATE(o.created_at) BETWEEN ? AND ?";
    $params[] = $date_from;
    $params[] = $date_to;
} elseif ($date_from !== '') {
    $where[] = "DATE(o.created_at) >= ?";
    $params[] = $date_from;
} elseif ($date_to !== '') {
    $where[] = "DATE(o.created_at) <= ?";
    $params[] = $date_to;
}

$perPage = 5;
$page = max(1, (int) ($_GET['page'] ?? 1));
$whereSql = implode(' AND ', $where);
$countStmt = $pdo->prepare(
    "SELECT COUNT(*) FROM orders o LEFT JOIN users u ON u.id=o.buyer_id WHERE " . $whereSql
);
$countStmt->execute($params);
$totalRows = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalRows / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$sql = "SELECT o.*, COALESCE(o.customer_name, u.name) buyer, u.email, u.province, u.city, u.district, u.village, u.address FROM orders o LEFT JOIN users u ON u.id=o.buyer_id WHERE " . $whereSql . " ORDER BY o.id DESC LIMIT ? OFFSET ?";
$stmt = $pdo->prepare($sql);
foreach ($params as $index => $param) {
    $stmt->bindValue($index + 1, $param);
}
$stmt->bindValue(count($params) + 1, $perPage, PDO::PARAM_INT);
$stmt->bindValue(count($params) + 2, $offset, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll();
$products = $pdo->query("SELECT id, name, price, stock FROM products WHERE active = 1 ORDER BY name")->fetchAll();
?><!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Pesanan Pembeli - Admin</title>
    <base href="<?= e(app_url('pages/seller/')) ?>">
    <link rel="icon" type="image/svg+xml" href="<?= e(app_favicon_url()) ?>">
    <link rel="stylesheet" href="<?= e(app_url('assets/css/style.css')) ?>?v=<?= (int) filemtime(app_path('assets/css/style.css')) ?>">
    <style>
        .card-filter {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 24px;
            box-shadow: 0 2px 6px rgba(0,0,0,0.02);
        }
        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 16px;
            align-items: end;
        }
        .orders-filter-grid {
            grid-template-columns: repeat(3, minmax(0, 1fr));
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
            height: 40px;
            padding: 8px 12px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            background: #fff;
            font-size: 14px;
            margin-top: 0;
        }
        .filter-actions {
            display: flex;
            gap: 8px;
        }
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
        .detail-row td {
            background: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
            white-space: normal;
            vertical-align: top;
        }
        .detail-grid {
            display: grid;
            grid-template-columns: minmax(260px, 1.05fr) minmax(280px, 2fr);
            grid-template-areas: "details updates";
            gap: 24px;
            align-items: start;
        }
        .detail-panel {
            min-width: 0;
        }
        .detail-products-panel { grid-area: details; }
        .detail-updates-panel { grid-area: updates; }
        .detail-status-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 16px;
        }
        .detail-updates-panel > .btn {
            margin-top: 12px;
        }
        .detail-receipt-panel {
            margin: 0;
        }
        .detail-shipping-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 16px;
            margin-top: 16px;
            padding-top: 14px;
            align-items: start;
        }
        .detail-shipping-grid .detail-form label {
            margin-top: 0;
        }
        .detail-receipt-panel p {
            margin: 0 0 8px;
            color: #64748b;
            font-size: 12px;
        }
        .detail-receipt-panel .btn {
            min-height: 40px;
            padding: 8px 14px;
        }
        .detail-panel h4 {
            margin: 0 0 12px;
            font-size: 14px;
            color: #1e293b;
        }
        .detail-products-panel h4 {
            margin-bottom: 8px;
            font-size: 13px;
        }
        .detail-products-panel p {
            font-size: 12px !important;
            line-height: 1.5;
        }
        .detail-products-panel .detail-list {
            font-size: 12px;
        }
        .detail-products-panel .detail-shipping-title {
            margin-top: 14px;
        }
        .detail-proof-empty {
            margin: 0;
            padding: 12px;
            border: 1px dashed #cbd5e1;
            border-radius: 8px;
            background: #fff;
            color: #64748b;
            font-size: 12px;
            line-height: 1.6;
        }
        .detail-proof-image {
            display: block;
            max-width: 100%;
            width: 180px;
            border-radius: 8px;
            border: 1px solid #cbd5e1;
        }
        .detail-list {
            margin: 0;
            padding-left: 18px;
            font-size: 13px;
            color: #475569;
        }
        .detail-list li {
            margin-bottom: 6px;
            line-height: 1.5;
        }
        .detail-form {
            display: flex;
            flex-direction: column;
            gap: 8px;
            align-items: stretch;
        }
        .detail-form select, .detail-status-grid select {
            width: 100%;
            min-width: 0;
            margin-top: 0;
            height: 42px;
            padding: 8px 12px;
            font-size: 14px;
            border-radius: 8px;
        }
        .detail-form input[type="text"] {
            width: 100%;
            min-width: 0;
            margin-top: 0;
            height: 42px;
            padding: 8px 12px;
            font-size: 14px;
            border-radius: 8px;
        }
        .detail-form .btn {
            margin-top: 0;
            padding: 8px 14px;
            font-size: 13px;
            min-height: 40px;
            background: #059669;
            align-self: flex-start;
        }
        .detail-shipping-grid .detail-form .btn {
            background: #2563eb;
        }
        @media (max-width: 1000px) {
            .detail-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
                grid-template-areas: "details updates";
                gap: 22px;
            }
        }
        @media (max-width: 640px) {
            .detail-row td {
                padding: 16px !important;
            }
            .detail-grid {
                grid-template-columns: minmax(0, 1fr);
                grid-template-areas: "details" "updates";
                gap: 20px;
            }
            .detail-status-grid {
                grid-template-columns: minmax(0, 1fr);
            }
            .detail-shipping-grid {
                grid-template-columns: minmax(0, 1fr);
            }
            .detail-shipping-spacer {
                display: none;
            }
        }
        .badge {
            display: inline-block;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-align: center;
        }
        .badge-pending, .badge-unpaid, .badge-not_shipped { background: #fef3c7; color: #d97706; }
        .badge-awaiting_seller { background: #e0f2fe; color: #0284c7; }
        .badge-paid { background: #d1fae5; color: #059669; }
        .badge-shipping { background: #ede9fe; color: #7c3aed; }
        .badge-completed { background: #dcfce7; color: #15803d; }
        .badge-refunded, .badge-returned { background: #fee2e2; color: #dc2626; }
        
        .btn-sm {
            padding: 6px 14px;
            font-size: 13px;
            border-radius: 6px;
            font-weight: 600;
            text-decoration: none;
            display: inline-block;
            cursor: pointer;
        }
        .btn-outline {
            background: #fff;
            border: 1px solid #cbd5e1;
            color: #334155;
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
        @media (max-width: 600px) {
            .orders-filter-grid {
                grid-template-columns: minmax(0, 1fr);
            }
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
        @media (max-width: 800px) {
            .table-modern th:nth-child(3),
            .table-modern tr:not(.detail-row) td:nth-child(3) {
                display: none !important;
            }
        }
    </style>
</head>
<body>
<?php include dirname(__DIR__, 2) . "/includes/partials/seller_nav.php"; ?>
<main>
    <?php show_flash(); ?>

    <?php if ($action === "create"): ?>
    <div class="seller-form-header" style="max-width: 1050px;">
        <div>
            <h1 style="margin: 0 0 4px 0;">Buat Pesanan</h1>
            <p class="muted" style="margin: 0;">Catat pesanan pelanggan yang belum menggunakan aplikasi.</p>
        </div>
        <a href="orders.php" class="btn btn-outline seller-form-back">← Kembali</a>
    </div>

    <section class="card-filter" style="max-width: 1050px; margin: 0 auto;">
        <p class="muted" style="margin: 0 0 16px;">Stok berkurang saat pesanan mulai dikirim.</p>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= csrf() ?>">
            <input type="hidden" name="action" value="manual_order">
            <div class="filter-grid">
                <div class="filter-group">
                    <label for="customer_name">Nama pelanggan</label>
                    <input id="customer_name" name="customer_name" maxlength="120" required>
                </div>
                <div class="filter-group">
                    <label for="customer_phone">Nomor telepon (opsional)</label>
                    <input id="customer_phone" name="customer_phone" maxlength="30">
                </div>
                <div class="filter-group">
                    <label for="payment_method">Metode pembayaran</label>
                    <select id="payment_method" name="payment_method" required>
                        <option value="cash">Tunai</option>
                        <option value="qris">QRIS</option>
                        <option value="bca">Transfer BCA</option>
                        <option id="manual-cod-option" value="cod" disabled>COD (khusus gratis ongkir)</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label for="manual_status">Status pesanan</label>
                    <select id="manual_status" name="manual_status" required>
                        <option value="not_shipped">Belum Dikirim</option>
                        <option value="shipping">Proses Pengiriman</option>
                        <option value="completed">Selesai</option>
                        <option value="returned">Dikembalikan</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label for="manual_payment_status">Status pembayaran</label>
                    <select id="manual_payment_status" name="manual_payment_status" required>
                        <option value="unpaid">Belum Dibayar</option>
                        <option value="paid">Lunas</option>
                        <option value="refunded">Pengembalian Dana</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label for="shipping_area">Wilayah pengiriman</label>
                    <select id="shipping_area" name="shipping_area" required>
                        <option value="pickup">Ambil sendiri</option>
                        <option value="free_zone">Wilayah gratis ongkir khusus</option>
                        <option value="jabodetabek">Jabodetabek</option>
                        <option value="outside">Luar Jabodetabek / pulau lain</option>
                    </select>
                </div>
            </div>
            <p class="muted" style="margin: 12px 0 0;">Pesanan manual: pilih gratis ongkir untuk Kecamatan Pasar Kemis, Sepatan, Rajeg, atau Kelurahan Jatiwaringin di Kecamatan Mauk. COD hanya tersedia untuk wilayah gratis ongkir. Selain itu, Jabodetabek Rp25.000 dan luar Jabodetabek Rp50.000.</p>
            <div class="table-wrap" style="margin: 16px 0;">
                <table class="table-modern">
                    <thead>
                        <tr><th>Produk</th><th>Harga</th><th>Stok</th><th style="width: 130px;">Jumlah</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($products as $product): ?>
                        <tr>
                            <td><?= e($product["name"]) ?></td>
                            <td><?= rupiah($product["price"]) ?></td>
                            <td><?= (int) $product["stock"] ?></td>
                            <td><input aria-label="Jumlah <?= e($product["name"]) ?>" type="number" name="qty[<?= (int) $product["id"] ?>]" min="0" max="<?= (int) $product["stock"] ?>" value="0" <?= (int) $product["stock"] < 1 ? "disabled" : "" ?> style="margin: 0;"></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (!$products): ?>
                        <tr><td colspan="4" style="text-align: center;">Belum ada produk aktif.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <button type="submit" class="btn" <?= !$products ? "disabled" : "" ?>>Simpan Pesanan Manual</button>
        </form>
    </section>

    <?php else: ?>
    <div style="margin-bottom: 24px; display: flex; justify-content: space-between; align-items: center; gap: 16px; flex-wrap: wrap;">
        <div>
            <h1 style="margin: 0 0 6px 0;">Pesanan Pembeli</h1>
            <p class="muted" style="margin: 0;">Perbarui status pembayaran dan status pesanan secara terpisah.</p>
        </div>
        <a href="orders.php?action=create" class="btn" style="height: 42px; padding: 0 20px; white-space: nowrap;">+ Buat Pesanan</a>
    </div>

    <!-- Menu Filter Berbentuk Kolom -->
    <div class="card-filter">
        <form method="get">
            <div class="filter-grid orders-filter-grid">
                <!-- Kolom 1: Nomor Pesanan / Nama Pelanggan -->
                <div class="filter-group">
                    <label>Cari (No. Pesanan / Nama)</label>
                    <input type="text" name="q" value="<?= e($search) ?>" placeholder="No. ID / Nama / Email...">
                </div>

                <!-- Kolom 2: Status Pesanan -->
                <div class="filter-group">
                    <label>Status Pesanan</label>
                    <select name="status">
                        <option value="">Semua Status</option>
                        <option value="not_shipped" <?= $status_filter === 'not_shipped' ? 'selected' : '' ?>>Belum Dikirim</option>
                        <option value="shipping" <?= $status_filter === 'shipping' ? 'selected' : '' ?>>Proses Pengiriman</option>
                        <option value="completed" <?= $status_filter === 'completed' ? 'selected' : '' ?>>Selesai</option>
                        <option value="returned" <?= $status_filter === 'returned' ? 'selected' : '' ?>>Dikembalikan</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label>Status Pembayaran</label>
                    <select name="payment_status">
                        <option value="">Semua Status</option>
                        <option value="unpaid" <?= $payment_status_filter === 'unpaid' ? 'selected' : '' ?>>Belum Dibayar</option>
                        <option value="awaiting_seller" <?= $payment_status_filter === 'awaiting_seller' ? 'selected' : '' ?>>Menunggu Konfirmasi Pembayaran</option>
                        <option value="paid" <?= $payment_status_filter === 'paid' ? 'selected' : '' ?>>Lunas</option>
                        <option value="refunded" <?= $payment_status_filter === 'refunded' ? 'selected' : '' ?>>Pengembalian Dana</option>
                    </select>
                </div>

                <!-- Kolom 3: Date Range (Rentang Tanggal) -->
                <div class="filter-group">
                    <label>Dari Tanggal</label>
                    <input type="date" name="date_from" value="<?= e($date_from) ?>">
                </div>

                <div class="filter-group">
                    <label>Sampai Tanggal</label>
                    <input type="date" name="date_to" value="<?= e($date_to) ?>">
                </div>

                <!-- Tombol Aksi Filter -->
                <div class="filter-group filter-actions">
                    <button class="btn" style="height: 40px; margin-top: 0; background: #2563eb; flex: 1;">Cari</button>
                    <a href="orders.php" class="btn btn-outline" style="height: 40px; line-height: 38px; margin-top: 0; text-align: center; padding: 0 16px;">Reset</a>
                </div>
            </div>
        </form>
    </div>

    <!-- Tabel Data Pesanan Modern -->
    <div class="table-wrap">
        <table class="table-modern">
            <thead>
                <tr>
                    <th>No. ID</th>
                    <th>Pelanggan</th>
                    <th>Tanggal</th>
                    <th>Total</th>
                    <th>Metode</th>
                    <th>Status Pembayaran</th>
                    <th>Status Pesanan</th>
                    <th style="text-align: right;"></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $o): ?>
                <?php 
                    $order_unique_id = $o["order_code"] ?? $o["id"];
                    $order_seq_label = "Pesanan #" . ($o["order_seq"] ?? 1);
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
                ?>
                <tr>
                    <td>
                        <strong style="color: #2563eb;"><?= e($order_unique_id) ?></strong>
                        <div style="font-size: 11px; color: #64748b;"><?= $order_seq_label ?></div>
                    </td>
                    <td>
                        <strong><?= e($o["buyer"]) ?></strong>
                        <div style="font-size: 12px; color: #64748b;"><?= e($o["email"] ?? $o["customer_phone"] ?? "Pelanggan manual") ?></div>
                    </td>
                    <td style="color: #475569; font-size: 13px;"><?= e($o["created_at"]) ?></td>
                    <td><strong><?= rupiah($o["total"]) ?></strong></td>
                    <td><span style="font-weight: 600; font-size: 12px; color: #334155;"><?= strtoupper(e($o["payment_method"] ?? 'QRIS')) ?></span></td>
                    <td>
                        <span class="badge badge-<?= e($o["payment_status"]) ?>"><?= e($payment_status_labels[$o["payment_status"]] ?? $o["payment_status"]) ?></span>
                    </td>
                    <td>
                        <span class="badge badge-<?= e($o["status"]) ?>"><?= e($order_status_labels[$o["status"]] ?? $o["status"]) ?></span>
                    </td>
                    <td style="text-align: right;">
                        <button type="button" class="btn-sm btn-outline" onclick="toggleDetail(<?= $o['id'] ?>)">Detail ▾</button>
                    </td>
                </tr>

                <!-- Baris Detail Tersembunyi (Expandable Detail Row) -->
                <tr id="detail-row-<?= $o['id'] ?>" class="detail-row" style="display: none;">
                    <td colspan="8" style="padding: 20px;">
                        <div class="detail-grid">
                            <div class="detail-panel detail-products-panel">
                                <h4 style="margin: 0 0 10px 0; font-size: 14px; color: #1e293b;">Rincian Produk:</h4>
                                <ul class="detail-list">
                                    <?php
                                    $s = $pdo->prepare("SELECT * FROM order_items WHERE order_id=?");
                                    $s->execute([$o["id"]]);
                                    $order_items = $s->fetchAll();
                                    foreach ($order_items as $it):
                                    ?>
                                        <li><?= e($it["product_name"]) ?> × <?= $it["qty"] ?> — <strong><?= rupiah($it["subtotal"]) ?></strong></li>
                                    <?php endforeach; ?>
                                </ul>
                                <h4 class="detail-shipping-title">Ongkir</h4>
                                <?php if ($o["buyer_id"] !== null): ?>
                                    <p class="muted" style="font-size: 12px; margin: 0 0 10px;">
                                        Alamat: <?= e(implode(", ", array_filter([
                                            $o["address"],
                                            $o["village"],
                                            $o["district"],
                                            $o["city"],
                                            $o["province"],
                                        ])) ?: "Belum diisi") ?>
                                    </p>
                                <?php endif; ?>
                                <?php if ($o["shipping_area"] === "free_zone"): ?>
                                    <p style="font-size: 13px; margin: 0 0 10px;">Gratis ongkir wilayah khusus.</p>
                                <?php elseif ($o["shipping_area"] === "pickup"): ?>
                                    <p style="font-size: 13px; margin: 0 0 10px;">Ambil sendiri — gratis.</p>
                                <?php elseif ($o["shipping_area"] !== null): ?>
                                    <p style="font-size: 13px; margin: 0 0 10px;">
                                        <?= $o["shipping_area"] === "jabodetabek" ? "Jabodetabek" : "Luar Jabodetabek" ?> —
                                        <?= rupiah($o["shipping_fee"]) ?>
                                    </p>
                                <?php else: ?>
                                    <p class="muted" style="font-size: 13px; margin: 0 0 10px;">Alamat belum tersedia untuk menentukan ongkir.</p>
                                <?php endif; ?>
                                <?php if(!empty($o["payment_proof"])): ?>
                                    <h4 class="detail-shipping-title">Bukti Pembayaran</h4>
                                    <a href="<?= e(app_url($o["payment_proof"])) ?>" target="_blank">
                                        <img class="detail-proof-image" src="<?= e(app_url($o["payment_proof"] ?? '')) ?>" alt="Bukti Pembayaran">
                                    </a>
                                    <div style="font-size: 11px; margin-top: 4px;"><a href="<?= e(app_url($o["payment_proof"] ?? '')) ?>" target="_blank" style="color: #2563eb; text-decoration: none;">🔍 Perbesar Gambar</a></div>
                                <?php else: ?>
                                    <h4 class="detail-shipping-title">Bukti Pembayaran</h4>
                                    <p class="detail-proof-empty">Belum ada bukti pembayaran yang diunggah.</p>
                                <?php endif; ?>
                            </div>

                            <div class="detail-panel detail-updates-panel">
                                <form id="order-update-<?= (int) $o["id"] ?>" method="post">
                                    <input type="hidden" name="csrf" value="<?= csrf() ?>">
                                    <input type="hidden" name="id" value="<?= $o["id"] ?>">
                                    <input type="hidden" name="action" value="update_order_details">
                                </form>
                                <div class="detail-status-grid">
                                    <div>
                                        <label for="payment-status-<?= (int) $o["id"] ?>">Status Pembayaran</label>
                                        <select id="payment-status-<?= (int) $o["id"] ?>" name="payment_status" form="order-update-<?= (int) $o["id"] ?>">
                                            <?php foreach ($payment_status_labels as $code => $label): ?>
                                                <option <?= $o["payment_status"] === $code ? "selected" : "" ?> value="<?= e($code) ?>" <?= $code === "awaiting_seller" && empty($o["payment_proof"]) ? "disabled" : "" ?>><?= e($label) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div>
                                        <label for="order-status-<?= (int) $o["id"] ?>">Status Pesanan</label>
                                        <select id="order-status-<?= (int) $o["id"] ?>" name="status" form="order-update-<?= (int) $o["id"] ?>">
                                            <?php foreach ($order_status_labels as $code => $label): ?>
                                                <option <?= $o["status"] === $code ? "selected" : "" ?> value="<?= e($code) ?>"><?= e($label) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <button class="btn" type="submit" form="order-update-<?= (int) $o["id"] ?>">Simpan Perubahan</button>
                                <?php if (
                                    in_array($o["shipping_area"], ["jabodetabek", "outside"], true) &&
                                    (float) $o["shipping_fee"] > 0
                                ): ?>
                                    <div class="detail-shipping-grid">
                                        <div class="detail-receipt-panel">
                                            <h4>Cetak Resi Pengiriman</h4>
                                            <a class="btn" href="order_receipt.php?id=<?= (int) $o["id"] ?>" target="_blank" rel="noopener">
                                                Cetak Resi
                                            </a>
                                        </div>
                                        <div class="detail-receipt-panel">
                                            <form method="post" class="detail-form">
                                                <input type="hidden" name="csrf" value="<?= csrf() ?>">
                                                <input type="hidden" name="id" value="<?= $o["id"] ?>">
                                                <input type="hidden" name="action" value="update_tracking_number">
                                                <label for="tracking-number-<?= (int) $o["id"] ?>">Nomor Resi Pengiriman</label>
                                                <input
                                                    id="tracking-number-<?= (int) $o["id"] ?>"
                                                    type="text"
                                                    name="tracking_number"
                                                    maxlength="100"
                                                    value="<?= e($o["tracking_number"] ?? "") ?>"
                                                    placeholder="Masukkan nomor resi"
                                                >
                                                <button class="btn" type="submit">Simpan Nomor Resi</button>
                                            </form>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>

                <?php if (empty($rows)): ?>
                    <tr>
                        <td colspan="8" style="text-align: center; padding: 40px; color: #64748b;">Tidak ada pesanan yang ditemukan sesuai filter.</td>
                    </tr>
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
        $paginationParams = $_GET;
        ?>
        <span class="pagination-summary">
            Menampilkan <?= $firstRow ?>–<?= $lastRow ?> dari <?= $totalRows ?> pesanan
        </span>
        <nav class="pagination-controls" aria-label="Navigasi halaman pesanan">
            <?php if ($page > 1): ?>
                <?php $paginationParams['page'] = $page - 1; ?>
                <a href="orders.php?<?= e(http_build_query($paginationParams)) ?>" aria-label="Halaman sebelumnya">&lsaquo;</a>
            <?php else: ?>
                <span class="page-disabled" aria-disabled="true" aria-label="Tidak ada halaman sebelumnya">&lsaquo;</span>
            <?php endif; ?>

            <?php if ($startPage > 1): ?>
                <?php $paginationParams['page'] = 1; ?>
                <a href="orders.php?<?= e(http_build_query($paginationParams)) ?>" aria-label="Halaman 1">1</a>
                <?php if ($startPage > 2): ?><span class="page-ellipsis" aria-hidden="true">&hellip;</span><?php endif; ?>
            <?php endif; ?>

            <?php for ($pageNumber = $startPage; $pageNumber <= $endPage; $pageNumber++): ?>
                <?php if ($pageNumber === $page): ?>
                    <span class="current-page" aria-current="page" aria-label="Halaman <?= $pageNumber ?>"><?= $pageNumber ?></span>
                <?php else: ?>
                    <?php $paginationParams['page'] = $pageNumber; ?>
                    <a href="orders.php?<?= e(http_build_query($paginationParams)) ?>" aria-label="Halaman <?= $pageNumber ?>"><?= $pageNumber ?></a>
                <?php endif; ?>
            <?php endfor; ?>

            <?php if ($endPage < $totalPages): ?>
                <?php if ($endPage < $totalPages - 1): ?><span class="page-ellipsis" aria-hidden="true">&hellip;</span><?php endif; ?>
                <?php $paginationParams['page'] = $totalPages; ?>
                <a href="orders.php?<?= e(http_build_query($paginationParams)) ?>" aria-label="Halaman <?= $totalPages ?>"><?= $totalPages ?></a>
            <?php endif; ?>

            <?php if ($page < $totalPages): ?>
                <?php $paginationParams['page'] = $page + 1; ?>
                <a href="orders.php?<?= e(http_build_query($paginationParams)) ?>" aria-label="Halaman berikutnya">&rsaquo;</a>
            <?php else: ?>
                <span class="page-disabled" aria-disabled="true" aria-label="Tidak ada halaman berikutnya">&rsaquo;</span>
            <?php endif; ?>
        </nav>
    </div>
    <?php endif; ?>
    <?php endif; ?>

    <script>
        function toggleDetail(id) {
            var row = document.getElementById('detail-row-' + id);
            if (row.style.display === 'none' || row.style.display === '') {
                row.style.display = 'table-row';
            } else {
                row.style.display = 'none';
            }
        }
        <?php if ($action === "create"): ?>
        const manualShippingArea = document.getElementById("shipping_area");
        const manualPaymentMethod = document.getElementById("payment_method");
        const manualCodOption = document.getElementById("manual-cod-option");
        function updateManualCodAvailability() {
            const codAvailable = manualShippingArea.value === "free_zone";
            manualCodOption.disabled = !codAvailable;
            if (!codAvailable && manualPaymentMethod.value === "cod") {
                manualPaymentMethod.value = "cash";
            }
        }
        manualShippingArea.addEventListener("change", updateManualCodAvailability);
        updateManualCodAvailability();
        <?php endif; ?>
    </script>
</main>
</body>
</html>