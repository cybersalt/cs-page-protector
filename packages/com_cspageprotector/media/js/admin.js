/**
 * Cybersalt Page Protector - admin helpers.
 * Click-to-copy for IPs and support details (Cybersalt UX convention #1).
 */
(function () {
    'use strict';

    var announce = function (text) {
        var live = document.getElementById('cspp-live');

        if (live) {
            live.textContent = '';
            window.setTimeout(function () { live.textContent = text; }, 50);
        }
    };

    var fallbackCopy = function (value) {
        var area = document.createElement('textarea');
        area.value = value;
        area.setAttribute('readonly', '');
        area.style.position = 'fixed';
        area.style.opacity = '0';
        document.body.appendChild(area);
        area.select();

        try {
            document.execCommand('copy');
        } finally {
            document.body.removeChild(area);
        }

        return Promise.resolve();
    };

    // Event log: show / hide the full-width details row under an entry.
    document.addEventListener('click', function (event) {
        var toggle = event.target.closest('.cspp-detail-toggle');

        if (!toggle) {
            return;
        }

        var row = document.getElementById(toggle.getAttribute('aria-controls'));

        if (row) {
            var open = toggle.getAttribute('aria-expanded') === 'true';
            toggle.setAttribute('aria-expanded', open ? 'false' : 'true');
            row.hidden = open;
        }
    });

    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-cspp-copy]');

        if (!button) {
            return;
        }

        event.preventDefault();

        var value = button.getAttribute('data-cspp-copy') || '';
        var copy  = navigator.clipboard && window.isSecureContext
            ? navigator.clipboard.writeText(value)
            : fallbackCopy(value);

        var copiedText = (window.Joomla && Joomla.Text) ? Joomla.Text._('COM_CSPAGEPROTECTOR_COPIED', 'Copied') : 'Copied';

        copy.then(function () {
            button.classList.add('cspp-copied');
            announce(copiedText + ': ' + value);
            window.setTimeout(function () { button.classList.remove('cspp-copied'); }, 1800);
        }).catch(function () {
            // Clipboard refused (permissions); the value is still visible to select by hand.
        });
    });
})();
