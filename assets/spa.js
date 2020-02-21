/**
 * Quicker page load.
 *
 * Instead of reloading whole pages, loads them using XHR and replaces current
 * page contents.  Maintains navigation history.  Works only for local pages,
 * has a blacklist.
 *
 * After loading and displaying the new page, fires custom "ufw:reload" event,
 * so that initialization scripts could re-run, initialize controls etc.
 *
 * Only updates contents of the .spa-container block, be it body or something
 * smaller.  If no such block exists -- does nothing.
 *
 * TODO: Save current scroll position on navigating BACK.  Example: (1) open the blog
 * index, scroll to the middle, open a blog entry.  It scrolls to the top of the page,
 * which is good.  Now (2) navigate back.  It opens the blog index and scrolls to the
 * middle, where you left, which is great.  Now (3) navigate forward!  It scrolls to
 * the top again, ignoring the position where you really left that page before.
 *
 * Needs to call savePosition(), but doint that in the popstate event handler seems
 * to be wrong, ruins all navigation.
 *
 * TODO: maintain window title, currently not done at all.
 **/

/* global spa_link_filter */
/* eslint camelcase: 0 */

jQuery(function ($) {
    const SELECTOR = '.spa-container';

    var container = $(SELECTOR);

    if (container.length === 0) {
        console && console.log('spa: .spa-container not found, not installing.');
        return;
    }

    if (container.is('body') || container.closest('body').length === 0) {
        console && console.log('spa: .spa-container MUST be inside <body>, won\'t work in current setup.');
        return;
    }

    console && console.log('spa: ready.');

    /**
     * Log a message to the console.
     **/
    var log = function (message) {
        console && console.log('ufw/spa.js: ' + message);
    };

    /**
     * Scroll to the anchor, specified in the link.
     **/
    var scrollTo = function (link, scrollTop) {
        var parts = link.split('#', 2);

        if (parts.length === 1) {
            log('scrolling to ' + scrollTop);
            window.scroll({ top: scrollTop });
        } else {
            var l = $('#' + parts[1]);

            if (l.length === 1) {
                var y = l.offset().top;
                log('scrolling to ' + y);
                window.scroll({ top: y });
            } else {
                log('cannot scroll to', link);
            }
        }
    };

    /**
     * Save current position in history.
     **/
    var savePosition = function () {
        window.history.replaceState({
            link: window.location.href,
            scrollTop: $(window).scrollTop()
        }, '', window.location.href);
        log('scroll position saved: top=' + $(window).scrollTop() + ', link=' + window.location.href);
    };

    /**
     * Update page title with the one from HTML.
     **/
    var update_title = function (html) {
        var m = html.match(/<title>(.+?)<\/title>/);
        if (m !== null) {
            window.document.title = m[1];
        } else {
            log('window title not found.');
        }
    };

    /**
     * Open the specified link.
     *
     * 1) Reloads the page if necessary (unless in-page navigation).
     * 2) Scrolls to the specified position (if an anchor is used).
     * 3) Calls the callback function.
     *
     * Does NO control on what the link is -- that belongs to the calling side,
     * namely the click event handler.  This assumes that the link IS good to work with.
     *
     * TODO: failure handling, e.g., page not found.
     *
     * @param {string} link      - Page to load.
     * @param {int}    scrollTop - Position to scroll to.  Only defined if we're navigating the history, zero for new pages.
     * @param {func}   callback  - Function to call afterwards.
     **/
    var openURL = function (link, scrollTop, callback) {
        // Start spinning.
        var bars = $('header .bars i');
        var cls = bars.attr('class');
        bars.attr('class', 'fas fa-spinner fa-spin');

        log('openURL: top=' + scrollTop + ', link=' + link);

        // Local anchor.
        if (link[0] === '#') {
            scrollTo(link, scrollTop);

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

            if (d.length === 0) {
                log('no contents, falling back to page reload');
                window.location.href = link;
            } else {
                update_title(res);

                $(SELECTOR).replaceWith(d);
                $(document).trigger('ufw:reload');

                scrollTo(link, scrollTop);

                if (callback) {
                    callback();
                }
            }
        }).always(function () {
            bars.attr('class', cls);
        }).fail(function () {
            log('error loading page.');
            alert('Error loading page.');

            if (callback) {
                callback();
            }
        });
    };

    /**
     * Handle link clicks.
     *
     * Ignores external links.
     * Ignores some special links, like photos in an album -- they pop up.
     **/
    $(document).on('click', 'a', function (e) {
        if (e.ctrlKey || e.shiftKey) {
            return;
        }

        // First of all, save current scroll position.
        //
        // This is needed even if we don't load the new page.  For example,
        // if a fancybox link is clicked -- there's a pop-up, which updates
        // the location.hash, and closes on navigating back.  Upon that navigation
        // we need to maintain the scroll position.  We sure don't want to
        // scroll to top.
        savePosition();

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
        if ($(SELECTOR).length === 0) {
            return;
        }

        // Fancybox pop-up, no need to load.
        if ($(this).is('[data-src]')) {
            return;
        }

        // White list pattern.
        if (typeof spa_link_filter === 'function') {
            if (!spa_link_filter($(this))) {
                log('link blacklisted by spa_link_filter');
                return;
            }
        }

        openURL(link, 0, function () {
            window.history.pushState({
                link: link,
                scrollTop: $(window).scrollTop()
            }, '', link);
        });

        e.preventDefault();
    });

    /**
     * Handle history navigation.
     *
     * This is fired on both forward and backwards navigation.
     *
     * @param {Object} e -- the NEW history item.
     **/
    window.addEventListener('popstate', function (e) {
        // savePosition();

        var state = $.extend({
            link: null,
            scrollTop: 0
        }, e.state ? e.state : {});

        log('popstate event, scrollTop=' + state.scrollTop + ', link=' + state.link);

        if (state.link !== null) {
            openURL(state.link, state.scrollTop);
        } else {
            openURL(document.location.href);
        }
    });

    /**
     * Tell some clever browsers that we don't need them maintaining the scroll position.
     * https://stackoverflow.com/q/10742422/371526
     **/
    if ('scrollRestoration' in history) {
        history.scrollRestoration = 'manual';
    }
});
