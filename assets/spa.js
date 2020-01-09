/**
 * Single-page interface.
 *
 * Not really.  Just load local pages with XHR and update page contents.
 * Fires custon "ufw:reload" event to re-initialize controls.
 * Drastically improves page load speed.
 **/
jQuery(function ($) {
    const SELECTOR = '#body';

    var openURL = function (link, callback) {
        var bars = $('a.bars i'),
            cls = bars.attr('class');

        bars.attr('class', 'fas fa-spinner fa-spin');

        // Load contents.
        $.ajax({
            url: link,
            dataType: 'html',
            type: 'GET'
        }).done(function (res) {
            var d = $('<div>').append($.parseHTML(res)).find(SELECTOR);

            if (d.length == 0) {
                console.log('ERROR: no contents, falling back to page reload');
                window.location.href = link;
            }

            else {
                $(SELECTOR).replaceWith(d);
                $(document).trigger('ufw:reload');

                if (callback) {
                    callback();
                }
            }
        }).always(function () {
            bars.attr('class', cls);
        });
    };

    $(document).on('click', 'a', function (e) {
        var link = $(this).attr('href');

        // External link, pass.
        if (link.indexOf('//') >= 0) {
            return;
        }

        // Local link, pass.
        if (link[0] == '#') {
            return;
        }

        // New tab, pass.
        if ($(this).attr('target')) {
            return;
        }

        // No reload target, malformed page.
        if ($(SELECTOR).length == 0) {
            return;
        }

        openURL(link, function () {
            window.history.pushState({link: link}, '', link);
        });

        e.preventDefault();
    });

    window.addEventListener('popstate', function (e) {
        if (e.state !== null) {
            openURL(e.state.link);
        } else {
            openURL(document.location.href);
        }
    });
});
