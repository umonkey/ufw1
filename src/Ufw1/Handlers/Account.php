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

    public function onLogin(Request $request, Response $response, array $args)
    {
        try {
            $login = $request->getParam("login");
            $password = $request->getParam("password");
            $next = $request->getParam("next");

            $tmp = $this->node->where("`type` = 'user' AND `key` = ?", [$login]);
            if (empty($tmp)) {
                return $response->withJSON([
                    "message" => "Нет такого пользователя.",
                ]);
            } else {
                $user = $tmp[0];
            }

            if (!password_verify($password, $user["password"])) {
                $hash = password_hash($password, PASSWORD_DEFAULT);

                $this->logger->debug("login: correct hash for password {password} would be {hash}.", [
                    "password" => $password,
                    "hash" => $hash,
                ]);

                return $response->withJSON([
                    "message" => "Пароль не подходит.",
                ]);
            }

            if ($user["published"] == 0) {
                return $response->withJSON([
                    "message" => "Учётная запись отключена.",
                ]);
            }

            $this->sessionSave($request, [
                "user_id" => $user["id"],
                "password" => $user["password"],
            ]);

            $user["last_login"] = strftime("%Y-%m-%d %H:%M:%S");
            $this->node->save($user);

            return $response->withJSON([
                "redirect" => $next,
            ]);
        } catch (\Exception $e) {
            return $response->withJSON([
                "message" => $e->getMessage(),
            ]);
        }
    }
}
