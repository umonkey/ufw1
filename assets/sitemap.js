jQuery(function ($) {
    $(document).on("click", "#show_sitemap", function (e) {
        var c = $("#sitemap");
        if (c.length == 1) {
            e.preventDefault();
            c.toggle();
        }
    });

    $(document).on("click", ".toggle", function (e) {
        var sel = $(this).attr("data-toggle"),
            em = $(sel);
        if (em.length == 1) {
            e.preventDefault();
            $(this).blur();

            if (em.is(":visible")) {
                em.hide("fade", 100);
            } else {
                $(".toggled").hide("fade", 100);
                em.show("fade", 100);
            }

            var inp = em.find("input:first");
            if (inp.length > 0)
                inp.focus();
        }
    });

    // Close popups on click outside of them.
    $(document).on("click", function (e) {
        if ($(e.target).closest(".toggled").length == 0) {
            $(".toggled:visible").hide("fade", 100);
        }
    });
});
