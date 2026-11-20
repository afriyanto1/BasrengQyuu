<?php
declare(strict_types=1);
session_start();

if (isset($_GET["lang"])) {
    $requested_language = strtolower(trim((string) $_GET["lang"]));
    if (in_array($requested_language, ["id", "en"], true)) {
        $_SESSION["app_language"] = $requested_language;
    }
}

function app_language(): string
{
    return ($_SESSION["app_language"] ?? "id") === "en" ? "en" : "id";
}

function translate_text(string $text, ?string $language = null): string
{
    $language = $language ?? app_language();
    if ($language !== "en") {
        return $text;
    }

    $translations = [
        "Login" => "Login",
        "Masuk" => "Login",
        "Daftar" => "Register",
        "Pendaftaran" => "Registration",
        "Keranjang" => "Cart",
        "Pesanan Saya" => "My Orders",
        "Notifikasi" => "Notifications",
        "Data profil" => "Profile",
        "Ubah alamat" => "Edit address",
        "Ganti password" => "Change password",
        "Dashboard" => "Dashboard",
        "Produk" => "Products",
        "Bahan Baku" => "Materials",
        "Pembelian" => "Purchases",
        "Pesanan" => "Orders",
        "Pembayaran" => "Payments",
        "Laporan" => "Reports",
        "Keranjang Saya" => "My Cart",
        "Katalog Basreng" => "Basreng Catalog",
        "Menu" => "Shop",
        "Pengguna" => "User",
        "Bahasa" => "Language",
        "Produk" => "Products",
        "Harga Satuan" => "Unit Price",
        "Jumlah" => "Quantity",
        "Subtotal" => "Subtotal",
        "Keranjang masih kosong" => "Your cart is still empty",
        "Lanjut Belanja" => "Continue Shopping",
        "Mulai Belanja" => "Start Shopping",
    ];

    return $translations[$text] ?? $text;
}

function t(string $text): string
{
    return translate_text($text);
}

$host = getenv("DB_HOST") ?: "dropship-db.ctq2e6428ybb.ap-southeast-2.rds.amazonaws.com";
$db = getenv("DB_NAME") ?: "basreng_pos";
$user = getenv("DB_USER") ?: "admin";
$pass = getenv("DB_PASS") ?: "";
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

const LOGIN_RATE_LIMIT_MAX_ATTEMPTS = 5;
const LOGIN_RATE_LIMIT_WINDOW_MINUTES = 15;

function login_rate_limit_retry_after(PDO $pdo, string $ip): int
{
    $stmt = $pdo->prepare(
        "SELECT attempts, GREATEST(
            0,
            TIMESTAMPDIFF(
                SECOND,
                UTC_TIMESTAMP(),
                DATE_ADD(window_started_at, INTERVAL " . LOGIN_RATE_LIMIT_WINDOW_MINUTES . " MINUTE)
            )
        ) AS retry_after
        FROM login_rate_limits
        WHERE ip_hash = ?
            AND window_started_at > DATE_SUB(
                UTC_TIMESTAMP(),
                INTERVAL " . LOGIN_RATE_LIMIT_WINDOW_MINUTES . " MINUTE
            )
        LIMIT 1"
    );
    $stmt->execute([hash("sha256", $ip)]);
    $attempt = $stmt->fetch();

    if (!$attempt || (int) $attempt["attempts"] < LOGIN_RATE_LIMIT_MAX_ATTEMPTS) {
        return 0;
    }

    return max(1, (int) $attempt["retry_after"]);
}

function record_failed_login(PDO $pdo, string $ip): int
{
    $stmt = $pdo->prepare(
        "INSERT INTO login_rate_limits (ip_hash, attempts, window_started_at)
        VALUES (?, 1, UTC_TIMESTAMP())
        ON DUPLICATE KEY UPDATE
            attempts = IF(
                window_started_at <= DATE_SUB(
                    UTC_TIMESTAMP(),
                    INTERVAL " . LOGIN_RATE_LIMIT_WINDOW_MINUTES . " MINUTE
                ),
                1,
                attempts + 1
            ),
            window_started_at = IF(
                window_started_at <= DATE_SUB(
                    UTC_TIMESTAMP(),
                    INTERVAL " . LOGIN_RATE_LIMIT_WINDOW_MINUTES . " MINUTE
                ),
                UTC_TIMESTAMP(),
                window_started_at
            )"
    );
    $stmt->execute([hash("sha256", $ip)]);

    return login_rate_limit_retry_after($pdo, $ip);
}

function clear_login_rate_limit(PDO $pdo, string $ip): void
{
    $stmt = $pdo->prepare("DELETE FROM login_rate_limits WHERE ip_hash = ?");
    $stmt->execute([hash("sha256", $ip)]);
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
function shipping_country_options(): array
{
    return [
        "ID" => "Indonesia",
        "SG" => "Singapura",
        "MY-W" => "Malaysia Barat (Semenanjung)",
        "MY-E" => "Malaysia Timur (Sabah & Sarawak)",
        "BN" => "Brunei Darussalam",
        "TH" => "Thailand",
        "VN" => "Vietnam",
        "PH" => "Filipina",
        "KH" => "Kamboja",
        "LA" => "Laos",
        "MM" => "Myanmar",
    ];
}
function shipping_area_for_address(array $address): string
{
    $country_code = $address["country_code"] ?? "ID";
    if (!is_string($country_code) || !array_key_exists($country_code, shipping_country_options())) {
        throw new InvalidArgumentException("Negara tujuan pengiriman tidak valid.");
    }
    foreach (["province", "city", "district"] as $field) {
        if (!is_string($address[$field] ?? null) || trim($address[$field]) === "") {
            throw new InvalidArgumentException("Lengkapi wilayah, kota, dan kecamatan pada alamat akun sebelum checkout.");
        }
    }
    if ($country_code !== "ID") {
        return $country_code;
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
    $province_zones = [
        "jabodetabek_banten" => ["banten", "dki jakarta", "daerah khusus ibukota jakarta"],
        "jawa_barat" => ["jawa barat"],
        "jawa_tengah_diy" => ["jawa tengah", "di yogyakarta", "daerah istimewa yogyakarta"],
        "jawa_timur" => ["jawa timur"],
        "bali_nusa_tenggara" => ["bali", "nusa tenggara barat", "nusa tenggara timur"],
        "sumatera_bagian_selatan" => [
            "lampung", "sumatera selatan", "bengkulu", "jambi", "kepulauan bangka belitung",
        ],
        "sumatera_bagian_utara" => [
            "sumatera utara", "sumatera barat", "riau", "kepulauan riau", "aceh",
        ],
        "kalimantan" => [
            "kalimantan barat", "kalimantan tengah", "kalimantan selatan",
            "kalimantan timur", "kalimantan utara",
        ],
        "sulawesi" => [
            "sulawesi selatan", "sulawesi tengah", "sulawesi tenggara",
            "sulawesi utara", "sulawesi barat", "gorontalo",
        ],
        "maluku_papua" => [
            "maluku", "maluku utara", "papua", "papua barat", "papua selatan",
            "papua tengah", "papua pegunungan", "papua barat daya",
        ],
    ];
    foreach ($province_zones as $zone => $provinces) {
        if (in_array($province, $provinces, true)) {
            return $zone;
        }
    }

    throw new InvalidArgumentException("Provinsi tidak termasuk dalam wilayah pengiriman yang tersedia.");
}
function calculate_shipping_fee(string $shipping_area): int
{
    $fees = [
        "pickup" => 0,
        "free_zone" => 0,
        "jabodetabek" => 10000,
        "outside" => 50000,
        "jabodetabek_banten" => 10000,
        "jawa_barat" => 13000,
        "jawa_tengah_diy" => 18000,
        "jawa_timur" => 22000,
        "bali_nusa_tenggara" => 35000,
        "sumatera_bagian_selatan" => 25000,
        "sumatera_bagian_utara" => 35000,
        "kalimantan" => 45000,
        "sulawesi" => 50000,
        "maluku_papua" => 85000,
        "SG" => 85000,
        "MY-W" => 90000,
        "MY-E" => 115000,
        "BN" => 150000,
        "TH" => 165000,
        "VN" => 175000,
        "PH" => 190000,
        "KH" => 230000,
        "LA" => 250000,
        "MM" => 260000,
    ];
    if (!array_key_exists($shipping_area, $fees)) {
        throw new InvalidArgumentException("Wilayah pengiriman tidak valid.");
    }

    return $fees[$shipping_area];
}
function manual_shipping_area_options(): array
{
    return [
        "pickup" => "Ambil sendiri",
        "free_zone" => "Wilayah gratis ongkir khusus",
        "jabodetabek_banten" => "Jabodetabek & Banten",
        "jawa_barat" => "Jawa Barat",
        "jawa_tengah_diy" => "Jawa Tengah & DIY",
        "jawa_timur" => "Jawa Timur",
        "bali_nusa_tenggara" => "Bali & Nusa Tenggara",
        "sumatera_bagian_selatan" => "Sumatera Bagian Selatan",
        "sumatera_bagian_utara" => "Sumatera Bagian Utara",
        "kalimantan" => "Kalimantan",
        "sulawesi" => "Sulawesi",
        "maluku_papua" => "Maluku & Papua",
    ] + array_diff_key(shipping_country_options(), ["ID" => true]);
}
function shipping_area_label(?string $shipping_area): string
{
    if ($shipping_area === null) {
        return "Menunggu konfirmasi ongkir";
    }

    $labels = [
        "pickup" => "Ambil sendiri",
        "free_zone" => "Gratis ongkir wilayah khusus",
        "jabodetabek" => "Jabodetabek",
        "outside" => "Luar Jabodetabek",
        "jabodetabek_banten" => "Jabodetabek & Banten",
        "jawa_barat" => "Jawa Barat",
        "jawa_tengah_diy" => "Jawa Tengah & DIY",
        "jawa_timur" => "Jawa Timur",
        "bali_nusa_tenggara" => "Bali & Nusa Tenggara",
        "sumatera_bagian_selatan" => "Sumatera Bagian Selatan",
        "sumatera_bagian_utara" => "Sumatera Bagian Utara",
        "kalimantan" => "Kalimantan",
        "sulawesi" => "Sulawesi",
        "maluku_papua" => "Maluku & Papua",
    ];

    $countries = shipping_country_options();
    return $labels[$shipping_area] ?? $countries[$shipping_area] ?? "Wilayah pengiriman";
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
function sync_buyer_cart(PDO $pdo, int $buyerId): array
{
    $legacyCart = $_SESSION["buyer_cart"][$buyerId] ?? [];
    if (!is_array($legacyCart)) {
        $legacyCart = [];
    }

    $cart_stmt = $pdo->prepare(
        "SELECT product_id, quantity FROM buyer_cart_items WHERE buyer_id = ?"
    );
    $cart_stmt->execute([$buyerId]);
    $cart = [];
    foreach ($cart_stmt->fetchAll() as $item) {
        $cart[(int) $item["product_id"]] = (int) $item["quantity"];
    }

    if (
        !$cart &&
        $legacyCart &&
        empty($_SESSION["buyer_cart_database_loaded"][$buyerId])
    ) {
        $product_stmt = $pdo->prepare("SELECT id FROM products WHERE id = ?");
        $insert_stmt = $pdo->prepare(
            "INSERT INTO buyer_cart_items (buyer_id, product_id, quantity)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE quantity = VALUES(quantity)"
        );
        foreach ($legacyCart as $productId => $quantity) {
            $productId = filter_var($productId, FILTER_VALIDATE_INT);
            $quantity = filter_var($quantity, FILTER_VALIDATE_INT);
            if (!$productId || $quantity === false || $quantity < 1) {
                continue;
            }
            $product_stmt->execute([$productId]);
            if ($product_stmt->fetchColumn()) {
                $insert_stmt->execute([$buyerId, $productId, $quantity]);
                $cart[$productId] = $quantity;
            }
        }
    }

    $_SESSION["buyer_cart"][$buyerId] = $cart;
    $_SESSION["buyer_cart_database_loaded"][$buyerId] = true;

    return $cart;
}
function persist_buyer_cart_item(
    PDO $pdo,
    int $buyerId,
    int $productId,
    ?int $quantity
): void {
    if ($quantity === null) {
        $stmt = $pdo->prepare(
            "DELETE FROM buyer_cart_items WHERE buyer_id = ? AND product_id = ?"
        );
        $stmt->execute([$buyerId, $productId]);
        unset($_SESSION["buyer_cart"][$buyerId][$productId]);
    } else {
        if ($quantity < 1) {
            throw new InvalidArgumentException("Jumlah keranjang harus lebih dari nol.");
        }
        $stmt = $pdo->prepare(
            "INSERT INTO buyer_cart_items (buyer_id, product_id, quantity)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE quantity = VALUES(quantity)"
        );
        $stmt->execute([$buyerId, $productId, $quantity]);
        $_SESSION["buyer_cart"][$buyerId][$productId] = $quantity;
    }

    if (empty($_SESSION["buyer_cart"][$buyerId])) {
        unset($_SESSION["buyer_cart"][$buyerId]);
    }
    $_SESSION["buyer_cart_database_loaded"][$buyerId] = true;
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