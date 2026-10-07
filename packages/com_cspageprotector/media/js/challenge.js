/**
 * Cybersalt Page Protector - challenge page.
 *
 * With Joomla's core Proof-of-Work captcha (an ALTCHA widget), starts the
 * proof-of-work as soon as the page loads and submits the form the moment it
 * is solved, so a real visitor just sees "Checking your browser..." for a
 * second or two and lands on the page they asked for.
 *
 * Any other captcha plugin works too: the visitor solves it and clicks Continue.
 */
(function () {
    'use strict';

    var init = function () {
        var form = document.getElementById('cspp-challenge-form');

        if (!form) {
            return;
        }

        var autoStart  = form.getAttribute('data-auto-start') === '1';
        var autoSubmit = form.getAttribute('data-auto-submit') === '1';
        var status     = form.querySelector('.cspp-challenge-status');
        var submitted  = false;

        var setStatus = function (key) {
            if (status) {
                status.textContent = status.getAttribute('data-' + key) || '';
            }
        };

        var submitOnce = function () {
            if (submitted) {
                return;
            }

            submitted = true;
            form.classList.add('cspp-submitting');
            form.submit();
        };

        var ensurePayload = function (payload) {
            if (!payload) {
                return;
            }

            var widget = form.querySelector('altcha-widget');
            var name   = (widget && widget.getAttribute('name')) || 'cspp_captcha';
            var input  = form.querySelector('input[name="' + name + '"]');

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

        form.addEventListener('submit', function () {
            form.classList.add('cspp-submitting');
        });

        if (!window.customElements || !form.querySelector('altcha-widget')) {
            return;
        }

        window.customElements.whenDefined('altcha-widget').then(function () {
            var widget = form.querySelector('altcha-widget');

            if (!widget) {
                return;
            }

            widget.addEventListener('statechange', function (event) {
                var state = event.detail && event.detail.state;

                if (state === 'verifying') {
                    setStatus('working');
                } else if (state === 'verified') {
                    setStatus('done');

                    if (autoSubmit) {
                        // ALTCHA fires "verified" before it renders its hidden
                        // payload input, so a synchronous submit posts an empty
                        // answer. Put the payload in the form ourselves if it
                        // isn't there yet, and submit on the next tick.
                        var payload = event.detail && event.detail.payload;

                        window.setTimeout(function () {
                            ensurePayload(payload);
                            submitOnce();
                        }, 0);
                    }
                }
            });

            if (autoStart) {
                setStatus('working');

                // whenDefined() resolves before the (Svelte) widget has finished
                // mounting, so verify()/getState() may not exist yet, and an
                // early verify() can be silently ignored. Keep nudging until the
                // widget actually leaves the "unverified" state, for up to ~5s.
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
            }
        });
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
