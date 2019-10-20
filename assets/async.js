jQuery(function ($) {
    window.handle_ajax = function (res) {
        res = $.extend({
            code: null,
            message: null,
            redirect: null,
            refresh: null,
            callback: null,
            callback_args: null
        }, res);

        if (res.refresh) {
            window.location.reload();
        }

        if (res.redirect) {
            window.location.href = res.redirect;
            return;
        }

        if (res.message) {
            var m = $(".msgbox");
            m.text(res.message);
            m.show();
        }

        if (res.callback) {
            if (res.callback in window) {
                window[res.callback](res.callback_args);
            } else {
                console.log("callback handler not found", res.callback);
            }
        }
    };

    $(document).on("submit", "form.async", function (e) {
        e.preventDefault();
        var form = $(this);

        if (window.FormData === undefined) {
            alert("This function does not work in your old browser.");
            return;
        }

        $("body").addClass("wait");

        var buttons = form.find("buttons");
        var msgbox = form.find(".msgbox");
        var pgbar = form.find(".progressbar");

        if (pgbar.length == 0) {
            form.append("<div class='progressbar' style='display: none'><div class='label'></div><div class='done'></div></div>");
            pgbar = form.find(".progressbar");
        }

        buttons.prop("disabled", true);

        msgbox.hide();

        var fd = new FormData($(this)[0]);

        var show_progress = function (percent, loaded, total) {
            if ("console" in window) console.log("upload progress: " + percent + "%");

            if (total >= 100000) {
                var mbs = function (bytes) { return (Math.round(bytes / 1048576 * 100) / 100).toFixed(2); };

                var label = mbs(loaded) + " MB / " + mbs(total) + " MB";
                pgbar.find(".label").html(label);

                pgbar.find(".done").css("width", parseInt(percent) + "%");

                pgbar.show();
            }
        };

        var show_message = function (msg) {
            if (msgbox.length > 0) {
                msgbox.text(msg);
                msgbox.show();
            } else {
                alert(msg);
            }
        };

        $.ajax({
            url: $(this).attr("action"),
            type: "POST",
            data: fd,
            processData: false,
            contentType: false,
            cache: false,
            dataType: "json",
            xhr: function () {
                var xhr = $.ajaxSettings.xhr();
                xhr.upload.onprogress = function (e) {
                    var pc = Math.round(e.loaded / e.total * 100);
                    show_progress(pc, e.loaded, e.total);
                };
                return xhr;
            }
        }).done(handle_ajax)
        .always(function () {
            $("body").removeClass("wait");
            pgbar.hide();
            buttons.prop("disabled", false);
        }).fail(function (xhr, status, message) {
            if (xhr.status == 404)
                show_message("Form handler not found.");
            else if (message == "Debug Output")
                show_message(xhr.responseText);
            else if (status == "error" && message == "")
                ;  // aborted, e.g. F5 pressed.
            else if (xhr.responseText)
                show_message("Request failed: " + xhr.responseText);
            else
                show_message(sfmt("Request failed: {0}\n\n{1}", message, xhr.responseText));
        });
    });

    $(document).on("click", "a.async", function (e) {
        e.preventDefault();

        var props = {
            'url': $(this).attr('href'),
            'type': $(this).hasClass('post') ? 'POST' : 'GET',
            'data': {
                'next': $(this).data('next'),
            }
        };

        var cnf = $(this).data('confirm');
        if (cnf != undefined && !confirm(cnf))
            return;

        $.ajax(props).done(handle_ajax);
    });

    $(document).on("change", ".autosubmit", function (e) {
        $(this).closest("form").submit();
    });
});
