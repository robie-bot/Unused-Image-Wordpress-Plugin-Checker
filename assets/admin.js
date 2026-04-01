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

    // ── State ──────────────────────────────────────────────────
    var scanData = {
        total_images:  0,
        used_count:    0,
        unused_count:  0,
        unused_images: [],
        total_size:    0
    };

    var currentPage   = 1;
    var perPage        = uif.per_page || 50;
    var selectedIds    = {};          // { id: true } across ALL pages
    var allPagesSelected = false;     // "Select All Pages" flag
    var isDryRun       = $('#uif-dry-run').is(':checked');

    // ── Dry Run (Safe Mode) ────────────────────────────────────

    function applyDryRunState() {
        isDryRun = $('#uif-dry-run').is(':checked');

        if (isDryRun) {
            $('#uif-dry-run-banner').show();
            deleteBtn.prop('disabled', true).addClass('uif-disabled-dry-run');
            tbody.find('.uif-delete-single').addClass('uif-disabled-dry-run');
            $('#uif-select-all, #uif-select-all-top, #uif-select-all-pages').prop('disabled', true);
            tbody.find('.uif-cb').prop('disabled', true);
        } else {
            $('#uif-dry-run-banner').hide();
            deleteBtn.removeClass('uif-disabled-dry-run');
            tbody.find('.uif-delete-single').removeClass('uif-disabled-dry-run');
            $('#uif-select-all, #uif-select-all-top, #uif-select-all-pages').prop('disabled', false);
            tbody.find('.uif-cb').prop('disabled', false);
            updateSelectedUI();
        }
    }

    $(document).on('change', '#uif-dry-run', function () {
        if (!$(this).is(':checked')) {
            if (!confirm('Are you sure you want to disable Safe Mode? This will allow permanent deletion of images.')) {
                $(this).prop('checked', true);
                return;
            }
        }
        // Clear selections when toggling.
        selectedIds = {};
        allPagesSelected = false;
        applyDryRunState();
    });

    // Initial state.
    applyDryRunState();

    // ── Helpers ────────────────────────────────────────────────

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
        setTimeout(function () { notice.fadeOut(); }, 5000);
    }

    function escAttr(str) {
        return $('<span>').text(str).html().replace(/"/g, '&quot;');
    }

    function totalSelectedCount() {
        if (allPagesSelected) return scanData.unused_images.length;
        var count = 0;
        for (var k in selectedIds) {
            if (selectedIds.hasOwnProperty(k) && selectedIds[k]) count++;
        }
        return count;
    }

    function updateSelectedUI() {
        var count = totalSelectedCount();
        $('#uif-selected-count').text(count > 0 ? count + ' selected' : '');
        deleteBtn.prop('disabled', count === 0);

        // Sync page checkboxes with selectedIds.
        tbody.find('.uif-cb').each(function () {
            var id = String($(this).val());
            $(this).prop('checked', !!selectedIds[id] || allPagesSelected);
        });

        // Update "Select All (this page)" checkbox.
        var pageImages = getPageImages();
        var allPageChecked = pageImages.length > 0;
        for (var i = 0; i < pageImages.length; i++) {
            if (!selectedIds[String(pageImages[i].id)] && !allPagesSelected) {
                allPageChecked = false;
                break;
            }
        }
        $('#uif-select-all, #uif-select-all-top').prop('checked', allPageChecked);
        $('#uif-select-all-pages').prop('checked', allPagesSelected);
    }

    // ── Pagination ─────────────────────────────────────────────

    function totalPages() {
        return Math.max(1, Math.ceil(scanData.unused_images.length / perPage));
    }

    function getPageImages() {
        var start = (currentPage - 1) * perPage;
        return scanData.unused_images.slice(start, start + perPage);
    }

    function renderPage() {
        tbody.empty();
        var images = getPageImages();
        var html = '';
        for (var i = 0; i < images.length; i++) {
            html += buildRow(images[i]);
        }
        tbody.html(html);
        renderPagination();
        updateSelectedUI();
        applyDryRunState();

        // Scroll to top of table.
        if (tableWrap.length) {
            $('html, body').animate({ scrollTop: tableWrap.offset().top - 50 }, 200);
        }
    }

    function renderPagination() {
        var total = totalPages();
        var showing = getPageImages().length;
        var totalImgs = scanData.unused_images.length;
        var start = (currentPage - 1) * perPage + 1;
        var end   = Math.min(currentPage * perPage, totalImgs);

        if (total <= 1) {
            $('#uif-pagination-top, #uif-pagination-bottom').empty();
            return;
        }

        var html = '<div class="tablenav"><div class="tablenav-pages">';
        html += '<span class="displaying-num">' + totalImgs + ' items</span>';
        html += '<span class="pagination-links">';

        // First.
        if (currentPage > 1) {
            html += '<a class="first-page button uif-page-btn" data-page="1" title="First page">&laquo;</a> ';
            html += '<a class="prev-page button uif-page-btn" data-page="' + (currentPage - 1) + '" title="Previous page">&lsaquo;</a> ';
        } else {
            html += '<span class="tablenav-pages-navspan button disabled">&laquo;</span> ';
            html += '<span class="tablenav-pages-navspan button disabled">&lsaquo;</span> ';
        }

        // Current / Total.
        html += '<span class="paging-input">';
        html += '<input class="current-page" id="uif-current-page" type="text" size="2" value="' + currentPage + '" />';
        html += ' of <span class="total-pages">' + total + '</span>';
        html += '</span> ';

        // Next / Last.
        if (currentPage < total) {
            html += '<a class="next-page button uif-page-btn" data-page="' + (currentPage + 1) + '" title="Next page">&rsaquo;</a> ';
            html += '<a class="last-page button uif-page-btn" data-page="' + total + '" title="Last page">&raquo;</a>';
        } else {
            html += '<span class="tablenav-pages-navspan button disabled">&rsaquo;</span> ';
            html += '<span class="tablenav-pages-navspan button disabled">&raquo;</span>';
        }

        html += '</span>';
        html += '<span class="uif-page-range">Showing ' + start + '–' + end + ' of ' + totalImgs + '</span>';
        html += '</div></div>';

        $('#uif-pagination-top').html(html);
        $('#uif-pagination-bottom').html(html);
    }

    // Page navigation events.
    $(document).on('click', '.uif-page-btn', function (e) {
        e.preventDefault();
        var page = parseInt($(this).data('page'), 10);
        if (page >= 1 && page <= totalPages()) {
            currentPage = page;
            renderPage();
        }
    });

    $(document).on('keypress', '#uif-current-page', function (e) {
        if (e.which === 13) {
            e.preventDefault();
            var page = parseInt($(this).val(), 10);
            if (page >= 1 && page <= totalPages()) {
                currentPage = page;
                renderPage();
            } else {
                $(this).val(currentPage);
            }
        }
    });

    // ── Row builder ────────────────────────────────────────────

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

    // ── Progress ───────────────────────────────────────────────

    function setProgress(pct, text, detail) {
        if (pct !== null && pct !== undefined) progressBar.css('width', pct + '%');
        if (text) progressTxt.text(text);
        if (detail) {
            progressDtl.text(detail).show();
        } else if (detail === '') {
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
        currentPage = 1;
        selectedIds = {};
        allPagesSelected = false;

        scanData = {
            total_images: 0, used_count: 0,
            unused_count: 0, unused_images: [], total_size: 0
        };

        setProgress(0, uif.i18n.scanning, 'Preparing scan...');

        // Step 1: Init — get image count and phase info.
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
            var totalPhases = res.data.total_phases;
            var phaseLabels = res.data.phase_labels;

            setProgress(5, 'Scanning...', 'Phase 1 of ' + totalPhases + ': ' + phaseLabels[0]);

            // Step 2: Run each phase sequentially.
            runPhase(0, totalPhases, phaseLabels);
        })
        .fail(function () {
            showNotice(uif.i18n.error, 'error');
            scanDone();
        });
    }

    function runPhase(phaseIndex, totalPhases, phaseLabels, retries) {
        if (typeof retries === 'undefined') retries = 0;
        var maxRetries = 3;

        if (phaseIndex >= totalPhases) {
            // All phases done — finalize.
            finalizeScan();
            return;
        }

        var phasePct = 5 + Math.round(((phaseIndex + 1) / totalPhases) * 55); // 5-60% for phases

        $.post(uif.ajax_url, {
            action: 'uif_scan_phase',
            nonce:  uif.nonce,
            phase:  phaseIndex
        })
        .done(function (res) {
            if (!res.success) {
                if (retries < maxRetries) {
                    setProgress(null, null, 'Phase ' + (phaseIndex + 1) + ' failed, retrying... (' + (retries + 1) + '/' + maxRetries + ')');
                    setTimeout(function () { runPhase(phaseIndex, totalPhases, phaseLabels, retries + 1); }, 2000);
                    return;
                }
                // Skip this phase and continue — don't abort the whole scan.
                showNotice('Phase ' + (phaseIndex + 1) + ' (' + phaseLabels[phaseIndex] + ') skipped after retries. Scan continues.', 'warning');
            }

            var nextPhase = phaseIndex + 1;
            var usedSoFar = (res.success && res.data) ? res.data.total_used : '?';
            var detail = 'Phase ' + (phaseIndex + 1) + ' of ' + totalPhases + ' complete — ' + usedSoFar + ' used images found so far';
            setProgress(phasePct, 'Scanning...', detail);

            if (nextPhase < totalPhases) {
                setProgress(null, null, 'Phase ' + (nextPhase + 1) + ' of ' + totalPhases + ': ' + phaseLabels[nextPhase]);
            }

            runPhase(nextPhase, totalPhases, phaseLabels, 0);
        })
        .fail(function (jqXHR, textStatus) {
            if (retries < maxRetries) {
                setProgress(null, null, 'Phase ' + (phaseIndex + 1) + ' failed (' + textStatus + '), retrying in 3s... (' + (retries + 1) + '/' + maxRetries + ')');
                setTimeout(function () { runPhase(phaseIndex, totalPhases, phaseLabels, retries + 1); }, 3000);
                return;
            }
            // Skip this phase and continue — don't abort the whole scan.
            showNotice('Phase ' + (phaseIndex + 1) + ' (' + phaseLabels[phaseIndex] + ') timed out and was skipped. Results may show more unused images than expected.', 'warning');
            var nextPhase = phaseIndex + 1;
            setProgress(phasePct, 'Scanning...', 'Phase ' + (phaseIndex + 1) + ' skipped, continuing...');
            if (nextPhase < totalPhases) {
                setProgress(null, null, 'Phase ' + (nextPhase + 1) + ' of ' + totalPhases + ': ' + phaseLabels[nextPhase]);
            }
            runPhase(nextPhase, totalPhases, phaseLabels, 0);
        });
    }

    function finalizeScan() {
        setProgress(60, 'Finalizing scan...', 'Computing unused images...');

        $.post(uif.ajax_url, {
            action: 'uif_scan_finalize',
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

            setProgress(65, 'Loading image details...', '0 of ' + res.data.unused_count + ' images loaded');
            fetchBatch(0, res.data.unused_count);
        })
        .fail(function () {
            showNotice(uif.i18n.error, 'error');
            scanDone();
        });
    }

    function fetchBatch(offset, total, retries) {
        if (typeof retries === 'undefined') retries = 0;
        var maxRetries = 5;
        var batchSize = uif.batch_size;

        // If we've gone past the total, we're done.
        if (offset >= total) {
            finishScan();
            return;
        }

        var loaded = scanData.unused_images.length;
        setProgress(null, null, loaded + ' of ' + total + ' images loaded... (requesting batch at ' + offset + ')');

        $.ajax({
            url: uif.ajax_url,
            type: 'POST',
            timeout: 30000,  // 30 second timeout per batch request
            data: {
                action:     'uif_scan_batch',
                nonce:      uif.nonce,
                offset:     offset,
                batch_size: batchSize
            }
        })
        .done(function (res) {
            if (!res.success) {
                if (retries < maxRetries) {
                    var waitTime = 2000 + (retries * 1000); // Increasing delay: 2s, 3s, 4s, 5s, 6s
                    setProgress(null, null, 'Batch at offset ' + offset + ' failed, retrying in ' + (waitTime/1000) + 's... (' + (retries + 1) + '/' + maxRetries + ')');
                    setTimeout(function () {
                        fetchBatch(offset, total, retries + 1);
                    }, waitTime);
                    return;
                }
                // Skip this batch and continue to the next one after a delay.
                setProgress(null, null, 'Skipped batch at offset ' + offset + ', continuing...');
                setTimeout(function () {
                    fetchBatch(offset + batchSize, total, 0);
                }, 2000);
                return;
            }

            var images = res.data.images;
            for (var i = 0; i < images.length; i++) {
                scanData.unused_images.push(images[i]);
                scanData.total_size += images[i].filesize;
            }

            var loaded = scanData.unused_images.length;
            var pct    = 65 + Math.round((loaded / total) * 35);
            var detail = loaded + ' of ' + total + ' images loaded (' + formatSize(scanData.total_size) + ' recoverable)';

            setProgress(pct, uif.i18n.building.replace('%d', loaded).replace('%d', total), detail);

            // Update stat cards live.
            $('#uif-total').text(scanData.total_images);
            $('#uif-used').text(scanData.used_count);
            $('#uif-unused').text(loaded);
            $('#uif-size').text(formatSize(scanData.total_size));

            if (res.data.has_more) {
                // 1 second delay between batches to avoid server rate-limiting.
                setTimeout(function () {
                    fetchBatch(offset + batchSize, total, 0);
                }, 1000);
            } else {
                finishScan();
            }
        })
        .fail(function (jqXHR, textStatus) {
            if (retries < maxRetries) {
                var loaded = scanData.unused_images.length;
                var waitTime = 3000 + (retries * 2000); // Increasing delay: 3s, 5s, 7s, 9s, 11s
                setProgress(null, null, 'Request failed (' + textStatus + '), retrying in ' + (waitTime/1000) + 's... (' + (retries + 1) + '/' + maxRetries + ') — ' + loaded + ' loaded so far');
                setTimeout(function () {
                    fetchBatch(offset, total, retries + 1);
                }, waitTime);
                return;
            }
            // Skip this batch and continue to the next one instead of aborting.
            setProgress(null, null, 'Batch at offset ' + offset + ' failed after ' + maxRetries + ' retries, skipping...');
            setTimeout(function () {
                fetchBatch(offset + batchSize, total, 0);
            }, 2000);
        });
    }

    function finishScan() {
        // Use actual loaded count for display (may differ from server count if batches were skipped).
        var actualCount = scanData.unused_images.length;

        $('#uif-total').text(scanData.total_images);
        $('#uif-used').text(scanData.used_count);
        $('#uif-unused').text(actualCount);
        $('#uif-size').text(formatSize(scanData.total_size));

        // Update internal count to match what actually loaded.
        scanData.unused_count = actualCount;

        if (actualCount > 0) {
            tableWrap.show();
            emptyMsg.hide();
            currentPage = 1;
            renderPage();
        } else {
            tableWrap.hide();
            emptyMsg.show();
        }

        results.show();
        setProgress(100, 'Done!', actualCount + ' unused images found — ' + formatSize(scanData.total_size) + ' recoverable');

        setTimeout(function () { progress.slideUp(300); }, 1500);
        scanDone();
    }

    function scanDone() {
        scanBtn.prop('disabled', false);
    }

    // ── Scan button ────────────────────────────────────────────

    scanBtn.on('click', startScan);

    // ── Select All (this page) ─────────────────────────────────

    $(document).on('change', '#uif-select-all, #uif-select-all-top', function () {
        var checked = $(this).prop('checked');
        var pageImages = getPageImages();
        for (var i = 0; i < pageImages.length; i++) {
            var id = String(pageImages[i].id);
            if (checked) {
                selectedIds[id] = true;
            } else {
                delete selectedIds[id];
                allPagesSelected = false;
            }
        }
        updateSelectedUI();
    });

    // ── Select All Pages ───────────────────────────────────────

    $(document).on('change', '#uif-select-all-pages', function () {
        var checked = $(this).prop('checked');
        allPagesSelected = checked;
        selectedIds = {};
        if (checked) {
            for (var i = 0; i < scanData.unused_images.length; i++) {
                selectedIds[String(scanData.unused_images[i].id)] = true;
            }
        }
        updateSelectedUI();
    });

    // ── Individual checkbox ────────────────────────────────────

    $(document).on('change', '.uif-cb', function () {
        var id = String($(this).val());
        if ($(this).prop('checked')) {
            selectedIds[id] = true;
        } else {
            delete selectedIds[id];
            allPagesSelected = false;
        }
        updateSelectedUI();
    });

    // ── Bulk delete ────────────────────────────────────────────

    deleteBtn.on('click', function () {
        if (isDryRun) {
            showNotice('Safe Mode is ON. Disable it to delete images.', 'warning');
            return;
        }

        var ids = [];
        for (var k in selectedIds) {
            if (selectedIds.hasOwnProperty(k) && selectedIds[k]) {
                ids.push(k);
            }
        }

        if (ids.length === 0) {
            showNotice(uif.i18n.no_selection, 'warning');
            return;
        }

        if (!confirm(uif.i18n.confirm.replace('%d', ids.length) || uif.i18n.confirm)) {
            return;
        }

        deleteBtn.prop('disabled', true).text(uif.i18n.deleting);

        // Delete in chunks of 50 to avoid server limits.
        var chunkSize = 50;
        var totalDeleted = 0;
        var totalToDelete = ids.length;

        function deleteChunk(startIdx) {
            var chunk = ids.slice(startIdx, startIdx + chunkSize);
            if (chunk.length === 0) {
                // All done.
                showNotice(totalDeleted + ' ' + uif.i18n.deleted, 'success');

                // Remove deleted images from scanData.
                var deletedSet = {};
                for (var i = 0; i < ids.length; i++) { deletedSet[ids[i]] = true; }

                scanData.unused_images = scanData.unused_images.filter(function (img) {
                    return !deletedSet[String(img.id)];
                });
                scanData.unused_count = scanData.unused_images.length;

                // Recalculate total size.
                scanData.total_size = 0;
                for (var j = 0; j < scanData.unused_images.length; j++) {
                    scanData.total_size += scanData.unused_images[j].filesize;
                }

                selectedIds = {};
                allPagesSelected = false;

                // Update stat cards.
                $('#uif-unused').text(scanData.unused_count);
                $('#uif-size').text(formatSize(scanData.total_size));

                // Fix current page if needed.
                if (currentPage > totalPages()) currentPage = totalPages();

                if (scanData.unused_images.length === 0) {
                    tableWrap.hide();
                    emptyMsg.show();
                } else {
                    renderPage();
                }

                deleteBtn.prop('disabled', false).text('Delete Selected');
                return;
            }

            $.post(uif.ajax_url, {
                action: 'uif_delete',
                nonce:  uif.nonce,
                ids:    chunk
            })
            .done(function (res) {
                if (res.success) {
                    totalDeleted += res.data.deleted;
                    deleteBtn.text('Deleting... ' + totalDeleted + '/' + totalToDelete);
                    deleteChunk(startIdx + chunkSize);
                } else {
                    showNotice(uif.i18n.error, 'error');
                    deleteBtn.prop('disabled', false).text('Delete Selected');
                }
            })
            .fail(function () {
                showNotice(uif.i18n.error, 'error');
                deleteBtn.prop('disabled', false).text('Delete Selected');
            });
        }

        deleteChunk(0);
    });

    // ── Export CSV (server-side streaming) ────────────────────────
    // Uses a dedicated PHP endpoint that streams CSV directly from the
    // transient (all 1503 IDs), bypassing the JS batch loading entirely.

    $('#uif-export-csv-btn').on('click', function () {
        if (scanData.unused_count === 0) {
            showNotice('No scan data available. Please run a scan first.', 'warning');
            return;
        }
        // Server streams the full CSV using the transient from scan_init.
        window.location.href = uif.csv_url;
    });

    // ── Single delete ──────────────────────────────────────────

    $(document).on('click', '.uif-delete-single', function (e) {
        e.preventDefault();

        if (isDryRun) {
            showNotice('Safe Mode is ON. Disable it to delete images.', 'warning');
            return;
        }

        var id  = $(this).data('id');
        var row = tbody.find('tr[data-id="' + id + '"]');

        if (!confirm(uif.i18n.confirm)) {
            return;
        }

        row.addClass('uif-row-deleting');

        $.post(uif.ajax_url, {
            action: 'uif_delete',
            nonce:  uif.nonce,
            ids:    [id]
        })
        .done(function (res) {
            if (res.success) {
                // Remove from scanData.
                scanData.unused_images = scanData.unused_images.filter(function (img) {
                    return img.id !== id;
                });
                scanData.unused_count = scanData.unused_images.length;
                scanData.total_size = 0;
                for (var i = 0; i < scanData.unused_images.length; i++) {
                    scanData.total_size += scanData.unused_images[i].filesize;
                }

                delete selectedIds[String(id)];

                $('#uif-unused').text(scanData.unused_count);
                $('#uif-size').text(formatSize(scanData.total_size));

                if (scanData.unused_images.length === 0) {
                    tableWrap.hide();
                    emptyMsg.show();
                } else {
                    if (currentPage > totalPages()) currentPage = totalPages();
                    renderPage();
                }
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

    // ── Orphaned Files Scanner ────────────────────────────────

    var orphanData = [];
    var orphanSelectedPaths = {};

    var orphanPhases = [
        { phase: 1, label: 'Scanning uploads folder...', pct: 33 },
        { phase: 2, label: 'Checking references in content...', pct: 66 },
        { phase: 3, label: 'Checking options & finalizing...', pct: 100 }
    ];

    $('#uif-orphan-scan-btn').on('click', function () {
        var btn = $(this);
        btn.prop('disabled', true);
        $('#uif-orphan-progress').show();
        $('#uif-orphan-results').hide();
        $('#uif-orphan-tbody').empty();
        $('#uif-orphan-progress-bar').css('width', '0%');
        orphanData = [];
        orphanSelectedPaths = {};

        runOrphanPhase(0, btn);
    });

    function runOrphanPhase(index, btn) {
        if (index >= orphanPhases.length) {
            // Should not reach here — phase 3 handles completion.
            btn.prop('disabled', false);
            $('#uif-orphan-progress').hide();
            return;
        }

        var phaseInfo = orphanPhases[index];
        $('#uif-orphan-progress-text').text('Phase ' + phaseInfo.phase + '/3: ' + phaseInfo.label);
        $('#uif-orphan-progress-bar').css('width', (phaseInfo.pct - 33) + '%');

        $.ajax({
            url: uif.ajax_url,
            type: 'POST',
            timeout: 180000,
            data: {
                action: 'uif_orphan_phase',
                nonce:  uif.nonce,
                phase:  phaseInfo.phase
            }
        })
        .done(function (res) {
            $('#uif-orphan-progress-bar').css('width', phaseInfo.pct + '%');

            if (!res.success) {
                // Phase failed — show warning but continue to next phase.
                showNotice('Orphan scan phase ' + phaseInfo.phase + ' failed: ' + (res.data || 'Unknown error') + '. Continuing...', 'warning');
                if (index < orphanPhases.length - 1) {
                    setTimeout(function () { runOrphanPhase(index + 1, btn); }, 500);
                } else {
                    btn.prop('disabled', false);
                    $('#uif-orphan-progress').hide();
                }
                return;
            }

            // If this is the final phase (3), show results.
            if (phaseInfo.phase === 3) {
                btn.prop('disabled', false);
                $('#uif-orphan-progress').hide();
                $('#uif-orphan-results').show();

                orphanData = res.data.files;
                $('#uif-orphan-count').text(res.data.total);
                $('#uif-orphan-size').text(formatSize(res.data.total_size));

                if (res.data.referenced_count > 0) {
                    showNotice(res.data.referenced_count + ' file(s) found on disk but excluded — they are referenced in your content.', 'info');
                }

                if (orphanData.length > 0) {
                    $('#uif-orphan-table-wrap').show();
                    $('#uif-orphan-empty').hide();
                    renderOrphanTable();
                } else {
                    $('#uif-orphan-table-wrap').hide();
                    $('#uif-orphan-empty').show();
                }
            } else {
                // Move to next phase.
                setTimeout(function () { runOrphanPhase(index + 1, btn); }, 500);
            }
        })
        .fail(function (xhr, status) {
            // Phase failed (timeout or error) — skip and continue.
            var msg = status === 'timeout' ? 'timed out' : 'server error';
            showNotice('Orphan scan phase ' + phaseInfo.phase + ' ' + msg + '. Skipping...', 'warning');
            $('#uif-orphan-progress-bar').css('width', phaseInfo.pct + '%');

            if (index < orphanPhases.length - 1) {
                setTimeout(function () { runOrphanPhase(index + 1, btn); }, 500);
            } else {
                btn.prop('disabled', false);
                $('#uif-orphan-progress').hide();
                showNotice('Orphan scan could not complete. Try again or check server timeout settings.', 'error');
            }
        });
    }

    function renderOrphanTable() {
        var html = '';
        for (var i = 0; i < orphanData.length; i++) {
            var f = orphanData[i];
            var safePath = escAttr(f.path);
            var safeUrl  = escAttr(f.url);
            var safeName = $('<span>').text(f.filename).html();
            html += '<tr data-path="' + safePath + '">' +
                '<td><input type="checkbox" class="uif-orphan-cb" value="' + safePath + '" /></td>' +
                '<td><img class="uif-thumb" src="' + safeUrl + '" alt="" loading="lazy" onerror="this.style.display=\'none\'" /></td>' +
                '<td><span class="uif-filename">' + $('<span>').text(f.path).html() + '</span></td>' +
                '<td>' + formatSize(f.filesize) + '</td>' +
                '<td>' + $('<span>').text(f.modified).html() + '</td>' +
                '</tr>';
        }
        $('#uif-orphan-tbody').html(html);
    }

    // Select all orphan checkboxes.
    $(document).on('change', '#uif-orphan-select-all, #uif-orphan-select-all-top', function () {
        var checked = $(this).prop('checked');
        $('#uif-orphan-select-all, #uif-orphan-select-all-top').prop('checked', checked);
        $('#uif-orphan-tbody .uif-orphan-cb').prop('checked', checked);
        orphanSelectedPaths = {};
        if (checked) {
            for (var i = 0; i < orphanData.length; i++) {
                orphanSelectedPaths[orphanData[i].path] = true;
            }
        }
        updateOrphanSelectedUI();
    });

    $(document).on('change', '.uif-orphan-cb', function () {
        var path = $(this).val();
        if ($(this).prop('checked')) {
            orphanSelectedPaths[path] = true;
        } else {
            delete orphanSelectedPaths[path];
        }
        updateOrphanSelectedUI();
    });

    function orphanSelectedCount() {
        var count = 0;
        for (var k in orphanSelectedPaths) {
            if (orphanSelectedPaths.hasOwnProperty(k) && orphanSelectedPaths[k]) count++;
        }
        return count;
    }

    function updateOrphanSelectedUI() {
        var count = orphanSelectedCount();
        $('#uif-orphan-selected-count').text(count > 0 ? count + ' selected' : '');
        $('#uif-orphan-delete-btn').prop('disabled', count === 0);
    }

    // Delete selected orphaned files.
    $('#uif-orphan-delete-btn').on('click', function () {
        var paths = [];
        for (var k in orphanSelectedPaths) {
            if (orphanSelectedPaths.hasOwnProperty(k) && orphanSelectedPaths[k]) {
                paths.push(k);
            }
        }

        if (paths.length === 0) {
            showNotice('No files selected.', 'warning');
            return;
        }

        if (!confirm('Are you sure you want to permanently delete ' + paths.length + ' orphaned file(s) from disk? This cannot be undone.')) {
            return;
        }

        var btn = $('#uif-orphan-delete-btn');
        btn.prop('disabled', true).text('Deleting...');

        // Delete in chunks of 50.
        var chunkSize = 50;
        var totalDeleted = 0;

        function deleteOrphanChunk(startIdx) {
            var chunk = paths.slice(startIdx, startIdx + chunkSize);
            if (chunk.length === 0) {
                showNotice(totalDeleted + ' orphaned file(s) deleted.', 'success');
                // Remove deleted from data.
                var deletedSet = {};
                for (var i = 0; i < paths.length; i++) { deletedSet[paths[i]] = true; }
                orphanData = orphanData.filter(function (f) { return !deletedSet[f.path]; });
                orphanSelectedPaths = {};
                $('#uif-orphan-count').text(orphanData.length);
                if (orphanData.length === 0) {
                    $('#uif-orphan-table-wrap').hide();
                    $('#uif-orphan-empty').show();
                } else {
                    renderOrphanTable();
                }
                btn.prop('disabled', false).text('Delete Selected Files');
                updateOrphanSelectedUI();
                return;
            }

            $.post(uif.ajax_url, {
                action: 'uif_orphan_delete',
                nonce:  uif.nonce,
                paths:  chunk
            })
            .done(function (res) {
                if (res.success) {
                    totalDeleted += res.data.deleted;
                    btn.text('Deleting... ' + totalDeleted + '/' + paths.length);
                    deleteOrphanChunk(startIdx + chunkSize);
                } else {
                    showNotice('Error deleting files: ' + (res.data || 'Unknown error'), 'error');
                    btn.prop('disabled', false).text('Delete Selected Files');
                }
            })
            .fail(function () {
                showNotice('Server error while deleting orphaned files.', 'error');
                btn.prop('disabled', false).text('Delete Selected Files');
            });
        }

        deleteOrphanChunk(0);
    });

})(jQuery);
