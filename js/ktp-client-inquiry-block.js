/**
 * 顧客タブ：公開商品問い合わせブロックボタン
 */
(function () {
    'use strict';

    var t = function (text) {
        return typeof ktpwpTranslate === 'function' ? ktpwpTranslate(text) : text;
    };

    function getConfig() {
        return window.ktpClientInquiryBlock || {};
    }

    function getAjaxUrl() {
        var config = getConfig();
        if (config.ajax_url) {
            return config.ajax_url;
        }
        if (typeof window.ajaxurl !== 'undefined' && window.ajaxurl) {
            return window.ajaxurl;
        }
        if (typeof window.ktp_ajax_object !== 'undefined' && window.ktp_ajax_object.ajax_url) {
            return window.ktp_ajax_object.ajax_url;
        }
        if (typeof window.ktpwp_ajax !== 'undefined' && window.ktpwp_ajax.ajax_url) {
            return window.ktpwp_ajax.ajax_url;
        }
        return '/wp-admin/admin-ajax.php';
    }

    function getNonce() {
        var config = getConfig();
        if (config.nonce) {
            return config.nonce;
        }
        if (typeof window.ktp_ajax_object !== 'undefined' && window.ktp_ajax_object.nonce) {
            return window.ktp_ajax_object.nonce;
        }
        if (typeof window.ktpwp_ajax !== 'undefined' && window.ktpwp_ajax.nonce) {
            return window.ktpwp_ajax.nonce;
        }
        return '';
    }

    function extractErrorMessage(payload, fallback) {
        if (!payload || !payload.data) {
            return fallback;
        }
        if (typeof payload.data === 'string') {
            return payload.data;
        }
        if (payload.data.message) {
            return payload.data.message;
        }
        return fallback;
    }

    function setButtonIcon(button, iconName) {
        var existing = button.querySelector('.ktp-svg-icon, .material-symbols-outlined');
        if (typeof KTPSvgIcons !== 'undefined' && typeof KTPSvgIcons.getIcon === 'function') {
            var html = KTPSvgIcons.getIcon(iconName);
            if (html) {
                if (existing) {
                    existing.outerHTML = html;
                } else {
                    button.insertAdjacentHTML('afterbegin', html);
                }
                return;
            }
        }
        if (existing) {
            existing.textContent = iconName;
        }
    }

    function applyBlockedState(button, blocked) {
        if (!button) {
            return;
        }

        var detailBox = button.closest('.data_detail_box');
        var badge = detailBox ? detailBox.querySelector('.ktp-inquiry-block-badge') : null;

        if (blocked) {
            button.classList.add('is-blocked');
            button.setAttribute('title', t('ブロックを解除'));
            button.setAttribute('aria-label', t('ブロックを解除'));
            setButtonIcon(button, 'block');
            if (badge) {
                badge.style.display = 'inline-flex';
            }
        } else {
            button.classList.remove('is-blocked');
            button.setAttribute('title', t('公開商品からの問い合わせをブロック'));
            button.setAttribute('aria-label', t('公開商品からの問い合わせをブロック'));
            setButtonIcon(button, 'block');
            if (badge) {
                badge.style.display = 'none';
            }
        }

        button.dataset.blocked = blocked ? '1' : '0';
    }

    function notify(message, type) {
        if (type === 'success' && typeof window.showSuccessNotification === 'function') {
            window.showSuccessNotification(message);
            return;
        }
        if (type === 'warning' && typeof window.showWarningNotification === 'function') {
            window.showWarningNotification(message);
            return;
        }
        if (typeof window.showInfoNotification === 'function') {
            window.showInfoNotification(message);
            return;
        }
        window.alert(message);
    }

    function handleClick(event) {
        var button = event.target.closest('.ktp-inquiry-block-btn');
        if (!button || button.disabled) {
            return;
        }

        event.preventDefault();
        event.stopPropagation();

        var clientId = parseInt(button.dataset.clientId || getConfig().client_id || '0', 10);
        if (!clientId) {
            notify(t('顧客 ID が取得できません。ページを再読み込みしてください。'), 'warning');
            return;
        }

        var currentlyBlocked = button.dataset.blocked === '1';
        if (!currentlyBlocked) {
            var confirmMessage = t('この顧客のメールアドレスからの公開商品お問い合わせをブロックしますか？');
            if (!window.confirm(confirmMessage)) {
                return;
            }
        }

        button.disabled = true;

        var formData = new FormData();
        formData.append('action', 'ktp_toggle_client_inquiry_block');
        formData.append('client_id', String(clientId));
        formData.append('nonce', getNonce());

        fetch(getAjaxUrl(), {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
        })
            .then(function (response) {
                return response.text().then(function (text) {
                    if (!text) {
                        throw new Error('empty response');
                    }
                    try {
                        return JSON.parse(text);
                    } catch (error) {
                        throw new Error('invalid json');
                    }
                });
            })
            .then(function (payload) {
                if (!payload || !payload.success) {
                    notify(
                        extractErrorMessage(payload, t('ブロック設定の更新に失敗しました。')),
                        'warning'
                    );
                    return;
                }

                var blocked = !!(payload.data && payload.data.blocked);
                applyBlockedState(button, blocked);
                notify(
                    (payload.data && payload.data.message) ? payload.data.message : t('更新しました。'),
                    'success'
                );
                if (blocked) {
                    var config = getConfig();
                    var listUrl = config.list_url || '';
                    if (listUrl) {
                        window.location.href = listUrl;
                        return;
                    }
                    var url = new URL(window.location.href);
                    url.searchParams.delete('data_id');
                    window.location.href = url.toString();
                }
            })
            .catch(function () {
                notify(t('通信エラーが発生しました。ページを再読み込みして再度お試しください。'), 'warning');
            })
            .finally(function () {
                button.disabled = false;
            });
    }

    document.addEventListener('click', handleClick);
})();
