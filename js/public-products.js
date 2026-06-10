(function () {
	'use strict';

	if (typeof window.ktpwpPublicProducts === 'undefined') {
		return;
	}

	var config = window.ktpwpPublicProducts;
	var i18n = config.i18n || {};
	var openModalCount = 0;

	function qs(root, selector) {
		return (root || document).querySelector(selector);
	}

	function qsa(root, selector) {
		return Array.prototype.slice.call((root || document).querySelectorAll(selector));
	}

	function parseProduct(el) {
		var raw = el.getAttribute('data-product');
		if (!raw) {
			return null;
		}
		try {
			return JSON.parse(raw);
		} catch (e) {
			return null;
		}
	}

	function escapeHtml(text) {
		var div = document.createElement('div');
		div.textContent = text == null ? '' : String(text);
		return div.innerHTML;
	}

	function lockBodyScroll() {
		openModalCount += 1;
		if (openModalCount === 1) {
			document.body.classList.add('ktpwp-public-product-modal-open');
		}
	}

	function unlockBodyScroll() {
		openModalCount = Math.max(0, openModalCount - 1);
		if (openModalCount === 0) {
			document.body.classList.remove('ktpwp-public-product-modal-open');
		}
	}

	function buildDetailHtml(product) {
		var parts = [];
		parts.push('<div class="ktpwp-public-product-detail__hero">');
		if (product.image) {
			parts.push(
				'<img src="' +
					escapeHtml(product.image) +
					'" alt="' +
					escapeHtml(product.name) +
					'" class="ktpwp-public-product-detail__image" loading="lazy" decoding="async" />'
			);
		}
		parts.push('<div class="ktpwp-public-product-detail__meta">');
		parts.push('<h3 class="ktpwp-public-product-detail__name">' + escapeHtml(product.name) + '</h3>');
		if (product.category) {
			parts.push(
				'<p class="ktpwp-public-product-detail__category">' +
					escapeHtml(i18n.category || 'カテゴリ') +
					': ' +
					escapeHtml(product.category) +
					'</p>'
			);
		}
		if (product.price_display) {
			parts.push(
				'<p class="ktpwp-public-product-detail__price">' +
					escapeHtml(i18n.price || '単価') +
					': ' +
					escapeHtml(product.price_display) +
					(product.unit ? ' / ' + escapeHtml(product.unit) : '') +
					'</p>'
			);
		}
		if (product.tax_rate !== '' && product.tax_rate != null) {
			parts.push(
				'<p class="ktpwp-public-product-detail__tax">' +
					escapeHtml(i18n.tax || '税率') +
					': ' +
					escapeHtml(product.tax_rate) +
					'%</p>'
			);
		}
		parts.push('</div></div>');
		return parts.join('');
	}

	function initCategoryFilter(wrapper) {
		var input = qs(wrapper, '.ktpwp-public-products-filter__input');
		if (!input) {
			return;
		}

		var clearBtn = qs(wrapper, '.ktpwp-public-products-filter__clear');
		var emptyMsg = qs(wrapper, '.ktpwp-public-products-filter__empty');
		var items = qsa(wrapper, '.ktpwp-public-product-item');

		function applyFilter() {
			var term = (input.value || '').trim().toLowerCase();
			var visible = 0;

			items.forEach(function (item) {
				var cat = (item.getAttribute('data-category') || '').trim().toLowerCase();
				var show = !term || cat === term || cat.indexOf(term) !== -1;
				item.hidden = !show;
				if (show) {
					visible += 1;
				}
			});

			if (emptyMsg) {
				emptyMsg.hidden = visible > 0;
			}
		}

		input.addEventListener('input', applyFilter);
		input.addEventListener('change', applyFilter);

		if (clearBtn) {
			clearBtn.addEventListener('click', function () {
				input.value = '';
				applyFilter();
				input.focus();
			});
		}

		applyFilter();
	}

	function initWrapper(wrapper) {
		initCategoryFilter(wrapper);
		var detail = qs(wrapper, '#ktpwp-public-product-detail');
		if (!detail) {
			return;
		}

		if (detail.parentNode !== document.body) {
			document.body.appendChild(detail);
		}

		var content = qs(detail, '.ktpwp-public-product-detail__content');
		var form = qs(detail, '.ktpwp-public-product-order-form');
		var closeBtn = qs(detail, '.ktpwp-public-product-detail__close');
		var backdrop = qs(detail, '.ktpwp-public-product-detail__backdrop');
		var dialog = qs(detail, '.ktpwp-public-product-detail__panel');
		var messageBox = qs(form, '.ktpwp-public-product-order-form__message');
		var submitBtn = qs(form, '.ktpwp-public-product-order-form__submit');
		var serviceIdInput = qs(form, 'input[name="service_id"]');
		var lastActiveElement = null;

		function onEscapeKey(event) {
			if (event.key === 'Escape') {
				closeDetail();
			}
		}

		function setActiveItem(el) {
			qsa(wrapper, '.ktpwp-public-product-item.is-active').forEach(function (node) {
				node.classList.remove('is-active');
			});
			if (el) {
				el.classList.add('is-active');
			}
		}

		function hideMessage() {
			if (!messageBox) {
				return;
			}
			messageBox.hidden = true;
			messageBox.textContent = '';
			messageBox.className = 'ktpwp-public-product-order-form__message';
		}

		function showMessage(text, type) {
			if (!messageBox) {
				return;
			}
			messageBox.hidden = false;
			messageBox.textContent = text;
			messageBox.className =
				'ktpwp-public-product-order-form__message ktpwp-public-product-order-form__message--' + (type || 'info');
		}

		function openDetail(el) {
			var product = parseProduct(el);
			if (!product || !product.id) {
				return;
			}

			lastActiveElement = document.activeElement;

			hideMessage();
			if (form) {
				form.reset();
				if (serviceIdInput) {
					serviceIdInput.value = String(product.id);
				}
				var qty = qs(form, 'input[name="quantity"]');
				if (qty) {
					qty.value = '1';
				}
				form.hidden = false;
			}

			if (content) {
				content.innerHTML = buildDetailHtml(product);
			}

			detail.hidden = false;
			detail.classList.add('is-open');
			lockBodyScroll();
			setActiveItem(el);

			document.addEventListener('keydown', onEscapeKey);

			window.requestAnimationFrame(function () {
				if (closeBtn) {
					closeBtn.focus();
				}
			});
		}

		function closeDetail() {
			if (detail.hidden) {
				return;
			}

			detail.hidden = true;
			detail.classList.remove('is-open');
			unlockBodyScroll();
			setActiveItem(null);
			hideMessage();
			document.removeEventListener('keydown', onEscapeKey);

			if (lastActiveElement && typeof lastActiveElement.focus === 'function') {
				lastActiveElement.focus();
			}
		}

		qsa(wrapper, '.ktpwp-public-product-item').forEach(function (item) {
			item.addEventListener('click', function () {
				openDetail(item);
			});
			item.addEventListener('keydown', function (event) {
				if (event.key === 'Enter' || event.key === ' ') {
					event.preventDefault();
					openDetail(item);
				}
			});
		});

		if (closeBtn) {
			closeBtn.addEventListener('click', closeDetail);
		}

		if (backdrop) {
			backdrop.addEventListener('click', closeDetail);
		}

		if (dialog) {
			dialog.addEventListener('click', function (event) {
				event.stopPropagation();
			});
		}

		if (form) {
			form.addEventListener('submit', function (event) {
				event.preventDefault();
				hideMessage();

				if (!serviceIdInput || !serviceIdInput.value) {
					showMessage(i18n.networkError || 'エラー', 'error');
					return;
				}

				var formData = new FormData(form);
				formData.append('action', 'ktpwp_public_product_submit');
				formData.append('nonce', config.nonce);

				if (submitBtn) {
					submitBtn.disabled = true;
					submitBtn.textContent = i18n.submitting || '送信中…';
				}

				fetch(config.ajaxUrl, {
					method: 'POST',
					body: formData,
					credentials: 'same-origin',
				})
					.then(function (response) {
						return response.json();
					})
					.then(function (json) {
						if (json && json.success) {
							showMessage((json.data && json.data.message) || i18n.submit || '送信しました', 'success');
							form.hidden = true;
						} else {
							var errMsg =
								(json && json.data && json.data.message) ||
								i18n.networkError ||
								'送信に失敗しました';
							showMessage(errMsg, 'error');
						}
					})
					.catch(function () {
						showMessage(i18n.networkError || '通信エラー', 'error');
					})
					.finally(function () {
						if (submitBtn) {
							submitBtn.disabled = false;
							submitBtn.textContent = i18n.submit || '送信する';
						}
					});
			});
		}
	}

	function boot() {
		qsa(document, '.ktpwp-public-products').forEach(initWrapper);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}
})();
