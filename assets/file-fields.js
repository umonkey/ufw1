jQuery(function ($) {
    $(document).on('click', 'a.thumbnail.pick', function (e) {
        e.preventDefault();
        $(this).blur();

        var _this = $(this);

        ufw_filepicker(function (res) {
            var html = sfmt('<a class="thumbnail" href="/node/{0}/download/large" data-fancybox="gallery">', res.id);
            html += sfmt('<input type="hidden" name="node[files][]" value="{0}"/>', res.id);
            html += sfmt('<img src="{0}" alt="" />', res.image);
            html += '<span class="file-delete"><i class="fas fa-trash-alt"></i></span>';
            html += '</a>';

            _this.before(html);
        });
    });

    $(document).on('click', 'a.thumbnail .file-delete', function (e) {
        $(this).closest('a.thumbnail').remove();
    });
});
