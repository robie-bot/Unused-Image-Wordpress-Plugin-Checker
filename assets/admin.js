(function ($) {
    'use strict';

    var scanBtn      = $('#uif-scan-btn');
    var progress     = $('#uif-progress');
    var progressTxt  = $('#uif-progress-text');
    var progressBar  = $('#uif-progress-bar');
    var progressDtl  = $('#uif-progress-detail');
    var results      = $('#uif-results');
    var tableWrap    = $('#uif-table-wrap');
    var tbody        = $('#uif-tbody');
    var emptyMsg     = $('#uif-empty');
    var deleteBtn    = $('#uif-delete-btn');
    var notice       = $('#uif-notice');
    var selectAll    = $('#uif-select-all, #uif-select-all-top');

    // Accumulated scan data for CSV export.
    var scanData = {
        total_images:  0,
        used_count:    0,
        unused_count:  0,
        unused_images: [],
        total_size:    0
    };

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

    function setProgress(pct, text, detail) {
        progressBar.css('width', pct + '%');
        if (text) progressTxt.text(text);
        if (detail) {
            progressDtl.text(detail).show();
        } else {
            progressDtl.hide();
        }
    }

    // ── Batched scan ───────────────────────────────────────────

    function startScan() {
        scanBtn.prop('disabled', true);
        progress.show();
        results.hide();
        notice.hide();
        tbody.empty();

        // Reset accumulated data.
        scanData = {
            total_images: 0, used_count: 0,
            unused_count: 0, unused_images: [], total_size: 0
        };

        setProgress(0, uif.i18n.scanning, 'Identifying used images across your site...');

        // Step 1: Init — identify unused IDs (heavy part).
        $.post(uif.ajax_url, {
            action: 'uif_scan_init',
            nonce:  uif.nonce
        })
        .done(function (res) {
            if (!res.success) {
                showNotice(res.data || uif.i18n.error, 'error');
                scanDone();
                return;
            }

            scanData.total_images = res.data.total_images;
            scanData.used_count   = res.data.used_count;
            scanData.unused_count = res.data.unused_count;

            if (res.data.unused_count === 0) {
                finishScan();
                return;
            }

            setProgress(10, 'Scan complete. Loading image details...', '0 of ' + res.data.unused_count + ' images loaded');

            // Step 2: Fetch metadata in batches.
            fetchBatch(0, res.data.unused_count);
        })
        .fail(function () {
            showNotice(uif.i18n.error, 'error');
            scanDone();
        });
    }

    function fetchBatch(offset, total) {
        $.post(uif.ajax_url, {
            action:     'uif_scan_batch',
            nonce:      uif.nonce,
            offset:     offset,
            batch_size: uif.batch_size
        })
        .done(function (res) {
            if (!res.success) {
                showNotice(res.data || uif.i18n.error, 'error');
                scanDone();
                return;
            }

            // Accumulate images.
            var images = res.data.images;
            for (var i = 0; i < images.length; i++) {
                scanData.unused_images.push(images[i]);
                scanData.total_size += images[i].filesize;
            }

            // Append rows to table immediately (streaming feel).
            var html = '';
            for (var j = 0; j < images.length; j++) {
                html += buildRow(images[j]);
            }
            tbody.append(html);

            // Update progress.
            var loaded = scanData.unused_images.length;
            var pct    = 10 + Math.round((loaded / total) * 90);
            var detail = loaded + ' of ' + total + ' images loaded (' + formatSize(scanData.total_size) + ' recoverable)';

            setProgress(pct, uif.i18n.building.replace('%d', loaded).replace('%d', total), detail);

            // Update stat cards live.
            $('#uif-total').text(scanData.total_images);
            $('#uif-used').text(scanData.used_count);
            $('#uif-unused').text(loaded);
            $('#uif-size').text(formatSize(scanData.total_size));

            if (res.data.has_more) {
                // Next batch.
                fetchBatch(offset + uif.batch_size, total);
            } else {
                finishScan();
            }
        })
        .fail(function () {
            showNotice(uif.i18n.error + ' Some images may not be listed.', 'warning');
            finishScan();
        });
    }

    function finishScan() {
        // Final stat update.
        $('#uif-total').text(scanData.total_images);
        $('#uif-used').text(scanData.used_count);
        $('#uif-unused').text(scanData.unused_count);
        $('#uif-size').text(formatSize(scanData.total_size));

        if (scanData.unused_count > 0) {
            tableWrap.show();
            emptyMsg.hide();
        } else {
            tableWrap.hide();
            emptyMsg.show();
        }

        results.show();
        setProgress(100, 'Done!', scanData.unused_count + ' unused images found — ' + formatSize(scanData.total_size) + ' recoverable');

        setTimeout(function () {
            progress.slideUp(300);
        }, 1500);

        scanDone();
    }

    function scanDone() {
        scanBtn.prop('disabled', false);
    }

    // ── Scan button ────────────────────────────────────────────

    scanBtn.on('click', startScan);

    // ── Select all ─────────────────────────────────────────────

    $(document).on('change', '#uif-select-all, #uif-select-all-top', function () {
        var checked = $(this).prop('checked');
        selectAll.prop('checked', checked);
        tbody.find('.uif-cb').prop('checked', checked);
        updateSelectedCount();
    });

    $(document).on('change', '.uif-cb', function () {
        updateSelectedCount();
        var total    = tbody.find('.uif-cb').length;
        var selected = tbody.find('.uif-cb:checked').length;
        selectAll.prop('checked', total === selected && total > 0);
    });

    // ── Bulk delete ────────────────────────────────────────────

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
            nonce:  uif.nonce,
            ids:    ids,
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

    // ── Export CSV (client-side, instant) ───────────────────────

    $('#uif-export-csv-btn').on('click', function () {
        if (!scanData.unused_images || scanData.unused_images.length === 0) {
            showNotice('No scan data available. Please run a scan first.', 'warning');
            return;
        }

        var rows = [];
        rows.push(['ID', 'Title', 'Filename', 'URL', 'File Size (bytes)', 'File Size (readable)', 'Upload Date', 'Edit Link']);

        $.each(scanData.unused_images, function (i, img) {
            rows.push([
                img.id,
                img.title,
                img.filename,
                img.url,
                img.filesize,
                formatSize(img.filesize),
                img.date,
                img.edit_link || ''
            ]);
        });

        rows.push([]);
        rows.push(['Summary']);
        rows.push(['Total Images in Library', scanData.total_images]);
        rows.push(['Used Images', scanData.used_count]);
        rows.push(['Unused Images', scanData.unused_count]);
        rows.push(['Total Recoverable Space', formatSize(scanData.total_size)]);

        var csvContent = '\uFEFF';
        $.each(rows, function (i, row) {
            var line = $.map(row, function (cell) {
                var str = String(cell == null ? '' : cell);
                if (str.indexOf(',') !== -1 || str.indexOf('"') !== -1 || str.indexOf('\n') !== -1) {
                    str = '"' + str.replace(/"/g, '""') + '"';
                }
                return str;
            });
            csvContent += line.join(',') + '\r\n';
        });

        var blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
        var url  = URL.createObjectURL(blob);
        var link = document.createElement('a');
        var now  = new Date();
        var ts   = now.getFullYear() + '-' +
                   String(now.getMonth() + 1).padStart(2, '0') + '-' +
                   String(now.getDate()).padStart(2, '0') + '-' +
                   String(now.getHours()).padStart(2, '0') +
                   String(now.getMinutes()).padStart(2, '0') +
                   String(now.getSeconds()).padStart(2, '0');

        link.setAttribute('href', url);
        link.setAttribute('download', 'unused-images-' + ts + '.csv');
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        URL.revokeObjectURL(url);

        showNotice('CSV exported: ' + scanData.unused_images.length + ' images.', 'success');
    });

    // ── Single delete ──────────────────────────────────────────

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
            nonce:  uif.nonce,
            ids:    [id],
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
