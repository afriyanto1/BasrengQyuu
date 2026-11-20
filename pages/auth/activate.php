<?php
require dirname(__DIR__, 2) . "/includes/config.php";

$error = "";
$token = is_string($_POST["token"] ?? $_GET["token"] ?? null)
    ? trim($_POST["token"] ?? $_GET["token"])
    : "";
$validToken = preg_match('/\A[a-f0-9]{64}\z/', $token) === 1;

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    check_csrf();
    }

if ($validToken) {
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            "SELECT user_id
             FROM email_verification_tokens
             WHERE token_hash = ? AND expires_at > UTC_TIMESTAMP()
             LIMIT 1
             FOR UPDATE"
        );
        $stmt->execute([hash("sha256", $token)]);
        $userId = $stmt->fetchColumn();
        if ($userId === false) {
            $pdo->rollBack();
            $validToken = false;
        } else {
            $pdo->prepare(
                "UPDATE users SET email_verified_at = UTC_TIMESTAMP() WHERE id = ?"
            )->execute([(int) $userId]);
            $pdo->prepare("DELETE FROM email_verification_tokens WHERE user_id = ?")
                ->execute([(int) $userId]);
            $pdo->commit();
            flash("Akun berhasil diaktivasi. Silakan masuk.", "success");
            header("Location: " . app_url("pages/auth/login.php"));
            exit();
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

if (!$validToken && $error === "") {
    $error = "Tautan aktivasi tidak valid atau sudah kedaluwarsa.";
}
?><!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Aktivasi Akun - BasrengQyuu Store</title>
    <base href="<?= e(app_url('pages/auth/')) ?>">
    <link rel="icon" type="image/svg+xml" href="<?= e(app_favicon_url()) ?>">
    <link rel="stylesheet" href="<?= e(app_url('assets/css/style.css')) ?>">
</head>
<body class="register-page">
    <main class="register-card">
        <div class="register-topbar">
            <a class="register-back" href="login.php">Kembali ke login</a>
            <span class="brand-name register-brand">
                <span class="brand-basreng">Basreng</span>
                <span class="brand-qyuu">Qyuu&nbsp;</span>
                <span class="brand-store">Store</span>
            </span>
        </div>
        <section class="register-intro">
            <h1>Aktivasi akun</h1>
            <?php if ($error): ?>
                <div class="alert" role="alert"><?= e($error) ?></div>
                <p>Anda dapat meminta tautan aktivasi baru.</p>
                <a class="btn register-submit" href="resend_activation.php">Kirim ulang aktivasi</a>
            <?php endif; ?>
        </section>
    </main>
</body>
</html>
