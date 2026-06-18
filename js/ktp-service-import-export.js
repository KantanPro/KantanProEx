(function ($) {
    'use strict';

    var titles = {
        export: 'サービス エクスポート',
        import: 'サービス インポート'
    };

    function getModal() {
        return $('#ktp-service-import-export-modal');
    }

    function ensureModalOnBody() {
        var $modal = getModal();
        if (!$modal.length) {
            return;
        }
        if (!$modal.data('ktp-mounted-body')) {
            $modal.appendTo(document.body);
            $modal.data('ktp-mounted-body', true);
        }
    }

    function showMode(mode) {
        var $exportForm = $('#ktp-service-export-form');
        var $importForm = $('#ktp-service-import-form');
        if (mode === 'import') {
            $exportForm.hide();
            $importForm.show();
            $('#ktp-service-ie-modal-title').text(titles.import);
            updateFormatFields('import');
        } else {
            $importForm.hide();
            $exportForm.show();
            $('#ktp-service-ie-modal-title').text(titles.export);
            updateFormatFields('export');
        }
    }

    function openModal(mode) {
        ensureModalOnBody();
        var $modal = getModal();
        if (!$modal.length) {
            return;
        }
        showMode(mode === 'import' ? 'import' : 'export');
        $modal.show().attr('aria-hidden', 'false');
        $('body').addClass('ktpwp-modal-open');
    }

    function closeModal() {
        var $modal = getModal();
        $modal.hide().attr('aria-hidden', 'true');
        $('body').removeClass('ktpwp-modal-open');
    }

    function updateFormatFields(mode) {
        var prefix = mode === 'import' ? '#ktp-service-import-form' : '#ktp-service-export-form';
        var format = $(prefix + ' input[name="format"]:checked').val();
        if (mode === 'import') {
            if (format === 'google_sheets') {
                $(prefix + ' .ktp-service-ie-file-field').hide();
                $(prefix + ' .ktp-service-ie-google-url-field').show();
            } else {
                $(prefix + ' .ktp-service-ie-file-field').show();
                $(prefix + ' .ktp-service-ie-google-url-field').hide();
            }
            updateImportImageFields();
        } else if (format === 'google_sheets') {
            $(prefix + ' .ktp-service-ie-google-export-note').show();
        } else {
            $(prefix + ' .ktp-service-ie-google-export-note').hide();
        }
    }

    function updateImportImageFields() {
        var $form = $('#ktp-service-import-form');
        var importImages = $form.find('.ktp-service-ie-import-images-toggle').is(':checked');
        var $policy = $('#ktp-service-existing-image-policy');
        var $importOption = $policy.find('option[value="import"]');
        if (importImages) {
            $importOption.prop('disabled', false).show();
        } else {
            $importOption.prop('disabled', true).hide();
            if ($policy.val() === 'import') {
                $policy.val('keep');
            }
        }
    }

    $(document).on('click', '.ktp-service-export-btn', function (e) {
        e.preventDefault();
        openModal('export');
    });

    $(document).on('click', '.ktp-service-import-btn', function (e) {
        e.preventDefault();
        openModal('import');
    });

    $(document).on('click', '.ktp-service-ie-close, #ktp-service-import-export-modal .ktpwp-modal-close', function (e) {
        e.preventDefault();
        closeModal();
    });

    $(document).on('click', '#ktp-service-import-export-modal .ktpwp-modal-overlay', function (e) {
        if (e.target === this) {
            closeModal();
        }
    });

    $(document).on('change', '#ktp-service-export-form input[name="format"]', function () {
        updateFormatFields('export');
    });

    $(document).on('change', '#ktp-service-import-form input[name="format"]', function () {
        updateFormatFields('import');
    });

    $(document).on('change', '#ktp-service-import-form .ktp-service-ie-import-images-toggle', function () {
        updateImportImageFields();
    });

    $(document).on('keydown', function (e) {
        if (e.key === 'Escape' && getModal().is(':visible')) {
            closeModal();
        }
    });
})(jQuery);
