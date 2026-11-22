<?php
require dirname(__DIR__, 2) . "/includes/config.php";
require dirname(__DIR__, 2) . "/includes/email.php";
require_login();

$accountId = (int) user()["id"];
$query = $pdo->prepare(
    "SELECT id, name, username, email, phone, date_of_birth, gender, email_verified_at, password, role,
            country_code, province, city, district, village, address
     FROM users WHERE id = ? LIMIT 1"
);
$query->execute([$accountId]);
$account = $query->fetch();
if (!$account) {
    http_response_code(404);
    exit("Akun tidak ditemukan.");
}

$error = "";
$is_restricted_account = in_array($account["role"], ["admin", "cashier"], true);
$sections = $is_restricted_account ? ["password"] : ["profile", "address", "password"];
$requestedSection = $_GET["section"] ?? null;
$section = is_string($requestedSection) && in_array($requestedSection, $sections, true)
    ? $requestedSection
    : ($is_restricted_account ? "password" : "profile");
if (
    $is_restricted_account &&
    is_string($requestedSection) &&
    in_array($requestedSection, ["profile", "address"], true)
) {
    header("Location: " . app_url("pages/auth/profile.php?section=password"));
    exit();
}
$formValues = [
    "name" => $account["name"],
    "email" => $account["email"],
    "phone" => $account["phone"] ?? "",
    "date_of_birth" => $account["date_of_birth"] ?? "",
    "gender" => $account["gender"] ?? "",
    "country_code" => $account["country_code"] ?? "ID",
    "province" => $account["province"] ?? "",
    "city" => $account["city"] ?? "",
    "district" => $account["district"] ?? "",
    "village" => $account["village"] ?? "",
    "address" => $account["address"] ?? "",
];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    check_csrf();
    $postedSection = $_POST["section"] ?? "";
    if (!is_string($postedSection) || !in_array($postedSection, $sections, true)) {
        $error = "Pilihan menu profil tidak valid.";
    } else {
        $section = $postedSection;
    }

    if ($section === "password" && $error === "") {
        $currentPassword = is_string($_POST["current_password"] ?? null) ? $_POST["current_password"] : "";
        $newPassword = is_string($_POST["new_password"] ?? null) ? $_POST["new_password"] : "";
        $passwordConfirmation = is_string($_POST["password_confirmation"] ?? null)
            ? $_POST["password_confirmation"]
            : "";

        if ($currentPassword === "" || $newPassword === "" || $passwordConfirmation === "") {
            $error = "Lengkapi password saat ini, password baru, dan konfirmasi password.";
        } elseif (!password_verify($currentPassword, $account["password"])) {
            $error = "Password saat ini tidak sesuai.";
        } elseif (strlen($newPassword) < 8) {
            $error = "Password baru minimal 8 karakter.";
        } elseif ($newPassword !== $passwordConfirmation) {
            $error = "Konfirmasi password baru tidak sama.";
        } else {
            try {
                $update = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
                $update->execute([password_hash($newPassword, PASSWORD_DEFAULT), $accountId]);
                flash("Password berhasil diubah.", "success");
                header("Location: " . app_url("pages/auth/profile.php?section=password"));
                exit();
            } catch (PDOException $e) {
                error_log("Gagal mengganti password untuk user ID " . $accountId . ": " . $e->getMessage());
                $error = "Password gagal diubah. Coba lagi beberapa saat lagi.";
            }
        }
    } elseif ($section === "profile" && $error === "") {
        $profileFieldsPosted = true;
        foreach (["name", "email", "phone", "date_of_birth", "gender"] as $field) {
            if (!isset($_POST[$field]) || !is_string($_POST[$field])) {
                $profileFieldsPosted = false;
            }
            $postedValue = $_POST[$field] ?? "";
            $formValues[$field] = is_string($postedValue) ? trim($postedValue) : "";
        }
        $birthDate = $formValues["date_of_birth"] === ""
            ? null
            : DateTimeImmutable::createFromFormat("!Y-m-d", $formValues["date_of_birth"]);
        $birthDateErrors = DateTimeImmutable::getLastErrors();
        $validBirthDate = $formValues["date_of_birth"] === "" || (
            $birthDate !== false &&
            $birthDate->format("Y-m-d") === $formValues["date_of_birth"] &&
            ($birthDateErrors === false ||
                ($birthDateErrors["warning_count"] === 0 && $birthDateErrors["error_count"] === 0)) &&
            $birthDate <= new DateTimeImmutable("today")
        );
        $validGender = in_array($formValues["gender"], ["", "male", "female", "prefer_not_to_say"], true);

        if (!$profileFieldsPosted) {
            $error = "Data profil tidak lengkap. Muat ulang halaman sebelum menyimpan.";
        } elseif (
            $formValues["name"] === "" ||
            strlen($formValues["name"]) > 120 ||
            !filter_var($formValues["email"], FILTER_VALIDATE_EMAIL) ||
            strlen($formValues["email"]) > 160 ||
            strlen($formValues["phone"]) > 30 ||
            ($formValues["phone"] !== "" &&
                preg_match('/\A[0-9+().\-\s]+\z/u', $formValues["phone"]) !== 1) ||
            !$validBirthDate ||
            !$validGender
        ) {
            $error = "Periksa kembali nama, email, nomor telepon, tanggal lahir, dan gender Anda.";
        } else {
            $emailCheck = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1");
            $emailCheck->execute([$formValues["email"], $accountId]);
            if ($emailCheck->fetch()) {
                $error = "Email sudah digunakan akun lain.";
            } else {
                $emailChanged = $formValues["email"] !== $account["email"];
                $activationUrl = "";
                $token = "";
                if ($emailChanged) {
                    $token = bin2hex(random_bytes(32));
                    $generatedActivationUrl = app_email_absolute_url(
                        "pages/auth/activate.php?token=" . urlencode($token)
                    );
                    if ($generatedActivationUrl === null) {
                        $error = "Tautan verifikasi email tidak dapat dibuat. Profil belum disimpan.";
                    } else {
                        $activationUrl = $generatedActivationUrl;
                    }
                }

                if ($error === "") {
                    try {
                        $pdo->beginTransaction();
                        $update = $pdo->prepare(
                            "UPDATE users
                             SET name = ?, email = ?, phone = ?, date_of_birth = ?, gender = ?,
                                 email_verified_at = ?
                             WHERE id = ?"
                        );
                        $update->execute([
                            $formValues["name"],
                            $formValues["email"],
                            $formValues["phone"] ?: null,
                            $formValues["date_of_birth"] ?: null,
                            $formValues["gender"] ?: null,
                            $emailChanged ? null : $account["email_verified_at"],
                            $accountId,
                        ]);
                        if ($emailChanged) {
                            $pdo->prepare("DELETE FROM email_verification_tokens WHERE user_id = ?")
                                ->execute([$accountId]);
                            $insertToken = $pdo->prepare(
                                "INSERT INTO email_verification_tokens (user_id, token_hash, expires_at)
                                 VALUES (?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 24 HOUR))"
                            );
                            $insertToken->execute([$accountId, hash("sha256", $token)]);
                        }
                        $pdo->commit();

                        $_SESSION["user"]["name"] = $formValues["name"];
                        $_SESSION["user"]["email"] = $formValues["email"];
                        if ($emailChanged) {
                            if (!send_account_activation_email(
                                $formValues["email"],
                                $formValues["name"],
                                $activationUrl
                            )) {
                                flash(
                                    "Email berubah, tetapi tautan aktivasi gagal dikirim. Silakan kirim ulang dari halaman masuk.",
                                    "danger"
                                );
                            } else {
                                flash(
                                    "Email berubah. Verifikasi alamat email baru sebelum masuk kembali.",
                                    "success"
                                );
                            }
                            unset($_SESSION["user"]);
                            session_regenerate_id(true);
                            header("Location: " . app_url("pages/auth/login.php"));
                            exit();
                        }

                        flash("Data profil berhasil diperbarui.", "success");
                        header("Location: " . app_url("pages/auth/profile.php?section=profile"));
                        exit();
                    } catch (Throwable $e) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        if ($e instanceof PDOException && $e->getCode() === "23000") {
                            $error = "Email sudah digunakan akun lain.";
                        } else {
                            error_log("Pembaruan profil gagal untuk user ID " . $accountId . ": " . $e->getMessage());
                            $error = "Profil gagal diperbarui. Coba lagi beberapa saat lagi.";
                        }
                    }
                }
            }
        }
    } elseif ($section === "address" && $error === "") {
        $addressFieldsPosted = true;
        foreach (["country_code", "province", "city", "district", "village", "address"] as $field) {
            if (!isset($_POST[$field]) || !is_string($_POST[$field])) {
                $addressFieldsPosted = false;
            }
        }
        foreach (["country_code", "province", "city", "district", "village", "address"] as $field) {
            $postedValue = $_POST[$field] ?? "";
            $formValues[$field] = is_string($postedValue) ? trim($postedValue) : "";
        }
        $validCountry = array_key_exists($formValues["country_code"], shipping_country_options());
        $addressFields = ["province", "city", "district", "village", "address"];
        $hasAddress = count(array_filter(
            $addressFields,
            static fn(string $field): bool => $formValues[$field] !== ""
        )) > 0;
        $hasCompleteAddress = count(array_filter(
            $addressFields,
            static fn(string $field): bool => $formValues[$field] !== ""
        )) === count($addressFields);

        if (!$addressFieldsPosted) {
            $error = "Daftar wilayah belum siap. Muat ulang halaman sebelum menyimpan profil.";
        } elseif (!$validCountry) {
            $error = "Pilih negara tujuan pengiriman yang valid.";
        } elseif ($hasAddress && !$hasCompleteAddress) {
            $error = "Lengkapi semua bagian alamat hingga alamat jalan.";
        } else {
            try {
                $update = $pdo->prepare(
                    "UPDATE users
                     SET country_code = ?, province = ?, city = ?, district = ?, village = ?, address = ?
                     WHERE id = ?"
                );
                $update->execute([
                    $formValues["country_code"],
                    $formValues["province"] ?: null,
                    $formValues["city"] ?: null,
                    $formValues["district"] ?: null,
                    $formValues["village"] ?: null,
                    $formValues["address"] ?: null,
                    $accountId,
                ]);
                flash("Alamat berhasil diperbarui.", "success");
                header("Location: " . app_url("pages/auth/profile.php?section=address"));
                exit();
            } catch (PDOException $e) {
                error_log("Pembaruan alamat gagal untuk user ID " . $accountId . ": " . $e->getMessage());
                $error = "Alamat gagal diperbarui. Coba lagi beberapa saat lagi.";
            }
        }
    }
}

$homeUrl = is_cashier()
    ? app_url("pages/cashier/shop.php")
    : (is_seller()
        ? app_url("pages/seller/seller.php")
        : app_url("pages/buyer/shop.php"));
$sectionTitles = [
    "profile" => "Data profil",
    "address" => "Ubah alamat",
    "password" => "Ganti password",
];
?><!doctype html>
<html lang="id">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= e($sectionTitles[$section]) ?> - BasrengQyuu Store</title>
    <link rel="icon" type="image/svg+xml" href="<?= e(app_favicon_url()) ?>">
    <link rel="stylesheet" href="<?= e(app_url('assets/css/style.css')) ?>?v=<?= (int) filemtime(app_path('assets/css/style.css')) ?>">
  </head>
  <body class="profile-page">
    <?php if ($account["role"] === "buyer"): ?>
      <?php include dirname(__DIR__, 2) . "/includes/partials/buyer_nav.php"; ?>
    <?php else: ?>
      <header class="profile-nav">
        <a class="brand" href="<?= e($homeUrl) ?>"><span class="brand-name"><span class="brand-basreng">Basreng</span><span class="brand-qyuu">Qyuu&nbsp;</span><span class="brand-store">Store</span></span></a>
        <button class="mobile-toggle" type="button" aria-label="Buka navigasi" onclick="this.parentElement.querySelector('nav').classList.toggle('show')">☰</button>
        <nav>
          <a href="<?= e($homeUrl) ?>">Kembali ke menu</a>
          <details class="profile-menu">
            <summary class="profile-menu-toggle" aria-label="Buka menu akun">
              <svg aria-hidden="true" viewBox="0 0 24 24"><path d="M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm0 2c-4.42 0-8 2.24-8 5v1h16v-1c0-2.76-3.58-5-8-5Z"></path></svg>
              <span><?= e(user()["name"] ?? "Pengguna") ?></span>
            </summary>
            <div class="profile-menu-dropdown">
              <?php if (is_owner()): ?>
                <a href="<?= e(app_url('pages/auth/profile.php?section=profile')) ?>">Data profil</a>
                <a href="<?= e(app_url('pages/auth/profile.php?section=address')) ?>">Ubah alamat</a>
              <?php endif; ?>
              <a href="<?= e(app_url('pages/auth/profile.php?section=password')) ?>">Ganti password</a>
              <div class="profile-language-setting">
                <?php include dirname(__DIR__, 2) . "/includes/partials/language_switch.php"; ?>
              </div>
              <a href="<?= e(app_url('pages/auth/logout.php')) ?>" class="profile-menu-logout">Logout</a>
            </div>
          </details>
        </nav>
      </header>
    <?php endif; ?>

    <main class="profile-card">
      <section class="profile-hero">
        <span class="register-eyebrow">PENGATURAN AKUN</span>
        <h1><?= e($sectionTitles[$section]) ?></h1>
      </section>

      <?php show_flash(); ?>
      <?php if ($error): ?>
        <div class="alert" role="alert"><?= e($error) ?></div>
      <?php endif; ?>

      <form method="post" class="profile-form">
        <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
        <input type="hidden" name="section" value="<?= e($section) ?>">

        <?php if ($section === "profile"): ?>
          <section class="profile-section" aria-labelledby="account-heading">
            <div class="profile-section-heading">
              <div>
                <h2 id="account-heading">Informasi pribadi</h2>
              </div>
            </div>
            <div class="profile-form-grid">
              <div class="profile-field">
                <label for="username">Username unik</label>
                <input id="username" type="text" autocomplete="username" value="<?= e($account["username"]) ?>" readonly>
                <small>Username digunakan sebagai identitas unik akun.</small>
              </div>
              <div class="profile-field">
                <label for="name">Nama lengkap</label>
                <input id="name" name="name" type="text" autocomplete="name" maxlength="120" value="<?= e($formValues["name"]) ?>" required>
              </div>
              <div class="profile-field">
                <label for="email">Email</label>
                <input id="email" name="email" type="email" autocomplete="email" maxlength="160" value="<?= e($formValues["email"]) ?>" required>
              </div>
              <div class="profile-field">
                <label for="phone">Nomor telepon <span class="profile-optional">(opsional)</span></label>
                <input id="phone" name="phone" type="tel" autocomplete="tel" maxlength="30" value="<?= e($formValues["phone"]) ?>" placeholder="Contoh: 081234567890">
              </div>
              <div class="profile-field">
                <label for="gender">Jenis kelamin <span class="profile-optional">(opsional)</span></label>
                <select id="gender" name="gender">
                  <option value="">Pilih jenis kelamin</option>
                  <option value="male" <?= $formValues["gender"] === "male" ? "selected" : "" ?>>Laki-laki</option>
                  <option value="female" <?= $formValues["gender"] === "female" ? "selected" : "" ?>>Perempuan</option>
                  <option value="prefer_not_to_say" <?= $formValues["gender"] === "prefer_not_to_say" ? "selected" : "" ?>>Memilih tidak menyebutkan</option>
                </select>
              </div>
              <div class="profile-field">
                <label for="date_of_birth">Tanggal lahir <span class="profile-optional">(opsional)</span></label>
                <input id="date_of_birth" name="date_of_birth" type="date" autocomplete="bday" max="<?= e(date("Y-m-d")) ?>" value="<?= e($formValues["date_of_birth"]) ?>">
              </div>
            </div>
          </section>
        <?php elseif ($section === "address"): ?>
          <section class="profile-section" aria-labelledby="address-heading">
            <div class="profile-section-heading">
              <div>
                <h2 id="address-heading">Alamat</h2>
              </div>
            </div>
            <div class="profile-form-grid">
              <div class="profile-field profile-field-wide">
                <label for="country_code">Negara tujuan</label>
                <select id="country_code" name="country_code" required>
                  <?php foreach (shipping_country_options() as $code => $countryName): ?>
                    <option value="<?= e($code) ?>" <?= $formValues["country_code"] === $code ? "selected" : "" ?>><?= e($countryName) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div id="domestic-address-fields" class="profile-form-grid profile-field-wide">
              <div class="profile-field">
                <label for="province">Provinsi</label>
                <select id="province" name="province" disabled><option value="">Memuat daftar provinsi...</option></select>
              </div>
              <div class="profile-field">
                <label for="city">Kota / Kabupaten</label>
                <select id="city" name="city" disabled><option value="">Pilih provinsi terlebih dahulu</option></select>
              </div>
              <div class="profile-field">
                <label for="district">Kecamatan</label>
                <select id="district" name="district" disabled><option value="">Pilih kota/kabupaten terlebih dahulu</option></select>
              </div>
              <div class="profile-field">
                <label for="village">Kelurahan / Desa</label>
                <select id="village" name="village" disabled><option value="">Pilih kecamatan terlebih dahulu</option></select>
              </div>
              </div>
              <div id="international-address-fields" class="profile-form-grid profile-field-wide" hidden>
                <div class="profile-field">
                  <label for="foreign-province">Provinsi / negara bagian</label>
                  <input id="foreign-province" name="province" type="text" maxlength="120" disabled>
                </div>
                <div class="profile-field">
                  <label for="foreign-city">Kota</label>
                  <input id="foreign-city" name="city" type="text" maxlength="120" disabled>
                </div>
                <div class="profile-field">
                  <label for="foreign-district">Distrik / wilayah</label>
                  <input id="foreign-district" name="district" type="text" maxlength="120" disabled>
                </div>
                <div class="profile-field">
                  <label for="foreign-village">Kelurahan / kawasan</label>
                  <input id="foreign-village" name="village" type="text" maxlength="120" disabled>
                </div>
              </div>
              <div class="profile-field profile-field-wide">
                <label for="address">Alamat jalan</label>
                <textarea id="address" name="address" rows="3" maxlength="500" autocomplete="street-address" placeholder="Nama jalan, nomor rumah, RT/RW, patokan"><?= e($formValues["address"]) ?></textarea>
              </div>
            </div>
            <div class="region-feedback" id="region-feedback" role="status" aria-live="polite"></div>
            <button type="button" class="region-retry" id="region-retry" hidden>Muat ulang daftar wilayah</button>
          </section>
        <?php else: ?>
          <section class="profile-section" aria-labelledby="password-heading">
            <div class="profile-section-heading">
              <div>
                <h2 id="password-heading">Perbarui password</h2>
                <p>Password baru harus terdiri dari minimal 8 karakter.</p>
              </div>
            </div>
            <div class="profile-form-grid">
              <div class="profile-field profile-field-wide">
                <label for="current_password">Password saat ini</label>
                <div class="password-input-group">
                  <input id="current_password" name="current_password" type="password" autocomplete="current-password" required>
                  <button class="password-toggle" type="button" aria-label="Tampilkan password saat ini" aria-controls="current_password" aria-pressed="false">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                  </button>
                </div>
              </div>
              <div class="profile-field">
                <label for="new_password">Password baru</label>
                <div class="password-input-group">
                  <input id="new_password" name="new_password" type="password" autocomplete="new-password" minlength="8" required>
                  <button class="password-toggle" type="button" aria-label="Tampilkan password baru" aria-controls="new_password" aria-pressed="false">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                  </button>
                </div>
              </div>
              <div class="profile-field">
                <label for="password_confirmation">Konfirmasi password baru</label>
                <div class="password-input-group">
                  <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" minlength="8" required>
                  <button class="password-toggle" type="button" aria-label="Tampilkan konfirmasi password baru" aria-controls="password_confirmation" aria-pressed="false">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                  </button>
                </div>
              </div>
            </div>
          </section>
        <?php endif; ?>

        <button class="btn full register-submit profile-submit" type="submit">
          <?php if ($section === "password"): ?>
            Simpan password baru
          <?php elseif ($section === "address"): ?>
            Simpan alamat
          <?php else: ?>
            Simpan perubahan
          <?php endif; ?>
        </button>
        <a class="profile-cancel" href="<?= e($homeUrl) ?>"><?= e(t("Batal")) ?></a>
      </form>
    </main>

    <?php if ($section === "address"): ?>
    <script>
      const savedRegion = <?= json_encode(
          [
              "province" => $formValues["province"],
              "city" => $formValues["city"],
              "district" => $formValues["district"],
              "village" => $formValues["village"],
          ],
          JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
      ) ?>;
      const countrySelect = document.getElementById("country_code");
      const domesticFields = document.getElementById("domestic-address-fields");
      const internationalFields = document.getElementById("international-address-fields");
      const domesticControls = [...domesticFields.querySelectorAll("select")];
      const internationalControls = [...internationalFields.querySelectorAll("input")];
      const foreignValues = {
        province: <?= json_encode($formValues["country_code"] === "ID" ? "" : $formValues["province"], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
        city: <?= json_encode($formValues["country_code"] === "ID" ? "" : $formValues["city"], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
        district: <?= json_encode($formValues["country_code"] === "ID" ? "" : $formValues["district"], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
        village: <?= json_encode($formValues["country_code"] === "ID" ? "" : $formValues["village"], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
      };
      const provinceSelect = document.getElementById("province");
      const citySelect = document.getElementById("city");
      const districtSelect = document.getElementById("district");
      const villageSelect = document.getElementById("village");
      const feedback = document.getElementById("region-feedback");
      const retryButton = document.getElementById("region-retry");
      let regionsLoaded = false;
      let regionsLoading = false;
      function toggleAddressFields() {
        const isDomestic = countrySelect.value === "ID";
        domesticFields.hidden = !isDomestic;
        internationalFields.hidden = isDomestic;
        domesticFields.style.display = isDomestic ? "grid" : "none";
        internationalFields.style.display = isDomestic ? "none" : "grid";
        domesticControls.forEach((control) => { control.disabled = !isDomestic; });
        internationalControls.forEach((control) => {
          control.disabled = isDomestic;
          if (!isDomestic && !control.value) {
            control.value = foreignValues[control.name] || "";
          }
        });
        feedback.hidden = !isDomestic;
        retryButton.hidden = !isDomestic || retryButton.hidden;
        if (isDomestic && !regionsLoaded && !regionsLoading) loadProvinces();
      }

      function resetSelect(select, label) {
        select.replaceChildren(new Option(label, ""));
        select.disabled = true;
      }

      async function loadRegions(select, url, placeholder, selectedName = "") {
        const response = await fetch(url);
        if (!response.ok) throw new Error(`Daftar wilayah gagal dimuat (${response.status}).`);
        const result = await response.json();
        if (!Array.isArray(result.data)) throw new Error("Format daftar wilayah tidak sesuai.");
        select.replaceChildren(new Option(placeholder, ""));
        result.data.forEach((item) => {
          const option = new Option(item.name, item.name);
          option.dataset.code = item.code;
          select.add(option);
        });
        select.disabled = false;
        if (selectedName) select.value = selectedName;
      }

      function showRegionError(error) {
        feedback.textContent = `${error.message} Periksa koneksi internet, lalu coba muat ulang.`;
        retryButton.hidden = false;
      }

      async function loadProvinces() {
        regionsLoading = true;
        feedback.textContent = "Memuat daftar provinsi...";
        retryButton.hidden = true;
        resetSelect(provinceSelect, "Memuat daftar provinsi...");
        try {
          await loadRegions(provinceSelect, "../api/region_options.php?level=provinces", "Pilih provinsi", savedRegion.province);
          regionsLoaded = true;
          feedback.textContent = "";
          if (provinceSelect.value) await loadCities(savedRegion.city);
        } catch (error) {
          regionsLoaded = false;
          showRegionError(error);
        } finally {
          regionsLoading = false;
        }
      }

      async function loadCities(selectedName = "") {
        resetSelect(citySelect, "Memuat kota/kabupaten...");
        resetSelect(districtSelect, "Pilih kota/kabupaten terlebih dahulu");
        resetSelect(villageSelect, "Pilih kecamatan terlebih dahulu");
        if (!provinceSelect.value) return;
        await loadRegions(citySelect, `../api/region_options.php?level=regencies&code=${encodeURIComponent(provinceSelect.selectedOptions[0].dataset.code)}`, "Pilih kota/kabupaten", selectedName);
        if (citySelect.value) await loadDistricts(savedRegion.district);
      }

      async function loadDistricts(selectedName = "") {
        resetSelect(districtSelect, "Memuat kecamatan...");
        resetSelect(villageSelect, "Pilih kecamatan terlebih dahulu");
        if (!citySelect.value) return;
        await loadRegions(districtSelect, `../api/region_options.php?level=districts&code=${encodeURIComponent(citySelect.selectedOptions[0].dataset.code)}`, "Pilih kecamatan", selectedName);
        if (districtSelect.value) await loadVillages(savedRegion.village);
      }

      async function loadVillages(selectedName = "") {
        resetSelect(villageSelect, "Memuat kelurahan/desa...");
        if (!districtSelect.value) return;
        await loadRegions(villageSelect, `../api/region_options.php?level=villages&code=${encodeURIComponent(districtSelect.selectedOptions[0].dataset.code)}`, "Pilih kelurahan/desa", selectedName);
      }

      provinceSelect.addEventListener("change", async () => {
        savedRegion.province = provinceSelect.value;
        savedRegion.city = savedRegion.district = savedRegion.village = "";
        feedback.textContent = "";
        try { await loadCities(); } catch (error) { showRegionError(error); }
      });
      citySelect.addEventListener("change", async () => {
        savedRegion.city = citySelect.value;
        savedRegion.district = savedRegion.village = "";
        feedback.textContent = "";
        try { await loadDistricts(); } catch (error) { showRegionError(error); }
      });
      districtSelect.addEventListener("change", async () => {
        savedRegion.district = districtSelect.value;
        savedRegion.village = "";
        feedback.textContent = "";
        try { await loadVillages(); } catch (error) { showRegionError(error); }
      });
      retryButton.addEventListener("click", loadProvinces);
      countrySelect.addEventListener("change", toggleAddressFields);
      toggleAddressFields();
    </script>
    <?php elseif ($section === "password"): ?>
    <script>
      const openEye = '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle>';
      const closedEye = '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line>';
      [
        ["current_password", "Tampilkan password saat ini", "Sembunyikan password saat ini"],
        ["new_password", "Tampilkan password baru", "Sembunyikan password baru"],
        ["password_confirmation", "Tampilkan konfirmasi password baru", "Sembunyikan konfirmasi password baru"]
      ].forEach(([inputId, showLabel, hideLabel]) => {
        const input = document.getElementById(inputId);
        const toggle = document.querySelector(`[aria-controls="${inputId}"]`);
        const icon = toggle.querySelector("svg");
        toggle.addEventListener("click", () => {
          const isVisible = input.type === "password";
          input.type = isVisible ? "text" : "password";
          toggle.setAttribute("aria-pressed", String(isVisible));
          toggle.setAttribute("aria-label", isVisible ? hideLabel : showLabel);
          icon.innerHTML = isVisible ? closedEye : openEye;
        });
      });
    </script>
    <?php endif; ?>
  </body>
</html>
