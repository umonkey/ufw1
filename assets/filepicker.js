/**
 * Call this to display the file picker dialog.
 **/
window.ufw_filepicker = function (callback) {
    window.ufw_filepicker_callback = callback;

    var dlg = $('#node-upload');
    if (dlg.length == 0) {
        var html = '<form id="node-upload" class="async filepicker dialog" action="/admin/upload" method="post" style="display: none">';

        html += '<div class="form-group">';
        html += '<label>Выбери недавний файл</label>';
        html += '<div class="recent"></div>';
        html += '</div>';

        html += '<div class="form-group">';
        html += '<label>Или загрузи новый</label>';
        html += '<input class="form-control autosubmit" type="file" name="files[]" accept="image/*" multiple="multiple"/>';
        html += '</div>';

        html += '<div class="form-group">';
        html += '<label>Или вставь ссылку на файл</label>';
        html += '<input class="form-control wide" type="text" name="link" placeholder="https://..."/>';
        html += '</div>';

        html += '<div class="form-actions">';
        html += '<button class="btn btn-primary" type="submit">Загрузить</button>';
        html += '<button class="btn btn-default cancel" type="button">Отмена</button>';
        html += '</div>';

        html += '<div class="msgbox"></div>';

        $('body').append(html);
        dlg = $('#node-upload');
    }

    if ($("#block").length == 0)
        $("body").append("<div id='block'></div>");

    dlg.find('.recent').html('');
    dlg.find('.msgbox').hide();
    dlg[0].reset();

    $('#node-upload, #block').show();

    $.ajax({
        url: "/files/recent.json",
        type: "GET",
        dataType: "json"
    }).done(function (res) {
        if ('files' in res) {
            var items = res.files.map(function (f) {
                return sfmt("<a data-id='{0}' data-thumbnail='{2}' href='{3}' title='{1}' target='_blank'><img src='{2}'/></a>", f.id, f.name_html, f.thumbnail, f.link);
            });

            $("#node-upload .recent").html(items.join(""));
        } else {
            handle_ajax(res);
        }
    });
};

jQuery(function ($) {
    $(document).on('click', 'form.filepicker .recent a', function (e) {
        e.preventDefault();
        $('#block, form.filepicker').hide();

        ufw_filepicker_callback([{
            'id': $(this).data('id'),
            'image': $(this).data('thumbnail')
        }]);
    });
});
