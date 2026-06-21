(function () {
	'use strict';

	var BOUND_FLAG = '__ktpOrderDeleteConfirmV2';

	if (window[BOUND_FLAG]) {
		return;
	}

	function translateMessage(message) {
		if (typeof window.ktpwpTranslate === 'function') {
			return window.ktpwpTranslate(String(message));
		}
		return String(message);
	}

	function getOrderDeleteConfirmMessage(form) {
		if (form) {
			var hidden = form.querySelector('input.ktp-order-delete-message');
			if (hidden && hidden.value) {
				return hidden.value;
			}
		}
		if (typeof window.ktpOrderDeleteConfirm !== 'undefined' && window.ktpOrderDeleteConfirm.message) {
			return window.ktpOrderDeleteConfirm.message;
		}
		return '本当にこの受注書を削除しますか？\n\n請求明細・原価明細・スタッフチャット・添付ファイル・メール送信履歴も削除されます。\nこの操作は元に戻せません。';
	}

	function confirmOrderDelete(form) {
		var message = translateMessage(getOrderDeleteConfirmMessage(form));
		if (!message) {
			return false;
		}
		return window.confirm(message);
	}

	function handleDeleteSubmit(event) {
		var form = event.target;
		if (!form || !form.classList || !form.classList.contains('ktp-order-delete-form')) {
			return;
		}

		var confirmedInput = form.querySelector('input[name="delete_confirmed"]');
		if (confirmedInput && confirmedInput.value === '1') {
			return;
		}

		event.preventDefault();
		if (typeof event.stopImmediatePropagation === 'function') {
			event.stopImmediatePropagation();
		} else {
			event.stopPropagation();
		}

		if (!confirmOrderDelete(form)) {
			return;
		}

		if (confirmedInput) {
			confirmedInput.value = '1';
		}

		HTMLFormElement.prototype.submit.call(form);
	}

	document.addEventListener('submit', handleDeleteSubmit, true);
	window[BOUND_FLAG] = true;
})();
