(function () {
    "use strict";

    function updateButtons(control) {
        var input = control.querySelector('input[type="number"]');
        var buttons = control.querySelectorAll("[data-qty-step]");
        if (!input || buttons.length !== 2) {
            return;
        }

        var value = Number(input.value || input.min || 0);
        buttons[0].disabled = value <= Number(input.min || 0);
        buttons[1].disabled = value >= Number(input.max || Infinity);
    }

    document.querySelectorAll(".qty-control").forEach(function (control) {
        var input = control.querySelector('input[type="number"]');
        if (input) {
            input.addEventListener("input", function () {
                updateButtons(control);
            });
        }
        updateButtons(control);
    });

    document.addEventListener("click", function (event) {
        var button = event.target.closest("[data-qty-step]");
        if (!button || button.disabled) {
            return;
        }

        var control = button.closest(".qty-control");
        var input = control && control.querySelector('input[type="number"]');
        if (!input) {
            return;
        }

        var min = Number(input.min || 0);
        var max = Number(input.max || Infinity);
        var step = Number(input.step || 1);
        var value = Number(input.value || min);
        input.value = String(Math.min(max, Math.max(min, value + Number(button.dataset.qtyStep) * step)));
        input.dispatchEvent(new Event("input", { bubbles: true }));
        input.dispatchEvent(new Event("change", { bubbles: true }));
    });
}());
