/**
 * Photoalbum animation.
 * Shows the more-less buttons.
 **/
jQuery(function ($) {
    var init = function () {
        $(".photoalbum").each(function () {
            var width = 0,
                max_width = $(this).width() - 35;

            $(this).find("a.image").each(function () {
                width += $(this).width() + 5;
                if (width >= max_width)
                    $(this).addClass("overflow");
            });

            var of = $(this).find("a.image.overflow");
            if (of.length > 0) {
                $(this).append("<div class='icon showmore'><i class='fas fa-chevron-circle-right'></i></div>");
                $(this).append("<div class='icon showless'><i class='fas fa-chevron-circle-left'></i></div>");
            }

            // show everything, without flickering
            $(this).fadeTo(100, 1.0);
        });
    };

    init();
    $(document).on('ufw:reload', init);

    $(document).on("click", ".photoalbum .showmore", function (e) {
        var album = $(this).closest(".photoalbum");
        album.toggleClass("open");
    });

    $(document).on("click", ".photoalbum .showless", function (e) {
        var album = $(this).closest(".photoalbum");
        album.toggleClass("open");
    });
});
