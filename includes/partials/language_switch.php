<?php
$language_switch_id = "language-" . bin2hex(random_bytes(4));
?>
<script>
    window.APP_LANGUAGE = <?= json_encode(app_language(), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
    document.documentElement.lang = window.APP_LANGUAGE;
    if (!window.APP_I18N_LOADED) {
        window.APP_I18N_LOADED = true;
        var i18nScript = document.createElement("script");
        i18nScript.src = <?= json_encode(app_url("assets/js/i18n.js"), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
        i18nScript.defer = true;
        document.head.appendChild(i18nScript);
    }
</script>
<div class="language-switch" aria-label="<?= e(app_language() === "id" ? "Pilih bahasa" : "Choose language") ?>">
    <form action="<?= e(app_url("pages/api/set_language.php")) ?>" method="post">
        <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
        <input type="hidden" name="return_url" value="<?= e($_SERVER["REQUEST_URI"] ?? app_url("index.php")) ?>">
        <label for="<?= e($language_switch_id) ?>"><?= e(app_language() === "id" ? "Bahasa" : "Language") ?></label>
        <select id="<?= e($language_switch_id) ?>" name="lang" onchange="this.form.submit()" aria-label="<?= e(app_language() === "id" ? "Pilih bahasa" : "Choose language") ?>">
            <option value="id" <?= app_language() === "id" ? "selected" : "" ?>>ID</option>
            <option value="en" <?= app_language() === "en" ? "selected" : "" ?>>EN</option>
        </select>
    </form>
</div>
