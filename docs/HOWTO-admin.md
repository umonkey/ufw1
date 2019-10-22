# Встроенная админка

Простая админка позволяет управлять нодами, очередью задач и некоторыми другими
штуками.  Для подключения админки в `src/routes.php`, перед собственными маршрутами,
нужно подключить встроенные:

```php
\Ufw1\Util::addAdminRoutes($app);
```

Это добавит обработку некоторых маршрутов с префиксом `/admin`.  Все они уходя
в обработчик `App\Handlers\Admin`, который должен быть унаследован от
`Ufw1\Handlers\Admin`, и может переопределять некоторые функции.  Пример обработчика:

```php
<?php
/**
 * Basic administrative UI.
 **/

namespace App\Handlers;

use Slim\Http\Request;
use Slim\Http\Response;

class Admin extends \Ufw1\Handlers\Admin
{
}
```

Это пока всё.
