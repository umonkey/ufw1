if (!("onerror" in window)) {
    window.onerror = function (msg, url, line, col, error) {
      alert("Error: " + msg + "\nLine: " + line + ", col: " + col + "\nurl: " + url);
    };
}


function sfmt(format) {
    var args = Array.prototype.slice.call(arguments, 1);
    return format.replace(/{(\d+)}/g, function (match, number) {
        return typeof args[number] == "undefined" ? match : args[number];
    });
}


// Parse query string, put to location.queryString.
(function () {
    location.queryString = {};
    location.search.substr(1).split("&").forEach(function (pair) {
        if (pair === "") return;
        var parts = pair.split("=");
        location.queryString[parts[0]] = parts[1] &&
            decodeURIComponent(parts[1].replace(/\+/g, " "));
    });
})();


jQuery(function ($) {
    enable_wiki_fancybox();
    enable_map();
    enable_toolbar();
    enable_sitemap_toggle($);
});


function enable_map()
{
  var create_map = function (div_id) {
    var osm_layer = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      maxZoom: 18,
      attribution: 'Map data © <a href="http://openstreetmap.org">OSM</a> contributors'
    });

    /*
    var osmfr_layer = L.tileLayer('http://a.tile.openstreetmap.fr/osmfr/{z}/{x}/{y}.png', {
      maxZoom: 20,
      attribution: 'Map data © <a href="http://openstreetmap.org">OpenStreetMap</a> contributors'
    });
    */

    // UNOFFICIAL HACK.
    // http://stackoverflow.com/questions/9394190/leaflet-map-api-with-google-satellite-layer
    /*
    var google_layer = L.tileLayer('http://{s}.google.com/vt/lyrs=m&x={x}&y={y}&z={z}', {
      maxZoom: 19,
      subdomains: ['mt0', 'mt1', 'mt2', 'mt3']
    });
    */

    var google_hybrid_layer = L.tileLayer('http://{s}.google.com/vt/lyrs=s,h&x={x}&y={y}&z={z}', {
      maxZoom: 19,
      subdomains: ['mt0', 'mt1', 'mt2', 'mt3']
    });

    var map = L.map(div_id, {
      layers: [osm_layer],
      loadingControl: true,
      fullscreenControl: true,
      scrollWheelZoom: false
    });

    L.control.layers({
      "OpenStreetMap": osm_layer,
      // "OSM France (больше зум)": osmfr_layer,
      "Google Satellite": google_hybrid_layer
    }).addTo(map);

    map.on("focus", function () {
        map.scrollWheelZoom.enable();
    });

    map.on("blur", function () {
        map.scrollWheelZoom.disable();
    });

    return map;
  };


    var cluster_map = function (div_id, markers)
    {
        var map = create_map(div_id);

        var points = [];
        var cluster = L.markerClusterGroup();

        for (var idx in markers) {
            var m = $.extend({
                latlng: null,
                html: null,
                link: null,
                title: null,
                image: null
            }, markers[idx]);

            if (m.latlng) {
                points.push(m.latlng);

                var m2 = L.marker(m.latlng);
                m2.addTo(cluster);

                var html = null;
                if (m.html !== null)
                    html = m.html;
                else if (m.link && m.title)
                    html = sfmt("<p><a href='{0}'>{1}</a></p>", m.link, m.title);
                else if (m.title)
                    html = sfmt("<p>{0}</p>", m.title);
                if (m.image)
                    html += sfmt("<p><a href='{0}'><img src='{1}' width='300'/></a></p>", m.link, m.image);

                if (html !== null)
                    m2.bindPopup(html);
            }
        }

        map.addLayer(cluster);

        if (markers.length > 1) {
            var bounds = L.latLngBounds(points);
            map.fitBounds(bounds);
        } else {
            map.setView(markers[0].latlng, 12);
        }

        map.on("click", function (e) {
            if (div_id == 'testmap') {
                var ll = sfmt("{0},{1}", e.latlng.lat, e.latlng.lng);
                var html = sfmt("<div class='map' data-center='{0}'></div>", ll);
                $("pre:first code").text(html);
            } else if (e.originalEvent.ctrlKey) {
                var ll = sfmt("{0},{1}", e.latlng.lat, e.latlng.lng);
                var html = sfmt("<div class=\"map\" data-center=\"{0}\"></div>", ll);
                console.log("map center: " + ll);
                console.log("map html: " + html);
            }
        });
    };

    $(".map").each(function () {
      var div = $(this);
      if (!div.attr("id")) {
          var id = "map_" + Math.round(Math.random() * 999999);
          div.attr("id", id);
      }

      div.html("");

      var source = div.attr("data-src");
      var points = div.attr("data-points");
      var center = div.attr("data-center");
      var zoom = parseInt(div.attr("data-zoom") || 13);

      if (source) {
        $.ajax({
          url: source,
          dataType: "json"
        }).done(function (res) {
          res = $.extend({
            markers: []
          }, res);

          var map = create_map(div.attr("id"));

          var points = [];
          var cluster = L.markerClusterGroup();

          for (var idx in res.markers) {
            var tree = res.markers[idx];
            if (tree.latlng) {
              points.push(tree.latlng);

              var m = L.marker(tree.latlng);
              m.addTo(cluster);

              var html = "<p><a href='" + tree.link + "'>" + tree.title + "</a></p>";
              m.bindPopup(html);
            }
          }

          map.addLayer(cluster);

          var bounds = L.latLngBounds(points);
          map.fitBounds(bounds);
        });
      }

      else if (points) {
        var points = JSON.parse(points),
            markers = [];

        for (var idx in points) {
            var p = $.extend({
                title: null,
                link: null,
                image: null
                }, points[idx]);

            if (p.link == null)
                p.link = "/wiki?name=" + encodeURI(p.title);

            markers.push(p);
        }

        cluster_map(div.attr("id"), markers);
      }

      else if (center) {
        var set_ll = function (ll) {
            var set = function (attr, value) {
              var sel = div.attr(attr);
              if (sel) {
                var ctl = $(sel);
                if (ctl.length)
                  ctl.val(value);
              }
            }

            set("data-for", ll.lat + ", " + ll.lng);
            set("data-for-lat", ll.lat);
            set("data-for-lng", ll.lng);
        };

        var parts = center.split(/,\s*/);
        if (parts.length == 2) {
          var markers = [];

          markers.push({
              latlng: [parseFloat(parts[0]), parseFloat(parts[1])],
              title: div.attr("data-title") || $("h1:first").text()
          });

          /*
          markers.push({
              latlng: [56.28333, 28.48333],
              title: "Себеж",
              link: "wiki?name=Себеж"
          });
          */

          cluster_map(div.attr("id"), markers);
        }
      }

      else {
          console && console.log("Map center not defined.");
      }
    });
}


/**
 * Включение просмотра картинок через Fancybox.
 *
 * Заворачивает уменьшенные превьюшки в ссылки на исходные изображения.
 **/
function enable_wiki_fancybox()
{
    /*
    $("main img").each(function () {
      if ($(this).parent().is("a")) {
          $(this).parent().attr("data-fancybox", "gallery");
      } else {
          var link = $(this).attr("src");
          link = $(this).attr("data-large") || link.replace(/_small\./, ".");

          $(this).wrap("<a></a>");

          var p = $(this).closest("a");
          p.attr("href", link);
          p.attr("data-fancybox", "gallery");

          var t = $(this).attr("alt");
          if (t != "")
              p.attr("data-caption", t);
      }
    });
    */
}


function enable_toolbar()
{
    var insert_text = function (text) {
        var ta = $("textarea.wiki")[0],
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
        } else if (action == "toc") {
            insert_text("<div id=\"toc\"></div>");
            e.preventDefault();
        }
    });

    $(document).on("change", "#filePhoto", function (e) {
        $(this).closest("form").submit();
    });
}


function enable_sitemap_toggle($)
{
    $(document).on("click", "#show_sitemap", function (e) {
        var c = $("#sitemap");
        if (c.length == 1) {
            e.preventDefault();
            c.toggle();
        }
    });

    $(document).on("click", ".toggle", function (e) {
        var sel = $(this).attr("data-toggle"),
            em = $(sel);
        if (em.length == 1) {
            e.preventDefault();
            $(this).blur();

            if (em.is(":visible")) {
                em.hide("fade", 100);
            } else {
                $(".toggled").hide("fade", 100);
                em.show("fade", 100);
            }

            var inp = em.find("input:first");
            if (inp.length > 0)
                inp.focus();
        }
    });

    // Close popups on click outside of them.
    $("html").on("click", function (e) {
        if ($(e.target).closest(".toggled").length == 0) {
            $(".toggled:visible").hide("fade", 100);
        }
    });
}


/**
 * Edit page sections.
 **/
jQuery(function ($) {
    var link = $("link[rel=edit]:first");
    if (link.length == 0)
        return;

    var base = link.attr("href");

    $(".formatted h1, .formatted h2, .formatted h3, .formatted h4, .formatted h5").each(function () {
        var text = $(this).text();
        var link = base + "&section=" + encodeURI(text);
        $(this).append("<span class='wiki-section-edit'> [ <a href='" + link + "'>редактировать</a> ]</span>");
    });
});
