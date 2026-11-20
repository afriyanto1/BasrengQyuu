<div class="login-language-picker">
    <script>
        window.APP_LANGUAGE = <?= json_encode(app_language(), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
        document.documentElement.lang = window.APP_LANGUAGE;
        if (!window.APP_I18N_LOADED) {
            window.APP_I18N_LOADED = true;
            var i18nScript = document.createElement("script");
            i18nScript.src = <?= json_encode(app_url("assets/js/i18n.js"), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
            document.head.appendChild(i18nScript);
        }
    </script>
    <form action="<?= e(app_url("pages/api/set_language.php")) ?>" method="post">
        <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
        <input type="hidden" name="return_url" value="<?= e($_SERVER["REQUEST_URI"] ?? app_url("pages/auth/login.php")) ?>">
        <label><?= e(app_language() === "id" ? "Bahasa" : "Language") ?></label>
        <details class="login-language-menu">
            <summary>
                <?php if (app_language() === "en"): ?>
                    <svg class="language-flag" aria-hidden="true" viewBox="0 0 24 16">
                        <rect width="24" height="16" fill="#21468b"/>
                        <path d="M0 0 24 16M24 0 0 16" stroke="#fff" stroke-width="4"/>
                        <path d="M0 0 24 16M24 0 0 16" stroke="#c8102e" stroke-width="1.5"/>
                        <path d="M12 0v16M0 8h24" stroke="#fff" stroke-width="6"/>
                        <path d="M12 0v16M0 8h24" stroke="#c8102e" stroke-width="3"/>
                    </svg> EN
                <?php else: ?>
                    <svg class="language-flag" aria-hidden="true" viewBox="0 0 24 16">
                        <rect width="24" height="8" fill="#e70011"/>
                        <rect y="8" width="24" height="8" fill="#fff"/>
                    </svg> ID
                <?php endif; ?>
            </summary>
            <div class="login-language-options">
                <button type="submit" name="lang" value="en">
                    <svg class="language-flag" aria-hidden="true" viewBox="0 0 24 16">
                        <rect width="24" height="16" fill="#21468b"/>
                        <path d="M0 0 24 16M24 0 0 16" stroke="#fff" stroke-width="4"/>
                        <path d="M0 0 24 16M24 0 0 16" stroke="#c8102e" stroke-width="1.5"/>
                        <path d="M12 0v16M0 8h24" stroke="#fff" stroke-width="6"/>
                        <path d="M12 0v16M0 8h24" stroke="#c8102e" stroke-width="3"/>
                    </svg> EN
                </button>
                <button type="submit" name="lang" value="id">
                    <svg class="language-flag" aria-hidden="true" viewBox="0 0 24 16">
                        <rect width="24" height="8" fill="#e70011"/>
                        <rect y="8" width="24" height="8" fill="#fff"/>
                    </svg> ID
                </button>
            </div>
        </details>
    </form>
</div>
