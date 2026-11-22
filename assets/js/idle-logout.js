(function () {
    "use strict";

    var timeout = 120000;
    var lastActivity = Date.now();
    var script = document.currentScript;
    var logoutUrl = script && script.dataset.logoutUrl;
    var activityUrl = script && script.dataset.activityUrl;
    var lastActivityPing = 0;
    var timer;

    if (!logoutUrl || !activityUrl) {
        return;
    }

    function logout() {
        window.location.replace(logoutUrl);
    }

    function logoutIfIdle() {
        var remaining = timeout - (Date.now() - lastActivity);
        if (remaining <= 0) {
            logout();
            return;
        }
        window.clearTimeout(timer);
        timer = window.setTimeout(logoutIfIdle, remaining);
    }

    function recordActivity() {
        var now = Date.now();
        if (now - lastActivity >= timeout) {
            logout();
            return;
        }
        lastActivity = now;
        window.clearTimeout(timer);
        timer = window.setTimeout(logoutIfIdle, timeout);

        if (now - lastActivityPing >= 60000) {
            lastActivityPing = now;
            fetch(activityUrl, {
                method: "POST",
                credentials: "same-origin",
                cache: "no-store"
            }).then(function (response) {
                if (!response.ok) {
                    logout();
                }
            }).catch(logout);
        }
    }

    ["mousemove", "mousedown", "keydown", "touchstart", "scroll", "pointerdown"].forEach(function (eventName) {
        document.addEventListener(eventName, recordActivity, { passive: true });
    });

    document.addEventListener("visibilitychange", function () {
        if (!document.hidden) {
            logoutIfIdle();
        }
    });

    timer = window.setTimeout(logoutIfIdle, timeout);
}());
