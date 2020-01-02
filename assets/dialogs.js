window.dialogs = {};

window.dialogs.close = function () {
    $('.dialog, #block').hide();
};

jQuery(function ($) {
    $(document).on('click', '.dialog .btn.cancel', function (e) {
        e.preventDefault();
        dialogs.close();
    });
});
