/**
 * Adds the upload file button.
 **/
jQuery(function ($) {
    $(".wiki_buttons").append('<li><a id="wiki_btn_upload" class="btn btn-default" href="/wiki/files" target="_blank" title="Загрузить фото"><i class="fa fa-image"></i></a></li>');

    $(document).on("click", "#wiki_btn_upload", function (e) {
        e.preventDefault();

        if ($("#dlg-upload").length == 0) {
            var html = '<form id="dlg-upload" class="dialog async" action="/wiki/upload" method="post" style="display: none">';

            html += '<div class="form-group">';
            html += '<label>Выбери недавний файл</label>';
            html += '<div class="recent"></div>';
            html += '</div>';

            html += '<div class="form-group">';
            html += '<label>Или загрузи новый</label>';
            html += '<input class="form-control autosubmit" type="file" name="file" accept="image/*"/>';
            html += '</div>';

            html += '<div class="form-group">';
            html += '<label>Или вставь ссылку на файл</label>';
            html += '<input class="form-control uploadLink wide" type="text" name="link" placeholder="https://..." autocomplete="off"/>';
            html += '</div>';

            html += '<div class="form-actions">';
            html += '<button class="btn btn-primary" type="submit">Загрузить</button>';
            html += '<button class="btn btn-default cancel" type="button">Отмена</button>';
            html += '</div>';

            html += '<p class="msgbox" style="display: none"></p>';

            html += '</form>';

            $("textarea.wiki").closest("form").after(html);
        }

        if ($("#block").length == 0)
            $("body").append("<div id='block'></div>");

        $("#dlg-upload .recent").html("");
        $("#dlg-upload")[0].reset();

        $("#dlg-upload .msgbox").hide();
        $("#dlg-upload, #block").show();
        $(".uploadLink").focus();

        $.ajax({
            url: "/wiki/recent-files.json",
            type: "GET",
            dataType: "json"
        }).done(function (res) {
            var items = res.files.map(function (f) {
                return sfmt("<a data-id='{0}' href='/wiki?name=File:{0}' title='{1}' target='_blank'><img src='/i/thumbnails/{0}.jpg'/></a>", f.id, f.name_html);
            });

            $("#dlg-upload .recent").html(items.join(""));
        });
    });

    $(document).on("click", "#dlg-upload .recent a", function (e) {
        e.preventDefault();
        var id = $(this).attr("data-id"),
            code = sfmt("[[image:{0}]]", id);

        editor_insert(code);
    });
});
