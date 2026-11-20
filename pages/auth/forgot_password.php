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
        $stmt = $pdo->prepare("SELECT id, name FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $account = $stmt->fetch();

        if ($account) {
            $token = bin2hex(random_bytes(32));
            $tokenHash = hash("sha256", $token);
            $pdo->beginTransaction();
            try {
                $delete = $pdo->prepare("DELETE FROM password_reset_tokens WHERE user_id = ?");
                $delete->execute([$account["id"]]);
                $insert = $pdo->prepare(
                    "INSERT INTO password_reset_tokens (user_id, token_hash, expires_at)
                     VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR))"
                );
                $insert->execute([$account["id"], $tokenHash]);
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }

            $resetUrl = app_email_absolute_url(
                "pages/auth/reset_password.php?token=" . urlencode($token)
            );
            if ($resetUrl === null) {
                error_log("Permintaan reset password gagal: nama server tidak valid.");
            } else {
                $accountName = trim((string) $account["name"]);
                if ($accountName === "") {
                    $accountName = "Pelanggan";
                }
                send_branded_email(
                    $email,
                    $accountName,
                    "Reset password BasrengQyuu Store",
                    "Reset password Anda",
                    "Kami menerima permintaan untuk mengatur ulang password akun BasrengQyuu Store Anda. Klik tombol di bawah untuk membuat password baru.",
                    "Atur ulang password",
                    $resetUrl,
                    "Tautan ini berlaku selama 1 jam. Jika Anda tidak meminta reset password, abaikan email ini. Password Anda tidak akan berubah."
                );
            }
            $message = "Link reset password telah dikirim. Periksa inbox atau spam di email Anda.";
        } else {
            $error = "Pengguna tidak terdaftar. Silakan daftar terlebih dahulu.";
        }
    }
}
?><!doctype html>
<html lang="<?= e(app_language()) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <title>Lupa Password - BasrengQyuu Store</title>
    <base href="<?= e(app_url('pages/auth/')) ?>">
    <link rel="icon" type="image/svg+xml" href="<?= e(app_favicon_url()) ?>">
    <link rel="stylesheet" href="<?= e(app_url('assets/css/style.css')) ?>">
    <script>
        window.APP_LANGUAGE = <?= json_encode(app_language(), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
        document.documentElement.lang = window.APP_LANGUAGE;
    </script>
    <style>
        body.auth-page {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            background: linear-gradient(135deg, #0b1224 0%, #172554 52%, #0f172a 100%);
        }
        .login-box {
            width: 100%;
            max-width: 420px;
            padding: 36px;
            border-radius: 16px;
            background: #fff;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.3);
        }
        .login-box h1 { font-size: 22px; text-align: center; }
        .login-box p { color: #64748b; text-align: center; }
        .login-box form label { display: block; margin: 18px 0 6px; color: #475569; font-weight: 600; }
        .login-box form input[type="email"] { display: block; width: 100%; height: 44px; box-sizing: border-box; }
        .btn.full { width: 100%; height: 46px; margin-top: 20px; }
        .alert { padding: 10px 14px; border-radius: 8px; margin: 16px 0; }
        .alert-error { background: #fee2e2; color: #b91c1c; }
        .alert-success { background: #dcfce7; color: #166534; }
        .auth-back { display: block; margin-top: 20px; color: #2563eb; text-align: center; text-decoration: none; }
    </style>
</head>
<body class="auth-page">
<main class="login-box">
    <h1>Lupa password?</h1>
    <p>Masukkan email akun Anda. Kami akan mengirim tautan untuk membuat password baru.</p>

    <?php if ($error): ?>
        <div class="alert alert-error" role="alert"><?= e($error) ?></div>
    <?php elseif ($message): ?>
        <div class="alert alert-success" role="status"><?= e($message) ?></div>
    <?php endif; ?>

    <form method="post">
        <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
        <label for="email">Email</label>
        <input id="email" type="email" name="email" autocomplete="email" maxlength="160" required>
        <button class="btn full" type="submit">Kirim tautan reset</button>
    </form>
    <a class="auth-back" href="login.php">Kembali ke login</a>
</main>
<?php if (app_language() === "en"): ?>
<script src="<?= e(app_url("assets/js/i18n.js")) ?>"></script>
<?php endif; ?>
</body>
</html>
