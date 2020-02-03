# Security concerns

Not exactly related to ufw, but still.  To protect a web site from hacking into, it should follow simple rules.

1. The application should never be able to modify itself or create executable files.
2. Database queries should never be constructed from user input, pass data only with query parameters.
3. Use and check XSS tokens.

The first rule means no template editing via admin UI, no templates in the database, no direct access to uploaded files.  It's best if PHP runs with a restricted account, like `www-data`, which can ONLY write to a `var` folder within the project, but outside the public document root.

It's good to use [open_basedir](https://www.php.net/manual/en/ini.core.php#ini.open-basedir).  In combination with immodifiable files, works well.
