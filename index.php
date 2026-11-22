<?php
require __DIR__ . "/includes/config.php";
if (!user()) {
    header("Location: " . app_url("pages/auth/login.php"));
    exit();
}
require_login();
header(
    "Location: " . app_url(
        is_cashier()
            ? "pages/cashier/shop.php"
            : (is_seller()
                ? "pages/seller/seller.php"
                : "pages/buyer/shop.php")
    )
);
exit();