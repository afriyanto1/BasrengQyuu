<?php
require dirname(__DIR__, 2) . "/includes/config.php";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    check_csrf();
    $lang = strtolower(trim((string) ($_POST["lang"] ?? "")));
    if (in_array($lang, ["id", "en"], true)) {
        $_SESSION["app_language"] = $lang;
    }
}

$returnUrl = (string) ($_POST["return_url"] ?? app_url("index.php"));
$parts = parse_url($returnUrl);
$path = is_array($parts) ? ($parts["path"] ?? "") : "";
if ($path === "" || substr($path, 0, 1) !== "/") {
    $returnUrl = app_url("index.php");
}

header("Location: " . $returnUrl);
exit();
