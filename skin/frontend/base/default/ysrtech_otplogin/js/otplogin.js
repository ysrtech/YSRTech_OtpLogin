/**
 * YSRTech OtpLogin - frontend behaviour.
 *
 * Framework-agnostic (no jQuery / prototype dependency). Handles the popup
 * modals, tab switching and the AJAX email-OTP flow.
 */
(function () {
    'use strict';

    function $(sel, ctx) { return (ctx || document).querySelector(sel); }
    function $all(sel, ctx) { return Array.prototype.slice.call((ctx || document).querySelectorAll(sel)); }

    function modalById(id) { return document.getElementById(id); }

    function closeAll() {
        $all('.ysrtech-otp-modal').forEach(function (m) { m.style.display = 'none'; });
        document.body.classList.remove('ysrtech-otp-open');
    }

    function open(id) {
        var modal = modalById(id);
        if (!modal) { return; }
        closeAll();
        modal.style.display = 'flex';
        document.body.classList.add('ysrtech-otp-open');
        var firstInput = modal.querySelector('input');
        if (firstInput) { try { firstInput.focus(); } catch (e) {} }
    }

    function clearMessages(scope) {
        var box = scope.querySelector('.ysrtech-otp-messages');
        if (box) { box.innerHTML = ''; }
    }

    function showMessage(scope, isError, text) {
        var box = scope.querySelector('.ysrtech-otp-messages');
        if (!box) { return; }
        box.innerHTML = '<div class="ysrtech-otp-message ' +
            (isError ? 'error' : 'success') + '">' + text + '</div>';
    }

    function setLoading(btn, loading) {
        if (!btn) { return; }
        if (loading) {
            btn.disabled = true;
            btn.setAttribute('data-otp-label', btn.innerHTML);
            btn.innerHTML = '<span>' + (btn.getAttribute('data-loading') || 'Please wait…') + '</span>';
        } else {
            btn.disabled = false;
            if (btn.getAttribute('data-otp-label')) {
                btn.innerHTML = btn.getAttribute('data-otp-label');
            }
        }
    }

    function postForm(url, form) {
        var data = new URLSearchParams();
        if (form) {
            $all('input, select, textarea', form).forEach(function (el) {
                if (!el.name) { return; }
                data.append(el.name, el.value);
            });
        }
        return fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: data.toString()
        }).then(function (r) { return r.json(); });
    }

    // ---- Send OTP (login-by-email and registration both land here) ----
    function handleSendOtp(form) {
        var btn = form.querySelector('.ysrtech-otp-send');
        var url = btn ? btn.getAttribute('data-url') : null;
        var scope = form.closest('.ysrtech-otp-dialog') || document;
        if (!url) { return; }
        clearMessages(scope);
        setLoading(btn, true);

        postForm(url, form).then(function (res) {
            setLoading(btn, false);
            if (res.errors) {
                showMessage(scope, true, res.message);
            } else {
                open('ysrtech-otp-verify');
                var verifyScope = $('#ysrtech-otp-verify .ysrtech-otp-dialog');
                if (verifyScope) { showMessage(verifyScope, false, res.message); }
            }
        }).catch(function () {
            setLoading(btn, false);
            showMessage(scope, true, 'An error occurred, please try again later.');
        });
    }

    // ---- Verify OTP ----
    function handleVerify(form) {
        var btn = form.querySelector('.ysrtech-otp-confirm');
        var url = btn ? btn.getAttribute('data-url') : null;
        var scope = form.closest('.ysrtech-otp-dialog') || document;
        if (!url) { return; }
        clearMessages(scope);
        setLoading(btn, true);

        postForm(url, form).then(function (res) {
            setLoading(btn, false);
            showMessage(scope, res.errors, res.message);
            if (!res.errors) {
                setTimeout(function () { window.location.reload(); }, 800);
            }
        }).catch(function () {
            setLoading(btn, false);
            showMessage(scope, true, 'An error occurred, please try again later.');
        });
    }

    // ---- Resend OTP ----
    function handleResend(link) {
        var url = link.getAttribute('data-url');
        var scope = link.closest('.ysrtech-otp-dialog') || document;
        if (!url) { return; }
        clearMessages(scope);
        postForm(url, null).then(function (res) {
            showMessage(scope, res.errors, res.message);
        }).catch(function () {
            showMessage(scope, true, 'An error occurred, please try again later.');
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        if (!modalById('ysrtech-otp-login') && !modalById('ysrtech-otp-register')) {
            return;
        }

        // Intercept the theme's Log In / Create Account links.
        $all('a[href]').forEach(function (a) {
            var href = a.getAttribute('href') || '';
            if (/customer\/account\/login(\/|$|\?)/.test(href) && modalById('ysrtech-otp-login')) {
                a.addEventListener('click', function (e) { e.preventDefault(); open('ysrtech-otp-login'); });
            } else if (/customer\/account\/create(\/|$|\?)/.test(href) && modalById('ysrtech-otp-register')) {
                a.addEventListener('click', function (e) { e.preventDefault(); open('ysrtech-otp-register'); });
            }
        });

        // Delegated click handling.
        document.addEventListener('click', function (e) {
            var t = e.target;

            var closer = t.closest('[data-otp-close], .ysrtech-otp-modal');
            if (t.closest('[data-otp-close]')) { e.preventDefault(); closeAll(); return; }
            // Clicking the dimmed backdrop (the modal element itself) closes it.
            if (t.classList && t.classList.contains('ysrtech-otp-modal')) { closeAll(); return; }

            var opener = t.closest('[data-otp-open]');
            if (opener) {
                e.preventDefault();
                open('ysrtech-otp-' + opener.getAttribute('data-otp-open'));
                return;
            }

            var tab = t.closest('[data-otp-tab]');
            if (tab) {
                e.preventDefault();
                var dialog = tab.closest('.ysrtech-otp-dialog');
                $all('[data-otp-tab]', dialog).forEach(function (x) { x.classList.remove('active'); });
                tab.classList.add('active');
                var key = tab.getAttribute('data-otp-tab');
                $all('[data-otp-pane]', dialog).forEach(function (p) {
                    p.style.display = (p.getAttribute('data-otp-pane') === key) ? 'block' : 'none';
                });
                return;
            }

            var resend = t.closest('.ysrtech-otp-resend');
            if (resend) { e.preventDefault(); handleResend(resend); return; }
        });

        // Escape closes.
        document.addEventListener('keyup', function (e) {
            if (e.key === 'Escape') { closeAll(); }
        });

        // Form submissions.
        var emailForm = modalById('ysrtech-otp-email-form');
        if (emailForm) {
            emailForm.addEventListener('submit', function (e) { e.preventDefault(); handleSendOtp(emailForm); });
        }
        var regForm = modalById('ysrtech-otp-register-form');
        if (regForm) {
            regForm.addEventListener('submit', function (e) { e.preventDefault(); handleSendOtp(regForm); });
        }
        var verifyForm = modalById('ysrtech-otp-verify-form');
        if (verifyForm) {
            verifyForm.addEventListener('submit', function (e) { e.preventDefault(); handleVerify(verifyForm); });
        }
        // The password tab posts normally to customer/account/loginPost.
    });
})();
