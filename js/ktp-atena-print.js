/**
 * 宛名印刷：プレビュー（編集）画面・罫線メモ（全体1テキストエリア）・localStorage 保存
 */
(function (global) {
    'use strict';

    var STORAGE_PREFIX = 'ktp-atena-memos:v1:';
    var MODAL_ID = 'ktp-atena-preview-modal';
    var STYLE_ID = 'ktp-atena-preview-styles';
    var MEMO_MAX_LENGTH = 2000;

    var DEFAULTS = {
        gridStartMm: 105,
        gridStepMm: 10,
        gridLineCount: 18,
    };

    function t(msg) {
        return typeof global.ktpwpTranslate === 'function' ? global.ktpwpTranslate(msg) : msg;
    }

    function esc(s) {
        if (s == null || s === '') {
            return '';
        }
        return String(s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function storageKey(entityType, recordId) {
        return STORAGE_PREFIX + entityType + ':' + (recordId || '0');
    }

    function loadMemoBody(key) {
        try {
            var raw = global.localStorage.getItem(key);
            if (!raw) {
                return '';
            }
            var parsed = JSON.parse(raw);
            if (typeof parsed === 'string') {
                return parsed;
            }
            if (parsed && typeof parsed.body === 'string') {
                return parsed.body;
            }
            if (parsed && typeof parsed === 'object' && parsed !== null) {
                var keys = Object.keys(parsed).filter(function (k) {
                    return /^\d+$/.test(k);
                });
                if (keys.length) {
                    keys.sort(function (a, b) {
                        return Number(a) - Number(b);
                    });
                    return keys
                        .map(function (k) {
                            return String(parsed[k] || '');
                        })
                        .filter(function (s) {
                            return s.length > 0;
                        })
                        .join('\n');
                }
            }
        } catch (e) {
            /* ignore */
        }
        return '';
    }

    function saveMemoBody(key, text) {
        try {
            global.localStorage.setItem(key, JSON.stringify(String(text || '')));
        } catch (e) {
            /* ignore */
        }
    }

    function memoAreaHeightMm(opts) {
        return opts.gridLineCount * opts.gridStepMm;
    }

    function ensureStyles() {
        if (document.getElementById(STYLE_ID)) {
            return;
        }
        var style = document.createElement('style');
        style.id = STYLE_ID;
        style.textContent =
            '#' +
            MODAL_ID +
            '{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:100000;align-items:center;justify-content:center;padding:16px;box-sizing:border-box;}' +
            '#' +
            MODAL_ID +
            '.is-open{display:flex;}' +
            '.ktp-atena-panel{background:#fff;border-radius:8px;width:min(96vw,520px);max-height:92vh;display:flex;flex-direction:column;box-shadow:0 8px 32px rgba(0,0,0,.18);}' +
            '.ktp-atena-panel-header{display:flex;justify-content:space-between;align-items:center;padding:12px 16px;border-bottom:1px solid #e5e7eb;}' +
            '.ktp-atena-panel-header h3{margin:0;font-size:16px;color:#111;}' +
            '.ktp-atena-panel-close{background:none;border:none;font-size:24px;line-height:1;cursor:pointer;color:#374151;padding:0 4px;}' +
            '.ktp-atena-panel-body{overflow:auto;padding:12px 16px;flex:1;}' +
            '.ktp-atena-panel-note{margin:0 0 10px;font-size:11px;color:#6b7280;line-height:1.45;}' +
            '.ktp-atena-panel-footer{display:flex;justify-content:flex-end;gap:8px;padding:12px 16px;border-top:1px solid #e5e7eb;}' +
            '.ktp-atena-btn{padding:8px 14px;font-size:13px;border-radius:4px;cursor:pointer;border:1px solid #d1d5db;background:#fff;color:#374151;}' +
            '.ktp-atena-btn-primary{background:#2563eb;border-color:#2563eb;color:#fff;}' +
            '.ktp-atena-sheet{position:relative;box-sizing:border-box;margin:0 auto;background:#fff;overflow:visible;width:120mm;min-height:235mm;}' +
            '.ktp-atena-label{position:absolute;z-index:2;top:6mm;left:23mm;text-align:left;font-size:12px;line-height:1.4;color:#333;max-width:88mm;word-wrap:break-word;}' +
            '.ktp-atena-grid-lines{position:absolute;left:10mm;right:10mm;top:0;bottom:0;pointer-events:none;z-index:0;}' +
            '.ktp-atena-line{position:absolute;left:0;right:0;height:0;border-top:1px dotted rgba(0,0,0,.22);}' +
            '.ktp-atena-memo-area{position:absolute;left:10mm;right:10mm;z-index:1;box-sizing:border-box;padding:1.2mm 0 0;}' +
            '.ktp-atena-memo{width:100%;height:100%;margin:0;padding:0;border:none;background:transparent;font:inherit;font-size:12px;color:#333;resize:none;overflow-x:hidden;overflow-y:auto;white-space:pre-wrap;word-wrap:break-word;overflow-wrap:anywhere;}' +
            '.ktp-atena-memo:focus{outline:1px dashed rgba(37,99,235,.45);background:rgba(37,99,235,.04);}';
        document.head.appendChild(style);
    }

    function closeModal() {
        var modal = document.getElementById(MODAL_ID);
        if (modal) {
            modal.classList.remove('is-open');
        }
    }

    function ensureModal() {
        ensureStyles();
        var modal = document.getElementById(MODAL_ID);
        if (modal) {
            return modal;
        }
        modal = document.createElement('div');
        modal.id = MODAL_ID;
        modal.setAttribute('role', 'dialog');
        modal.setAttribute('aria-modal', 'true');
        modal.innerHTML =
            '<div class="ktp-atena-panel">' +
            '<div class="ktp-atena-panel-header">' +
            '<h3 class="ktp-atena-panel-title"></h3>' +
            '<button type="button" class="ktp-atena-panel-close" aria-label="' +
            esc(t('閉じる')) +
            '">&times;</button>' +
            '</div>' +
            '<div class="ktp-atena-panel-body">' +
            '<p class="ktp-atena-panel-note"></p>' +
            '<div class="ktp-atena-sheet-wrap"></div>' +
            '</div>' +
            '<div class="ktp-atena-panel-footer">' +
            '<button type="button" class="ktp-atena-btn ktp-atena-cancel">' +
            esc(t('閉じる')) +
            '</button>' +
            '<button type="button" class="ktp-atena-btn ktp-atena-btn-primary ktp-atena-print">' +
            esc(t('印刷')) +
            '</button>' +
            '</div>' +
            '</div>';
        document.body.appendChild(modal);

        modal.querySelector('.ktp-atena-panel-close').addEventListener('click', closeModal);
        modal.querySelector('.ktp-atena-cancel').addEventListener('click', closeModal);
        modal.addEventListener('click', function (ev) {
            if (ev.target === modal) {
                closeModal();
            }
        });
        return modal;
    }

    function buildSheetHtml(config) {
        var opts = Object.assign({}, DEFAULTS, config || {});
        var gridStart = opts.gridStartMm;
        var gridStep = opts.gridStepMm;
        var lineCount = opts.gridLineCount;
        var areaHeight = memoAreaHeightMm(opts);
        var memoKey = storageKey(opts.entityType, opts.recordId);
        var memoBody = loadMemoBody(memoKey);
        var html = '';
        var i;

        html += '<div class="ktp-atena-sheet" data-memo-key="' + esc(memoKey) + '">';
        html += '<div class="ktp-atena-label">' + (opts.labelInnerHtml || '') + '</div>';
        html +=
            '<div class="ktp-atena-memo-area" style="top:' +
            gridStart +
            'mm;height:' +
            areaHeight +
            'mm">' +
            '<textarea class="ktp-atena-memo" rows="' +
            lineCount +
            '" maxlength="' +
            MEMO_MAX_LENGTH +
            '" aria-label="' +
            esc(t('罫線エリアのメモ')) +
            '" placeholder="' +
            esc(t('罫線内に自由にメモを入力（改行可）')) +
            '" style="line-height:' +
            gridStep +
            'mm">' +
            esc(memoBody) +
            '</textarea></div>';

        html += '<div class="ktp-atena-grid-lines" aria-hidden="true">';
        for (i = 0; i < lineCount; i++) {
            html +=
                '<div class="ktp-atena-line" style="top:' + (gridStart + i * gridStep) + 'mm"></div>';
        }
        html += '</div>';
        html += '</div>';
        return html;
    }

    function wireMemo(sheetEl) {
        if (!sheetEl) {
            return;
        }
        var key = sheetEl.getAttribute('data-memo-key');
        var ta = sheetEl.querySelector('.ktp-atena-memo');
        if (!key || !ta) {
            return;
        }
        var saveTimer = null;
        function persist() {
            saveMemoBody(key, ta.value);
        }
        ta.addEventListener('input', function () {
            if (saveTimer) {
                clearTimeout(saveTimer);
            }
            saveTimer = setTimeout(persist, 250);
        });
        ta.addEventListener('blur', persist);
    }

    function collectMemoBody(sheetEl) {
        if (!sheetEl) {
            return '';
        }
        var ta = sheetEl.querySelector('.ktp-atena-memo');
        return ta ? String(ta.value || '') : '';
    }

    function buildPrintDocument(config, memoBody) {
        var opts = Object.assign({}, DEFAULTS, config || {});
        var gridStart = opts.gridStartMm;
        var gridStep = opts.gridStepMm;
        var lineCount = opts.gridLineCount;
        var areaHeight = memoAreaHeightMm(opts);
        var printHTML = '<!DOCTYPE html><html lang="' + (document.documentElement.lang || 'ja') + '"><head><meta charset="UTF-8">';
        printHTML += '<title>' + esc(opts.title || t('宛名')) + '</title>';
        printHTML += '<style>';
        printHTML += '*{margin:0;padding:0;box-sizing:border-box;}';
        printHTML +=
            'body{position:relative;margin:0;padding:0;min-height:235mm;font-family:"Noto Sans JP","Hiragino Kaku Gothic ProN","Yu Gothic",Meiryo,sans-serif;font-size:12px;line-height:1.4;color:#333;background:#fff;}';
        printHTML +=
            '.ktp-atena-grid-lines{position:absolute;left:10mm;right:10mm;top:0;bottom:0;pointer-events:none;z-index:0;}';
        printHTML +=
            '.ktp-atena-line{position:absolute;left:0;right:0;height:0;border-top:1px dotted rgba(0,0,0,0.22);}';
        printHTML +=
            '.ktp-atena-memo-body{position:absolute;left:10mm;right:10mm;z-index:1;padding:1.2mm 0 0;font-size:12px;white-space:pre-wrap;word-wrap:break-word;overflow:hidden;}';
        printHTML += '@page{size:120mm 235mm;margin:10mm;}';
        printHTML +=
            '@media print{body{margin:0;padding:0;}.ktp-atena-line{border-top-width:0.25mm;border-top-style:dotted;border-top-color:rgba(0,0,0,0.2);}}';
        printHTML +=
            '.label{position:absolute;z-index:2;top:6mm;left:23mm;text-align:left;font-size:12px;line-height:1.4;color:#333;max-width:88mm;word-wrap:break-word;}';
        printHTML += '</style></head><body>';

        var gi;
        printHTML += '<div class="ktp-atena-grid-lines" aria-hidden="true">';
        for (gi = 0; gi < lineCount; gi++) {
            printHTML +=
                '<div class="ktp-atena-line" style="top:' + (gridStart + gi * gridStep) + 'mm"></div>';
        }
        printHTML += '</div>';

        if (memoBody && String(memoBody).trim()) {
            printHTML +=
                '<div class="ktp-atena-memo-body" style="top:' +
                gridStart +
                'mm;height:' +
                areaHeight +
                'mm;line-height:' +
                gridStep +
                'mm">' +
                esc(memoBody) +
                '</div>';
        }

        printHTML += '<div class="label">' + (opts.labelInnerHtml || '') + '</div>';
        printHTML += '</body></html>';
        return printHTML;
    }

    function printHtml(printHTML) {
        var iframe = document.createElement('iframe');
        iframe.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0;visibility:hidden;';
        document.body.appendChild(iframe);
        var cleanupDone = false;
        function cleanup() {
            if (cleanupDone) {
                return;
            }
            cleanupDone = true;
            setTimeout(function () {
                try {
                    document.body.removeChild(iframe);
                } catch (_) {}
            }, 300);
        }
        var printed = false;
        function triggerPrint() {
            if (printed) {
                return;
            }
            printed = true;
            try {
                var frameWin = iframe.contentWindow || iframe;
                frameWin.focus();
                frameWin.onafterprint = cleanup;
                setTimeout(function () {
                    try {
                        frameWin.print();
                    } catch (e) {
                        cleanup();
                    }
                }, 50);
            } catch (e) {
                cleanup();
            }
        }
        try {
            var frameDoc = iframe.contentDocument || iframe.contentWindow.document;
            frameDoc.open();
            frameDoc.write(printHTML);
            frameDoc.close();
            setTimeout(triggerPrint, 50);
        } catch (e) {
            cleanup();
            throw e;
        }
    }

    function openPreview(config) {
        if (!config || typeof config !== 'object') {
            return;
        }
        var modal = ensureModal();
        modal.querySelector('.ktp-atena-panel-title').textContent = config.title || t('宛名印刷');
        modal.querySelector('.ktp-atena-panel-note').textContent =
            config.memoNote ||
            t('罫線エリア全体にメモを入力できます。内容は次にこの宛名印刷を開くまで保存されます。');
        var wrap = modal.querySelector('.ktp-atena-sheet-wrap');
        wrap.innerHTML = buildSheetHtml(config);
        var sheet = wrap.querySelector('.ktp-atena-sheet');
        wireMemo(sheet);

        var printBtn = modal.querySelector('.ktp-atena-print');
        var newPrintBtn = printBtn.cloneNode(true);
        printBtn.parentNode.replaceChild(newPrintBtn, printBtn);
        newPrintBtn.addEventListener('click', function () {
            var memoBody = collectMemoBody(sheet);
            var key = sheet.getAttribute('data-memo-key');
            if (key) {
                saveMemoBody(key, memoBody);
            }
            closeModal();
            try {
                printHtml(buildPrintDocument(config, memoBody));
            } catch (e) {
                console.error('[宛名印刷] 印刷に失敗:', e);
            }
        });

        modal.classList.add('is-open');
    }

    global.KtpAtenaPrint = {
        openPreview: openPreview,
        storageKey: storageKey,
    };
})(window);
