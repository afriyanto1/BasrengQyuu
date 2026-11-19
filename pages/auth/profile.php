<?php
require dirname(__DIR__, 2) . "/includes/config.php";
require_login();

$accountId = (int) user()["id"];
$query = $pdo->prepare(
    "SELECT id, name, email, password, role, province, city, district, village, address
     FROM users WHERE id = ? LIMIT 1"
);
$query->execute([$accountId]);
$account = $query->fetch();
if (!$account) {
    http_response_code(404);
    exit("Akun tidak ditemukan.");
}

$error = "";
$section = is_string($_GET["section"] ?? null) && in_array($_GET["section"], ["address", "password"], true)
    ? $_GET["section"]
    : "address";
$formValues = [
    "name" => $account["name"],
    "email" => $account["email"],
    "province" => $account["province"] ?? "",
    "city" => $account["city"] ?? "",
    "district" => $account["district"] ?? "",
    "village" => $account["village"] ?? "",
    "address" => $account["address"] ?? "",
];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    check_csrf();
    $postedSection = $_POST["section"] ?? "";
    if (!is_string($postedSection) || !in_array($postedSection, ["address", "password"], true)) {
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
    } elseif ($section === "address" && $error === "") {
        $addressFieldsPosted = true;
        foreach (["province", "city", "district", "village"] as $field) {
            if (!isset($_POST[$field]) || !is_string($_POST[$field])) {
                $addressFieldsPosted = false;
            }
        }
        foreach ($formValues as $field => $value) {
            $postedValue = $_POST[$field] ?? "";
            $formValues[$field] = is_string($postedValue) ? trim($postedValue) : "";
        }
        $addressFields = ["province", "city", "district", "village", "address"];
        $hasAddress = count(array_filter(
            $addressFields,
            static fn(string $field): bool => $formValues[$field] !== ""
        )) > 0;
        $hasCompleteAddress = count(array_filter(
            $addressFields,
            static fn(string $field): bool => $formValues[$field] !== ""
        )) === count($addressFields);

        if (
            $formValues["name"] === "" ||
            strlen($formValues["name"]) > 120 ||
            !filter_var($formValues["email"], FILTER_VALIDATE_EMAIL) ||
            strlen($formValues["email"]) > 160
        ) {
            $error = "Masukkan nama pengguna dan alamat email yang valid.";
        } elseif (!$addressFieldsPosted) {
            $error = "Daftar wilayah belum siap. Muat ulang halaman sebelum menyimpan profil.";
        } elseif ($hasAddress && !$hasCompleteAddress) {
            $error = "Lengkapi semua bagian alamat, dari provinsi hingga alamat jalan.";
        } else {
            $emailCheck = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1");
            $emailCheck->execute([$formValues["email"], $accountId]);
            if ($emailCheck->fetch()) {
                $error = "Email sudah digunakan akun lain.";
            } else {
                try {
                    $update = $pdo->prepare(
                        "UPDATE users SET name = ?, email = ?, province = ?, city = ?, district = ?, village = ?, address = ? WHERE id = ?"
                    );
                    $update->execute([
                        $formValues["name"],
                        $formValues["email"],
                        $formValues["province"] ?: null,
                        $formValues["city"] ?: null,
                        $formValues["district"] ?: null,
                        $formValues["village"] ?: null,
                        $formValues["address"] ?: null,
                        $accountId,
                    ]);
                    $_SESSION["user"]["name"] = $formValues["name"];
                    $_SESSION["user"]["email"] = $formValues["email"];
                    flash("Profil berhasil diperbarui.", "success");
                    header("Location: " . app_url("pages/auth/profile.php?section=address"));
                    exit();
                } catch (PDOException $e) {
                    if ($e->getCode() === "23000") {
                        $error = "Email sudah digunakan akun lain.";
                    } else {
                        error_log("Pembaruan profil gagal untuk user ID " . $accountId . ": " . $e->getMessage());
                        $error = "Profil gagal diperbarui. Coba lagi beberapa saat lagi.";
                    }
                }
            }
        }
    }
}

$homeUrl = $account["role"] === "seller"
    ? app_url("pages/seller/seller.php")
    : app_url("pages/buyer/shop.php");
?><!doctype html>
<html lang="id">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= $section === "password" ? "Ganti Password" : "Ubah Alamat" ?> - BasrengQyuu Store</title>
    <link rel="icon" type="image/svg+xml" href="<?= e(app_favicon_url()) ?>">
    <link rel="stylesheet" href="<?= e(app_url('assets/css/style.css')) ?>?v=<?= (int) filemtime(app_path('assets/css/style.css')) ?>">
  </head>
  <body class="register-page profile-page">
    <header class="profile-nav">
      <a class="brand" href="<?= e($homeUrl) ?>"><span class="brand-name"><span class="brand-basreng">Basreng</span><span class="brand-qyuu">Qyuu&nbsp;</span><span class="brand-store">Store</span></span></a>
      <button class="mobile-toggle" type="button" aria-label="Buka navigasi" onclick="document.querySelector('nav').classList.toggle('show')">☰</button>
      <nav>
        <a href="<?= e($homeUrl) ?>">Kembali ke menu</a>
        <a href="<?= e(app_url('pages/auth/logout.php')) ?>" class="profile-logout">Logout</a>
      </nav>
    </header>

    <main class="register-card">
      <header class="register-header">
        <span class="register-eyebrow">PENGATURAN AKUN</span>
        <h1><?= $section === "password" ? "Ganti Password" : "Ubah Alamat" ?></h1>
        <p><?= $section === "password" ? "Gunakan password saat ini untuk mengamankan perubahan." : "Perbarui informasi akun dan alamat pengiriman Anda." ?></p>
      </header>

      <?php show_flash(); ?>
      <?php if ($error): ?>
        <div class="alert" role="alert"><?= e($error) ?></div>
      <?php endif; ?>

      <form method="post" class="register-form">
        <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
        <input type="hidden" name="section" value="<?= e($section) ?>">

        <?php if ($section === "address"): ?>
          <section class="register-section" aria-labelledby="account-heading">
            <div class="register-section-heading">
              <span class="register-step">01</span>
              <div>
                <h2 id="account-heading">Informasi akun</h2>
                <p>Nama pengguna dan email untuk akun Anda.</p>
              </div>
            </div>
            <div class="register-grid">
              <div class="register-field">
                <label for="name">Nama pengguna</label>
                <input id="name" name="name" type="text" autocomplete="name" maxlength="120" value="<?= e($formValues["name"]) ?>" required>
              </div>
              <div class="register-field">
                <label for="email">Email</label>
                <input id="email" name="email" type="email" autocomplete="email" maxlength="160" value="<?= e($formValues["email"]) ?>" required>
              </div>
            </div>
          </section>

          <section class="register-section" aria-labelledby="address-heading">
            <div class="register-section-heading">
              <span class="register-step">02</span>
              <div>
                <h2 id="address-heading">Alamat</h2>
                <p>Ubah alamat lengkap yang digunakan untuk pengiriman.</p>
              </div>
            </div>
            <div class="register-grid">
              <div class="register-field">
                <label for="province">Provinsi</label>
                <select id="province" name="province" disabled><option value="">Memuat daftar provinsi...</option></select>
              </div>
              <div class="register-field">
                <label for="city">Kota / Kabupaten</label>
                <select id="city" name="city" disabled><option value="">Pilih provinsi terlebih dahulu</option></select>
              </div>
              <div class="register-field">
                <label for="district">Kecamatan</label>
                <select id="district" name="district" disabled><option value="">Pilih kota/kabupaten terlebih dahulu</option></select>
              </div>
              <div class="register-field">
                <label for="village">Kelurahan / Desa</label>
                <select id="village" name="village" disabled><option value="">Pilih kecamatan terlebih dahulu</option></select>
              </div>
              <div class="register-field register-field-wide">
                <label for="address">Alamat jalan</label>
                <textarea id="address" name="address" rows="3" maxlength="500" autocomplete="street-address" placeholder="Nama jalan, nomor rumah, RT/RW, patokan"><?= e($formValues["address"]) ?></textarea>
              </div>
            </div>
            <div class="region-feedback" id="region-feedback" role="status" aria-live="polite"></div>
            <button type="button" class="region-retry" id="region-retry" hidden>Muat ulang daftar wilayah</button>
          </section>
        <?php else: ?>
          <section class="register-section" aria-labelledby="password-heading">
            <div class="register-section-heading">
              <span class="register-step">01</span>
              <div>
                <h2 id="password-heading">Perbarui password</h2>
                <p>Password baru harus terdiri dari minimal 8 karakter.</p>
              </div>
            </div>
            <div class="register-grid">
              <div class="register-field register-field-wide">
                <label for="current_password">Password saat ini</label>
                <div class="password-input-group">
                  <input id="current_password" name="current_password" type="password" autocomplete="current-password" required>
                  <button class="password-toggle" type="button" aria-label="Tampilkan password saat ini" aria-controls="current_password" aria-pressed="false">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                  </button>
                </div>
              </div>
              <div class="register-field">
                <label for="new_password">Password baru</label>
                <div class="password-input-group">
                  <input id="new_password" name="new_password" type="password" autocomplete="new-password" minlength="8" required>
                  <button class="password-toggle" type="button" aria-label="Tampilkan password baru" aria-controls="new_password" aria-pressed="false">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                  </button>
                </div>
              </div>
              <div class="register-field">
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

        <button class="btn full register-submit" type="submit"><?= $section === "password" ? "Simpan password baru" : "Simpan perubahan" ?></button>
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
      const provinceSelect = document.getElementById("province");
      const citySelect = document.getElementById("city");
      const districtSelect = document.getElementById("district");
      const villageSelect = document.getElementById("village");
      const feedback = document.getElementById("region-feedback");
      const retryButton = document.getElementById("region-retry");

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
        feedback.textContent = "Memuat daftar provinsi...";
        retryButton.hidden = true;
        resetSelect(provinceSelect, "Memuat daftar provinsi...");
        try {
          await loadRegions(provinceSelect, "../api/region_options.php?level=provinces", "Pilih provinsi", savedRegion.province);
          feedback.textContent = "";
          if (provinceSelect.value) await loadCities(savedRegion.city);
        } catch (error) {
          showRegionError(error);
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
      loadProvinces();
    </script>
    <?php else: ?>
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
