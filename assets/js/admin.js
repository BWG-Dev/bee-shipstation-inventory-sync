/* global WSI_WR_Admin, WSI_WR_Alignment, jQuery */
(function ($) {
    'use strict';

    function escHtml(str) {
        return $('<span>').text(String(str)).html();
    }

    // ── Phase 2 ──────────────────────────────────────────────────────────────

    var WSI_WR = {
        init: function () {
            if ($('#wsi-wr-test-connection').length) {
                $('#wsi-wr-test-connection').on('click', WSI_WR.testConnection);
                $('#wsi-wr-fetch-sample').on('click', WSI_WR.fetchSample);
            }

            if ($('#wsi-wr-run-alignment-report').length) {
                $('#wsi-wr-run-alignment-report').on('click', WSI_WR.runAlignmentReport);
            }

            if ($('#wsi-wr-run-dry-run').length) {
                $('#wsi-wr-run-dry-run').on('click', WSI_WR.runDryRun);
            }

            if ($('#wsi-wr-run-manual-sync').length) {
                $('#wsi-wr-run-manual-sync').on('click', WSI_WR.runManualSync);
            }

            if ($('#wsi-wr-refresh-locations').length) {
                $('#wsi-wr-refresh-locations').on('click', WSI_WR.refreshLocations);
            }

            if ($('#wsi-wr-approve-sku-btn').length) {
                $('#wsi-wr-approve-sku-btn').on('click', WSI_WR.approveSku);
                $(document).on('click', '.wsi-wr-toggle-sync', WSI_WR.toggleTrackedSku);
            }

        },

        testConnection: function (e) {
            e.preventDefault();
            var $btn    = $(this);
            var $result = $('#wsi-wr-test-connection-result');
            var strings = WSI_WR_Admin.strings;

            $btn.prop('disabled', true).text(strings.testing);
            $result.removeClass('notice-success notice-error').empty();

            $.post(WSI_WR_Admin.ajax_url, {
                action: 'wsi_wr_test_connection',
                nonce:  WSI_WR_Admin.nonces.test_connection
            })
            .done(function (response) {
                if (response.success) {
                    var d   = response.data;
                    var msg = '<strong>' + escHtml(strings.success) + '</strong> ' + escHtml(d.message);
                    msg += ' &mdash; ' + escHtml(strings.total_records) + ': <strong>' + escHtml(d.total_records) + '</strong>';
                    if (d.has_available_field) {
                        msg += ' &mdash; <span class="wsi-wr-available-confirmed">' + escHtml(strings.available_field_confirmed) + '</span>';
                    }
                    $result.addClass('notice notice-success').html('<p>' + msg + '</p>');
                } else {
                    $result.addClass('notice notice-error').html(
                        '<p><strong>' + escHtml(strings.error) + '</strong> ' + escHtml(response.data.message) + '</p>'
                    );
                }
            })
            .fail(function () {
                $result.addClass('notice notice-error').html(
                    '<p>' + escHtml(strings.request_failed) + '</p>'
                );
            })
            .always(function () {
                $btn.prop('disabled', false).text(strings.test_connection_label);
            });
        },

        fetchSample: function (e) {
            e.preventDefault();
            var $btn    = $(this);
            var $result = $('#wsi-wr-fetch-sample-result');
            var strings = WSI_WR_Admin.strings;

            $btn.prop('disabled', true).text(strings.fetching);
            $result.empty();

            $.post(WSI_WR_Admin.ajax_url, {
                action: 'wsi_wr_fetch_inventory_sample',
                nonce:  WSI_WR_Admin.nonces.fetch_sample
            })
            .done(function (response) {
                if (response.success) {
                    var d     = response.data;
                    var $wrap = $('<div>');

                    var summary = escHtml(strings.showing) + ' ' +
                        escHtml(d.records.length) + ' / ' +
                        escHtml(d.total) + ' ' + escHtml(strings.records) +
                        ' &mdash; ' + escHtml(strings.page_label) + ' ' +
                        escHtml(d.page) + ' / ' + escHtml(d.pages);

                    if (d.has_available_field) {
                        summary += ' &mdash; <span class="wsi-wr-available-confirmed">' +
                            escHtml(strings.available_field_confirmed) + '</span>';
                    }

                    $wrap.append($('<p class="wsi-wr-pagination-info">').html(summary));

                    if (d.records.length > 0) {
                        var $table = $('<table class="wsi-wr-sample-table widefat striped">');
                        var $thead = $('<thead><tr>' +
                            '<th>SKU</th>' +
                            '<th>' + escHtml(strings.on_hand) + '</th>' +
                            '<th>' + escHtml(strings.available) + '</th>' +
                            '</tr></thead>');
                        var $tbody = $('<tbody>');

                        $.each(d.records, function (i, rec) {
                            var $tr = $('<tr>');
                            $tr.append($('<td>').text(rec.sku));
                            $tr.append($('<td>').text(rec.on_hand !== null ? rec.on_hand : '—'));
                            $tr.append($('<td>').html(
                                rec.available !== null
                                    ? '<strong>' + escHtml(rec.available) + '</strong>'
                                    : '—'
                            ));
                            $tbody.append($tr);
                        });

                        $table.append($thead).append($tbody);
                        $wrap.append($table);
                    } else {
                        $wrap.append($('<p>').text(strings.no_records));
                    }

                    $result.html($wrap);
                } else {
                    $result.html(
                        $('<div class="notice notice-error">').append(
                            $('<p>').html(
                                '<strong>' + escHtml(strings.error) + '</strong> ' +
                                escHtml(response.data.message)
                            )
                        )
                    );
                }
            })
            .fail(function () {
                $result.html(
                    $('<div class="notice notice-error">').append(
                        $('<p>').text(strings.request_failed)
                    )
                );
            })
            .always(function () {
                $btn.prop('disabled', false).text(strings.fetch_sample_label);
            });
        },

        // ── Phase 3 ──────────────────────────────────────────────────────────

        runAlignmentReport: function (e) {
            e.preventDefault();

            if (typeof WSI_WR_Alignment === 'undefined') {
                return;
            }

            var $btn     = $(this);
            var $spinner = $('#wsi-wr-alignment-spinner');
            var $notice  = $('#wsi-wr-alignment-notice');
            var $results = $('#wsi-wr-alignment-results');
            var strings  = WSI_WR_Admin.strings;

            $btn.prop('disabled', true).text(strings.running);
            $spinner.css('visibility', 'visible');
            $notice.removeClass('notice notice-success notice-error').empty();
            $results.hide().find('#wsi-wr-alignment-summary, #wsi-wr-alignment-export, #wsi-wr-alignment-tables').empty();

            $.post(WSI_WR_Admin.ajax_url, {
                action: 'wsi_wr_run_alignment_report',
                nonce:  WSI_WR_Alignment.nonce
            })
            .done(function (response) {
                if (response.success) {
                    var d = response.data;

                    // Pagination warning
                    if (!d.summary.pagination_complete) {
                        $notice.addClass('notice notice-error').html(
                            '<p>' + escHtml(strings.pagination_incomplete) + '</p>'
                        );
                    }

                    // Summary box
                    var s     = d.summary;
                    var total = s.matched_simple + s.matched_variation;
                    var $sum  = $('#wsi-wr-alignment-summary');

                    $sum.html(
                        '<ul>' +
                        '<li><strong>' + escHtml(s.total_shipstation_records) + '</strong> ShipStation records</li>' +
                        '<li><strong>' + escHtml(total) + '</strong> matched (' +
                            escHtml(s.matched_simple) + ' simple, ' + escHtml(s.matched_variation) + ' variation)</li>' +
                        '<li><strong>' + escHtml(s.unmatched) + '</strong> unmatched</li>' +
                        '<li><strong>' + escHtml(s.manage_stock_disabled) + '</strong> manage stock disabled</li>' +
                        '<li><strong>' + escHtml(s.ambiguous) + '</strong> ambiguous</li>' +
                        '</ul>'
                    );

                    // CSV download link
                    if (d.run_id) {
                        var csvUrl = WSI_WR_Alignment.export_url_base +
                            '&run_id=' + encodeURIComponent(d.run_id) +
                            '&nonce='  + encodeURIComponent(WSI_WR_Alignment.export_nonce);
                        $('#wsi-wr-alignment-export').html(
                            '<a href="' + escHtml(csvUrl) + '" class="button button-secondary">' +
                            escHtml(strings.download_csv) + '</a>'
                        );
                    }

                    // Result tables by type
                    var groups = {
                        matched_simple:           { label: 'Matched — Simple',                          cls: 'wsi-wr-result-matched' },
                        matched_variation:        { label: 'Matched — Variation',                       cls: 'wsi-wr-result-matched' },
                        unmatched:                { label: 'Unmatched (ShipStation only)',               cls: 'wsi-wr-result-warn' },
                        manage_stock_disabled:    { label: 'Manage Stock Disabled',                     cls: 'wsi-wr-result-info' },
                        variation_parent_stock:   { label: 'Skipped — Variation Uses Parent Stock',     cls: 'wsi-wr-result-warn' },
                        skipped_not_published:    { label: 'Skipped — Not Published',                   cls: 'wsi-wr-result-info' },
                        ambiguous:                { label: 'Ambiguous Matches',                         cls: 'wsi-wr-result-warn' },
                        skipped_empty_sku:        { label: 'Skipped — Empty SKU',                       cls: 'wsi-wr-result-info' }
                    };

                    var buckets = {};
                    $.each(d.results, function (i, row) {
                        if (!buckets[row.result_type]) {
                            buckets[row.result_type] = [];
                        }
                        buckets[row.result_type].push(row);
                    });

                    var $tables = $('#wsi-wr-alignment-tables');
                    $.each(groups, function (type, meta) {
                        var rows = buckets[type];
                        if (!rows || rows.length === 0) {
                            return;
                        }

                        var $section = $('<div class="wsi-wr-result-section ' + escHtml(meta.cls) + '">');
                        $section.append(
                            '<h4>' + escHtml(meta.label) + ' <span class="wsi-wr-count">(' + escHtml(rows.length) + ')</span></h4>'
                        );

                        var $tbl   = $('<table class="wsi-wr-result-table widefat striped">');
                        var $thead = $('<thead><tr>' +
                            '<th>' + escHtml(strings.sku_col) + '</th>' +
                            '<th>' + escHtml(strings.product_id_col) + '</th>' +
                            '<th>' + escHtml(strings.current_stock_col) + '</th>' +
                            '<th>' + escHtml(strings.ss_available_col) + '</th>' +
                            '<th>' + escHtml(strings.ss_on_hand_col) + '</th>' +
                            '<th>' + escHtml(strings.notes_col) + '</th>' +
                            '</tr></thead>');
                        var $tbody = $('<tbody>');

                        $.each(rows, function (i, row) {
                            var productCell = row.product_id
                                ? String(row.product_id) + (row.is_variation ? ' (var)' : '')
                                : '—';
                            $tbody.append(
                                '<tr>' +
                                '<td><strong>' + escHtml(row.sku) + '</strong></td>' +
                                '<td>' + escHtml(productCell) + '</td>' +
                                '<td>' + (row.current_stock !== null ? escHtml(row.current_stock) : '—') + '</td>' +
                                '<td>' + (row.shipstation_available !== null ? '<strong>' + escHtml(row.shipstation_available) + '</strong>' : '—') + '</td>' +
                                '<td>' + (row.shipstation_on_hand !== null ? escHtml(row.shipstation_on_hand) : '—') + '</td>' +
                                '<td class="wsi-wr-reason">' + escHtml(row.reason || '') + '</td>' +
                                '</tr>'
                            );
                        });

                        $tbl.append($thead).append($tbody);
                        $section.append($tbl);
                        $tables.append($section);
                    });

                    $results.show();
                } else {
                    $notice.addClass('notice notice-error').html(
                        '<p><strong>' + escHtml(strings.error) + '</strong> ' +
                        escHtml(response.data.message) + '</p>'
                    );
                }
            })
            .fail(function () {
                $notice.addClass('notice notice-error').html(
                    '<p>' + escHtml(strings.request_failed) + '</p>'
                );
            })
            .always(function () {
                $btn.prop('disabled', false).text(strings.run_report_label);
                $spinner.css('visibility', 'hidden');
            });
        },

        runDryRun: function (e) {
            e.preventDefault();

            if (typeof WSI_WR_DryRun === 'undefined') {
                return;
            }

            var $btn     = $(this);
            var $spinner = $('#wsi-wr-dry-run-spinner');
            var $notice  = $('#wsi-wr-dry-run-notice');
            var $results = $('#wsi-wr-dry-run-results');
            var strings  = WSI_WR_Admin.strings;

            $btn.prop('disabled', true).text(strings.running);
            $spinner.css('visibility', 'visible');
            $notice.removeClass('notice notice-success notice-error').empty();
            $results.hide().find('#wsi-wr-dry-run-summary, #wsi-wr-dry-run-export, #wsi-wr-dry-run-tables').empty();

            $.post(WSI_WR_Admin.ajax_url, {
                action: 'wsi_wr_run_dry_run',
                nonce:  WSI_WR_DryRun.nonce
            })
            .done(function (response) {
                if (response.success) {
                    var d = response.data;
                    var s = d.summary;

                    if (!s.pagination_complete) {
                        $notice.addClass('notice notice-error').html(
                            '<p>' + escHtml(strings.pagination_incomplete) + '</p>'
                        );
                    }

                    // Summary box
                    var $sum = $('#wsi-wr-dry-run-summary');
                    $sum.html(
                        '<ul>' +
                        '<li><strong>' + escHtml(s.total_shipstation_records) + '</strong> ShipStation records</li>' +
                        '<li><strong class="wsi-wr-increase">' + escHtml(s.proposed_increase) + '</strong> proposed increases</li>' +
                        '<li><strong class="wsi-wr-decrease">' + escHtml(s.proposed_decrease) + '</strong> proposed decreases</li>' +
                        '<li><strong>' + escHtml(s.no_change) + '</strong> already at correct stock</li>' +
                        '<li><strong>' + escHtml(s.skipped_recent_order) + '</strong> blocked by recent-order protection</li>' +
                        '<li><strong>' + escHtml(s.missing_tracked_sku) + '</strong> missing tracked SKUs (report only)</li>' +
                        ( s.proposed_zero > 0 ? '<li><strong class="wsi-wr-zero">' + escHtml(s.proposed_zero) + '</strong> proposed zero stock</li>' : '' ) +
                        '<li><strong>' + escHtml(s.skipped_unmatched) + '</strong> unmatched</li>' +
                        '</ul>'
                    );

                    // CSV download link
                    if (d.run_id) {
                        var csvUrl = WSI_WR_DryRun.export_url_base +
                            '&run_id=' + encodeURIComponent(d.run_id) +
                            '&nonce='  + encodeURIComponent(WSI_WR_DryRun.export_nonce);
                        $('#wsi-wr-dry-run-export').html(
                            '<a href="' + escHtml(csvUrl) + '" class="button button-secondary">' +
                            escHtml(strings.download_csv) + '</a>'
                        );
                    }

                    // Result tables by proposed_action
                    var groups = [
                        { action: 'proposed_increase',                label: 'Proposed Increases',                          cls: 'wsi-wr-result-increase' },
                        { action: 'proposed_decrease',                label: 'Proposed Decreases',                          cls: 'wsi-wr-result-decrease' },
                        { action: 'no_change',                        label: 'No Change Required',                          cls: 'wsi-wr-result-info' },
                        { action: 'skipped_recent_order',             label: 'Skipped — Recent Order Protection',           cls: 'wsi-wr-result-protected' },
                        { action: 'missing_tracked_sku',              label: 'Missing Tracked SKUs',                        cls: 'wsi-wr-result-warn' },
                        { action: 'proposed_zero',                    label: 'Proposed Zero Stock',                         cls: 'wsi-wr-result-zero' },
                        { action: 'skipped_unmatched',                label: 'Unmatched (ShipStation only)',                 cls: 'wsi-wr-result-info' },
                        { action: 'skipped_manage_stock_disabled',    label: 'Manage Stock Disabled',                       cls: 'wsi-wr-result-info' },
                        { action: 'skipped_variation_parent_stock',   label: 'Skipped — Variation Uses Parent Stock',       cls: 'wsi-wr-result-warn' },
                        { action: 'skipped_not_published',            label: 'Skipped — Not Published',                     cls: 'wsi-wr-result-info' },
                        { action: 'skipped_ambiguous',                label: 'Ambiguous Matches',                           cls: 'wsi-wr-result-warn' },
                        { action: 'skipped_no_available',             label: 'No Available Quantity Returned',              cls: 'wsi-wr-result-warn' },
                        { action: 'skipped_empty_sku',                label: 'Skipped — Empty SKU',                         cls: 'wsi-wr-result-info' }
                    ];

                    var buckets = {};
                    $.each(d.results, function (i, row) {
                        if (!buckets[row.proposed_action]) {
                            buckets[row.proposed_action] = [];
                        }
                        buckets[row.proposed_action].push(row);
                    });

                    var $tables = $('#wsi-wr-dry-run-tables');
                    $.each(groups, function (i, grp) {
                        var rows = buckets[grp.action];
                        if (!rows || rows.length === 0) {
                            return;
                        }

                        var $section = $('<div class="wsi-wr-result-section ' + escHtml(grp.cls) + '">');
                        $section.append(
                            '<h4>' + escHtml(grp.label) + ' <span class="wsi-wr-count">(' + escHtml(rows.length) + ')</span></h4>'
                        );

                        var $tbl = $('<table class="wsi-wr-result-table widefat striped">');
                        $tbl.append(
                            '<thead><tr>' +
                            '<th>' + escHtml(strings.sku_col) + '</th>' +
                            '<th>' + escHtml(strings.product_id_col) + '</th>' +
                            '<th>' + escHtml(strings.current_stock_col) + '</th>' +
                            '<th>' + escHtml(strings.ss_available_col) + '</th>' +
                            '<th>' + escHtml(strings.proposed_stock_col) + '</th>' +
                            '<th>' + escHtml(strings.notes_col) + '</th>' +
                            '</tr></thead>'
                        );
                        var $tbody = $('<tbody>');

                        $.each(rows, function (i, row) {
                            var productCell = row.product_id
                                ? String(row.product_id) + (row.is_variation ? ' (var)' : '')
                                : '—';
                            var proposedCell = row.proposed_stock !== null
                                ? '<strong>' + escHtml(row.proposed_stock) + '</strong>'
                                : '—';
                            $tbody.append(
                                '<tr>' +
                                '<td><strong>' + escHtml(row.sku) + '</strong></td>' +
                                '<td>' + escHtml(productCell) + '</td>' +
                                '<td>' + (row.current_stock !== null ? escHtml(row.current_stock) : '—') + '</td>' +
                                '<td>' + (row.shipstation_available !== null ? escHtml(row.shipstation_available) : '—') + '</td>' +
                                '<td>' + proposedCell + '</td>' +
                                '<td class="wsi-wr-reason">' + escHtml(row.reason || '') + '</td>' +
                                '</tr>'
                            );
                        });

                        $tbl.append($tbody);
                        $section.append($tbl);
                        $tables.append($section);
                    });

                    $results.show();
                } else {
                    $notice.addClass('notice notice-error').html(
                        '<p><strong>' + escHtml(strings.error) + '</strong> ' +
                        escHtml(response.data.message) + '</p>'
                    );
                }
            })
            .fail(function () {
                $notice.addClass('notice notice-error').html(
                    '<p>' + escHtml(strings.request_failed) + '</p>'
                );
            })
            .always(function () {
                $btn.prop('disabled', false).text(strings.run_dry_run_label);
                $spinner.css('visibility', 'hidden');
            });
        },

        toggleTrackedSku: function (e) {
            e.preventDefault();
            var $btn    = $(this);
            var id      = $btn.data('id');
            var enabled = $btn.data('enabled') === 1 || $btn.data('enabled') === '1';
            var nonce   = $btn.data('nonce');
            var strings = WSI_WR_Admin.strings;

            $btn.prop('disabled', true);

            $.post(WSI_WR_Admin.ajax_url, {
                action:  'wsi_wr_toggle_tracked_sku',
                id:      id,
                enabled: enabled ? '0' : '1',
                nonce:   nonce
            })
            .done(function (response) {
                if (response.success) {
                    var newEnabled = response.data.enabled ? '1' : '0';
                    $btn.data('enabled', newEnabled).text(response.data.label);
                    var $notice = $('#wsi-wr-tracked-sku-notice');
                    $notice.addClass('notice notice-success').html(
                        '<p>' + escHtml(strings.success) + ' ' +
                        escHtml(response.data.label) + '</p>'
                    );
                } else {
                    var $notice = $('#wsi-wr-tracked-sku-notice');
                    $notice.addClass('notice notice-error').html(
                        '<p>' + escHtml(strings.error) + ' ' +
                        escHtml(response.data.message) + '</p>'
                    );
                }
            })
            .fail(function () {
                $('#wsi-wr-tracked-sku-notice').addClass('notice notice-error').html(
                    '<p>' + escHtml(strings.request_failed) + '</p>'
                );
            })
            .always(function () {
                $btn.prop('disabled', false);
            });
        },

        // ── Phase 5 ──────────────────────────────────────────────────────────

        runManualSync: function (e) {
            e.preventDefault();

            if (typeof WSI_WR_ManualSync === 'undefined') {
                return;
            }

            var $btn     = $(this);
            var $spinner = $('#wsi-wr-manual-sync-spinner');
            var $notice  = $('#wsi-wr-manual-sync-notice');
            var $results = $('#wsi-wr-manual-sync-results');
            var strings  = WSI_WR_Admin.strings;

            $btn.prop('disabled', true).text(strings.running);
            $spinner.css('visibility', 'visible');
            $notice.removeClass('notice notice-success notice-error').empty();
            $results.hide().find('#wsi-wr-manual-sync-summary, #wsi-wr-manual-sync-export, #wsi-wr-manual-sync-tables').empty();

            $.post(WSI_WR_Admin.ajax_url, {
                action: 'wsi_wr_run_manual_sync',
                nonce:  WSI_WR_ManualSync.nonce
            })
            .done(function (response) {
                if (response.success) {
                    var d = response.data;
                    var s = d.summary;

                    if (!s.pagination_complete) {
                        $notice.addClass('notice notice-error').html(
                            '<p>' + escHtml(strings.pagination_incomplete) + '</p>'
                        );
                    }

                    // Summary box
                    var $sum = $('#wsi-wr-manual-sync-summary');
                    var appliedBreakdown = '';
                    if (s.proposed_increase > 0 || s.proposed_decrease > 0 || s.proposed_zero > 0) {
                        var parts = [];
                        if (s.proposed_increase > 0) { parts.push(escHtml(s.proposed_increase) + ' increase'); }
                        if (s.proposed_decrease > 0) { parts.push(escHtml(s.proposed_decrease) + ' decrease'); }
                        if (s.proposed_zero > 0)     { parts.push(escHtml(s.proposed_zero) + ' zeroed'); }
                        appliedBreakdown = ' (' + parts.join(', ') + ')';
                    }

                    $sum.html(
                        '<ul>' +
                        '<li><strong class="wsi-wr-applied">' + escHtml(s.applied) + '</strong> applied' + appliedBreakdown + '</li>' +
                        ( s.failed > 0 ? '<li><strong class="wsi-wr-failed">' + escHtml(s.failed) + '</strong> failed</li>' : '' ) +
                        '<li><strong>' + escHtml(s.no_change) + '</strong> already at correct stock</li>' +
                        '<li><strong>' + escHtml(s.skipped_recent_order) + '</strong> blocked by recent-order protection</li>' +
                        '<li><strong>' + escHtml(s.missing_tracked_sku) + '</strong> missing tracked SKUs</li>' +
                        ( s.proposed_zero > 0 ? '<li><strong class="wsi-wr-zero">' + escHtml(s.proposed_zero) + '</strong> proposed zero stock</li>' : '' ) +
                        '<li><strong>' + escHtml(s.skipped_unmatched) + '</strong> unmatched</li>' +
                        '</ul>'
                    );

                    // CSV download link
                    if (d.run_id) {
                        var csvUrl = WSI_WR_ManualSync.export_url_base +
                            '&run_id=' + encodeURIComponent(d.run_id) +
                            '&nonce='  + encodeURIComponent(WSI_WR_ManualSync.export_nonce);
                        $('#wsi-wr-manual-sync-export').html(
                            '<a href="' + escHtml(csvUrl) + '" class="button button-secondary">' +
                            escHtml(strings.download_csv) + '</a>'
                        );
                    }

                    // Result tables — bucket by action_taken then proposed_action
                    var groups = [
                        { key: 'proposed_increase',                  label: 'Applied — Stock Increased',                   cls: 'wsi-wr-result-increase' },
                        { key: 'proposed_decrease',                  label: 'Applied — Stock Decreased',                   cls: 'wsi-wr-result-decrease' },
                        { key: 'proposed_zero',                      label: 'Applied — Zero Stock',                        cls: 'wsi-wr-result-zero' },
                        { key: 'failed',                             label: 'Failed',                                      cls: 'wsi-wr-result-failed' },
                        { key: 'no_change',                          label: 'No Change Required',                          cls: 'wsi-wr-result-info' },
                        { key: 'skipped_recent_order',               label: 'Skipped — Recent Order Protection',           cls: 'wsi-wr-result-protected' },
                        { key: 'missing_tracked_sku',                label: 'Missing Tracked SKUs',                        cls: 'wsi-wr-result-warn' },
                        { key: 'skipped_unmatched',                  label: 'Unmatched (ShipStation only)',                 cls: 'wsi-wr-result-info' },
                        { key: 'skipped_manage_stock_disabled',      label: 'Manage Stock Disabled',                       cls: 'wsi-wr-result-info' },
                        { key: 'skipped_variation_parent_stock',     label: 'Skipped — Variation Uses Parent Stock',       cls: 'wsi-wr-result-warn' },
                        { key: 'skipped_not_published',              label: 'Skipped — Not Published',                     cls: 'wsi-wr-result-info' },
                        { key: 'skipped_ambiguous',                  label: 'Ambiguous Matches',                           cls: 'wsi-wr-result-warn' },
                        { key: 'skipped_no_available',               label: 'No Available Quantity Returned',              cls: 'wsi-wr-result-warn' },
                        { key: 'skipped_empty_sku',                  label: 'Skipped — Empty SKU',                         cls: 'wsi-wr-result-info' }
                    ];

                    var buckets = {};
                    $.each(d.results, function (i, row) {
                        var key = row.action_taken === 'applied'
                            ? row.proposed_action
                            : (row.action_taken === 'failed' ? 'failed' : row.proposed_action);
                        if (!buckets[key]) { buckets[key] = []; }
                        buckets[key].push(row);
                    });

                    var $tables = $('#wsi-wr-manual-sync-tables');
                    $.each(groups, function (i, grp) {
                        var rows = buckets[grp.key];
                        if (!rows || rows.length === 0) { return; }

                        var isApplied = grp.key === 'proposed_increase' || grp.key === 'proposed_decrease' || grp.key === 'proposed_zero';
                        var stockColLabel = isApplied ? strings.new_stock_col : strings.proposed_stock_col;

                        var $section = $('<div class="wsi-wr-result-section ' + escHtml(grp.cls) + '">');
                        $section.append(
                            '<h4>' + escHtml(grp.label) + ' <span class="wsi-wr-count">(' + escHtml(rows.length) + ')</span></h4>'
                        );

                        var $tbl = $('<table class="wsi-wr-result-table widefat striped">');
                        $tbl.append(
                            '<thead><tr>' +
                            '<th>' + escHtml(strings.sku_col) + '</th>' +
                            '<th>' + escHtml(strings.product_id_col) + '</th>' +
                            '<th>' + escHtml(strings.current_stock_col) + '</th>' +
                            '<th>' + escHtml(strings.ss_available_col) + '</th>' +
                            '<th>' + escHtml(stockColLabel) + '</th>' +
                            '<th>' + escHtml(strings.notes_col) + '</th>' +
                            '</tr></thead>'
                        );
                        var $tbody = $('<tbody>');

                        $.each(rows, function (i, row) {
                            var productCell = row.product_id
                                ? String(row.product_id) + (row.is_variation ? ' (var)' : '')
                                : '—';
                            var stockCell = row.proposed_stock !== null
                                ? '<strong>' + escHtml(row.proposed_stock) + '</strong>'
                                : '—';
                            $tbody.append(
                                '<tr>' +
                                '<td><strong>' + escHtml(row.sku) + '</strong></td>' +
                                '<td>' + escHtml(productCell) + '</td>' +
                                '<td>' + (row.current_stock !== null ? escHtml(row.current_stock) : '—') + '</td>' +
                                '<td>' + (row.shipstation_available !== null ? escHtml(row.shipstation_available) : '—') + '</td>' +
                                '<td>' + stockCell + '</td>' +
                                '<td class="wsi-wr-reason">' + escHtml(row.reason || '') + '</td>' +
                                '</tr>'
                            );
                        });

                        $tbl.append($tbody);
                        $section.append($tbl);
                        $tables.append($section);
                    });

                    $results.show();
                } else {
                    $notice.addClass('notice notice-error').html(
                        '<p><strong>' + escHtml(strings.error) + '</strong> ' +
                        escHtml(response.data.message) + '</p>'
                    );
                }
            })
            .fail(function () {
                $notice.addClass('notice notice-error').html(
                    '<p>' + escHtml(strings.request_failed) + '</p>'
                );
            })
            .always(function () {
                $btn.prop('disabled', false).text(strings.run_manual_sync_label);
                $spinner.css('visibility', 'hidden');
            });
        },

        // ── Export ───────────────────────────────────────────────────────────

        refreshLocations: function (e) {
            e.preventDefault();
            var $btn    = $(this);
            var $result = $('#wsi-wr-refresh-locations-result');
            var $select = $('#wsi-wr-export-location-select');
            var strings = WSI_WR_Admin.strings;

            $btn.prop('disabled', true).text(strings.refreshing_locations);
            $result.removeClass('notice notice-success notice-error').empty();

            $.post(WSI_WR_Admin.ajax_url, {
                action: 'wsi_wr_refresh_inventory_locations',
                nonce:  WSI_WR_Admin.nonces.refresh_locations
            })
            .done(function (response) {
                if (response.success) {
                    var d          = response.data;
                    var locations  = d.locations || [];
                    var selectedId = d.selected_location_id || '';

                    // Rebuild the location dropdown
                    $select.empty();
                    $select.append(
                        $('<option>').val('').text(strings.select_location_placeholder)
                    );
                    $.each(locations, function (i, loc) {
                        var label = (loc.name ? loc.name : loc.inventory_location_id) +
                            ' — ' + loc.inventory_location_id;
                        var $opt  = $('<option>').val(loc.inventory_location_id).text(label);
                        if (loc.inventory_location_id === selectedId) {
                            $opt.prop('selected', true);
                        }
                        $select.append($opt);
                    });

                    var msg = escHtml(d.message || (locations.length + ' location(s) loaded.'));
                    if (d.auto_selected && selectedId) {
                        msg += ' ' + escHtml(strings.location_auto_selected);
                    }
                    $result.addClass('notice notice-success').html('<p>' + msg + '</p>');
                } else {
                    $result.addClass('notice notice-error').html(
                        '<p><strong>' + escHtml(strings.error) + '</strong> ' +
                        escHtml(response.data.message) + '</p>'
                    );
                }
            })
            .fail(function () {
                $result.addClass('notice notice-error').html(
                    '<p>' + escHtml(strings.request_failed) + '</p>'
                );
            })
            .always(function () {
                $btn.prop('disabled', false).text(strings.refresh_locations_label);
            });
        },

        approveSku: function (e) {
            e.preventDefault();
            var $btn      = $(this);
            var $notice   = $('#wsi-wr-tracked-sku-notice');
            var strings   = WSI_WR_Admin.strings;
            var sku       = $.trim($('#wsi-wr-approve-sku').val());
            var productId = $.trim($('#wsi-wr-approve-product-id').val());
            var isVar     = $('input[name="wsi-wr-approve-type"]:checked').val();
            var nonce     = $btn.data('nonce');

            $notice.removeClass('notice notice-success notice-error').empty();

            if (!sku) {
                $notice.addClass('notice notice-error').html('<p>SKU is required.</p>');
                return;
            }
            if (!productId || parseInt(productId, 10) < 1) {
                $notice.addClass('notice notice-error').html('<p>A valid product ID is required.</p>');
                return;
            }

            $btn.prop('disabled', true).text(strings.saving);

            $.post(WSI_WR_Admin.ajax_url, {
                action:       'wsi_wr_approve_tracked_sku',
                sku:          sku,
                product_id:   productId,
                is_variation: isVar,
                nonce:        nonce
            })
            .done(function (response) {
                if (response.success) {
                    $notice.addClass('notice notice-success').html(
                        '<p>' + escHtml(response.data.message) + '</p>' +
                        '<p><em>' + escHtml(strings.reload_page) + '</em></p>'
                    );
                    setTimeout(function () {
                        window.location.reload();
                    }, 2000);
                } else {
                    $notice.addClass('notice notice-error').html(
                        '<p><strong>' + escHtml(strings.error) + '</strong> ' +
                        escHtml(response.data.message) + '</p>'
                    );
                    $btn.prop('disabled', false).text(strings.approve_label);
                }
            })
            .fail(function () {
                $notice.addClass('notice notice-error').html(
                    '<p>' + escHtml(strings.request_failed) + '</p>'
                );
                $btn.prop('disabled', false).text(strings.approve_label);
            });
        }
    };

    $(document).ready(function () {
        WSI_WR.init();
    });

}(jQuery));
