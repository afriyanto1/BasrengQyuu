<?php
require dirname(__DIR__, 2) . "/includes/config.php";
require_role("owner");

$currentUserId = (int) user()["id"];
$roleLabels = [
    "owner" => "Owner",
    "admin" => "Admin",
    "cashier" => "Kasir",
];
$id = filter_var($_GET["id"] ?? null, FILTER_VALIDATE_INT);

if ($id === false || $id < 1) {
    flash("Data pengguna tidak valid.");
    header("Location: team.php");
    exit();
}

$userStmt = $pdo->prepare(
    "SELECT id, name, role FROM users WHERE id = ? AND role IN ('owner', 'admin', 'cashier')"
);
$userStmt->execute([$id]);
$account = $userStmt->fetch();
if (!$account) {
    flash("Pengguna Owner, Admin, atau Kasir tidak ditemukan.");
    header("Location: team.php");
    exit();
}
if ((int) $account["id"] === $currentUserId) {
    flash("Akun yang sedang digunakan tidak dapat diedit dari sini.");
    header("Location: team.php");
    exit();
}

$name = (string) $account["name"];
$role = (string) $account["role"];
$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    check_csrf();

    $name = is_string($_POST["name"] ?? null) ? trim($_POST["name"]) : "";
    $role = $_POST["role"] ?? null;
    $password = is_string($_POST["password"] ?? null) ? $_POST["password"] : "";
    $passwordConfirmation = is_string($_POST["password_confirmation"] ?? null)
        ? $_POST["password_confirmation"]
        : "";

    if (preg_match('/\A.{1,120}\z/us', $name) !== 1) {
        $error = "Nama harus diisi maksimal 120 karakter.";
    } elseif (!is_string($role) || !array_key_exists($role, $roleLabels)) {
        $error = "Role tidak valid.";
    } elseif (
        ($password !== "" || $passwordConfirmation !== "") &&
        (strlen($password) < 8 || $password !== $passwordConfirmation)
    ) {
        $error = "Password dan konfirmasi password harus sama dan minimal 8 karakter.";
    } else {
        try {
            $pdo->beginTransaction();
            $ownerRows = $pdo->query(
                "SELECT id FROM users WHERE role = 'owner' FOR UPDATE"
            )->fetchAll();
            $lockedUserStmt = $pdo->prepare(
                "SELECT id, role FROM users WHERE id = ? AND role IN ('owner', 'admin', 'cashier') FOR UPDATE"
            );
            $lockedUserStmt->execute([$id]);
            $lockedUser = $lockedUserStmt->fetch();
            if (!$lockedUser) {
                throw new RuntimeException("Pengguna Owner, Admin, atau Kasir tidak ditemukan.");
            }
            if ($lockedUser["role"] === "owner" && $role !== "owner" && count($ownerRows) <= 1) {
                throw new RuntimeException("Setidaknya satu akun Owner harus tetap ada.");
            }

            if ($password !== "") {
                $updateStmt = $pdo->prepare(
                    "UPDATE users SET name = ?, role = ?, password = ? WHERE id = ?"
                );
                $updateStmt->execute([
                    $name,
                    $role,
                    password_hash($password, PASSWORD_DEFAULT),
                    $id,
                ]);
            } else {
                $updateStmt = $pdo->prepare(
                    "UPDATE users SET name = ?, role = ? WHERE id = ?"
                );
                $updateStmt->execute([$name, $role, $id]);
            }
            $pdo->commit();
            flash("Pengguna berhasil diperbarui.", "success");
            header("Location: team.php");
            exit();
        } catch (RuntimeException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = $e->getMessage();
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log("Gagal memperbarui pengguna seller: " . $e->getMessage());
            $error = "Pengguna gagal diperbarui. Silakan coba lagi.";
        }
    }
}
?><!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Edit Pengguna - BasrengQyuu Store</title>
    <link rel="icon" type="image/svg+xml" href="<?= e(app_favicon_url()) ?>">
    <link rel="stylesheet" href="<?= e(app_url('assets/css/style.css')) ?>?v=<?= (int) filemtime(app_path('assets/css/style.css')) ?>">
    <style>
        .user-edit-header { max-width: 900px; margin: 0 auto 20px; display: flex; align-items: center; justify-content: space-between; gap: 16px; }
        .user-edit-header h1 { margin: 0 0 4px; }
        .user-edit-header p { margin: 0; color: #64748b; }
        .user-edit-header .btn { flex: 0 0 auto; margin: 0; white-space: nowrap; }
        .user-edit-card { max-width: 900px; margin: 0 auto; padding: 32px; }
        .user-edit-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 20px; }
        .user-edit-field { min-width: 0; }
        .user-edit-field label { display: block; margin: 0 0 6px; color: #475569; font-size: 13px; font-weight: 600; }
        .user-edit-field input, .user-edit-field select { width: 100%; margin: 0; padding: 11px 14px; }
        .user-edit-field .password-input-group input { padding-right: 52px; }
        .user-edit-hint { display: block; margin-top: 6px; color: #64748b; font-size: 12px; }
        .user-edit-actions { display: flex; justify-content: flex-end; gap: 12px; margin-top: 24px; padding-top: 20px; border-top: 1px solid #e2e8f0; }
        .user-edit-actions .btn { margin: 0; }
        @media (max-width: 700px) {
            .user-edit-header { align-items: flex-start; flex-direction: column; }
            .user-edit-header .btn { width: 100%; text-align: center; }
            .user-edit-card { padding: 20px 16px; }
            .user-edit-grid { grid-template-columns: 1fr; gap: 16px; }
            .user-edit-actions { flex-direction: column-reverse; }
            .user-edit-actions .btn { width: 100%; }
        }
    </style>
</head>
<body>
<?php include dirname(__DIR__, 2) . "/includes/partials/seller_nav.php"; ?>
<main>
    <?php show_flash(); ?>
    <?php if ($error !== ""): ?>
        <div class="alert alert-danger" role="alert"><?= e($error) ?></div>
    <?php endif; ?>
    <div class="user-edit-header">
        <div>
            <h1>Edit Pengguna</h1>
            <p>Perbarui nama, role, atau password pengguna.</p>
        </div>
        <a href="team.php" class="btn btn-outline">Kembali</a>
    </div>
    <section class="panel user-edit-card">
        <form method="post">
            <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
            <div class="user-edit-grid">
                <div class="user-edit-field">
                    <label for="edit-name">Nama</label>
                    <input id="edit-name" name="name" type="text" maxlength="120" value="<?= e($name) ?>" required>
                </div>
                <div class="user-edit-field">
                    <label for="edit-role">Role</label>
                    <select id="edit-role" name="role" required>
                        <?php foreach ($roleLabels as $value => $label): ?>
                            <option value="<?= e($value) ?>" <?= $role === $value ? "selected" : "" ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="user-edit-field">
                    <label for="edit-password">Password baru (opsional)</label>
                    <div class="password-input-group">
                        <input id="edit-password" name="password" type="password" minlength="8" autocomplete="new-password">
                        <button class="password-toggle" id="edit-password-toggle" type="button" aria-label="Tampilkan password baru" aria-controls="edit-password" aria-pressed="false">
                            <svg id="edit-password-eye" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                        </button>
                    </div>
                    <small class="user-edit-hint">Kosongkan jika tidak ingin mengganti password.</small>
                </div>
                <div class="user-edit-field">
                    <label for="edit-password-confirmation">Konfirmasi password</label>
                    <div class="password-input-group">
                        <input id="edit-password-confirmation" name="password_confirmation" type="password" minlength="8" autocomplete="new-password">
                        <button class="password-toggle" id="edit-password-confirmation-toggle" type="button" aria-label="Tampilkan konfirmasi password" aria-controls="edit-password-confirmation" aria-pressed="false">
                            <svg id="edit-password-confirmation-eye" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                        </button>
                    </div>
                </div>
            </div>
            <div class="user-edit-actions">
                <a href="team.php" class="btn btn-outline">Batal</a>
                <button class="btn" type="submit">Simpan Perubahan</button>
            </div>
        </form>
    </section>
</main>
<script>
    const openEye = '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle>';
    const closedEye = '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line>';
    [
        ["edit-password", "edit-password-toggle", "edit-password-eye", "Tampilkan password baru", "Sembunyikan password baru"],
        ["edit-password-confirmation", "edit-password-confirmation-toggle", "edit-password-confirmation-eye", "Tampilkan konfirmasi password", "Sembunyikan konfirmasi password"]
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
</body>
</html>
