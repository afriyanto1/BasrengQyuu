<?php
declare(strict_types=1);
session_start();

$host = getenv("DB_HOST") ?: "dropship-db.ctq2e6428ybb.ap-southeast-2.rds.amazonaws.com";
$db = getenv("DB_NAME") ?: "basreng_pos";
$user = getenv("DB_USER") ?: "admin";
$pass = getenv('DB_PASS') ?: 'PapiAriyasa04';
$port = (int) (getenv("DB_PORT") ?: 3306);
$sslCa = getenv("DB_SSL_CA");
if ($sslCa === false || trim($sslCa) === "") {
    $sslCa = dirname(__DIR__) . DIRECTORY_SEPARATOR . "database" . DIRECTORY_SEPARATOR . "global-bundle.pem";
}

try {
    $pdo = new PDO(
        "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4",
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::MYSQL_ATTR_SSL_CA => $sslCa,
        ]
    );
} catch (PDOException $e) {
    http_response_code(500);
    exit("Koneksi database gagal. Periksa includes/config.php dan pastikan database sudah di-import.");
}

if (!function_exists('e')) {
    function e($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8");
    }
}

function app_base_path(): string
{
    $script_name = str_replace("\\", "/", $_SERVER["SCRIPT_NAME"] ?? "/");
    $pages_position = strpos($script_name, "/pages/");
    if ($pages_position !== false) {
        return substr($script_name, 0, $pages_position);
    }

    $directory = rtrim(str_replace("\\", "/", dirname($script_name)), "/");
    return $directory === "." ? "" : $directory;
}

function app_url(string $path): string
{
    if (preg_match('~^(?:[a-z][a-z0-9+.-]*:|//)~i', $path)) {
        return $path;
    }

    return rtrim(app_base_path(), "/") . "/" . ltrim($path, "/");
}

function app_path(string $path): string
{
    $relative_path = str_replace(["/", "\\"], DIRECTORY_SEPARATOR, ltrim($path, "/\\"));
    return dirname(__DIR__) . DIRECTORY_SEPARATOR . $relative_path;
}
function app_favicon_url(): string
{
    $path = "assets/images/favicon.svg";
    return app_url($path) . "?v=" . filemtime(app_path($path));
}

function rupiah($n): string
{
    return "Rp " . number_format((float) $n, 0, ",", ".");
}
function normalize_region_name(?string $name): string
{
    $name = strtolower(trim($name ?? ""));
    $name = preg_replace('/^(provinsi|kabupaten|kota administrasi|kecamatan|kec\.|kelurahan|kel\.|desa|kota)\s*/', '', $name);
    return trim($name);
}
function is_free_shipping_location(
    ?string $province,
    ?string $city,
    ?string $district,
    ?string $village
): bool
{
    $province = normalize_region_name($province);
    $city = normalize_region_name($city);
    $district = normalize_region_name($district);
    $village = normalize_region_name($village);

    return $province === "banten" &&
        $city === "tangerang" &&
        (
            in_array($district, ["pasar kemis", "sepatan", "rajeg"], true) ||
            ($district === "mauk" && $village === "jatiwaringin")
        );
}
function shipping_area_for_address(array $address): string
{
    foreach (["province", "city", "district"] as $field) {
        if (!is_string($address[$field] ?? null) || trim($address[$field]) === "") {
            throw new InvalidArgumentException("Lengkapi provinsi, kota/kabupaten, dan kecamatan pada alamat akun sebelum checkout.");
        }
    }
    if (is_free_shipping_location(
        $address["province"],
        $address["city"],
        $address["district"],
        is_string($address["village"] ?? null) ? $address["village"] : null
    )) {
        return "free_zone";
    }

    $province = normalize_region_name($address["province"]);
    $city = normalize_region_name($address["city"]);
    $jabodetabek_cities = [
        "jakarta pusat",
        "jakarta utara",
        "jakarta barat",
        "jakarta selatan",
        "jakarta timur",
        "kepulauan seribu",
        "bogor",
        "depok",
        "bekasi",
    ];
    if (
        (in_array($province, ["dki jakarta", "daerah khusus ibukota jakarta"], true) &&
            in_array($city, $jabodetabek_cities, true)) ||
        ($province === "jawa barat" && in_array($city, ["bogor", "depok", "bekasi"], true)) ||
        ($province === "banten" && in_array($city, ["tangerang", "tangerang selatan"], true))
    ) {
        return "jabodetabek";
    }

    return "outside";
}
function calculate_shipping_fee(string $shipping_area): int
{
    if (!in_array($shipping_area, ["pickup", "jabodetabek", "outside", "free_zone"], true)) {
        throw new InvalidArgumentException("Wilayah pengiriman tidak valid.");
    }

    return match ($shipping_area) {
        "jabodetabek" => 25000,
        "outside" => 50000,
        "pickup" => 0,
        "free_zone" => 0,
    };
}
function user(): ?array
{
    return $_SESSION["user"] ?? null;
}
function require_login(): void
{
    if (!user()) {
        header("Location: " . app_url("pages/auth/login.php"));
        exit();
    }
}
function require_role(string $role): void
{
    require_login();
    if (user()["role"] !== $role) {
        http_response_code(403);
        exit("Akses ditolak.");
    }
}
function csrf(): string
{
    if (empty($_SESSION["csrf"])) {
        $_SESSION["csrf"] = bin2hex(random_bytes(32));
    }
    return $_SESSION["csrf"];
}
function check_csrf(): void
{
    if (!hash_equals($_SESSION["csrf"] ?? "", $_POST["csrf"] ?? "")) {
        http_response_code(419);
        exit("Sesi formulir tidak valid. Muat ulang halaman.");
    }
}
function flash(string $message, string $type = "danger"): void
{
    $_SESSION["flash"] = $message;
    $_SESSION["flash_type"] = in_array($type, ["success", "danger"], true) ? $type : "danger";
}
function create_order_notification(PDO $pdo, array $order, string $type, string $message): ?array
{
    if (
        empty($order["buyer_id"]) ||
        !in_array($order["payment_method"] ?? null, ["qris", "bca"], true)
    ) {
        return null;
    }

    $stmt = $pdo->prepare(
        "INSERT INTO notifications (user_id, order_id, type, message) VALUES (?, ?, ?, ?)"
    );
    $stmt->execute([
        (int) $order["buyer_id"],
        (int) $order["id"],
        $type,
        $message,
    ]);
    $email_stmt = $pdo->prepare("SELECT email, name FROM users WHERE id = ?");
    $email_stmt->execute([(int) $order["buyer_id"]]);
    $buyer = $email_stmt->fetch();
    if (!$buyer || !is_string($buyer["email"]) || !filter_var($buyer["email"], FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException("Email pembeli tidak valid untuk mengirim notifikasi.");
    }

    return [
        "email" => $buyer["email"],
        "name" => (string) $buyer["name"],
        "message" => $message,
        "type" => $type,
    ];
}
function save_order_status(
    PDO $pdo,
    array $order,
    string $status,
    ?string $tracking_number
): ?array {
    $was_stock_deducted = (bool) $order["stock_deducted"];
    $is_stock_deducted = in_array($status, ["shipping", "completed"], true)
        || (
            $status === "not_shipped" &&
            $order["status"] === "not_shipped" &&
            $was_stock_deducted
        );
    $items_stmt = $pdo->prepare("SELECT product_id, qty FROM order_items WHERE order_id = ?");
    $items_stmt->execute([$order["id"]]);
    $items = $items_stmt->fetchAll();

    if (!$was_stock_deducted && $is_stock_deducted) {
        $deduct_stock = $pdo->prepare(
            "UPDATE products SET stock = stock - ? WHERE id = ? AND stock >= ?"
        );
        foreach ($items as $item) {
            $deduct_stock->execute([$item["qty"], $item["product_id"], $item["qty"]]);
            if ($deduct_stock->rowCount() !== 1) {
                throw new RuntimeException("Stok produk tidak mencukupi untuk memproses pesanan.");
            }
        }
    } elseif ($was_stock_deducted && !$is_stock_deducted) {
        $restore_stock = $pdo->prepare("UPDATE products SET stock = stock + ? WHERE id = ?");
        foreach ($items as $item) {
            $restore_stock->execute([$item["qty"], $item["product_id"]]);
        }
    }

    $pdo->prepare("UPDATE orders SET status = ?, tracking_number = ?, stock_deducted = ? WHERE id = ?")
        ->execute([
            $status,
            $tracking_number,
            $is_stock_deducted ? 1 : 0,
            $order["id"],
        ]);
    if ($order["status"] !== $status && in_array($status, ["shipping", "completed", "returned"], true)) {
        $display_order_id = $order["order_code"] ?? $order["id"];
        $status_messages = [
            "shipping" => "Pesanan #{$display_order_id} sedang dalam proses pengiriman.",
            "completed" => "Pesanan #{$display_order_id} telah selesai dikirim.",
            "returned" => "Pesanan #{$display_order_id} telah dikembalikan.",
        ];
        return create_order_notification(
            $pdo,
            $order,
            "order_{$status}",
            $status_messages[$status]
        );
    }

    return null;
}
function show_flash(): void
{
    if (!empty($_SESSION["flash"])) {
        $type = $_SESSION["flash_type"] ?? null;
        if ($type === null) {
            $type = in_array($_SESSION["flash"], [
                "Password berhasil diubah. Silakan masuk dengan password baru.",
                "Pendaftaran berhasil. Silakan login.",
            ], true) ? "success" : "danger";
        }
        $type = in_array($type, ["success", "danger"], true) ? $type : "danger";
        echo '<div class="alert alert-' . e($type) . '">' . e($_SESSION["flash"]) . "</div>";
        unset($_SESSION["flash"]);
        unset($_SESSION["flash_type"]);
    }
}