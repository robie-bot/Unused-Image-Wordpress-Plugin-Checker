(function ($) {
    'use strict';

    var scanBtn     = $('#uif-scan-btn');
    var progress    = $('#uif-progress');
    var progressTxt = $('#uif-progress-text');
    var results     = $('#uif-results');
    var tableWrap   = $('#uif-table-wrap');
    var tbody       = $('#uif-tbody');
    var emptyMsg    = $('#uif-empty');
    var deleteBtn   = $('#uif-delete-btn');
    var notice      = $('#uif-notice');
    var selectAll   = $('#uif-select-all, #uif-select-all-top');

    function formatSize(bytes) {
        if (bytes === 0) return '0 B';
        var units = ['B', 'KB', 'MB', 'GB'];
        var i = Math.floor(Math.log(bytes) / Math.log(1024));
        return (bytes / Math.pow(1024, i)).toFixed(i > 0 ? 1 : 0) + ' ' + units[i];
    }

    function showNotice(message, type) {
        notice
            .removeClass('notice-success notice-error notice-warning')
            .addClass('notice-' + type)
            .empty()
            .append($('<p>').text(message))
            .show();

        setTimeout(function () {
            notice.fadeOut();
        }, 5000);
    }

    function updateSelectedCount() {
        var count = tbody.find('.uif-cb:checked').length;
        $('#uif-selected-count').text(count > 0 ? count + ' selected' : '');
        deleteBtn.prop('disabled', count === 0);
    }

    function escAttr(str) {
        return $('<span>').text(str).html().replace(/"/g, '&quot;');
    }

    function buildRow(img) {
        var safeUrl      = escAttr(img.url);
        var safeTitle    = $('<span>').text(img.title || img.filename).html();
        var safeFilename = $('<span>').text(img.filename).html();
        var safeDate     = $('<span>').text(img.date).html();
        var editLink     = img.edit_link ? escAttr(img.edit_link) : '';

        return '<tr data-id="' + parseInt(img.id, 10) + '">' +
            '<td><input type="checkbox" class="uif-cb" value="' + parseInt(img.id, 10) + '" /></td>' +
            '<td><img class="uif-thumb" src="' + safeUrl + '" alt="" loading="lazy" /></td>' +
            '<td>' +
                '<strong>' + safeTitle + '</strong>' +
                '<span class="uif-filename">' + safeFilename + '</span>' +
            '</td>' +
            '<td>' + formatSize(img.filesize) + '</td>' +
            '<td>' + safeDate + '</td>' +
            '<td>' +
                '<div class="row-actions">' +
                    '<span class="view"><a href="' + safeUrl + '" target="_blank" rel="noopener">View</a> | </span>' +
                    (editLink ? '<span class="edit"><a href="' + editLink + '" target="_blank" rel="noopener">Edit</a> | </span>' : '') +
                    '<span class="delete"><a class="uif-delete-single" data-id="' + parseInt(img.id, 10) + '">Delete</a></span>' +
                '</div>' +
            '</td>' +
            '</tr>';
    }

    // Scan.
    scanBtn.on('click', function () {
        scanBtn.prop('disabled', true);
        progress.show();
        results.hide();
        notice.hide();
        tbody.empty();
        progressTxt.text(uif.i18n.scanning);

        $.post(uif.ajax_url, {
            action: 'uif_scan',
            nonce: uif.nonce,
        })
        .done(function (res) {
            if (!res.success) {
                showNotice(uif.i18n.error, 'error');
                return;
            }

            var data = res.data;

            $('#uif-total').text(data.total_images);
            $('#uif-used').text(data.used_count);
            $('#uif-unused').text(data.unused_count);
            $('#uif-size').text(formatSize(data.total_size));

            if (data.unused_count > 0) {
                var html = '';
                $.each(data.unused_images, function (i, img) {
                    html += buildRow(img);
                });
                tbody.html(html);
                tableWrap.show();
                emptyMsg.hide();
            } else {
                tableWrap.hide();
                emptyMsg.show();
            }

            results.show();
        })
        .fail(function () {
            showNotice(uif.i18n.error, 'error');
        })
        .always(function () {
            progress.hide();
            scanBtn.prop('disabled', false);
        });
    });

    // Select all.
    $(document).on('change', '#uif-select-all, #uif-select-all-top', function () {
        var checked = $(this).prop('checked');
        selectAll.prop('checked', checked);
        tbody.find('.uif-cb').prop('checked', checked);
        updateSelectedCount();
    });

    $(document).on('change', '.uif-cb', function () {
        updateSelectedCount();
        var total = tbody.find('.uif-cb').length;
        var selected = tbody.find('.uif-cb:checked').length;
        selectAll.prop('checked', total === selected && total > 0);
    });

    // Bulk delete.
    deleteBtn.on('click', function () {
        var ids = [];
        tbody.find('.uif-cb:checked').each(function () {
            ids.push($(this).val());
        });

        if (ids.length === 0) {
            showNotice(uif.i18n.no_selection, 'warning');
            return;
        }

        if (!confirm(uif.i18n.confirm)) {
            return;
        }

        deleteBtn.prop('disabled', true).text(uif.i18n.deleting);

        $.post(uif.ajax_url, {
            action: 'uif_delete',
            nonce: uif.nonce,
            ids: ids,
        })
        .done(function (res) {
            if (res.success) {
                $.each(ids, function (i, id) {
                    tbody.find('tr[data-id="' + id + '"]').fadeOut(300, function () {
                        $(this).remove();
                        updateSelectedCount();
                        var remaining = tbody.find('tr').length;
                        $('#uif-unused').text(remaining);
                        if (remaining === 0) {
                            tableWrap.hide();
                            emptyMsg.show();
                        }
                    });
                });
                showNotice(res.data.deleted + ' ' + uif.i18n.deleted, 'success');
            } else {
                showNotice(uif.i18n.error, 'error');
            }
        })
        .fail(function () {
            showNotice(uif.i18n.error, 'error');
        })
        .always(function () {
            deleteBtn.prop('disabled', false).text('Delete Selected');
        });
    });

    // Export CSV.
    $('#uif-export-csv-btn').on('click', function () {
        window.location.href = uif.csv_url;
    });

    // Single delete.
    $(document).on('click', '.uif-delete-single', function (e) {
        e.preventDefault();
        var id  = $(this).data('id');
        var row = tbody.find('tr[data-id="' + id + '"]');

        if (!confirm(uif.i18n.confirm)) {
            return;
        }

        row.addClass('uif-row-deleting');

        $.post(uif.ajax_url, {
            action: 'uif_delete',
            nonce: uif.nonce,
            ids: [id],
        })
        .done(function (res) {
            if (res.success) {
                row.fadeOut(300, function () {
                    $(this).remove();
                    updateSelectedCount();
                    var remaining = tbody.find('tr').length;
                    $('#uif-unused').text(remaining);
                    if (remaining === 0) {
                        tableWrap.hide();
                        emptyMsg.show();
                    }
                });
            } else {
                row.removeClass('uif-row-deleting');
                showNotice(uif.i18n.error, 'error');
            }
        })
        .fail(function () {
            row.removeClass('uif-row-deleting');
            showNotice(uif.i18n.error, 'error');
        });
    });

})(jQuery);
