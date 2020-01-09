# Single page application

This is not nativelly supported, as it doesn't belong to the backend.

However, there's a built in spa.js script, which makes internal website navigation much faster,
by loading pages with XHR and refreshing only a part of the page.

It works only for links to other pages within the web site, updates contents of
the #body element, i.e., wrap your page in:

    <div id='body'>...</div>

While the page is loading, `a.bars > i` is updated with a spinner.
