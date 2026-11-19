<?php
header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: public, max-age=86400");

function region_error(int $status, string $message): void
{
    http_response_code($status);
    echo json_encode(["error" => $message]);
    exit();
}

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
    header("Allow: GET");
    region_error(405, "Metode permintaan tidak didukung.");
}

$level = $_GET["level"] ?? "";
$code = $_GET["code"] ?? "";
$patterns = [
    "provinces" => null,
    "regencies" => '/^\d{2}$/',
    "districts" => '/^\d{2}\.\d{2}$/',
    "villages" => '/^\d{2}\.\d{2}\.\d{2}$/',
];

if (!is_string($level) || !array_key_exists($level, $patterns)) {
    region_error(400, "Tingkatan wilayah tidak valid.");
}

if ($level === "provinces") {
    $path = "provinces.json";
} else {
    if (!is_string($code) || !preg_match($patterns[$level], $code)) {
        region_error(400, "Kode wilayah tidak valid.");
    }
    $path = $level . "/" . rawurlencode($code) . ".json";
}

$context = stream_context_create([
    "http" => [
        "method" => "GET",
        "timeout" => 15,
        "ignore_errors" => true,
        "follow_location" => 0,
        "header" => "Accept: application/json\r\nUser-Agent: BasrengQyuuStore/1.0\r\n",
    ],
]);
error_clear_last();
$response = @file_get_contents("https://wilayah.id/api/" . $path, false, $context);
$status = 0;
foreach ($http_response_header ?? [] as $header) {
    if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $matches)) {
        $status = (int) $matches[1];
        break;
    }
}

if ($response === false || $status < 200 || $status >= 300) {
    $streamError = error_get_last();
    error_log(
        "API wilayah gagal (" . $status . "): " .
        ($streamError["message"] ?? "Tidak ada detail kesalahan.")
    );
    region_error(502, "Layanan data wilayah gagal memberikan daftar.");
}

$result = json_decode($response, true);
if (json_last_error() !== JSON_ERROR_NONE || !is_array($result["data"] ?? null)) {
    error_log("API wilayah mengembalikan format data yang tidak valid.");
    region_error(502, "Format data wilayah tidak valid.");
}

echo $response;
