<?php
require dirname(__DIR__, 2) . "/includes/config.php";
require dirname(__DIR__, 2) . "/includes/email.php";
$error = "";
$showResendActivation = false;
$activeStep = 1;
$formValues = [
    "name" => "",
    "username" => "",
    "email" => "",
    "phone" => "",
    "country_code" => "ID",
    "province" => "",
    "city" => "",
    "district" => "",
    "village" => "",
    "address" => "",
    "date_of_birth" => "",
    "gender" => "",
];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    check_csrf();
    foreach ($formValues as $field => $value) {
        $postedValue = $_POST[$field] ?? "";
        $formValues[$field] = is_string($postedValue) ? trim($postedValue) : "";
    }
    $pass = is_string($_POST["password"] ?? null) ? $_POST["password"] : "";
    $passConfirm = is_string($_POST["password_confirmation"] ?? null)
        ? $_POST["password_confirmation"]
        : "";
    $formValues["username"] = strtolower($formValues["username"]);
    $birthDate = DateTimeImmutable::createFromFormat("!Y-m-d", $formValues["date_of_birth"]);
    $birthDateErrors = DateTimeImmutable::getLastErrors();
    $validBirthDate = $birthDate !== false &&
        $birthDate->format("Y-m-d") === $formValues["date_of_birth"] &&
        ($birthDateErrors === false ||
            ($birthDateErrors["warning_count"] === 0 && $birthDateErrors["error_count"] === 0)) &&
        $birthDate <= new DateTimeImmutable("today");
    $validGender = in_array(
        $formValues["gender"],
        ["", "male", "female", "prefer_not_to_say"],
        true
    );

    if (
        $formValues["name"] === "" ||
        !filter_var($formValues["email"], FILTER_VALIDATE_EMAIL) ||
        strlen($formValues["phone"]) > 30 ||
        ($formValues["phone"] !== "" &&
            preg_match('/\A[0-9+().\-\s]+\z/u', $formValues["phone"]) !== 1)
    ) {
        $error = "Periksa nama, email, dan nomor telepon. Nomor telepon bersifat opsional.";
        $activeStep = 1;
    } elseif (
        !array_key_exists($formValues["country_code"], shipping_country_options()) ||
        $formValues["province"] === "" ||
        $formValues["city"] === "" ||
        $formValues["district"] === "" ||
        $formValues["village"] === "" ||
        $formValues["address"] === ""
    ) {
        $error = "Lengkapi alamat tinggal Anda.";
        $activeStep = 2;
    } elseif (!$validBirthDate || !$validGender) {
        $error = "Periksa kembali tanggal lahir dan pilihan gender Anda.";
        $activeStep = 3;
    } elseif (preg_match('/\A[a-z0-9_]{3,20}\z/', $formValues["username"]) !== 1) {
        $error = "Username harus berisi 3-20 huruf, angka, atau garis bawah.";
        $activeStep = 4;
    } elseif (strlen($pass) < 8) {
        $error = "Password harus memiliki minimal 8 karakter.";
        $activeStep = 5;
    } elseif ($pass !== $passConfirm) {
        $error = "Konfirmasi password tidak cocok.";
        $activeStep = 5;
    } else {
        try {
            $emailCheck = $pdo->prepare("SELECT 1 FROM users WHERE email = ? LIMIT 1");
            $emailCheck->execute([$formValues["email"]]);
            $usernameCheck = $pdo->prepare("SELECT 1 FROM users WHERE username = ? LIMIT 1");
            $usernameCheck->execute([$formValues["username"]]);
            if ($emailCheck->fetchColumn()) {
                $error = "Email sudah terdaftar. Silakan gunakan email lain.";
                $activeStep = 1;
            } elseif ($usernameCheck->fetchColumn()) {
                $error = "Username sudah digunakan. Silakan pilih username lain.";
                $activeStep = 4;
            } else {
                $token = bin2hex(random_bytes(32));
                $activationUrl = app_email_absolute_url(
                    "pages/auth/activate.php?token=" . urlencode($token)
                );
                if ($activationUrl === null) {
                    $error = "Tautan aktivasi tidak dapat dibuat. Silakan coba lagi.";
                } else {
                    $pdo->beginTransaction();
                    try {
                        $insertUser = $pdo->prepare(
                            "INSERT INTO users
                                (name, username, email, phone, date_of_birth, gender, email_verified_at,
                                 password, role, country_code, province, city, district, village, address)
                             VALUES (?, ?, ?, ?, ?, ?, NULL, ?, 'buyer', ?, ?, ?, ?, ?, ?)"
                        );
                        $insertUser->execute([
                            $formValues["name"],
                            $formValues["username"],
                            $formValues["email"],
                            $formValues["phone"] !== "" ? $formValues["phone"] : null,
                            $formValues["date_of_birth"],
                            $formValues["gender"] !== "" ? $formValues["gender"] : null,
                            password_hash($pass, PASSWORD_DEFAULT),
                            $formValues["country_code"],
                            $formValues["province"],
                            $formValues["city"],
                            $formValues["district"],
                            $formValues["village"],
                            $formValues["address"],
                        ]);
                        $userId = (int) $pdo->lastInsertId();
                        $insertToken = $pdo->prepare(
                            "INSERT INTO email_verification_tokens (user_id, token_hash, expires_at)
                             VALUES (?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 24 HOUR))"
                        );
                        $insertToken->execute([$userId, hash("sha256", $token)]);
                        $pdo->commit();
                    } catch (Throwable $e) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        throw $e;
                    }

                    if (send_account_activation_email(
                        $formValues["email"],
                        $formValues["name"],
                        $activationUrl
                    )) {
                        flash(
                            "Pendaftaran berhasil. Periksa email untuk mengaktifkan akun sebelum login.",
                            "success"
                        );
                        header("Location: login.php");
                        exit();
                    }

                    $error = "Akun dibuat, tetapi email aktivasi gagal dikirim. Silakan minta tautan aktivasi baru.";
                    $showResendActivation = true;
                }
            }
        } catch (PDOException $e) {
            if ($e->getCode() === "23000") {
                $error = "Email atau username sudah digunakan. Periksa kembali data akun Anda.";
                $activeStep = 4;
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
<?php include dirname(__DIR__, 2) . "/includes/partials/language_switch.php"; ?>
    <main class="register-card">
      <div class="register-topbar">
        <a class="register-back" href="login.php" aria-label="Kembali ke login">&larr; Kembali ke login</a>
        <span class="brand-name register-brand">
          <span class="brand-basreng">Basreng</span>
          <span class="brand-qyuu">Qyuu&nbsp;</span>
          <span class="brand-store">Store</span>
        </span>
      </div>

      <?php if ($error): ?>
        <div class="alert" role="alert"><?= e($error) ?></div>
        <?php if ($showResendActivation): ?>
          <p class="register-activation-resend">
            <a href="resend_activation.php">Kirim ulang tautan aktivasi</a>
          </p>
        <?php endif; ?>
      <?php endif; ?>

      <form method="post" class="register-form" id="register-form">
        <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">

        <ol class="register-progress" aria-label="Tahapan pendaftaran">
          <li data-progress-step="1"><span>1</span><strong>Akun</strong></li>
          <li data-progress-step="2"><span>2</span><strong>Alamat</strong></li>
          <li data-progress-step="3"><span>3</span><strong>Tanggal lahir</strong></li>
          <li data-progress-step="4"><span>4</span><strong>Username</strong></li>
          <li data-progress-step="5"><span>5</span><strong>Password</strong></li>
        </ol>

        <div class="register-wizard" id="register-wizard" data-step="<?= $activeStep ?>">
          <section class="register-intro" aria-live="polite">
            <span class="register-eyebrow" id="step-kicker">LANGKAH 01 DARI 05</span>
            <h1 id="step-title">Informasi akun</h1>
            <p id="step-description" hidden></p>
          </section>

          <div class="register-content">
              <section class="register-panel" data-panel="1" aria-label="Informasi akun">
              <div class="register-grid">
                <div class="register-field">
                  <label for="name">Nama lengkap</label>
                  <input id="name" name="name" type="text" autocomplete="name" maxlength="120" placeholder="Contoh: Siti Rahma" value="<?= e($formValues["name"]) ?>" required>
                </div>
                <div class="register-field">
                  <label for="email">Email</label>
                  <input id="email" name="email" type="email" autocomplete="email" maxlength="160" placeholder="nama@email.com" value="<?= e($formValues["email"]) ?>" required>
                  <div class="register-email-feedback" id="email-feedback" role="status" aria-live="polite"></div>
                </div>
                <div class="register-field">
                  <label for="phone">Nomor telepon <span class="register-optional">(opsional)</span></label>
                  <input id="phone" name="phone" type="tel" autocomplete="tel" maxlength="30" placeholder="Contoh: 081234567890" value="<?= e($formValues["phone"]) ?>">
                </div>
              </div>
            </section>

            <section class="register-panel" data-panel="2" aria-label="Alamat pengiriman">
              <div class="register-grid register-grid-address">
                <div class="register-field register-field-wide">
                  <label for="country_code">Negara tujuan</label>
                  <select id="country_code" name="country_code" required>
                    <?php foreach (shipping_country_options() as $code => $countryName): ?>
                      <option value="<?= e($code) ?>" <?= $formValues["country_code"] === $code ? "selected" : "" ?>><?= e($countryName) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div id="domestic-address-fields" class="register-grid register-grid-address register-field-wide">
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
                </div>
                <div id="international-address-fields" class="register-grid register-grid-address register-field-wide" hidden>
                  <div class="register-field">
                    <label for="foreign-province">Provinsi / negara bagian</label>
                    <input id="foreign-province" name="province" type="text" maxlength="120" disabled>
                  </div>
                  <div class="register-field">
                    <label for="foreign-city">Kota</label>
                    <input id="foreign-city" name="city" type="text" maxlength="120" disabled>
                  </div>
                  <div class="register-field">
                    <label for="foreign-district">Distrik / wilayah</label>
                    <input id="foreign-district" name="district" type="text" maxlength="120" disabled>
                  </div>
                  <div class="register-field">
                    <label for="foreign-village">Kelurahan / kawasan</label>
                    <input id="foreign-village" name="village" type="text" maxlength="120" disabled>
                  </div>
                </div>
                <div class="register-field register-field-wide">
                  <label for="address">Alamat lengkap</label>
                  <textarea id="address" name="address" rows="3" maxlength="500" autocomplete="street-address" placeholder="Nama jalan, nomor rumah, RT/RW, patokan" required><?= e($formValues["address"]) ?></textarea>
                </div>
              </div>
              <div class="region-feedback" id="region-feedback" role="status" aria-live="polite"></div>
              <button type="button" class="region-retry" id="region-retry" hidden>Muat ulang daftar wilayah</button>
            </section>

            <section class="register-panel" data-panel="3" aria-label="Tanggal lahir">
              <div class="register-grid">
                <div class="register-field">
                  <label for="date-of-birth">Tanggal lahir</label>
                  <input
                    id="date-of-birth"
                    name="date_of_birth"
                    type="date"
                    autocomplete="bday"
                    max="<?= e(date("Y-m-d")) ?>"
                    value="<?= e($formValues["date_of_birth"]) ?>"
                    required
                  >
                </div>
                <div class="register-field">
                  <label for="gender">Gender <span class="register-optional">(opsional)</span></label>
                  <select id="gender" name="gender">
                    <option value="">Pilih gender</option>
                    <option value="female" <?= $formValues["gender"] === "female" ? "selected" : "" ?>>Perempuan</option>
                    <option value="male" <?= $formValues["gender"] === "male" ? "selected" : "" ?>>Laki-laki</option>
                    <option value="prefer_not_to_say" <?= $formValues["gender"] === "prefer_not_to_say" ? "selected" : "" ?>>Memilih tidak menyebutkan</option>
                  </select>
                </div>
              </div>
            </section>

            <section class="register-panel" data-panel="4" aria-label="Pilih username">
              <p class="register-username-help">Pilih salah satu username yang tersedia atau buat username sendiri.</p>
              <div class="register-username-options" id="username-options" role="radiogroup" aria-label="Pilihan username">
                <p>Menyiapkan pilihan username...</p>
              </div>
              <div class="register-grid">
                <div class="register-field">
                  <label for="username">Username</label>
                  <input
                    id="username"
                    name="username"
                    type="text"
                    autocomplete="username"
                    minlength="3"
                    maxlength="20"
                    pattern="[A-Za-z0-9_]{3,20}"
                    placeholder="Pilih atau masukkan username"
                    value="<?= e($formValues["username"]) ?>"
                    required
                  >
                  <small>Gunakan 3-20 huruf, angka, atau garis bawah (_).</small>
                  <div class="register-username-feedback" id="username-feedback" role="status" aria-live="polite"></div>
                </div>
              </div>
            </section>

            <section class="register-panel" data-panel="5" aria-label="Buat password yang kuat">
              <div class="register-grid">
                <div class="register-field">
                  <label for="password">Password</label>
                  <input id="password" name="password" type="password" autocomplete="new-password" minlength="8" placeholder="Minimal 8 karakter" required>
                </div>
                <div class="register-field">
                  <label for="password-confirmation">Konfirmasi password</label>
                  <input id="password-confirmation" name="password_confirmation" type="password" autocomplete="new-password" minlength="8" placeholder="Masukkan ulang password" required>
                </div>
                <label class="register-show-password">
                  <input id="show-password" type="checkbox">
                  <span>Tampilkan password</span>
                </label>
              </div>
            </section>

            <div class="register-actions">
              <button type="button" class="register-previous" id="register-previous" hidden>Kembali</button>
              <button type="button" class="btn register-next" id="register-next" hidden>Selanjutnya</button>
              <button class="btn register-submit" id="register-submit" type="submit">Buat akun</button>
            </div>
          </div>
        </div>
      </form>
      <p class="register-login">Sudah punya akun? <a href="login.php">Masuk di sini</a></p>
    </main>

    <script>
      const registerForm = document.getElementById("register-form");
      const wizard = document.getElementById("register-wizard");
      const panels = Array.from(document.querySelectorAll("[data-panel]"));
      const progressSteps = Array.from(document.querySelectorAll("[data-progress-step]"));
      const previousButton = document.getElementById("register-previous");
      const nextButton = document.getElementById("register-next");
      const submitButton = document.getElementById("register-submit");
      const stepTitle = document.getElementById("step-title");
      const stepDescription = document.getElementById("step-description");
      const stepKicker = document.getElementById("step-kicker");
      const emailInput = document.getElementById("email");
      const emailFeedback = document.getElementById("email-feedback");
      const nameInput = document.getElementById("name");
      const usernameInput = document.getElementById("username");
      const usernameOptions = document.getElementById("username-options");
      const usernameFeedback = document.getElementById("username-feedback");
      usernameInput.readOnly = true;
      let usernameSuggestionsLoaded = false;
      const stepContent = [
        {
          title: "Informasi akun",
          description: "",
        },
        {
          title: "Alamat pengiriman",
          description: "",
        },
        {
          title: "Tanggal lahir",
          description: "",
        },
        {
          title: "Pilih username",
          description: "",
        },
        {
          title: "Buat password yang kuat",
          description: "",
        },
      ];
      let activeStep = Number(wizard.dataset.step) || 1;
      let customUsername = "";

      function showStep(step, moveFocus = false) {
        activeStep = step;
        wizard.dataset.step = String(step);
        panels.forEach((panel) => {
          panel.hidden = Number(panel.dataset.panel) !== step;
        });
        progressSteps.forEach((progressStep) => {
          const number = Number(progressStep.dataset.progressStep);
          progressStep.classList.toggle("is-active", number === step);
          progressStep.classList.toggle("is-complete", number < step);
          if (number === step) {
            progressStep.setAttribute("aria-current", "step");
          } else {
            progressStep.removeAttribute("aria-current");
          }
        });
        stepKicker.textContent = `LANGKAH ${String(step).padStart(2, "0")} DARI 05`;
        stepTitle.textContent = stepContent[step - 1].title;
        stepDescription.textContent = stepContent[step - 1].description;
        stepDescription.hidden = !stepContent[step - 1].description;
        previousButton.hidden = step === 1;
        nextButton.hidden = step === 5;
        submitButton.hidden = step !== 5;
        if (moveFocus) {
          stepTitle.focus();
        }
      }

      wizard.classList.add("is-enhanced");
      showStep(activeStep);
      nextButton.addEventListener("click", async () => {
        const step = activeStep;
        const originalLabel = nextButton.textContent;
        nextButton.disabled = true;
        nextButton.textContent = "Memeriksa...";
        try {
          if (await validateStep(step)) {
            showStep(step + 1, true);
          }
        } finally {
          nextButton.disabled = false;
          nextButton.textContent = originalLabel;
        }
      });
      previousButton.addEventListener("click", () => {
        const previousStep = activeStep - 1;
        showStep(previousStep, true);
        if (previousStep === 4 && !usernameSuggestionsLoaded) {
          loadUsernameSuggestions();
        }
      });
      registerForm.addEventListener("keydown", (event) => {
        if (
          event.key === "Enter" &&
          event.target.tagName !== "TEXTAREA" &&
          activeStep < 5
        ) {
          event.preventDefault();
          nextButton.click();
        }
      });

      const passwordInput = document.getElementById("password");
      const passwordConfirmation = document.getElementById("password-confirmation");
      const showPassword = document.getElementById("show-password");
      function validatePasswordConfirmation() {
        passwordConfirmation.setCustomValidity(
          passwordConfirmation.value && passwordConfirmation.value !== passwordInput.value
            ? "Konfirmasi password tidak cocok."
            : ""
        );
      }
      passwordInput.addEventListener("input", validatePasswordConfirmation);
      passwordConfirmation.addEventListener("input", validatePasswordConfirmation);
      showPassword.addEventListener("change", () => {
        const type = showPassword.checked ? "text" : "password";
        passwordInput.type = type;
        passwordConfirmation.type = type;
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
        domesticControls.forEach((control) => {
          control.disabled = !isDomestic;
          control.required = isDomestic;
        });
        internationalControls.forEach((control) => {
          control.disabled = isDomestic;
          control.required = !isDomestic;
          if (!isDomestic && !control.value) {
            control.value = foreignValues[control.name] || "";
          }
        });
        feedback.hidden = !isDomestic;
        retryButton.hidden = !isDomestic || retryButton.hidden;
        if (isDomestic && !regionsLoaded && !regionsLoading) loadProvinces();
      }
      emailInput.addEventListener("input", () => {
        emailInput.setCustomValidity("");
        emailFeedback.textContent = "";
        emailFeedback.dataset.state = "";
      });
      function renderUsernameOptions(suggestions) {
        const existingUsername = usernameInput.value.toLowerCase();
        customUsername = existingUsername && !suggestions.includes(existingUsername)
          ? existingUsername
          : "";
        usernameOptions.replaceChildren();

        function addChoice(value, labelText, description, selected) {
          const label = document.createElement("label");
          label.className = "register-username-choice";
          const radio = document.createElement("input");
          radio.type = "radio";
          radio.name = "username-choice";
          radio.value = value;
          radio.checked = selected;
          const text = document.createElement("span");
          text.className = "register-username-choice-text";
          const title = document.createElement("strong");
          title.textContent = labelText;
          const detail = document.createElement("small");
          detail.textContent = description;
          text.append(title, detail);
          label.append(radio, text);
          usernameOptions.append(label);
          radio.addEventListener("change", () => {
            if (value === "custom") {
              usernameInput.readOnly = false;
              usernameInput.value = customUsername;
              usernameInput.focus();
            } else {
              usernameInput.readOnly = true;
              usernameInput.value = value;
            }
            usernameInput.setCustomValidity("");
            usernameFeedback.textContent = "";
            usernameFeedback.dataset.state = "";
          });
        }

        suggestions.forEach((suggestion, index) => {
          const selected = existingUsername
            ? existingUsername === suggestion
            : index === 0;
          addChoice(
            suggestion,
            suggestion,
            "Username tersedia",
            selected
          );
          if (selected) {
            usernameInput.value = suggestion;
            usernameInput.readOnly = true;
          }
        });

        const customSelected = Boolean(customUsername) || suggestions.length === 0;
        addChoice(
          "custom",
          "Buat username sendiri",
          "Tentukan username pilihan Anda",
          customSelected
        );
        if (customSelected) {
          usernameInput.value = customUsername;
          usernameInput.readOnly = false;
        } else if (suggestions.length === 0) {
          usernameInput.value = "";
          usernameInput.readOnly = false;
        }
      }

      async function loadUsernameSuggestions() {
        usernameFeedback.textContent = "Memuat pilihan username...";
        usernameFeedback.dataset.state = "checking";
        try {
          const response = await fetch("../api/suggest_usernames.php", {
            method: "POST",
            headers: {
              "Accept": "application/json",
              "Content-Type": "application/x-www-form-urlencoded;charset=UTF-8",
            },
            body: new URLSearchParams({
              name: nameInput.value.trim(),
              csrf: registerForm.querySelector('input[name="csrf"]').value,
            }),
          });
          const result = await response.json();
          if (
            !response.ok ||
            !Array.isArray(result.suggestions) ||
            result.suggestions.some((suggestion) => typeof suggestion !== "string")
          ) {
            throw new Error(result.error || "Pilihan username belum dapat dimuat.");
          }
          renderUsernameOptions(result.suggestions);
          usernameSuggestionsLoaded = true;
          usernameFeedback.textContent = "";
          usernameFeedback.dataset.state = "";
        } catch (error) {
          renderUsernameOptions([]);
          usernameSuggestionsLoaded = true;
          usernameFeedback.textContent = "Saran username tidak dapat dimuat. Anda masih dapat membuat username sendiri.";
          usernameFeedback.dataset.state = "error";
        }
        return true;
      }

      async function validateUsernameAvailability() {
        usernameInput.value = usernameInput.value.trim().toLowerCase();
        usernameFeedback.textContent = "Memeriksa ketersediaan username...";
        usernameFeedback.dataset.state = "checking";
        try {
          const response = await fetch("../api/check_username.php", {
            method: "POST",
            headers: {
              "Accept": "application/json",
              "Content-Type": "application/x-www-form-urlencoded;charset=UTF-8",
            },
            body: new URLSearchParams({
              username: usernameInput.value,
              csrf: registerForm.querySelector('input[name="csrf"]').value,
            }),
          });
          const result = await response.json();
          if (!response.ok || typeof result.available !== "boolean") {
            throw new Error(result.error || "Username belum dapat diperiksa.");
          }
          if (!result.available) {
            const message = "Username sudah digunakan. Silakan pilih username lain.";
            usernameInput.setCustomValidity(message);
            usernameInput.reportValidity();
            usernameFeedback.textContent = message;
            usernameFeedback.dataset.state = "error";
            return false;
          }
          usernameInput.setCustomValidity("");
          usernameFeedback.textContent = "Username tersedia.";
          usernameFeedback.dataset.state = "available";
          return true;
        } catch (error) {
          usernameFeedback.textContent = "Username belum dapat diperiksa. Silakan coba lagi.";
          usernameFeedback.dataset.state = "error";
          return false;
        }
      }

      usernameInput.addEventListener("input", () => {
        customUsername = usernameInput.value;
        usernameInput.setCustomValidity("");
        usernameFeedback.textContent = "";
        usernameFeedback.dataset.state = "";
      });

      function validatePanel(step) {
        const panel = document.querySelector(`[data-panel="${step}"]`);
        const invalidField = panel.querySelector(":invalid");
        if (invalidField) {
          invalidField.reportValidity();
          return false;
        }

        const incompleteDomesticRegion = countrySelect.value === "ID" &&
          [provinceSelect, citySelect, districtSelect, villageSelect].some(
            (select) => select.disabled || !select.value
          );
        if (step === 2 && incompleteDomesticRegion) {
          feedback.textContent = "Pilih provinsi, kota/kabupaten, kecamatan, dan kelurahan/desa secara berurutan.";
          const firstIncomplete = [provinceSelect, citySelect, districtSelect, villageSelect]
            .find((select) => select.disabled || !select.value);
          if (firstIncomplete && !firstIncomplete.disabled) {
            firstIncomplete.focus();
          }
          return false;
        }

        return true;
      }

      async function validateStep(step) {
        if (!validatePanel(step)) {
          return false;
        }

        if (step === 1) {
          emailFeedback.textContent = "Memeriksa email...";
          emailFeedback.dataset.state = "checking";
          try {
            const response = await fetch("../api/check_email.php", {
              method: "POST",
              headers: {
                "Accept": "application/json",
                "Content-Type": "application/x-www-form-urlencoded;charset=UTF-8",
              },
              body: new URLSearchParams({
                email: emailInput.value.trim(),
                csrf: registerForm.querySelector('input[name="csrf"]').value,
              }),
            });
            const result = await response.json();
            if (!response.ok || typeof result.available !== "boolean") {
              throw new Error(result.error || "Email belum dapat diperiksa.");
            }
            if (!result.available) {
              const message = "Email sudah terdaftar. Silakan gunakan email lain.";
              emailInput.setCustomValidity(message);
              emailInput.reportValidity();
              emailFeedback.textContent = message;
              emailFeedback.dataset.state = "error";
              return false;
            }
            emailInput.setCustomValidity("");
            emailFeedback.textContent = "";
            emailFeedback.dataset.state = "";
          } catch (error) {
            emailFeedback.textContent = "Email belum dapat diperiksa. Silakan coba lagi.";
            emailFeedback.dataset.state = "error";
            return false;
          }
        }

        if (step === 3) {
          return loadUsernameSuggestions();
        }

        if (step === 4) {
          return validateUsernameAvailability();
        }

        return true;
      }
      registerForm.addEventListener("submit", (event) => {
        validatePasswordConfirmation();
        if (activeStep !== 5) {
          event.preventDefault();
          nextButton.click();
        } else if (!validatePanel(5)) {
          event.preventDefault();
        }
      });
      if (activeStep === 4) {
        loadUsernameSuggestions();
      }

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
        regionsLoading = true;
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
          regionsLoaded = true;
          feedback.textContent = "";
          if (provinceSelect.value) {
            await loadCities(savedRegion.city);
          }
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
      countrySelect.addEventListener("change", toggleAddressFields);
      toggleAddressFields();
    </script>
  </body>
</html>
