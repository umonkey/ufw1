<?php

/**
 * Account operations.
 *
 * Lets users log in.
 **/

declare(strict_types=1);

namespace Ufw1\Controllers;

use Exception;
use Slim\Http\Request;
use Slim\Http\Response;
use Ufw1\CommonHandler;

class AccountController extends CommonHandler
{
    /**
     * Display account information.
     **/
    public function onAccount(Request $request, Response $response, array $args): Response
    {
        $user = $this->auth->getUser($request);

        if (null === $user) {
            return $response->withRedirect('/login');
        }

        return $this->render($request, 'pages/account.html.twig', [
            'user' => $user,
        ]);
    }

    /**
     * Confirm that the user logged out.
     **/
    public function onBye(Request $request, Response $response, array $args): Response
    {
        $user = $this->auth->getUser($request);

        if (null !== $user) {
            return $response->withRedirect('/account');
        }

        return $this->render($request, 'pages/bye.html.twig');
    }

    public function onGetLoginForm(Request $request, Response $response, array $args)
    {
        $user = $this->auth->getUser($request);

        if (null !== $user) {
            return $response->withRedirect('/account');
        }

        return $this->render($request, "pages/login.twig", [
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

            $user = $this->auth->logIn($request, $email, $password);

            return $response->withJSON([
                "redirect" => $next ? $next : "/",
            ]);
        } catch (Exception $e) {
            return $response->withJSON([
                "message" => $e->getMessage(),
            ]);
        }
    }

    /**
     * Log in using vk.com
     *
     * @todo Move logic to some AuthService?
     **/
    public function onLoginVK(Request $request, Response $response, array $args): Response
    {
        $settings = $this->settings['vk'] ?? [];

        if (empty($settings)) {
            $this->logger->error('vk.com api not configured.');
            $this->unavailable('vk.com api not configured');
        }

        if ($code = $request->getParam('code')) {
            $next = $request->getParam('state');
            $token = $this->vk->getToken($code);

            $profile = $this->vk->call('users.get', [
                'lang' => 'ru',
                'user_ids' => $token['user_id'],
                'fields' => 'screen_name,nickname,bdate,sex,photo_100,photo_200',
                'v' => '5.92',
            ], $token['access_token'])[0];

            $email = $profile['id'] . '@users.vk.com';
            $user = $this->auth->getUserByEmail($email);

            if (empty($user)) {
                $user = $this->node->save([
                    'type' => 'user',
                    'published' => 1,
                    'deleted' => 0,
                    'name' => $profile['first_name'] . ' ' . $profile['last_name'],
                    'email' => $email,
                    'role' => $settings['role'] ?? 'nobody',
                    'userpic' => $profile['photo_200'],
                ]);

                $this->notifyAdmin($request, $user);
            }

            $this->auth->push($request, (int)$user['id']);

            $this->logger->info('auth: user {id} logged in using vk.', [
                'id' => $user['id'],
            ]);

            return $response->withRedirect($next ? $next : '/');
        }

        else {
            $url = $this->vk->getLoginURL('status', $request->getParam('back'));

            $this->logger->debug('auth: redirecting to {url}', [
                'url' => $url,
            ]);

            return $response->withRedirect($url);
        }
    }

    /**
     * Display the logout form and handle requests.
     **/
    public function onLogout(Request $request, Response $response, array $args): Response
    {
        if ($request->getMethod() == 'GET') {
            $user = $this->auth->getUser($request);

            if (null === $user) {
                return $response->withRedirect('/account/bye');
            }

            return $this->render($request, 'pages/logout.html.twig', [
                'user' => $user,
            ]);
        }

        else {
            $this->auth->logOut($request);

            return $response->withJSON([
                'redirect' => '/account/bye',
            ]);
        }
    }

    /**
     * Displays and handles the user registration form.
     **/
    public function onRegister(Request $request, Response $response, array $args)
    {
        $user = $this->auth->getUser($request);

        if ($request->getMethod() == "POST") {
            return $this->onRegisterNew($request, $response, $args);
        }

        if ($user) {
            $back = $_GET["back"] ?: "/";
            return $response->withRedirect($back);
        }

        return $this->render($request, "pages/register.twig");
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

        $this->auth->push((int)$node['id']);

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

        foreach ($require as $k => $msg) {
            if (empty($form[$k])) {
                $this->fail($msg);
            }
        }
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

        $app->get('/account', $class . ':onAccount');
        $app->get('/account/bye', $class . ':onBye');
        $app->get('/login', $class . ':onGetLoginForm');
        $app->post('/login', $class . ':onLogin');
        $app->get('/login/google', $class . ':onLoginGoogle');
        $app->get('/login/vk', $class . ':onLoginVK');
        $app->any('/logout', $class . ':onLogout');
        $app->get('/logout/bye', $class . ':onLogoutComplete');
        $app->any('/profile', $class . ':onProfile');
        $app->any('/register', $class . ':onRegister');
    }
}
