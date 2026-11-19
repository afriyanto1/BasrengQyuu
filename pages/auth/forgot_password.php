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

            $serverName = $_SERVER["SERVER_NAME"] ?? "";
            $isIpv6 = is_string($serverName) && filter_var($serverName, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6);
            $isValidHost = is_string($serverName) &&
                ($isIpv6 || preg_match('/^(?=.{1,253}$)[a-zA-Z0-9](?:[a-zA-Z0-9.-]*[a-zA-Z0-9])?$/', $serverName));
            if (!$isValidHost) {
                error_log("Permintaan reset password gagal: nama server tidak valid.");
            } else {
                $scheme = (!empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off") ? "https" : "http";
                $port = (int) ($_SERVER["SERVER_PORT"] ?? ($scheme === "https" ? 443 : 80));
                $portSuffix = in_array($port, [80, 443], true) ? "" : ":" . $port;
                $host = $isIpv6 ? "[" . $serverName . "]" : $serverName;
                $origin = $scheme . "://" . $host . $portSuffix;
                $resetUrl = $origin .
                    app_url("pages/auth/reset_password.php?token=" . urlencode($token));
                $accountName = trim((string) $account["name"]);
                if ($accountName === "") {
                    $accountName = "Pelanggan";
                }
                $logoData = file_get_contents(app_path("assets/images/basrengqyuu-email-logo.jpeg"));
                if ($logoData === false) {
                    error_log("Logo email reset password gagal dibaca.");
                    $brandHtml = '<span style="font-family:\'Arial Black\',\'Arial Rounded MT Bold\',sans-serif;font-size:30px;font-weight:900;">BasrengQyuu Store</span>';
                } else {
                    $brandHtml = '<img src="cid:basrengqyuu-email-logo" width="200" alt="BasrengQyuu Store" style="display:block;width:100%;max-width:200px;height:auto;border:0;">';
                }
                $subject = "Reset password BasrengQyuu Store";
                $plainBody = "Halo, " . $accountName . "!\n\n" .
                    "Kami menerima permintaan untuk mengatur ulang password akun BasrengQyuu Store Anda.\n" .
                    "Klik tautan berikut untuk membuat password baru. Tautan berlaku selama 1 jam:\n" .
                    $resetUrl . "\n\n" .
                    "Jika Anda tidak meminta reset password, abaikan email ini. Password Anda tidak akan berubah.";
                $safeResetUrl = htmlspecialchars($resetUrl, ENT_QUOTES, "UTF-8");
                $safeAccountName = htmlspecialchars($accountName, ENT_QUOTES, "UTF-8");
                $htmlBody = '<!doctype html><html lang="id"><head><meta charset="utf-8"><style>@import url(\'https://fonts.googleapis.com/css2?family=Baloo+2:wght@600;700;800&family=Inter:wght@400;500;600;700&display=swap\');</style></head>' .
                    '<body style="margin:0;padding:0;background-color:#f3f4f6;font-family:\'Inter\',system-ui,-apple-system,sans-serif;color:#1f2937;">' .
                    '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background-color:#f3f4f6;">' .
                    '<tr><td align="center" style="padding:32px 16px;">' .
                    '<table role="presentation" width="600" cellspacing="0" cellpadding="0" border="0" style="width:100%;max-width:600px;background-color:#ffffff;border:1px solid #e5e7eb;border-radius:12px;">' .
                    '<tr><td align="center" style="padding:32px 32px 20px;">' .
                    $brandHtml .
                    '</td></tr>' .
                    '<tr><td style="padding:0 40px 32px;">' .
                    '<h1 style="margin:0 0 32px;text-align:center;font-size:24px;line-height:1.3;color:#111827;">Reset password Anda</h1>' .
                    '<p style="margin:0 0 16px;font-size:16px;line-height:1.6;">Halo, ' . $safeAccountName . '!</p>' .
                    '<p style="margin:0 0 24px;font-size:16px;line-height:1.6;">Kami menerima permintaan untuk mengatur ulang password akun BasrengQyuu Store Anda. Klik tombol di bawah untuk membuat password baru.</p>' .
                    '<table role="presentation" cellspacing="0" cellpadding="0" border="0" align="center"><tr>' .
                    '<td align="center" bgcolor="#f59e0b" style="border-radius:8px;">' .
                    '<a href="' . $safeResetUrl . '" style="display:inline-block;padding:14px 28px;border:1px solid #f59e0b;border-radius:8px;color:#111827;font-size:16px;font-weight:bold;text-decoration:none;">Atur ulang password</a>' .
                    '</td></tr></table>' .
                    '<p style="margin:24px 0;font-size:14px;line-height:1.6;color:#4b5563;">Tombol di atas berlaku selama <strong>1 jam</strong>.</p>' .
                    '<hr style="border:0;border-top:1px solid #e5e7eb;margin:0 0 20px;">' .
                    '<p style="margin:0;font-size:13px;line-height:1.6;color:#6b7280;">Jika Anda tidak meminta reset password, abaikan email ini. Password Anda tidak akan berubah.</p>' .
                    '</td></tr></table>' .
                    '<p style="margin:20px 0 0;font-size:12px;line-height:1.5;color:#6b7280;">Email otomatis dari BasrengQyuu Store. Mohon tidak membalas email ini.</p>' .
                    '</td></tr></table></body></html>';
                try {
                    $mail = create_configured_mailer();
                    $mail->addAddress($email);
                    $mail->Subject = $subject;
                    $mail->isHTML(true);
                    $mail->Body = $htmlBody;
                    $mail->AltBody = $plainBody;
                    if ($logoData !== false) {
                        $mail->addStringEmbeddedImage(
                            $logoData,
                            "basrengqyuu-email-logo",
                            "basrengqyuu-email-logo.jpeg",
                            \PHPMailer\PHPMailer\PHPMailer::ENCODING_BASE64,
                            "image/jpeg"
                        );
                    }
                    $mail->send();
                } catch (\PHPMailer\PHPMailer\Exception $e) {
                    error_log(
                        "Email reset password gagal dikirim untuk user ID " .
                        (int) $account["id"] . ": " . $e->getMessage()
                    );
                } catch (RuntimeException $e) {
                    error_log("Email reset password gagal dikirim: " . $e->getMessage());
                }
            }
        }
        $message = "Link reset password telah dikirim. Periksa inbox atau spam di email Anda.";
    }
}
?><!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <title>Lupa Password - BasrengQyuu Store</title>
    <base href="<?= e(app_url('pages/auth/')) ?>">
    <link rel="icon" type="image/svg+xml" href="<?= e(app_favicon_url()) ?>">
    <link rel="stylesheet" href="<?= e(app_url('assets/css/style.css')) ?>">
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
</body>
</html>
