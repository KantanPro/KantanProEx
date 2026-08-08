/**
 * 受注書複製（KantanBiz orders.duplicate 相当）
 */
(function ($) {
    'use strict';

    function t(msg) {
        return typeof window.ktpwpTranslate === 'function' ? window.ktpwpTranslate(msg) : msg;
    }

    function cfg() {
        return window.ktpOrderDuplicate || {};
    }

    function ensureModalInBody() {
        var modal = document.getElementById('ktp-order-duplicate-modal');
        if (!modal || modal.parentElement === document.body) {
            return;
        }
        document.body.appendChild(modal);
    }

    function openModal() {
        ensureModalInBody();
        var modal = document.getElementById('ktp-order-duplicate-modal');
        if (!modal) {
            return;
        }
        modal.hidden = false;
        modal.setAttribute('aria-hidden', 'false');
        var submit = document.getElementById('ktp-order-duplicate-submit');
        if (submit) {
            submit.disabled = false;
            submit.textContent = t('複製する');
        }
    }

    function closeModal() {
        var modal = document.getElementById('ktp-order-duplicate-modal');
        if (!modal) {
            return;
        }
        modal.hidden = true;
        modal.setAttribute('aria-hidden', 'true');
    }

    function syncClientSelectVisibility() {
        var modal = document.getElementById('ktp-order-duplicate-modal');
        if (!modal) {
            return;
        }
        var mode = modal.querySelector('input[name="ktp-order-duplicate-client-mode"]:checked');
        var wrap = modal.querySelector('.ktp-order-duplicate-modal__client-wrap');
        var select = document.getElementById('ktp-order-duplicate-target-client');
        if (!wrap || !select) {
            return;
        }
        var isOther = mode && mode.value === 'other';
        wrap.hidden = !isOther;
        select.disabled = !isOther;
        select.required = isOther;
    }

    function showDuplicatedNotice() {
        try {
            var params = new URLSearchParams(window.location.search);
            if (params.get('message') !== 'duplicated') {
                return;
            }
            if (typeof window.showSuccessNotification === 'function') {
                window.showSuccessNotification(t('受注書を複製しました。'));
            }
            params.delete('message');
            var next = window.location.pathname + (params.toString() ? '?' + params.toString() : '') + window.location.hash;
            window.history.replaceState({}, '', next);
        } catch (e) {
            /* ignore */
        }
    }

    function duplicateOrder() {
        var btn = document.getElementById('orderDuplicateButton');
        var submit = document.getElementById('ktp-order-duplicate-submit');
        if (!btn || !submit) {
            return;
        }
        var orderId = btn.getAttribute('data-order-id') || '';
        if (!orderId) {
            return;
        }
        var modal = document.getElementById('ktp-order-duplicate-modal');
        var modeInput = modal ? modal.querySelector('input[name="ktp-order-duplicate-client-mode"]:checked') : null;
        var mode = modeInput && modeInput.value ? modeInput.value : 'same';
        var targetClientId = '';
        if (mode === 'other') {
            var select = document.getElementById('ktp-order-duplicate-target-client');
            targetClientId = select ? String(select.value || '').trim() : '';
            if (!targetClientId) {
                alert(t('複製先の顧客を選んでください。'));
                return;
            }
        }

        var settings = cfg();
        var ajaxUrl = settings.ajax_url || (typeof ajaxurl !== 'undefined' ? ajaxurl : '');
        var nonce = settings.nonce || '';
        if (!ajaxUrl || !nonce) {
            alert(t('複製の準備ができていません。ページを再読み込みしてください。'));
            return;
        }

        submit.disabled = true;
        submit.textContent = t('読み込み中…');

        var body = new URLSearchParams();
        body.set('action', 'ktp_duplicate_order');
        body.set('nonce', nonce);
        body.set('order_id', orderId);
        body.set('duplicate_client_mode', mode);
        body.set('target_client_id', targetClientId);
        body.set('redirect_base', window.location.href.split('#')[0]);

        fetch(ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: body.toString()
        })
            .then(function (res) {
                return res.json();
            })
            .then(function (json) {
                if (json && json.success && json.data && json.data.redirect_url) {
                    window.location.href = json.data.redirect_url;
                    return;
                }
                var msg = (json && json.data) ? json.data : t('受注書の複製に失敗しました。');
                alert(typeof msg === 'string' ? msg : t('受注書の複製に失敗しました。'));
                submit.disabled = false;
                submit.textContent = t('複製する');
            })
            .catch(function () {
                alert(t('受注書の複製に失敗しました。'));
                submit.disabled = false;
                submit.textContent = t('複製する');
            });
    }

    $(document).on('click', '#orderDuplicateButton:not([disabled])', function (e) {
        e.preventDefault();
        openModal();
        syncClientSelectVisibility();
    });

    $(document).on('click', '[data-ktp-order-duplicate-close]', function (e) {
        e.preventDefault();
        closeModal();
    });

    $(document).on('change', 'input[name="ktp-order-duplicate-client-mode"]', syncClientSelectVisibility);

    $(document).on('click', '#ktp-order-duplicate-submit', function (e) {
        e.preventDefault();
        duplicateOrder();
    });

    $(document).on('keydown', function (e) {
        if (e.key === 'Escape') {
            closeModal();
        }
    });

    $(function () {
        syncClientSelectVisibility();
        showDuplicatedNotice();
    });
})(jQuery);
