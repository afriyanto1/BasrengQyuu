<?php
require dirname(__DIR__, 2) . "/includes/config.php";
if (user()) {
    header("Location: " . app_url("index.php"));
    exit();
}
$error = "";
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    check_csrf();
    $stmt = $pdo->prepare("SELECT * FROM users WHERE email=? LIMIT 1");
    $stmt->execute([trim($_POST["email"] ?? "")]);
    $u = $stmt->fetch();
    if ($u && password_verify($_POST["password"] ?? "", $u["password"])) {
        session_regenerate_id(true);
        $_SESSION["user"] = [
            "id" => $u["id"],
            "name" => $u["name"],
            "email" => $u["email"],
            "role" => $u["role"],
        ];
        header("Location: " . app_url("index.php"));
        exit();
    }
    $error = "Email atau password salah.";
}
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login - Basreng POS</title>
    <base href="<?= e(app_url('pages/auth/')) ?>">
    <link rel="icon" type="image/svg+xml" href="<?= e(app_favicon_url()) ?>">
    <link rel="stylesheet" href="<?= e(app_url('assets/css/style.css')) ?>?v=<?= (int) filemtime(dirname(__DIR__, 2) . '/assets/css/style.css') ?>">
    <style>
        body.auth-page {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 28px 20px;
            background:
                radial-gradient(ellipse at 12% 10%, rgba(37, 99, 235, 0.3), transparent 42%),
                radial-gradient(ellipse at 90% 92%, rgba(8, 145, 178, 0.24), transparent 40%),
                linear-gradient(135deg, #172554 0%, #111c3d 58%, #0f172a 100%);
        }
        .login-box {
            width: 100%;
            max-width: 448px;
            padding: 38px;
            border: 1px solid rgba(255, 255, 255, 0.7);
            border-radius: 18px;
            background: #fff;
            box-shadow: 0 24px 60px rgba(2, 6, 23, 0.3);
        }
        .login-header {
            text-align: center;
            margin-bottom: 26px;
        }
        .login-logo {
            display: block;
            width: 100%;
            max-width: 230px;
            height: auto;
            margin: 0 auto;
        }
        .login-box form label {
            display: block;
            margin: 16px 0 7px;
            color: #52617a;
            font-size: 13px;
            font-weight: 600;
        }
        .login-box form input[type="email"],
        .login-box form input[type="text"],
        .login-box form input[type="password"] {
            display: block;
            width: 100%;
            height: 48px;
            padding: 10px 14px;
            border: 1px solid #d2def2;
            border-radius: 9px;
            background: #eaf1fc;
            color: #172033;
            font-size: 14px;
            box-sizing: border-box;
            transition: border-color 0.2s, box-shadow 0.2s, background 0.2s;
        }
        .login-box form input::placeholder {
            color: #8290a8;
        }
        .login-box form input:focus {
            border-color: #3b72f2;
            background: #fff;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.14);
            outline: none;
        }
        .input-group {
            position: relative;
            display: flex;
            align-items: center;
            width: 100%;
        }
        .input-group input {
            padding-right: 44px !important;
        }
        .btn-eye {
            position: absolute;
            right: 12px;
            background: none;
            border: none;
            color: #71809a;
            cursor: pointer;
            padding: 4px;
            display: flex;
            align-items: center;
            justify-content: center;
            height: 100%;
        }
        .btn-eye:hover {
            color: #2563eb;
        }
        .btn-eye:focus-visible {
            border-radius: 4px;
            outline: 2px solid #2563eb;
            outline-offset: 2px;
        }
        .btn.full {
            width: 100%;
            background: #2563eb;
            color: #fff;
            border: none;
            border-radius: 9px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.2s, transform 0.2s, box-shadow 0.2s;
            height: 50px;
            margin-top: 24px;
            box-shadow: 0 5px 12px rgba(37, 99, 235, 0.2);
        }
        .btn.full:hover {
            background: #1d4ed8;
            box-shadow: 0 7px 16px rgba(37, 99, 235, 0.26);
            transform: translateY(-1px);
        }
        .alert {
            background: #fee2e2;
            color: #dc2626;
            padding: 10px 14px;
            border-radius: 8px;
            font-size: 13px;
            margin-bottom: 16px;
            border: 1px solid #fecaca;
        }
        .alert.alert-success {
            background: #dcfce7;
            color: #166534;
            border-color: #bbf7d0;
        }
        .login-forgot {
            margin: 12px 0 0;
            text-align: right;
            font-size: 13px;
        }
        .login-register {
            margin: 22px 0 0;
            color: #52617a;
            text-align: center;
            font-size: 13.5px;
        }
        .login-forgot a,
        .login-register a {
            color: #2563eb;
            font-weight: 600;
            text-decoration: none;
        }
        .login-forgot a:hover,
        .login-register a:hover {
            text-decoration: underline;
        }
        @media (max-width: 480px) {
            body.auth-page {
                padding: 18px 14px;
            }
            .login-box {
                padding: 30px 24px;
                border-radius: 16px;
            }
            .login-header {
                margin-bottom: 20px;
            }
            .login-logo {
                max-width: 210px;
            }
        }
    </style>
</head>
<body class="auth-page">
<div class="login-box">
    <div class="login-header">
        <img class="login-logo" src="<?= e(app_url('assets/images/basrengqyuu-logo.png')) ?>" alt="BasrengQyuu Store">
    </div>

    <?php show_flash(); ?>

    <?php if ($error): ?>
        <div class="alert"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="post">
        <input type="hidden" name="csrf" value="<?= csrf() ?>">
        <label for="email">Email / Username</label>
        <input id="email" type="email" name="email" autocomplete="username" placeholder="Alamat email Anda" required>

        <label for="passInput">Password</label>
        <div class="input-group">
            <input type="password" name="password" id="passInput" autocomplete="current-password" placeholder="Masukkan password" required>
            <button type="button" class="btn-eye" onclick="togglePass()" aria-label="Tampilkan password" aria-controls="passInput">
                <svg id="eyeIcon" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
            </button>
        </div>

        <button class="btn full" type="submit">Masuk ke Sistem</button>
    </form>

    <p class="login-forgot">
        <a href="forgot_password.php">Lupa password?</a>
    </p>
    <p class="login-register">
        Belum punya akun pembeli? <a href="register.php">Daftar di sini</a>
    </p>
</div>

<script>
function togglePass() {
    const input = document.getElementById('passInput');
    const icon = document.getElementById('eyeIcon');
    const toggle = document.querySelector('.btn-eye');
    if (input.type === 'password') {
        input.type = 'text';
        toggle.setAttribute('aria-label', 'Sembunyikan password');
        icon.innerHTML = '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line>';
    } else {
        input.type = 'password';
        toggle.setAttribute('aria-label', 'Tampilkan password');
        icon.innerHTML = '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle>';
    }
}
</script>
</body>
</html>