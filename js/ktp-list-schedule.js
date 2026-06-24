/**
 * 仕事リスト・受注タブ: 工程表モーダル表示と印刷
 *
 * @package KTPWP
 */
(function () {
    'use strict';

    function escapeHtml(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function getModal() {
        return document.getElementById('work-list-schedule-modal');
    }

    function ensureModalInBody() {
        var modal = getModal();
        if (!modal || modal.parentElement === document.body) {
            return;
        }
        document.body.appendChild(modal);
    }

    function openModal() {
        var modal = getModal();
        if (!modal) {
            return;
        }
        ensureModalInBody();
        modal.classList.add('is-open');
        modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('work-list-schedule-modal-open');
    }

    function closeModal() {
        var modal = getModal();
        if (!modal) {
            return;
        }
        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('work-list-schedule-modal-open');
    }

    function buildScheduleUrl() {
        var url = new URL(window.location.href);
        url.searchParams.delete('page_start');
        url.searchParams.delete('page_stage');
        url.searchParams.delete('list_search');
        url.searchParams.delete('print_all');
        url.searchParams.delete('schedule');
        url.searchParams.set('tab_name', 'list');
        url.searchParams.set('progress', '3');
        url.searchParams.set('schedule', '1');
        return url.toString();
    }

    function loadScheduleContent() {
        var body = document.getElementById('work-list-schedule-modal-body');
        if (!body) {
            return Promise.resolve();
        }

        var loadingText = body.dataset.loadingText || '読み込み中…';
        var errorText = body.dataset.errorText || '工程表を読み込めませんでした。';
        body.innerHTML = '<p class="work-list-schedule-loading">' + escapeHtml(loadingText) + '</p>';

        return fetch(buildScheduleUrl(), {
            credentials: 'same-origin',
            headers: { Accept: 'text/html' }
        })
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('schedule fetch failed');
                }
                return response.text();
            })
            .then(function (html) {
                var doc = new DOMParser().parseFromString(html, 'text/html');
                var area = doc.querySelector('#work-list-schedule-area');
                body.innerHTML = area ? area.innerHTML : html;
            })
            .catch(function () {
                body.innerHTML = '<p class="work-list-schedule-error">' + escapeHtml(errorText) + '</p>';
            });
    }

    function extractScheduleChartStyles() {
        return [
            '.work-list-schedule-chart{font-size:12px;line-height:1.4;}',
            '.work-list-schedule-count{margin:0 0 12px;font-size:12px;font-weight:600;color:#374151;}',
            '.work-list-schedule-count-note{color:#6b7280;font-weight:400;}',
            '.work-list-schedule-empty{font-size:14px;color:#4b5563;}',
            '.work-list-schedule-scroll{overflow-x:auto;}',
            '.work-list-schedule-layout{position:relative;min-width:640px;}',
            '.work-list-schedule-gridlines{position:absolute;pointer-events:none;z-index:0;left:calc(14rem + 12px);right:0;top:40px;bottom:0;}',
            '.work-list-schedule-gridline{position:absolute;top:0;bottom:0;border-left:1px solid #9ca3af;}',
            '.work-list-schedule-grid{position:relative;z-index:1;display:grid;grid-template-columns:14rem 1fr;column-gap:12px;row-gap:0;}',
            '.work-list-schedule-row-divider{grid-column:1 / -1;height:0;border-top:1px solid #d1d5db;}',
            '.work-list-schedule-case-header{height:32px;display:flex;align-items:flex-end;font-size:12px;font-weight:600;color:#6b7280;border-bottom:1px solid #d1d5db;}',
            '.work-list-schedule-timeline-header{position:relative;height:32px;overflow:visible;border-bottom:1px solid #d1d5db;background:transparent;}',
            '.work-list-schedule-tick{position:absolute;bottom:2px;transform:translateX(-50%);font-size:11px;line-height:1;color:#374151;white-space:nowrap;}',
            '.work-list-schedule-label-cell{min-height:28px;display:flex;flex-direction:column;justify-content:center;align-self:center;padding:6px 0;}',
            '.work-list-schedule-label{display:block;font-size:13px;font-weight:600;color:#075985;text-decoration:none;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}',
            '.work-list-schedule-sublabel{margin:0;font-size:11px;color:#6b7280;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}',
            '.work-list-schedule-track{position:relative;height:28px;align-self:center;background:transparent;border:none;}',
            '.work-list-schedule-no-bar{position:absolute;inset:0;display:flex;align-items:center;font-size:11px;color:#9ca3af;white-space:nowrap;}',
            '.work-list-schedule-footnotes{margin-top:12px;}',
            '.work-list-schedule-footnotes > * + *{margin-top:4px;}',
            '.work-list-schedule-legend{margin:0;font-size:12px;color:#4b5563;}',
            '.work-list-schedule-bar{position:absolute;top:4px;bottom:4px;z-index:2;box-sizing:border-box;border-radius:4px;background:#d1d5db;border:1px solid #9ca3af;box-shadow:0 1px 2px rgba(15,23,42,0.1);}'
        ].join('\n');
    }

    function getPrintOfficeName() {
        var el = document.querySelector('#ktp_list_my_company_name');
        if (el && el.textContent) {
            return el.textContent.trim();
        }
        return '（自社名未設定）';
    }

    function createSchedulePrintableHTML(content, title, footerText, chartStyles) {
        var safeTitle = escapeHtml(title || '工程表');
        return '<!DOCTYPE html><html lang="ja"><head><meta charset="UTF-8">'
            + '<meta name="viewport" content="width=device-width,initial-scale=1.0">'
            + '<title>' + safeTitle + '</title>'
            + '<style>'
            + (chartStyles || '')
            + '*{margin:0;padding:0;box-sizing:border-box;}'
            + 'body{font-family:"Noto Sans JP","Hiragino Kaku Gothic ProN","Yu Gothic",Meiryo,sans-serif;font-size:12px;line-height:1.5;color:#333;background:#fff;padding:20px;-webkit-print-color-adjust:exact;print-color-adjust:exact;}'
            + '.print-header{position:relative;display:flex;align-items:center;justify-content:center;min-height:12mm;margin:0 0 4mm 0;padding:1mm 0;border-bottom:1px solid #ddd;background:#fff;font-size:22px;font-weight:700;page-break-after:avoid;}'
            + '.print-footer{position:fixed;bottom:0;left:0;right:0;height:10mm;display:flex;align-items:center;justify-content:center;border-top:1px solid #ddd;background:#fff;font-size:11px;z-index:9999;pointer-events:none;}'
            + '.page-container{max-width:297mm;margin:0 auto;background:#fff;}'
            + '.work-list-schedule-scroll{overflow:visible!important;}'
            + '.work-list-schedule-tick{color:#1f2937!important;-webkit-print-color-adjust:exact;print-color-adjust:exact;}'
            + '.work-list-schedule-case-header{color:#4b5563!important;}'
            + '.work-list-schedule-layout,.work-list-schedule-grid{page-break-inside:avoid;}'
            + '.work-list-schedule-footnotes{page-break-before:avoid;}'
            + 'a.work-list-schedule-label{color:#075985;text-decoration:none;}'
            + '@page{size:A4 landscape;margin:12mm 10mm 16mm 10mm;}'
            + '@media print{body{margin:0;padding:0;}.page-container{box-shadow:none;padding:0;max-width:none;}.print-header{position:static!important;display:flex!important;}}'
            + '</style></head><body>'
            + '<div class="print-header">' + safeTitle + '</div>'
            + '<div class="print-footer">' + escapeHtml(footerText) + '</div>'
            + '<div class="page-container">' + content + '</div>'
            + '</body></html>';
    }

    function printSchedule(triggerBtn) {
        var titleEl = document.getElementById('work-list-schedule-modal-title');
        var title = (titleEl && titleEl.textContent) ? titleEl.textContent.trim() : '工程表';
        var footerText = getPrintOfficeName();
        var filename = (title.replace(/[\u0000-\u001F/\\:\uFF1A*?"<>|]/g, '-').trim() || '工程表');

        return fetch(buildScheduleUrl(), {
            credentials: 'same-origin',
            headers: { Accept: 'text/html' }
        })
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('schedule print fetch failed');
                }
                return response.text();
            })
            .then(function (html) {
                var doc = new DOMParser().parseFromString(html, 'text/html');
                var area = doc.querySelector('#work-list-schedule-area');
                var styles = extractScheduleChartStyles();
                var printHTML = createSchedulePrintableHTML(area ? area.innerHTML : '', title, footerText, styles);
                var iframe = document.createElement('iframe');
                iframe.style.cssText = 'position:fixed;right:0;bottom:0;width:1px;height:1px;border:0;visibility:hidden;';
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
                        } catch (e) {}
                        if (triggerBtn && typeof triggerBtn.focus === 'function') {
                            triggerBtn.focus();
                        }
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
                        }, 100);
                    } catch (e) {
                        cleanup();
                    }
                }

                var frameDoc = iframe.contentDocument || iframe.contentWindow.document;
                iframe.onload = function () {
                    try {
                        var d = iframe.contentDocument || iframe.contentWindow.document;
                        if (d) {
                            d.title = filename;
                        }
                    } catch (e) {}
                    triggerPrint();
                };
                frameDoc.open();
                frameDoc.write(printHTML);
                frameDoc.close();
                setTimeout(cleanup, 15000);
            })
            .catch(function () {
                alert(typeof ktpwpTranslate === 'function'
                    ? ktpwpTranslate('工程表を読み込めませんでした。')
                    : '工程表を読み込めませんでした。');
            });
    }

    function initScheduleModalDataset() {
        var body = document.getElementById('work-list-schedule-modal-body');
        var btn = document.getElementById('js-work-list-schedule-btn');
        ensureModalInBody();
        if (!body || !btn) {
            return;
        }
        body.dataset.loadingText = btn.dataset.loadingText || '読み込み中…';
        body.dataset.errorText = btn.dataset.errorText || '工程表を読み込めませんでした。';
    }

    function onDocumentClick(event) {
        var target = event.target;
        if (!(target instanceof Element)) {
            return;
        }

        var openBtn = target.closest('#js-work-list-schedule-btn');
        if (openBtn && !openBtn.disabled) {
            event.preventDefault();
            openModal();
            loadScheduleContent();
            return;
        }

        if (target.closest('.work-list-schedule-close, .work-list-schedule-overlay')) {
            event.preventDefault();
            closeModal();
            return;
        }

        var printBtn = target.closest('#js-work-list-schedule-print-btn');
        if (printBtn && !printBtn.disabled) {
            event.preventDefault();
            printSchedule(printBtn);
        }
    }

    function onDocumentKeydown(event) {
        if (event.key !== 'Escape') {
            return;
        }
        var modal = getModal();
        if (modal && modal.classList.contains('is-open')) {
            closeModal();
        }
    }

    document.addEventListener('click', onDocumentClick);
    document.addEventListener('keydown', onDocumentKeydown);

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initScheduleModalDataset);
    } else {
        initScheduleModalDataset();
    }
})();
