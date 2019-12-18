<?php
/**
 * Account operations.
 *
 * Lets users log in.
 **/

namespace Ufw1\Handlers;

use Slim\Http\Request;
use Slim\Http\Response;
use Ufw1\CommonHandler;


class Account extends CommonHandler
{
    public function onGetLoginForm(Request $request, Response $response, array $args)
    {
        return $this->render($request, "login.twig", [
            "title" => "Идентификация",
        ]);
    }

    /**
     * Handle the login form.
     *
     * Displayed from the Unauthorized error handler, in App\Handlers\Unauthorized,
     * see src/App/Handlers/Unauthorized.php
     **/
    public function onLogin(Request $request, Response $response, array $args)
    {
        try {
            $email = $request->getParam("email");
            $password = $request->getParam("password");
            $next = $request->getParam("next");

            $nodes = $this->node->where("`type` = 'user' AND `deleted` = 0 AND `id` IN (SELECT `id` FROM `nodes_user_idx` WHERE `email` = ?) ORDER BY `id`", [$email]);

            if (empty($nodes))
                $this->fail("Нет пользователя с таким адресом.");

            $user = $nodes[0];

            if (!password_verify($password, $user["password"])) {
                $hash = password_hash($password, PASSWORD_DEFAULT);

                $this->logger->debug("login: correct hash for password {password} would be {hash}.", [
                    "password" => $password,
                    "hash" => $hash,
                ]);

                $this->fail("Пароль не подходит.");
            }

            $this->sessionSave($request, [
                "user_id" => $user["id"],
                "password" => $user["password"],
            ]);

            $user["last_login"] = strftime("%Y-%m-%d %H:%M:%S");
            $this->node->save($user);

            return $response->withJSON([
                "redirect" => $next ? $next : "/",
            ]);
        } catch (\Exception $e) {
            return $response->withJSON([
                "message" => $e->getMessage(),
            ]);
        }
    }

    /**
     * Displays and handles the user registration form.
     **/
    public function onRegister(Request $request, Response $response, array $args)
    {
        $user = $this->getUser($request);

        if ($request->getMethod() == "POST") {
            return $this->onRegisterNew($request, $response, $args);
        }

        if ($user) {
            $back = $_GET["back"] ?: "/";
            return $response->withRedirect($back);
        }

        return $this->render($request, "register.twig");
    }

    public function onRegisterNew(Request $request, Response $response, array $args)
    {
        $this->db->beginTransaction();

        $form = array_merge([
            "name" => null,
            "first_name" => null,
            "last_name" => null,
            "email" => null,
            "password" => null,
            "next" => null,
        ], $request->getParsedBody());

        if (empty($form['name']) and !empty($form['first_name'])) {
            $form['name'] = $form['last_name'] . ' ' . $form['first_name'];
        }

        $this->checkRegisterForm($form);

        $nodes = $this->node->where("`type` = 'user' AND `key` = ?", [$form["email"]]);
        if (count($nodes)) {
            $this->fail("Пользователь с таким адресом уже есть.");
        }

        $node = array_merge($form, [
            "type" => "user",
            "published" => 0,
            "role" => "nobody",
            "password" => password_hash($form["password"], PASSWORD_DEFAULT),
        ]);

        // Первый регистрируемый пользователь всегда администратор.
        $userCount = (int)$this->db->fetchcell("SELECT COUNT(*) FROM `nodes` WHERE `type` = 'user'");
        if ($userCount == 0) {
            $node["published"] = 1;
            $node["role"] = "admin";
        }

        $node = $this->node->save($node);

        $this->sessionSave($request, [
            "user_id" => $node["id"],
        ]);

        $this->notifyAdmin($request, $node);

        $this->db->commit();

        return $response->withJSON([
            "redirect" => $form["next"] ? $form["next"] : "/",
        ]);
    }

    protected function checkRegisterForm(array &$form)
    {
        $require = [
            "name" => "Не указано имя.",
            "email" => "Не указан адрес электронной почты.",
            "password" => "Не задан пароль.",
        ];

        foreach ($require as $k => $msg)
            if (empty($form[$k]))
                $this->fail($msg);
    }

    protected function notifyAdmin(Request $request, array $node)
    {
        $base = $request->getUri()->getBaseUrl();
        $url = "{$base}/admin/nodes/{$node['id']}/edit";

        $message = "Зарегистрирован новый пользователь:\n";
        $message .= "Email: {$node['email']}\n";
        $message .= "Имя: {$node['name']}\n";
        $message .= $url;

        $this->container->get('taskq')->add('telega', [
            'message' => $message,
        ]);
    }

    public static function setupRoutes(&$app)
    {
        $class = get_called_class();

        $app->get ('/account',      $class . ':onInfo');
        $app->get ('/login',        $class . ':onGetLoginForm');
        $app->post('/login',        $class . ':onLogin');
        $app->get ('/login/google', $class . ':onLoginGoogle');
        $app->get ('/login/vk',     $class . ':onLoginVK');
        $app->any ('/logout',       $class . ':onLogout');
        $app->get ('/logout/bye',   $class . ':onLogoutComplete');
        $app->any ('/profile',      $class . ':onProfile');
        $app->any ('/register',     $class . ':onRegister');
    }
}
