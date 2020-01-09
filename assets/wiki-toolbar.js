jQuery(function ($) {
    $(document).on("click", "a.tool", function (e) {
        var dsel = $(this).attr("data-dialog");
        if (dsel) {
            $(dsel).dialog({
                autoOpen: true,
                modal: true,
                open: function () {
                    if ($(this).is("form"))
                        $(this)[0].reset();  // clean up the fields
                    $(this).find(".msgbox").hide();
                }
            });
            e.preventDefault();
        }

        var action = $(this).attr("data-action");
        if (action == "map") {
            $("#dlgMap").show();
            e.preventDefault();
        }
    });

    $(document).on("change", "#filePhoto", function (e) {
        $(this).closest("form").submit();
    });
});
