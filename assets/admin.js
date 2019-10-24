jQuery(function ($) {
    $(document).on('change', 'input.published', function (e) {
        var tr = $(this).closest('tr'),
            checked = $(this).is(':checked');

        if (checked) {
            tr.removeClass('unpublished').addClass('published');
        } else {
            tr.removeClass('published').addClass('unpublished');
        }

        $(this).blur();

        $.ajax({
            url: '/admin/nodes/publish',
            data: {id: $(this).attr('value'), published: checked ? 1 : 0},
            type: 'POST',
            dataType: 'json'
        }).done(handle_ajax);
    });

    $(document).on('change', 'table input[type=checkbox].deleted', function (e) {
        var value = $(this).is(':checked') ? 1 : 0,
            id = $(this).attr('value');

        $.ajax({
            url: '/admin/nodes/delete',
            data: {id: id, deleted: value},
            type: 'POST',
            dataType: 'json'
        }).done(handle_ajax);
    });
});
