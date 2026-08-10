/**
 * 一括請求書プレビュー：宛名ブロック（「請求書在中」含む）をドラッグして
 * 窓付き封筒の窓位置に合わせる。（KantanBiz bulk-invoice-addressee-drag.js 相当）
 * ・明細行など本文の余白・幅には影響しない（宛名ブロックのマージンだけが動く）
 * ・移動量は envelope_top_mm / envelope_left_mm として帳票設定へ保存
 * ・ドラッグ中は封筒窓の 上／左 mm を表示する
 */
(function () {
    /** CSS の mm は常に 96px/25.4 の固定換算（デバイス・ズームに依存しない） */
    const PX_PER_MM = 96 / 25.4;
    const PREVIEW_READY_EVENT = 'ktp-bulk-invoice-preview-ready';

    /** @type {null | {
     *   block: HTMLElement,
     *   container: HTMLElement,
     *   config: object,
     *   startClientX: number,
     *   startClientY: number,
     *   startTopMm: number,
     *   startLeftMm: number,
     * }} */
    let activeDrag = null;
    let saveTimer = 0;
    let globalsBound = false;
    /** @type {HTMLElement | null} */
    let mmReadout = null;

    function clamp(value, min, max) {
        return Math.max(min, Math.min(max, value));
    }

    function readConfig() {
        if (typeof ktpClientInvoice === 'undefined' || !ktpClientInvoice.bulkAddresseeDrag) {
            return null;
        }

        return ktpClientInvoice.bulkAddresseeDrag;
    }

    function findPageRoot() {
        return document.getElementById('invoiceList')
            || document.querySelector('.ktp-bulk-invoice-preview')
            || document.getElementById('ktp-invoice-preview-popup');
    }

    function baseTopMm(config) {
        const value = Number(config.baseTop);
        return Number.isFinite(value) ? value : 6;
    }

    function baseLeftMm(config) {
        const value = Number(config.baseLeft);
        return Number.isFinite(value) ? value : 23;
    }

    function readEnvelopeMm(container, propName, fallback) {
        const raw = getComputedStyle(container).getPropertyValue(propName).trim();
        const parsed = parseFloat(raw.replace('mm', ''));
        return Number.isFinite(parsed) ? parsed : fallback;
    }

    function formatMm(value) {
        const rounded = Math.round(value * 10) / 10;
        return Number.isInteger(rounded) ? String(rounded) : rounded.toFixed(1);
    }

    /**
     * プレビュー用紙（content-area）の左上に mm 表示を載せる。
     *
     * @param {HTMLElement} container
     * @returns {HTMLElement}
     */
    function ensureMmReadout(container) {
        if (mmReadout instanceof HTMLElement && container.contains(mmReadout)) {
            return mmReadout;
        }

        let el = container.querySelector(':scope > .ktp-bulk-invoice-addressee-mm-readout');
        if (!(el instanceof HTMLElement)) {
            el = document.createElement('div');
            el.className = 'ktp-bulk-invoice-addressee-mm-readout ktp-bulk-invoice-no-print';
            el.setAttribute('aria-live', 'polite');
            el.innerHTML =
                '<span class="mm-pair"><span class="mm-label">上</span><span data-mm="top">0</span>mm</span>'
                + '<span class="mm-pair"><span class="mm-label">左</span><span data-mm="left">0</span>mm</span>';
            container.insertBefore(el, container.firstChild);
        }

        mmReadout = el;
        return el;
    }

    /**
     * @param {HTMLElement} container
     * @param {number} topMm
     * @param {number} leftMm
     * @param {boolean} visible
     */
    function updateMmReadout(container, topMm, leftMm, visible) {
        const el = ensureMmReadout(container);
        const topEl = el.querySelector('[data-mm="top"]');
        const leftEl = el.querySelector('[data-mm="left"]');
        if (topEl) {
            topEl.textContent = formatMm(topMm);
        }
        if (leftEl) {
            leftEl.textContent = formatMm(leftMm);
        }
        el.classList.toggle('is-visible', visible);
    }

    function hideMmReadout() {
        if (mmReadout instanceof HTMLElement) {
            mmReadout.classList.remove('is-visible');
        }
    }

    function applyEnvelopePosition(container, topMm, leftMm) {
        container.style.setProperty('--bulk-env-top', `${topMm}mm`);
        container.style.setProperty('--bulk-env-left', `${leftMm}mm`);
    }

    async function saveEnvelopePosition(config, topMm, leftMm) {
        const body = new URLSearchParams();
        body.set('action', config.action);
        body.set('nonce', config.nonce);
        body.set('envelope_top_mm', String(topMm));
        body.set('envelope_left_mm', String(leftMm));

        const response = await fetch(config.ajaxUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                Accept: 'application/json',
            },
            credentials: 'same-origin',
            body: body.toString(),
        });

        if (!response.ok) {
            throw new Error(`addressee position save failed: ${response.status}`);
        }

        const json = await response.json();
        if (!json || !json.success || !json.data) {
            throw new Error('addressee position save failed: invalid response');
        }

        return json.data;
    }

    function scheduleSave(container, config, topMm, leftMm) {
        window.clearTimeout(saveTimer);
        saveTimer = window.setTimeout(async () => {
            const min = Number(config.min) || 0;
            const max = Number(config.max) || 40;
            const roundedTop = clamp(Math.round(topMm), min, max);
            const roundedLeft = clamp(Math.round(leftMm), min, max);

            applyEnvelopePosition(container, roundedTop, roundedLeft);

            try {
                const result = await saveEnvelopePosition(config, roundedTop, roundedLeft);
                if (result
                    && Number.isFinite(Number(result.envelope_top_mm))
                    && Number.isFinite(Number(result.envelope_left_mm))) {
                    applyEnvelopePosition(container, Number(result.envelope_top_mm), Number(result.envelope_left_mm));
                }
            } catch (error) {
                console.warn('[一括請求書] 宛名位置の保存に失敗しました', error);
            }
        }, 250);
    }

    function bindGlobals() {
        if (globalsBound) {
            return;
        }

        globalsBound = true;

        window.addEventListener('pointermove', (event) => {
            if (!activeDrag) {
                return;
            }

            const { container, config, startClientX, startClientY, startTopMm, startLeftMm } = activeDrag;
            const min = Number(config.min) || 0;
            const max = Number(config.max) || 40;
            const deltaXmm = (event.clientX - startClientX) / PX_PER_MM;
            const deltaYmm = (event.clientY - startClientY) / PX_PER_MM;
            const nextTopMm = clamp(startTopMm + deltaYmm, min, max);
            const nextLeftMm = clamp(startLeftMm + deltaXmm, min, max);

            applyEnvelopePosition(container, nextTopMm, nextLeftMm);
            updateMmReadout(container, nextTopMm, nextLeftMm, true);
        });

        const endDrag = () => {
            if (!activeDrag) {
                return;
            }

            const { block, container, config } = activeDrag;
            activeDrag = null;
            block.classList.remove('is-dragging');

            const topMm = readEnvelopeMm(container, '--bulk-env-top', baseTopMm(config));
            const leftMm = readEnvelopeMm(container, '--bulk-env-left', baseLeftMm(config));
            // ドロップ直後は丸め後の値を一瞬見せてから消す
            updateMmReadout(container, Math.round(topMm), Math.round(leftMm), true);
            window.setTimeout(hideMmReadout, 600);
            scheduleSave(container, config, topMm, leftMm);
        };

        window.addEventListener('pointerup', endDrag);
        window.addEventListener('pointercancel', endDrag);
    }

    function init() {
        const config = readConfig();
        if (!config || !config.enabled || !config.ajaxUrl || !config.nonce || !config.action) {
            return;
        }

        const pageRoot = findPageRoot();
        if (!(pageRoot instanceof HTMLElement)) {
            return;
        }

        const block = pageRoot.querySelector('.ktp-bulk-invoice-address-block[data-ktp-bulk-addressee-drag="1"]');
        if (!(block instanceof HTMLElement) || block.dataset.ktpBulkAddresseeDragBound === '1') {
            return;
        }

        const container = block.closest('.ktp-bulk-invoice-content-area');
        if (!(container instanceof HTMLElement)) {
            return;
        }

        block.dataset.ktpBulkAddresseeDragBound = '1';
        bindGlobals();

        block.addEventListener('pointerdown', (event) => {
            if (event.button !== undefined && event.button !== 0) {
                return;
            }

            event.preventDefault();
            event.stopPropagation();

            block.classList.add('is-dragging');

            const startTopMm = readEnvelopeMm(container, '--bulk-env-top', baseTopMm(config));
            const startLeftMm = readEnvelopeMm(container, '--bulk-env-left', baseLeftMm(config));

            activeDrag = {
                block,
                container,
                config,
                startClientX: event.clientX,
                startClientY: event.clientY,
                startTopMm,
                startLeftMm,
            };

            updateMmReadout(container, startTopMm, startLeftMm, true);
            block.setPointerCapture?.(event.pointerId);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init, { once: true });
    } else {
        init();
    }

    document.addEventListener(PREVIEW_READY_EVENT, init);
})();
