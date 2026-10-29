/**
 * YSRTech OtpLogin - frontend behaviour.
 *
 * Framework-agnostic (no jQuery / prototype dependency). Handles the popup
 * modals, tab switching and the AJAX email-OTP flow.
 */
(function () {
    'use strict';

    // Set from window.YsrtechOtpConfig once the page has loaded: this file is in <head>, the
    // settings are printed further down the page (otp.phtml).
    var cfg = { text: {} };

    // What happens after a code is accepted. The sign-in popups reload the page; the store's own
    // login and Create Account pages set their own follow-up (see enhancePages()).
    var verifyState = { extra: null, after: null };

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

    // Public hook so other checkout scripts (e.g. OneStepCheckout's "login now"
    // links) can open the passwordless popup instead of a password form.
    window.YsrtechOtp = { open: open, close: closeAll };

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
            btn.innerHTML = '<span>' + (btn.getAttribute('data-loading') || (cfg.text && cfg.text.wait) || 'Please wait…') + '</span>';
        } else {
            btn.disabled = false;
            if (btn.getAttribute('data-otp-label')) {
                btn.innerHTML = btn.getAttribute('data-otp-label');
            }
        }
    }

    function postForm(url, form, extra) {
        var data = new URLSearchParams();
        if (form) {
            $all('input, select, textarea', form).forEach(function (el) {
                if (!el.name) { return; }
                data.append(el.name, el.value);
            });
        }
        Object.keys(extra || {}).forEach(function (k) { data.append(k, extra[k]); });
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

    // Where messages for a form should be shown: its popup dialog, its inline
    // wrapper on the login page, or the document as a last resort.
    function scopeOf(form) {
        return form.closest('.ysrtech-otp-dialog') || form.closest('.ysrtech-otp-inline') || document;
    }

    // ---- Send code (email sign-in and registration both land here) ----
    function handleSendOtp(form) {
        var btn = form.querySelector('.ysrtech-otp-send');
        var url = btn ? btn.getAttribute('data-url') : null;
        var scope = scopeOf(form);
        if (!url) { return; }
        clearMessages(scope);
        setLoading(btn, true);

        postForm(url, form).then(function (res) {
            setLoading(btn, false);
            if (res.errors) {
                showMessage(scope, true, res.message);
            } else {
                setVerifyMode(null);
                open('ysrtech-otp-verify');
                var verifyScope = $('#ysrtech-otp-verify .ysrtech-otp-dialog');
                if (verifyScope) { showMessage(verifyScope, false, res.message); }
            }
        }).catch(function () {
            setLoading(btn, false);
            showMessage(scope, true, (cfg.text && cfg.text.error) || 'An error occurred, please try again later.');
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

        postForm(url, form, verifyState.extra).then(function (res) {
            setLoading(btn, false);
            showMessage(scope, res.errors, res.message);
            if (!res.errors) {
                var after = verifyState.after || function () { window.location.reload(); };
                setTimeout(after, 800);
            }
        }).catch(function () {
            setLoading(btn, false);
            showMessage(scope, true, (cfg.text && cfg.text.error) || 'An error occurred, please try again later.');
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
            showMessage(scope, true, (cfg.text && cfg.text.error) || 'An error occurred, please try again later.');
        });
    }

    // ---- Verify popup texts: sign-in (default) or confirming an address for a new account ----
    var verifyDefaults = null;
    function setVerifyMode(mode) {
        var modal = modalById('ysrtech-otp-verify');
        if (!modal) { return; }
        var title = modal.querySelector('.ysrtech-otp-title');
        var hint = modal.querySelector('.ysrtech-otp-hint');
        var button = modal.querySelector('.ysrtech-otp-confirm');
        if (!verifyDefaults) {
            verifyDefaults = { title: title && title.innerHTML, hint: hint && hint.innerHTML, button: button && button.innerHTML };
        }
        var t = cfg.text || {};
        var register = mode === 'register';
        if (title) { title.innerHTML = register && t.verifyTitle ? t.verifyTitle : verifyDefaults.title; }
        if (hint) { hint.innerHTML = register && t.verifyHint ? t.verifyHint : verifyDefaults.hint; }
        if (button) { button.innerHTML = register && t.verifyButton ? '<span>' + t.verifyButton + '</span>' : verifyDefaults.button; }
        if (!mode) { verifyState.extra = null; verifyState.after = null; }
        var code = modal.querySelector('#ysrtech-otp-code');
        if (code) { code.value = ''; }
    }

    // The referer the login page was opened with (/referer/<base64>/), if it is on this site.
    function loginReferer() {
        var m = window.location.pathname.match(/\/referer\/([^\/]+)/);
        if (!m) { return null; }
        try {
            var url = atob(m[1].replace(/-/g, '+').replace(/_/g, '/').replace(/,/g, '='));
            var target = new URL(url, window.location.href);
            return target.origin === window.location.origin ? target.href : null;
        } catch (e) { return null; }
    }

    function validates(form) {
        if (window.Validation) {
            try { return new window.Validation(form, { onSubmit: false }).validate(); } catch (e) {}
        }
        return form.reportValidity ? form.reportValidity() : true;
    }

    function removeField(input) {
        if (!input) { return; }
        var box = input.closest('.field') || input.closest('li') || input.closest('.input-box') || input;
        box.parentNode.removeChild(box);
    }

    function formMessages(form) {
        var box = form.querySelector('.ysrtech-otp-messages');
        if (!box) {
            box = document.createElement('div');
            box.className = 'ysrtech-otp-messages';
            form.insertBefore(box, form.firstChild);
        }
        return form;
    }

    function setButtonText(form, text) {
        var btn = form.querySelector('button[type="submit"], button:not([type])');
        if (!btn || !text) { return btn; }
        var inner = btn.querySelector('span span') || btn.querySelector('span') || btn;
        inner.textContent = text;
        btn.setAttribute('title', text);
        return btn;
    }

    // Send a code for the page form, then open the code popup. Submitting is stopped before the
    // theme's own handlers run (capture phase + stopImmediatePropagation).
    function interceptSubmit(form, collect, onSent) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            e.stopImmediatePropagation();
            if (!validates(form)) { return; }
            var scope = formMessages(form);
            clearMessages(scope);
            var btn = form.querySelector('button[type="submit"], button:not([type])');
            setLoading(btn, true);
            postForm(cfg.sendUrl, null, collect()).then(function (res) {
                setLoading(btn, false);
                if (res.errors) { showMessage(scope, true, res.message); return; }
                onSent(res);
            }).catch(function () {
                setLoading(btn, false);
                showMessage(scope, true, (cfg.text && cfg.text.error) || 'An error occurred, please try again later.');
            });
        }, true);
    }

    // A Cloudflare Turnstile token in the form (e.g. Fballiano_Turnstile) is single-use and
    // expires after about five minutes, and finding the emailed code can take longer. Ask the
    // widget for a fresh token before the form is submitted (waits up to 30 seconds).
    function withFreshTurnstile(form, done) {
        var hasToken = !!form.querySelector('input[name="cf-turnstile-response"]');
        if (!hasToken || !window.turnstile) { done(); return; }
        try { window.turnstile.reset(form); } catch (e) {
            try { window.turnstile.reset(); } catch (e2) { done(); return; }
        }
        var waited = 0;
        (function poll() {
            var input = form.querySelector('input[name="cf-turnstile-response"]');
            if ((input && input.value) || (waited += 250) > 30000) { done(); return; }
            setTimeout(poll, 250);
        })();
    }

    function showVerify(message) {
        open('ysrtech-otp-verify');
        var verifyScope = $('#ysrtech-otp-verify .ysrtech-otp-dialog');
        if (verifyScope) { showMessage(verifyScope, false, message); }
    }

    function enhancePages() {
        if (!modalById('ysrtech-otp-verify') || !cfg.sendUrl) { return; }
        var t = cfg.text || {};

        // The store's login page (and any other page form posting to loginPost): code instead of password.
        $all('form[action*="customer/account/loginPost"]').forEach(function (form) {
            if (form.closest('.ysrtech-otp-modal')) { return; }
            removeField(form.querySelector('input[type="password"]'));
            $all('a[href*="forgotpassword"]', form).forEach(function (a) { a.parentNode.removeChild(a); });
            removeField(form.querySelector('input[name="persistent_remember_me"]'));
            setButtonText(form, t.sendCode);
            interceptSubmit(form, function () {
                var email = form.querySelector('input[name="login[username]"], input[type="email"]');
                return { email: email ? email.value.trim() : '' };
            }, function (res) {
                setVerifyMode('login');
                verifyState.after = function () {
                    window.location.href = loginReferer() || cfg.accountUrl || window.location.href;
                };
                showVerify(res.message);
            });
        });

        // The store's Create Account page: no password; the address is confirmed with a code,
        // then the page's own form is submitted with all its fields.
        if (!cfg.registrationPage) { return; }
        $all('form[action*="customer/account/createpost"]').forEach(function (form) {
            removeField(form.querySelector('input[name="password"]'));
            removeField(form.querySelector('input[name="confirmation"]'));
            setButtonText(form, t.confirmCode);
            interceptSubmit(form, function () {
                var get = function (name) { var el = form.querySelector('[name="' + name + '"]'); return el ? el.value.trim() : ''; };
                return { email: get('email'), firstname: get('firstname'), lastname: get('lastname') };
            }, function (res) {
                setVerifyMode('register');
                verifyState.extra = { verify_only: 1 };
                verifyState.after = function () {
                    withFreshTurnstile(form, function () { HTMLFormElement.prototype.submit.call(form); });
                };
                showVerify(res.message);
            });
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        cfg = window.YsrtechOtpConfig || cfg;
        enhancePages();

        // The verify popup is what every entry point funnels into, so bind as long
        // as it (or an inline sign-in form on the login page) is on the page.
        if (!modalById('ysrtech-otp-verify') && !$('.ysrtech-otp-sendform')) {
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

        // Every "email me a code" form - the popup, the registration popup, and the
        // inline form on the login page - is bound the same way by class.
        $all('form.ysrtech-otp-sendform').forEach(function (form) {
            form.addEventListener('submit', function (e) { e.preventDefault(); handleSendOtp(form); });
        });
        var verifyForm = modalById('ysrtech-otp-verify-form');
        if (verifyForm) {
            verifyForm.addEventListener('submit', function (e) { e.preventDefault(); handleVerify(verifyForm); });
        }
    });
})();
