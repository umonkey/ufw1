/**
 * Single-page interface.
 *
 * Not really.  Just load local pages with XHR and update page contents.
 * Fires custon "ufw:reload" event to re-initialize controls.
 * Drastically improves page load speed.
 *
 * TODO: scroll restoration doesn't work, hard-coded 0 for now.
 **/
jQuery(function ($) {
    const SELECTOR = '#body';

    var fqurl = function (link) {
        var a = document.createElement('a');
        a.href = link;
        return a.href;
    };

    var scrollTo = function (link) {
        var parts = link.split('#', 2);

        if (parts.length == 1) {
            console.log('scrolling to top');
            window.scroll({top: 0});
        } else {
            var l = $('#' + parts[1]);

            if (l.length == 1) {
                var y = l.offset().top;
                console.log('scrolling to ' + y);
                window.scroll({top: y});
            } else {
                console.log('cannot scroll to', link);
            }
        }
    };

    var openURL = function (link, scroll, callback) {
        var bars = $('a.bars i'),
            cls = bars.attr('class');

        bars.attr('class', 'fas fa-spinner fa-spin');

        if (link[0] == '#') {
            scrollTo(link);

            if (callback) {
                callback();
            }

            return;
        }

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

                scrollTo(link);

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

        // New tab, pass.
        if ($(this).attr('target')) {
            return;
        }

        // No reload target, malformed page.
        if ($(SELECTOR).length == 0) {
            return;
        }

        // Update current history entry to save scroll position.
        window.history.replaceState({
            link: window.location.href,
            scrollTop: $(window).scrollTop()
        }, '', window.location.href);

        openURL(link, 0, function () {
            window.history.pushState({
                link: link,
                scrollTop: $(window).scrollTop()
            }, '', link);
        });

        e.preventDefault();
    });

    window.addEventListener('popstate', function (e) {
        console.log('popstate', e.state ? e.state.link : null);

        if (e.state !== null) {
            openURL(e.state.link);
        } else {
            openURL(document.location.href);
        }
    });

    if ('scrollRestoration' in history) {
        history.scrollRestoration = 'manual';
    }
});
