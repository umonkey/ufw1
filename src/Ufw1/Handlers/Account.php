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
        $back = @$_GET["back"];

        return $this->render($request, "login.twig", [
            "title" => "Идентификация",
            "back" => $back,
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

            $nodes = $this->node->where("`type` = 'user' AND `id` IN (SELECT `id` FROM `nodes_user_idx` WHERE `email` = ?) ORDER BY `id`", [$email]);

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

    public function onRegister(Request $request, Response $response, array $args)
    {
        $user = $this->getUser($request);

        if ($request->getMethod() == "POST") {
            $form = array_merge([
                "name" => null,
                "email" => null,
                "password" => null,
                "next" => null,
            ], $request->getParsedBody());

            $this->checkRegisterForm($form);

            $nodes = $this->node->where("`type` = 'user' AND `key` = ?", [$form["email"]]);
            if (count($nodes))
                $this->fail("Пользователь с таким адресом уже есть.");

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

            return $response->withJSON([
                "redirect" => $form["next"] ? $form["next"] : "/",
            ]);
        }

        if ($user) {
            $back = $_GET["back"] ?? "/";
            return $response->withRedirect($back);
        }

        return $this->render($request, "register.twig");
    }

    protected function checkRegisterForm(array &$form)
    {
        $require = [
            "name" => "Не указано имя.",
            "email" => "Не указан почтовый адрес.",
            "password" => "Не задан пароль.",
        ];

        foreach ($require as $k => $msg)
            if (empty($form[$k]))
                $this->fail($msg);
    }
}
