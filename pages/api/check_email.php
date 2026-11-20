<?php
require dirname(__DIR__, 2) . "/includes/config.php";

header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store");

function email_check_error(int $status, string $message): void
{
    http_response_code($status);
    echo json_encode(["error" => $message]);
    exit();
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Allow: POST");
    email_check_error(405, "Metode permintaan tidak didukung.");
}

$csrf = $_POST["csrf"] ?? "";
if (!is_string($csrf) || !hash_equals($_SESSION["csrf"] ?? "", $csrf)) {
    email_check_error(419, "Sesi formulir tidak valid. Muat ulang halaman.");
}

$email = $_POST["email"] ?? "";
if (!is_string($email) || !filter_var(trim($email), FILTER_VALIDATE_EMAIL)) {
    email_check_error(400, "Masukkan alamat email yang valid.");
}

try {
    $stmt = $pdo->prepare("SELECT 1 FROM users WHERE email = ? LIMIT 1");
    $stmt->execute([trim($email)]);
    echo json_encode(["available" => $stmt->fetchColumn() === false]);
} catch (PDOException $e) {
    error_log("Pemeriksaan email pendaftaran gagal: " . $e->getMessage());
    email_check_error(500, "Pemeriksaan email gagal. Silakan coba lagi.");
}
