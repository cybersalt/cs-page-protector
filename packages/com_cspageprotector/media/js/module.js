/**
 * Cybersalt Page Protector - inline check for protected modules.
 *
 * Clicking "Show content" in a protected module's placeholder puts the
 * Proof-of-Work widget into that box, solves it, and posts the answer. The
 * server issues the pass and sends the visitor back to the same page, which
 * reloads with the module (and every other protected module) shown.
 *
 * The widget markup sits once per page in an inert <template>, so only one
 * captcha ever exists on the page, and nothing runs until a visitor clicks.
 * Without JavaScript, or for captchas that can't run inline, the button is a
 * normal link to the check on its own page.
 */
(function () {
    'use strict';

    var started = false;

    var setStatus = function (box, key) {
        var status = box.querySelector('.cspp-module-locked-status');

        if (status) {
            status.textContent = status.getAttribute('data-' + key) || '';
        }
    };

    // ALTCHA fires "verified" before it renders its hidden answer field, so
    // put the answer into the form ourselves before submitting.
    var ensurePayload = function (form, widget, payload) {
        if (!payload) {
            return;
        }

        var name  = widget.getAttribute('name') || 'cspp_captcha';
        var input = form.querySelector('input[name="' + name + '"]');

        if (!input) {
            input      = document.createElement('input');
            input.type = 'hidden';
            input.name = name;
            form.appendChild(input);
        }

        if (!input.value) {
            input.value = payload;
        }
    };

    var startInline = function (box, button) {
        var template = document.querySelector('template.cspp-captcha-template');
        var form     = box.querySelector('.cspp-module-locked-form');
        var slot     = box.querySelector('.cspp-module-locked-captcha');

        if (!template || !form || !slot || !window.customElements) {
            return false;
        }

        started = true;
        slot.appendChild(template.content.cloneNode(true));
        // The widget shows its own "Verifying..." state while it solves.
        button.hidden = true;

        // One check unlocks every protected module, so the other buttons wait.
        Array.prototype.forEach.call(document.querySelectorAll('.cspp-module-locked-button'), function (other) {
            if (other !== button) {
                other.classList.add('disabled');
                other.setAttribute('aria-disabled', 'true');
            }
        });

        var widget = slot.querySelector('altcha-widget');

        if (!widget) {
            return false;
        }

        window.customElements.whenDefined('altcha-widget').then(function () {
            var submitted = false;

            widget.addEventListener('statechange', function (event) {
                var state = event.detail && event.detail.state;

                if (state === 'verified' && !submitted) {
                    submitted = true;
                    setStatus(box, 'done');

                    var payload = event.detail && event.detail.payload;

                    window.setTimeout(function () {
                        ensurePayload(form, widget, payload);
                        form.submit();
                    }, 0);
                } else if (state === 'error') {
                    setStatus(box, 'error');
                }
            });

            // The widget's methods only exist once it has mounted; keep
            // nudging until it actually starts solving (up to ~5 seconds).
            var attempts = 0;

            var kick = function () {
                var ready = typeof widget.verify === 'function' && typeof widget.getState === 'function';
                var state = ready ? widget.getState() : '';

                if (state && state !== 'unverified') {
                    return;
                }

                if (ready) {
                    try {
                        widget.verify();
                    } catch (e) {
                        // Not ready yet; try again below.
                    }
                }

                if (++attempts < 20) {
                    window.setTimeout(kick, 250);
                }
            };

            kick();
        });

        return true;
    };

    document.addEventListener('click', function (event) {
        var button = event.target.closest('.cspp-module-locked-button');

        if (!button) {
            return;
        }

        if (button.classList.contains('disabled') || started) {
            event.preventDefault();

            return;
        }

        var box = button.closest('.cspp-module-locked');

        if (!box || box.getAttribute('data-cspp-inline') !== '1') {
            // Not inline-capable: follow the link to the check page.
            return;
        }

        if (startInline(box, button)) {
            event.preventDefault();
        }
    });
})();
