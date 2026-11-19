<?php
require __DIR__ . "/includes/config.php";
if (!user()) {
    header("Location: " . app_url("pages/auth/login.php"));
    exit();
}
header(
    "Location: " . app_url(
        user()["role"] === "seller"
            ? "pages/seller/seller.php"
            : "pages/buyer/shop.php"
    )
);
exit();