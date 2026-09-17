/**
 * Inisialisasi tabel Data Tagihan — script terpisah agar tidak terganggu error JS lain di halaman.
 */
(function (window, $) {
    'use strict';

    function bootDataTagihanTable() {
        if (window.__dataTagihanTableBooted) {
            return;
        }

        const boot = window.DATA_TAGIHAN_BOOT || {};
        const columnUrl = boot.columnUrl || '';
        const dataUrl = boot.dataUrl || '';
        const prefetchedColumns = Array.isArray(boot.prefetchedColumns) ? boot.prefetchedColumns : [];

        if (!dataUrl) {
            console.error('Data Tagihan: dataUrl kosong', boot);
            return;
        }

        if (typeof window.getDT !== 'function') {
            console.error('Data Tagihan: getDT tidak ditemukan');
            if (typeof window.errorAlert === 'function') {
                window.errorAlert('Script tabel gagal dimuat. Tekan Ctrl+F5 untuk muat ulang halaman.');
            }
            return;
        }

        window.__dataTagihanTableBooted = true;

        const dtOptions = {
            tableId: 'main_table',
            formId: 'filter-form',
            columnUrl: columnUrl,
            dataUrl: dataUrl,
            prefetchedColumns: prefetchedColumns,
            dataColumns: [],
            thead: true,
            tfoot: true,
            scrollX: true,
            order: [[2, 'asc']],
            paging: true,
            searching: true,
            fixedHeader: false,
            pageLength: 10,
            lengthMenu: [10, 25, 50, 75, 100],
            select: true,
            rowId: 'AA',
            buttons: ['excel', 'pdf', 'print'],
            excelCurrencyTotal: true,
            pdfOrientation: 'landscape',
            pdfPageSize: 'A3',
            pdfMargins: [10, 14, 10, 14],
            pdfFontSize: 6,
            pdfHeaderFontSize: 7,
        };

        window.dtOptions = window.dtOptions || dtOptions;
        window.getDT(dtOptions);

        $('#main_table').on('init.dt draw.dt select.dt deselect.dt', function () {
            if (typeof window.ensureUrutanToolbarButtons === 'function') {
                window.ensureUrutanToolbarButtons();
            }
            if (typeof window.syncTagihanCheckboxSelection === 'function') {
                window.syncTagihanCheckboxSelection();
            }
            if (typeof window.updateUrutanToolbarState === 'function') {
                window.updateUrutanToolbarState();
            }
        });
        $('#main_table').on('draw.dt', function () {
            if (typeof window.closeAllTransLogRows === 'function') {
                window.closeAllTransLogRows();
            }
        });

        const filterForm = $('#filter-form');
        filterForm.on('submit', function (e) {
            e.preventDefault();
            if (typeof window.dataReFilter === 'function') {
                window.dataReFilter('main_table');
            }
        });
        filterForm.on('reset', function () {
            setTimeout(function () {
                if (typeof window.dataReFilter === 'function') {
                    window.dataReFilter('main_table');
                }
                $('[data-control="select2"]', '#filter-form').trigger('change');
            }, 0);
        });
    }

    function scheduleBoot() {
        if (typeof window.jQuery === 'undefined') {
            setTimeout(scheduleBoot, 50);
            return;
        }
        $(bootDataTagihanTable);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', scheduleBoot);
    } else {
        scheduleBoot();
    }
})(window, window.jQuery);
