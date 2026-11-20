<?php
require dirname(__DIR__, 2) . "/includes/config.php";

$token = is_string($_POST["token"] ?? $_GET["token"] ?? null)
    ? (string) ($_POST["token"] ?? $_GET["token"])
    : "";
$validTokenFormat = preg_match('/^[a-f0-9]{64}$/', $token) === 1;
$error = "";
$validToken = false;

if ($validTokenFormat) {
    $stmt = $pdo->prepare(
        "SELECT user_id FROM password_reset_tokens WHERE token_hash = ? AND expires_at > NOW() LIMIT 1"
    );
    $stmt->execute([hash("sha256", $token)]);
    $validToken = (bool) $stmt->fetch();
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    check_csrf();
    $password = is_string($_POST["password"] ?? null) ? $_POST["password"] : "";
    $passwordConfirmation = is_string($_POST["password_confirmation"] ?? null)
        ? $_POST["password_confirmation"]
        : "";

    if (!$validToken) {
        $error = "Tautan reset tidak valid atau sudah kedaluwarsa. Minta tautan baru.";
    } elseif (strlen($password) < 8) {
        $error = "Password baru minimal 8 karakter.";
    } elseif ($password !== $passwordConfirmation) {
        $error = "Konfirmasi password tidak sama.";
    } else {
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                "SELECT user_id FROM password_reset_tokens
                 WHERE token_hash = ? AND expires_at > NOW() FOR UPDATE"
            );
            $stmt->execute([hash("sha256", $token)]);
            $reset = $stmt->fetch();
            if (!$reset) {
                $pdo->rollBack();
                $validToken = false;
                $error = "Tautan reset tidak valid atau sudah kedaluwarsa. Minta tautan baru.";
            } else {
                $update = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
                $update->execute([password_hash($password, PASSWORD_DEFAULT), $reset["user_id"]]);
                $delete = $pdo->prepare("DELETE FROM password_reset_tokens WHERE user_id = ?");
                $delete->execute([$reset["user_id"]]);
                $pdo->commit();
                flash("Password berhasil diubah. Silakan masuk dengan password baru.", "success");
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
} elseif (!$validTokenFormat || !$validToken) {
    $error = "Tautan reset tidak valid atau sudah kedaluwarsa. Minta tautan baru.";
}
?><!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <title>Buat Password Baru - BasrengQyuu Store</title>
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
        .login-box form .password-input-group { position: relative; }
        .login-box form .password-input-group input { display: block; width: 100%; height: 44px; padding: 10px 52px 10px 14px; box-sizing: border-box; }
        .login-box form .password-toggle {
            position: absolute;
            top: 50%;
            right: 12px;
            display: flex;
            width: 28px;
            height: 28px;
            padding: 4px;
            align-items: center;
            justify-content: center;
            transform: translateY(-50%);
            border: 0;
            border-radius: 6px;
            background: transparent;
            color: #64748b;
            cursor: pointer;
        }
        .login-box form .password-toggle:hover { color: #2563eb; }
        .login-box form .password-toggle:focus-visible { outline: 2px solid #2563eb; outline-offset: 2px; }
        .btn.full { width: 100%; height: 46px; margin-top: 20px; }
        .alert { padding: 10px 14px; border-radius: 8px; margin: 16px 0; }
        .alert-error { background: #fee2e2; color: #b91c1c; }
        .auth-back { display: block; margin-top: 20px; color: #2563eb; text-align: center; text-decoration: none; }
    </style>
</head>
<body class="auth-page">
<?php include dirname(__DIR__, 2) . "/includes/partials/language_switch.php"; ?>
<main class="login-box">
    <h1>Buat password baru</h1>
    <p>Gunakan password minimal 8 karakter.</p>

    <?php if ($error): ?>
        <div class="alert alert-error" role="alert"><?= e($error) ?></div>
    <?php endif; ?>

    <?php if ($validToken): ?>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
            <input type="hidden" name="token" value="<?= e($token) ?>">
            <label for="password">Password baru</label>
            <div class="password-input-group">
                <input id="password" type="password" name="password" autocomplete="new-password" minlength="8" required>
                <button class="password-toggle" id="password-toggle" type="button" aria-label="Tampilkan password baru" aria-controls="password" aria-pressed="false">
                    <svg id="password-eye" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                </button>
            </div>
            <label for="password_confirmation">Ulangi password baru</label>
            <div class="password-input-group">
                <input id="password_confirmation" type="password" name="password_confirmation" autocomplete="new-password" minlength="8" required>
                <button class="password-toggle" id="confirmation-toggle" type="button" aria-label="Tampilkan konfirmasi password" aria-controls="password_confirmation" aria-pressed="false">
                    <svg id="confirmation-eye" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                </button>
            </div>
            <button class="btn full" type="submit">Simpan password baru</button>
        </form>
    <?php else: ?>
        <a class="auth-back" href="forgot_password.php">Minta tautan reset baru</a>
    <?php endif; ?>
</main>
<?php if ($validToken): ?>
<script>
    const openEye = '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle>';
    const closedEye = '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line>';
    [
        ["password", "password-toggle", "password-eye", "Tampilkan password baru", "Sembunyikan password baru"],
        ["password_confirmation", "confirmation-toggle", "confirmation-eye", "Tampilkan konfirmasi password", "Sembunyikan konfirmasi password"]
    ].forEach(([inputId, toggleId, eyeId, showLabel, hideLabel]) => {
        const input = document.getElementById(inputId);
        const toggle = document.getElementById(toggleId);
        const eye = document.getElementById(eyeId);
        toggle.addEventListener("click", () => {
            const isVisible = input.type === "password";
            input.type = isVisible ? "text" : "password";
            toggle.setAttribute("aria-pressed", String(isVisible));
            toggle.setAttribute("aria-label", isVisible ? hideLabel : showLabel);
            eye.innerHTML = isVisible ? closedEye : openEye;
        });
    });
</script>
<?php endif; ?>
</body>
</html>
