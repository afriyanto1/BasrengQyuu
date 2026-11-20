<?php
require dirname(__DIR__, 2) . "/includes/config.php";

header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store");

function username_suggestions_error(int $status, string $message): void
{
    http_response_code($status);
    echo json_encode(["error" => $message]);
    exit();
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Allow: POST");
    username_suggestions_error(405, "Metode permintaan tidak didukung.");
}

$csrf = $_POST["csrf"] ?? "";
if (!is_string($csrf) || !hash_equals($_SESSION["csrf"] ?? "", $csrf)) {
    username_suggestions_error(419, "Sesi formulir tidak valid. Muat ulang halaman.");
}

$name = $_POST["name"] ?? "";
if (!is_string($name) || trim($name) === "" || strlen($name) > 120) {
    username_suggestions_error(400, "Masukkan nama lengkap yang valid.");
}

$ascii_name = function_exists("iconv")
    ? iconv("UTF-8", "ASCII//TRANSLIT//IGNORE", trim($name))
    : trim($name);
$base = strtolower((string) $ascii_name);
$base = preg_replace('/[^a-z0-9]/', "", $base);
if (!is_string($base) || strlen($base) < 3) {
    $base = "pelanggan";
}
$base = substr($base, 0, 15);

try {
    $check = $pdo->prepare("SELECT 1 FROM users WHERE username = ? LIMIT 1");
    $suggestions = [];
    for ($attempt = 0; $attempt < 40 && count($suggestions) < 2; $attempt++) {
        $suffix = (string) random_int(100, 9999);
        $candidate = substr($base, 0, 20 - strlen($suffix) - 1) . "_" . $suffix;
        $check->execute([$candidate]);
        if ($check->fetchColumn() === false) {
            $suggestions[] = $candidate;
        }
    }

    if (count($suggestions) < 2) {
        username_suggestions_error(503, "Username belum dapat dibuat. Silakan coba lagi.");
    }

    echo json_encode(["suggestions" => $suggestions]);
} catch (PDOException $e) {
    error_log("Saran username pendaftaran gagal: " . $e->getMessage());
    username_suggestions_error(500, "Saran username gagal dimuat. Silakan coba lagi.");
}
