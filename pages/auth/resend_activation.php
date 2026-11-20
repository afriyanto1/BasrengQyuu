<?php
require dirname(__DIR__, 2) . "/includes/config.php";
require dirname(__DIR__, 2) . "/includes/email.php";

$message = "";
$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    check_csrf();
    $email = is_string($_POST["email"] ?? null) ? trim($_POST["email"]) : "";
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Masukkan alamat email yang valid.";
    } else {
        $stmt = $pdo->prepare(
            "SELECT id, name, email_verified_at FROM users WHERE email = ? LIMIT 1"
        );
        $stmt->execute([$email]);
        $account = $stmt->fetch();
        if ($account && $account["email_verified_at"] === null) {
            $token = bin2hex(random_bytes(32));
            $activationUrl = app_email_absolute_url(
                "pages/auth/activate.php?token=" . urlencode($token)
            );
            if ($activationUrl === null) {
                $error = "Tautan aktivasi tidak dapat dibuat. Silakan coba lagi.";
            } else {
                $pdo->beginTransaction();
                try {
                    $pdo->prepare("DELETE FROM email_verification_tokens WHERE user_id = ?")
                        ->execute([(int) $account["id"]]);
                    $insert = $pdo->prepare(
                        "INSERT INTO email_verification_tokens (user_id, token_hash, expires_at)
                         VALUES (?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 24 HOUR))"
                    );
                    $insert->execute([(int) $account["id"], hash("sha256", $token)]);
                    $pdo->commit();
                    if (!send_account_activation_email(
                        $email,
                        (string) $account["name"],
                        $activationUrl
                    )) {
                        $error = "Email aktivasi gagal dikirim. Silakan coba lagi beberapa saat.";
                    }
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    throw $e;
                }
            }
        }
        if ($error === "") {
            $message = "Jika email terdaftar dan belum aktif, tautan aktivasi akan dikirim.";
        }
    }
}
?><!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Kirim Ulang Aktivasi - BasrengQyuu Store</title>
    <base href="<?= e(app_url('pages/auth/')) ?>">
    <link rel="icon" type="image/svg+xml" href="<?= e(app_favicon_url()) ?>">
    <link rel="stylesheet" href="<?= e(app_url('assets/css/style.css')) ?>">
</head>
<body class="register-page">
<?php include dirname(__DIR__, 2) . "/includes/partials/language_switch.php"; ?>
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
            <h1>Kirim ulang aktivasi</h1>
            <p>Masukkan email yang digunakan saat mendaftar.</p>
        </section>
        <?php if ($error): ?>
            <div class="alert" role="alert"><?= e($error) ?></div>
        <?php elseif ($message): ?>
            <div class="alert alert-success" role="status"><?= e($message) ?></div>
        <?php endif; ?>
        <form method="post" class="register-form">
            <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
            <div class="register-field">
                <label for="email">Email</label>
                <input id="email" name="email" type="email" autocomplete="email" maxlength="160" required>
            </div>
            <div class="register-actions">
                <button class="btn register-submit" type="submit">Kirim tautan aktivasi</button>
            </div>
        </form>
    </main>
</body>
</html>
