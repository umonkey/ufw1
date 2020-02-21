window.wiki_insert_text = function (text) {
    var ta = $("textarea")[0],
        tv = ta.value,
        ss = ta.selectionStart,
        se = ta.selectionEnd,
        tt = tv.substring(ss, se);

    var ntext = tv.substring(0, ss) + text + tv.substring(se);
    ta.value = ntext;
    ta.selectionStart = ss; // ss + text.length;
    ta.selectionEnd = ss + text.length;
    ta.focus();
};


jQuery(function ($) {
    var init = function () {
        $('.formatted a.external').each(function (em) {
            $(this).attr('target', '_blank');
        });
    };

    init();
    $(document).on('ufw:reload', init);
});


/**
 * Edit page sections.
 **/
jQuery(function ($) {
    var update = function () {
        var link = $("link[rel=edit]:first");
        if (link.length == 0) {
            return;
        }

        var base = link.attr("href");

        $(".formatted h1, .formatted h2, .formatted h3, .formatted h4, .formatted h5").each(function () {
            var text = $(this).text();
            var link = base + "&section=" + encodeURI(text);
            $(this).append("<span class='wiki-section-edit'> [ <a href='" + link + "'>редактировать</a> ]</span>");
        });
    };

    update();
    $(document).on('ufw:reload', update);
});


window.editor_insert = function (text)
{
    var ta = $("textarea")[0];

    var v = ta.value,
        s = ta.selectionStart,
        e = ta.selectionEnd;

    var ntext = v.substring(0, s) + text + v.substring(e);
    ta.value = ntext;
    ta.selectionStart = e + text.length;
    ta.selectionEnd = e + text.length;

    $("#block, .dialog").hide();
    $("textarea").focus();
}
