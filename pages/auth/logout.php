<?php
require dirname(__DIR__, 2) . "/includes/config.php";
if (user() && user()["role"] === "buyer") {
    sync_buyer_cart($pdo, (int) user()["id"]);
}
$_SESSION = [];
session_destroy();
header("Location: login.php");
exit();
