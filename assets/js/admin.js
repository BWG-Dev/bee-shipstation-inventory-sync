/* global WSI_WR_Admin, jQuery */
(function ($) {
    'use strict';

    function escHtml(str) {
        return $('<span>').text(String(str)).html();
    }

    var WSI_WR = {
        init: function () {
            $('#wsi-wr-test-connection').on('click', WSI_WR.testConnection);
            $('#wsi-wr-fetch-sample').on('click', WSI_WR.fetchSample);
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
                    var d   = response.data;
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
        }
    };

    $(document).ready(function () {
        WSI_WR.init();
    });

}(jQuery));
