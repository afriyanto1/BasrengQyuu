<?php
require dirname(__DIR__, 2) . "/includes/config.php";
$_SESSION = [];
session_destroy();
header("Location: login.php");
exit();
