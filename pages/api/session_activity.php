<?php
require dirname(__DIR__, 2) . "/includes/config.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Allow: POST");
    http_response_code(405);
    exit();
}

if (!user()) {
    http_response_code(401);
    exit();
}

http_response_code(204);
exit();
