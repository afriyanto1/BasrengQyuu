<?php
require dirname(__DIR__, 2) . "/includes/config.php";
$error = "";
$formValues = [
    "name" => "",
    "email" => "",
    "province" => "",
    "city" => "",
    "district" => "",
    "village" => "",
    "address" => "",
];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    check_csrf();
    foreach ($formValues as $field => $value) {
        $postedValue = $_POST[$field] ?? "";
        $formValues[$field] = is_string($postedValue) ? trim($postedValue) : "";
    }
    $pass = is_string($_POST["password"] ?? null) ? $_POST["password"] : "";

    if (
        $formValues["name"] === "" ||
        !filter_var($formValues["email"], FILTER_VALIDATE_EMAIL) ||
        strlen($pass) < 8 ||
        $formValues["province"] === "" ||
        $formValues["city"] === "" ||
        $formValues["district"] === "" ||
        $formValues["village"] === "" ||
        $formValues["address"] === ""
    ) {
        $error = "Lengkapi semua data. Password minimal 8 karakter.";
    } else {
        try {
            $s = $pdo->prepare(
                "INSERT INTO users(name,email,password,role,province,city,district,village,address)
                 VALUES(?,?,?,'buyer',?,?,?,?,?)"
            );
            $s->execute([
                $formValues["name"],
                $formValues["email"],
                password_hash($pass, PASSWORD_DEFAULT),
                $formValues["province"],
                $formValues["city"],
                $formValues["district"],
                $formValues["village"],
                $formValues["address"],
            ]);
            flash("Pendaftaran berhasil. Silakan login.", "success");
            header("Location: login.php");
            exit();
        } catch (PDOException $e) {
            if ($e->getCode() === "23000") {
                $error = "Email sudah digunakan.";
            } else {
                error_log("Pendaftaran pembeli gagal: " . $e->getMessage());
                $error = "Pendaftaran gagal. Coba lagi beberapa saat lagi.";
            }
        }
    }
}
?><!doctype html>
<html lang="id">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Daftar Pembeli - BasrengQyuu Store</title>
    <base href="<?= e(app_url('pages/auth/')) ?>">
    <link rel="icon" type="image/svg+xml" href="<?= e(app_favicon_url()) ?>">
    <link rel="stylesheet" href="<?= e(app_url('assets/css/style.css')) ?>?v=<?= (int) filemtime(dirname(__DIR__, 2) . '/assets/css/style.css') ?>">
  </head>
  <body class="register-page">
    <main class="register-card">
      <a class="register-back" href="login.php" aria-label="Kembali ke login">&larr; Kembali ke login</a>
      <header class="register-header">
        <span class="register-eyebrow">BASRENGQYUU STORE</span>
        <h1>Buat Akun Pembeli</h1>
        <p>Isi data diri dan alamat tinggal untuk mulai berbelanja.</p>
      </header>

      <?php if ($error): ?>
        <div class="alert" role="alert"><?= e($error) ?></div>
      <?php endif; ?>

      <form method="post" class="register-form">
        <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">

        <section class="register-section" aria-labelledby="personal-heading">
          <div class="register-section-heading">
            <span class="register-step">01</span>
            <div>
              <h2 id="personal-heading">Informasi akun</h2>
              <p>Data untuk masuk ke akun Anda.</p>
            </div>
          </div>
          <div class="register-grid">
            <div class="register-field">
              <label for="name">Nama lengkap</label>
              <input id="name" name="name" type="text" autocomplete="name" maxlength="120" placeholder="Contoh: Siti Rahma" value="<?= e($formValues["name"]) ?>" required>
            </div>
            <div class="register-field">
              <label for="email">Email</label>
              <input id="email" name="email" type="email" autocomplete="email" maxlength="160" placeholder="nama@email.com" value="<?= e($formValues["email"]) ?>" required>
            </div>
            <div class="register-field register-field-wide">
              <label for="password">Password</label>
              <div class="password-input-group">
                <input id="password" name="password" type="password" autocomplete="new-password" minlength="8" placeholder="Minimal 8 karakter" required>
                <button class="password-toggle" id="password-toggle" type="button" aria-label="Tampilkan password" aria-controls="password" aria-pressed="false">
                  <svg id="password-eye" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                </button>
              </div>
              <small>Gunakan minimal 8 karakter.</small>
            </div>
          </div>
        </section>

        <section class="register-section" aria-labelledby="address-heading">
          <div class="register-section-heading">
            <span class="register-step">02</span>
            <div>
              <h2 id="address-heading">Alamat tinggal</h2>
              <p>Pilih wilayah secara berurutan dari provinsi hingga kelurahan.</p>
            </div>
          </div>
          <div class="register-grid">
            <div class="register-field">
              <label for="province">Provinsi</label>
              <select id="province" name="province" required disabled>
                <option value="">Memuat daftar provinsi...</option>
              </select>
            </div>
            <div class="register-field">
              <label for="city">Kota / Kabupaten</label>
              <select id="city" name="city" required disabled>
                <option value="">Pilih provinsi terlebih dahulu</option>
              </select>
            </div>
            <div class="register-field">
              <label for="district">Kecamatan</label>
              <select id="district" name="district" required disabled>
                <option value="">Pilih kota/kabupaten terlebih dahulu</option>
              </select>
            </div>
            <div class="register-field">
              <label for="village">Kelurahan / Desa</label>
              <select id="village" name="village" required disabled>
                <option value="">Pilih kecamatan terlebih dahulu</option>
              </select>
            </div>
            <div class="register-field register-field-wide">
              <label for="address">Alamat tinggal</label>
              <textarea id="address" name="address" rows="3" maxlength="500" autocomplete="street-address" placeholder="Nama jalan, nomor rumah, RT/RW, patokan" required><?= e($formValues["address"]) ?></textarea>
            </div>
          </div>
          <div class="region-feedback" id="region-feedback" role="status" aria-live="polite"></div>
          <button type="button" class="region-retry" id="region-retry" hidden>Muat ulang daftar wilayah</button>
        </section>

        <button class="btn full register-submit" type="submit">Buat akun</button>
      </form>
      <p class="register-login">Sudah punya akun? <a href="login.php">Masuk di sini</a></p>
    </main>

    <script>
      const passwordInput = document.getElementById("password");
      const passwordToggle = document.getElementById("password-toggle");
      const passwordEye = document.getElementById("password-eye");
      passwordToggle.addEventListener("click", () => {
        const isVisible = passwordInput.type === "password";
        passwordInput.type = isVisible ? "text" : "password";
        passwordToggle.setAttribute("aria-pressed", String(isVisible));
        passwordToggle.setAttribute("aria-label", isVisible ? "Sembunyikan password" : "Tampilkan password");
        passwordEye.innerHTML = isVisible
          ? '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line>'
          : '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle>';
      });

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

      function populateSelect(select, items, placeholder, selectedName = "") {
        select.replaceChildren(new Option(placeholder, ""));
        items.forEach((item) => {
          const option = new Option(item.name, item.name);
          option.dataset.code = item.code;
          select.add(option);
        });
        select.disabled = false;
        if (selectedName) {
          select.value = selectedName;
        }
      }

      async function loadRegions(select, url, placeholder, selectedName = "") {
        const response = await fetch(url);
        if (!response.ok) {
          throw new Error(`Daftar wilayah gagal dimuat (${response.status}).`);
        }
        const result = await response.json();
        if (!Array.isArray(result.data)) {
          throw new Error("Format daftar wilayah tidak sesuai.");
        }
        populateSelect(select, result.data, placeholder, selectedName);
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
          await loadRegions(
            provinceSelect,
            "../api/region_options.php?level=provinces",
            "Pilih provinsi",
            savedRegion.province
          );
          feedback.textContent = "";
          if (provinceSelect.value) {
            await loadCities(savedRegion.city);
          }
        } catch (error) {
          showRegionError(error);
        }
      }

      async function loadCities(selectedName = "") {
        resetSelect(citySelect, "Memuat kota/kabupaten...");
        resetSelect(districtSelect, "Pilih kota/kabupaten terlebih dahulu");
        resetSelect(villageSelect, "Pilih kecamatan terlebih dahulu");
        if (!provinceSelect.value) return;

        await loadRegions(
          citySelect,
          `../api/region_options.php?level=regencies&code=${encodeURIComponent(provinceSelect.selectedOptions[0].dataset.code)}`,
          "Pilih kota/kabupaten",
          selectedName
        );
        if (citySelect.value) {
          await loadDistricts(savedRegion.district);
        }
      }

      async function loadDistricts(selectedName = "") {
        resetSelect(districtSelect, "Memuat kecamatan...");
        resetSelect(villageSelect, "Pilih kecamatan terlebih dahulu");
        if (!citySelect.value) return;

        await loadRegions(
          districtSelect,
          `../api/region_options.php?level=districts&code=${encodeURIComponent(citySelect.selectedOptions[0].dataset.code)}`,
          "Pilih kecamatan",
          selectedName
        );
        if (districtSelect.value) {
          await loadVillages(savedRegion.village);
        }
      }

      async function loadVillages(selectedName = "") {
        resetSelect(villageSelect, "Memuat kelurahan/desa...");
        if (!districtSelect.value) return;

        await loadRegions(
          villageSelect,
          `../api/region_options.php?level=villages&code=${encodeURIComponent(districtSelect.selectedOptions[0].dataset.code)}`,
          "Pilih kelurahan/desa",
          selectedName
        );
      }

      provinceSelect.addEventListener("change", async () => {
        savedRegion.province = provinceSelect.value;
        savedRegion.city = "";
        savedRegion.district = "";
        savedRegion.village = "";
        feedback.textContent = "";
        try {
          await loadCities();
        } catch (error) {
          showRegionError(error);
        }
      });

      citySelect.addEventListener("change", async () => {
        savedRegion.city = citySelect.value;
        savedRegion.district = "";
        savedRegion.village = "";
        feedback.textContent = "";
        try {
          await loadDistricts();
        } catch (error) {
          showRegionError(error);
        }
      });

      districtSelect.addEventListener("change", async () => {
        savedRegion.district = districtSelect.value;
        savedRegion.village = "";
        feedback.textContent = "";
        try {
          await loadVillages();
        } catch (error) {
          showRegionError(error);
        }
      });

      retryButton.addEventListener("click", loadProvinces);
      loadProvinces();
    </script>
  </body>
</html>
