jQuery(function ($) {
    window.reload_files = function () {
        $.ajax({
            url: '/wiki/recent-files.json',
            type: 'GET',
            dataType: 'json'
        }).done(function (res) {
            if ('files' in res) {
                var items = res.files.map(function (f) {
                    return sfmt("<a data-id='{0}' href='/wiki?name=File:{0}' title='{1}' target='_blank'><img src='/node/{0}/download/small'/></a>", f.id, f.name_html);
                });

                var html = items.join('');

                $('.wiki_edit .tiles .wrap').html(items.join(''));
            } else {
                handle_ajax(res);
            }
        });
    };

    $(document).on('click', '.wiki_edit .toolbar button', function (e) {
        var name, form;

        $(this).blur();

        name = $(this).attr('name');
        form = $('form.wikisource');

        if (name == 'save') {
            form.submit();
            e.preventDefault();
        }

        else if (name == 'cancel') {
            e.preventDefault();
            window.location.href = $('.wikiedit').data('back');
        }

        else if (name == 'toc') {
            e.preventDefault();
            wiki_insert_text("<div id=\"toc\"></div>");
        }

        else if (name == 'upload') {
            $('.wiki_edit .files').toggle();
            $(this).toggleClass('active');

            if ($('.wiki_edit .files input[name=query]').is(':visible')) {
                $('.wiki_edit .files input[name=query]').focus();
                reload_files();
            }
        }

        else if ($(this).data('link')) {
            e.preventDefault();
            window.open($(this).data('link'), '_blank');
        }
    });

    $(document).on('click', '.wiki_edit .tiles a[data-id]', function (e) {
        e.preventDefault();

        var id = $(this).data('id');
        wiki_insert_text("[[image:" + id + "]]\n");

        var ta = $('textarea.wiki')[0];
        ta.selectionStart = ta.selectionEnd;
    });

    $(document).on('change', '.wiki_edit input#uploadctl', function (e) {
        $(this).closest('form').submit();
    });
});
