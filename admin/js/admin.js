/**
 * Dragon Content Decay - Admin JavaScript
 */

(function($) {
    'use strict';

    // Icon plus text, with the (translated) text added as a text node.
    function setLabel($button, icon, text) {
        $button.empty()
            .append($('<span class="dashicons"></span>').addClass(icon))
            .append(document.createTextNode(' ' + text));
    }

    // Manual sync button
    $('#dcd-manual-sync').on('click', function(e) {
        e.preventDefault();

        var $button = $(this);
        var originalText = $button.html();

        // Disable button and show loading state
        $button.prop('disabled', true).addClass('syncing');
        setLabel($button, 'dashicons-update', dcdAdmin.i18n.syncing);

        $.ajax({
            url: dcdAdmin.ajaxUrl,
            type: 'POST',
            data: {
                action: 'dragoncontentdecay_manual_sync',
                nonce: dcdAdmin.nonce
            },
            success: function(response) {
                if (response.success) {
                    // Show success message
                    setLabel($button, 'dashicons-yes', dcdAdmin.i18n.synced);

                    // Reload page after short delay to show updated data
                    setTimeout(function() {
                        location.reload();
                    }, 1500);
                } else {
                    alert((response.data && response.data.message) || dcdAdmin.i18n.error);
                    $button.prop('disabled', false).removeClass('syncing');
                    $button.html(originalText);
                }
            },
            error: function() {
                alert(dcdAdmin.i18n.error);
                $button.prop('disabled', false).removeClass('syncing');
                $button.html(originalText);
            }
        });
    });

    // Trend filter
    $('#dcd-filter-trend').on('change', function() {
        var trend = $(this).val();
        var $rows = $('.dcd-posts-table tbody tr');

        if (!trend) {
            $rows.show();
        } else {
            $rows.hide();
            $rows.filter('[data-trend="' + trend + '"]').show();
        }
    });

    // Show/hide password field
    $('#dragoncontentdecay_google_client_secret').on('focus', function() {
        $(this).attr('type', 'text');
    }).on('blur', function() {
        $(this).attr('type', 'password');
    });

    // Copy redirect URI to clipboard
    $('.dcd-step-content code').on('click', function() {
        var text = $(this).text();

        if (navigator.clipboard) {
            navigator.clipboard.writeText(text).then(function() {
                // Show copied feedback
                var $code = $(this);
                var originalBg = $code.css('background');
                $code.css('background', '#d1fae5');
                setTimeout(function() {
                    $code.css('background', originalBg);
                }, 1000);
            }.bind(this));
        }
    });

})(jQuery);
