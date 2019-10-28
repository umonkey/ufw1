jQuery(function ($) {
    var wb = $('.wiki_buttons');
    if (wb.length > 0) {
        wb.append('<li><a class="btn btn-default tool" data-action="toc" href="/wiki?name=wiki:оглавление" title="Вставить оглавление" target="_blank"><i class="fa fa-list-ol"></i></a></li>');

        $(document).on('click', '.wiki_buttons a[data-action=toc]', function (e) {
            e.preventDefault();
            wiki_insert_text("<div id=\"toc\"></div>");
        });
    }
});
