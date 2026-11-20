<?php
require dirname(__DIR__, 2) . "/includes/config.php";

header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store");

function username_check_error(int $status, string $message): void
{
    http_response_code($status);
    echo json_encode(["error" => $message]);
    exit();
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Allow: POST");
    username_check_error(405, "Metode permintaan tidak didukung.");
}

$csrf = $_POST["csrf"] ?? "";
if (!is_string($csrf) || !hash_equals($_SESSION["csrf"] ?? "", $csrf)) {
    username_check_error(419, "Sesi formulir tidak valid. Muat ulang halaman.");
}

$username = $_POST["username"] ?? "";
if (!is_string($username)) {
    username_check_error(400, "Username tidak valid.");
}
$username = strtolower(trim($username));
if (preg_match('/\A[a-z0-9_]{3,20}\z/', $username) !== 1) {
    username_check_error(400, "Username harus berisi 3-20 huruf, angka, atau garis bawah.");
}

try {
    $stmt = $pdo->prepare("SELECT 1 FROM users WHERE username = ? LIMIT 1");
    $stmt->execute([$username]);
    echo json_encode(["available" => $stmt->fetchColumn() === false]);
} catch (PDOException $e) {
    error_log("Pemeriksaan username pendaftaran gagal: " . $e->getMessage());
    username_check_error(500, "Pemeriksaan username gagal. Silakan coba lagi.");
}
