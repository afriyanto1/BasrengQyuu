<?php
require dirname(__DIR__, 2) . "/includes/config.php";
require_role("owner");
require dirname(__DIR__, 2) . "/includes/email.php";

$currentUserId = (int) user()["id"];
$roleLabels = [
    "owner" => "Owner",
    "admin" => "Admin",
    "cashier" => "Kasir",
];
$showCreateForm = ($_GET["show_create"] ?? null) === "1";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    check_csrf();

    $action = $_POST["action"] ?? "";
    $userCreated = false;
    if ($action === "create_user") {
        $name = is_string($_POST["name"] ?? null) ? trim($_POST["name"]) : "";
        $username = is_string($_POST["username"] ?? null)
            ? strtolower(trim($_POST["username"]))
            : "";
        $email = is_string($_POST["email"] ?? null)
            ? strtolower(trim($_POST["email"]))
            : "";
        $password = is_string($_POST["password"] ?? null) ? $_POST["password"] : "";
        $passwordConfirmation = is_string($_POST["password_confirmation"] ?? null)
            ? $_POST["password_confirmation"]
            : "";
        $newRole = $_POST["role"] ?? null;

        if (
            preg_match('/\A.{1,120}\z/us', $name) !== 1 ||
            preg_match('/\A[a-z0-9_]{3,20}\z/', $username) !== 1 ||
            !filter_var($email, FILTER_VALIDATE_EMAIL) ||
            !is_string($newRole) ||
            !array_key_exists($newRole, $roleLabels) ||
            strlen($password) < 8 ||
            $password !== $passwordConfirmation
        ) {
            flash("Periksa kembali nama, username, email, role, dan password. Password minimal 8 karakter dan harus sama dengan konfirmasi.");
        } else {
            try {
                $duplicateStmt = $pdo->prepare(
                    "SELECT id FROM users WHERE username = ? OR email = ? LIMIT 1"
                );
                $duplicateStmt->execute([$username, $email]);
                if ($duplicateStmt->fetch()) {
                    flash("Username atau email sudah digunakan.");
                } else {
                    $token = bin2hex(random_bytes(32));
                    $activationUrl = app_email_absolute_url(
                        "pages/auth/activate.php?token=" . urlencode($token)
                    );
                    if ($activationUrl === null) {
                        flash("Tautan aktivasi tidak dapat dibuat. Pengguna tidak dibuat. Silakan coba lagi.");
                    } else {
                        $pdo->beginTransaction();
                        $insertStmt = $pdo->prepare(
                            "INSERT INTO users (name, username, email, password, role, email_verified_at)
                             VALUES (?, ?, ?, ?, ?, NULL)"
                        );
                        $insertStmt->execute([
                            $name,
                            $username,
                            $email,
                            password_hash($password, PASSWORD_DEFAULT),
                            $newRole,
                        ]);
                        $userId = (int) $pdo->lastInsertId();
                        $tokenStmt = $pdo->prepare(
                            "INSERT INTO email_verification_tokens (user_id, token_hash, expires_at)
                             VALUES (?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 24 HOUR))"
                        );
                        $tokenStmt->execute([$userId, hash("sha256", $token)]);
                        $pdo->commit();
                        $userCreated = true;
                    }
                }
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                if ($e instanceof PDOException && $e->getCode() === "23000") {
                    flash("Username atau email sudah digunakan.");
                } else {
                    error_log("Gagal membuat pengguna seller: " . $e->getMessage());
                    flash("Pengguna gagal dibuat. Silakan coba lagi.");
                }
            }

            if ($userCreated) {
                if (send_account_activation_email($email, $name, $activationUrl)) {
                    flash("Pengguna berhasil dibuat. Tautan aktivasi telah dikirim ke email.", "success");
                } else {
                    flash("Pengguna berhasil dibuat, tetapi email aktivasi gagal dikirim. Silakan gunakan fitur kirim ulang aktivasi di halaman login.");
                }
            }
        }
    } elseif ($action === "delete_user") {
        $targetId = filter_var($_POST["user_id"] ?? null, FILTER_VALIDATE_INT);
        if ($targetId === false || $targetId < 1) {
            flash("Data pengguna tidak valid.");
        } elseif ($targetId === $currentUserId) {
            flash("Akun yang sedang digunakan tidak dapat dihapus.");
        } else {
            try {
                $pdo->beginTransaction();
                $ownerRows = $pdo->query(
                    "SELECT id FROM users WHERE role = 'owner' FOR UPDATE"
                )->fetchAll();
                $userStmt = $pdo->prepare(
                    "SELECT id, role FROM users WHERE id = ? AND role IN ('owner', 'admin', 'cashier') FOR UPDATE"
                );
                $userStmt->execute([$targetId]);
                $target = $userStmt->fetch();
                if (!$target) {
                    throw new RuntimeException("Pengguna Owner, Admin, atau Kasir tidak ditemukan.");
                }
                if ($target["role"] === "owner" && count($ownerRows) <= 1) {
                    throw new RuntimeException("Setidaknya satu akun Owner harus tetap ada.");
                }

                $orderStmt = $pdo->prepare(
                    "SELECT 1 FROM orders WHERE buyer_id = ? LIMIT 1"
                );
                $orderStmt->execute([$targetId]);
                if ($orderStmt->fetchColumn()) {
                    throw new RuntimeException("Pengguna dengan riwayat pesanan tidak dapat dihapus.");
                }

                $deleteStmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
                $deleteStmt->execute([$targetId]);
                $pdo->commit();
                flash("Pengguna berhasil dihapus.", "success");
            } catch (RuntimeException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                flash($e->getMessage());
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log("Gagal menghapus pengguna seller: " . $e->getMessage());
                if ($e->getCode() === "23000") {
                    flash("Pengguna tidak dapat dihapus karena masih memiliki data terkait.");
                } else {
                    flash("Pengguna gagal dihapus. Silakan coba lagi.");
                }
            }
        }
    } else {
        flash("Aksi pengguna tidak valid.");
    }

    $keepCreateFormOpen = $action === "create_user" && !$userCreated;
    header("Location: team.php" . ($keepCreateFormOpen ? "?show_create=1" : ""));
    exit();
}

$users = $pdo->query(
    "SELECT id, name, username, email, role
     FROM users
     WHERE role IN ('owner', 'admin', 'cashier')
     ORDER BY CASE role WHEN 'owner' THEN 1 WHEN 'admin' THEN 2 WHEN 'cashier' THEN 3 ELSE 4 END, name"
)->fetchAll();
?><!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Pengguna - BasrengQyuu Store</title>
    <link rel="icon" type="image/svg+xml" href="<?= e(app_favicon_url()) ?>">
    <link rel="stylesheet" href="<?= e(app_url('assets/css/style.css')) ?>?v=<?= (int) filemtime(app_path('assets/css/style.css')) ?>">
    <style>
        .team-header { display: flex; align-items: flex-start; justify-content: space-between; gap: 20px; margin-bottom: 20px; }
        .team-header-copy { min-width: 0; }
        .team-header h1 { margin-bottom: 6px; }
        .team-header .btn { flex: 0 0 auto; gap: 8px; margin: 0; white-space: nowrap; }
        .team-header .btn[hidden],
        .user-create-panel[hidden],
        .user-list-panel[hidden] { display: none; }
        .user-create-panel { margin-bottom: 20px; }
        .user-create-form { display: grid; gap: 16px; }
        .user-form-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px; }
        .user-form-field { min-width: 0; }
        .user-form-field label { display: block; margin-bottom: 5px; color: #334155; font-weight: 600; }
        .user-form-field input, .user-form-field select { margin: 0; }
        .user-create-actions { display: flex; justify-content: flex-end; gap: 12px; }
        .user-create-actions button { margin: 0; }
        .team-table { width: 100%; border-collapse: collapse; }
        .team-table th, .team-table td { padding: 12px 14px; border-bottom: 1px solid #e2e8f0; text-align: left; }
        .team-table th { background: #f8fafc; color: #475569; font-size: 12px; }
        .team-table td { color: #334155; }
        .team-list-actions { display: flex; align-items: center; justify-content: flex-end; gap: 6px; }
        .team-delete-form { margin: 0; }
        .team-list-actions .btn-sm { display: inline-block; padding: 6px 14px; border-radius: 6px; font-size: 13px; font-weight: 600; text-decoration: none; white-space: nowrap; }
        .team-list-actions .btn-outline { border: 1px solid #3182ce; color: #3182ce; }
        .team-list-actions .btn-delete { border: 1px solid #fecaca; background: #fee2e2; color: #dc2626; cursor: pointer; }
        .team-current-user { color: #64748b; font-size: 12px; }
        .team-table-scroll-hint { display: none; }
        @media (max-width: 700px) {
            .team-header { flex-direction: column; gap: 12px; }
            .team-header .btn { width: 100%; }
            .user-form-grid { grid-template-columns: 1fr; }
            .user-create-actions { flex-wrap: wrap; }
            .user-create-actions button { flex: 1; }
            .user-list-panel { padding: 18px 14px; }
            .team-table { min-width: 760px; }
            .team-table th:last-child, .team-table td:last-child { min-width: 190px; }
            .team-list-actions { flex-wrap: nowrap; justify-content: flex-start; }
            .team-table-scroll-hint { display: block; margin: 0 0 8px; color: #64748b; font-size: 12px; }
        }
    </style>
</head>
<body>
<?php include dirname(__DIR__, 2) . "/includes/partials/seller_nav.php"; ?>
<main>
    <?php show_flash(); ?>
    <div class="team-header">
        <div class="team-header-copy">
            <h1>Pengguna</h1>
        </div>
        <button id="user-create-toggle" class="btn" type="button" aria-controls="user-create-panel user-list-panel" aria-expanded="<?= $showCreateForm ? "true" : "false" ?>" <?= $showCreateForm ? "hidden" : "" ?>>
            <span aria-hidden="true">+</span>
            <span>Tambahkan Pengguna</span>
        </button>
    </div>
    <section id="user-create-panel" class="panel user-create-panel" <?= $showCreateForm ? "" : "hidden" ?>>
        <h2>Buat Pengguna</h2>
        <form method="post" class="user-create-form">
            <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
            <input type="hidden" name="action" value="create_user">
            <div class="user-form-grid">
                <div class="user-form-field">
                    <label for="new-name">Nama</label>
                    <input id="new-name" name="name" type="text" maxlength="120" required>
                </div>
                <div class="user-form-field">
                    <label for="new-username">Username</label>
                    <input id="new-username" name="username" type="text" minlength="3" maxlength="20" pattern="[a-zA-Z0-9_]+" autocomplete="username" required>
                </div>
                <div class="user-form-field">
                    <label for="new-email">Email</label>
                    <input id="new-email" name="email" type="email" maxlength="160" autocomplete="email" required>
                </div>
                <div class="user-form-field">
                    <label for="new-role">Role</label>
                    <select id="new-role" name="role" required>
                        <option value="admin" selected>Admin</option>
                        <option value="owner">Owner</option>
                        <option value="cashier">Kasir</option>
                    </select>
                </div>
                <div class="user-form-field">
                    <label for="new-password">Password</label>
                    <input id="new-password" name="password" type="password" minlength="8" autocomplete="new-password" required>
                </div>
                <div class="user-form-field">
                    <label for="new-password-confirmation">Konfirmasi Password</label>
                    <input id="new-password-confirmation" name="password_confirmation" type="password" minlength="8" autocomplete="new-password" required>
                </div>
            </div>
            <div class="user-create-actions">
                <button id="user-create-cancel" class="btn btn-outline" type="button">Batal</button>
                <button class="btn" type="submit">Buat Pengguna</button>
            </div>
        </form>
    </section>
    <section id="user-list-panel" class="panel user-list-panel" <?= $showCreateForm ? "hidden" : "" ?>>
        <h2>Daftar Pengguna</h2>
        <div class="table-wrap">
            <p class="team-table-scroll-hint">Geser tabel ke samping untuk melihat semua kolom.</p>
            <table class="team-table">
                <thead>
                    <tr>
                        <th>Nama</th>
                        <th>Username</th>
                        <th>Email</th>
                        <th>Role</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $account): ?>
                        <tr>
                            <td data-label="Nama"><?= e($account["name"]) ?></td>
                            <td data-label="Username"><?= e($account["username"]) ?></td>
                            <td data-label="Email"><?= e($account["email"]) ?></td>
                            <td data-label="Role"><?= e($roleLabels[$account["role"]] ?? $account["role"]) ?></td>
                            <td data-label="Aksi">
                                <?php if ((int) $account["id"] === $currentUserId): ?>
                                    <span class="team-current-user">Akun yang sedang digunakan</span>
                                <?php else: ?>
                                    <div class="team-list-actions">
                                        <a href="edit_user.php?id=<?= (int) $account["id"] ?>" class="btn-sm btn-outline">Edit</a>
                                        <form method="post" class="team-delete-form" onsubmit="return confirm('Hapus pengguna ini? Tindakan ini tidak dapat dibatalkan.')">
                                            <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
                                            <input type="hidden" name="action" value="delete_user">
                                            <input type="hidden" name="user_id" value="<?= (int) $account["id"] ?>">
                                            <button class="btn-sm btn-delete" type="submit">Hapus</button>
                                        </form>
                                    </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>
<script>
    (function () {
        var toggleButton = document.getElementById("user-create-toggle");
        var cancelButton = document.getElementById("user-create-cancel");
        var createPanel = document.getElementById("user-create-panel");
        var userListPanel = document.getElementById("user-list-panel");

        function setCreateMode(showForm) {
            createPanel.hidden = !showForm;
            userListPanel.hidden = showForm;
            toggleButton.hidden = showForm;
            toggleButton.setAttribute("aria-expanded", showForm ? "true" : "false");
            if (showForm) {
                document.getElementById("new-name").focus();
            } else {
                if (window.location.search !== "") {
                    window.history.replaceState(null, "", window.location.pathname);
                }
                toggleButton.focus();
            }
        }

        toggleButton.addEventListener("click", function () {
            setCreateMode(true);
        });
        cancelButton.addEventListener("click", function () {
            setCreateMode(false);
        });
    })();
</script>
</body>
</html>
