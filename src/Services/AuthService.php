<?php

/**
 * Authentication service.
 *
 * Handles passwords, etc.
 **/

declare(strict_types=1);

namespace Ufw1\Services;

use Psr\Log\LoggerInterface;
use Slim\Http\Request;
use Ufw1\Errors\AuthError;

class AuthService
{
    /**
     * @var LoggerInterface
     **/
    private $logger;

    /**
     * @var SessionService
     **/
    private $session;

    /**
     * @var NodeRepository
     **/
    private $node;

    public function __construct(SessionService $session, LoggerInterface $logger, NodeRepository $node)
    {
        $this->session = $session;
        $this->logger = $logger;
        $this->node = $node;
    }

    public function getUser(Request $request): ?array
    {
        $session = $this->session->get($request);

        if (empty($session) or empty($session['user_id'])) {
            return null;
        }

        $node = $this->node->get((int)$session['user_id']);

        if (empty($node) or $node['type'] != 'user') {
            return null;
        }

        return $node;
    }

    public function getUserByEmail(string $email): ?array
    {
        $nodes = $this->node->where('`type` = \'user\' AND `id` IN (SELECT `id` FROM `nodes_user_idx` WHERE `email` = ?) ORDER BY `id` LIMIT 1', [$email]);
        return $nodes ? $nodes[0] : null;
    }

    public function isAdmin(Request $request): bool
    {
        $user = $this->getUser($request);

        if ($user and ($user['role'] ?? null) == 'admin') {
            return true;
        }

        return false;
    }

    /**
     * Check user password, store in session.
     *
     * @param Request $request  Request information.
     * @param string  $email    User email.
     * @param string  $password User password.
     *
     * @return array User profile.
     **/
    public function logIn(Request $request, string $email, string $password): array
    {
        $nodes = $this->node->where("`type` = 'user' AND `deleted` = 0 AND `id` IN (SELECT `id` FROM `nodes_user_idx` WHERE `email` = ?) ORDER BY `id`", [$email]);

        if (empty($nodes)) {
            throw new AuthError('Нет пользователя с таким адресом.');
        }

        $node = $nodes[0];

        if (empty($node['password'])) {
            throw new AuthError('Пароль не установлен.');
        }

        if (!password_verify($password, $node["password"])) {
            $this->logger->warning('login: wrong password entered for user {email}.', [
                'email' => $node['email'],
            ]);

            throw new AuthError('Пароль не подходит.');
        }

        $session = $this->session->get($request);
        $session['user_id'] = (int)$node['id'];
        $this->session->set($request, $session);

        $node['last_login'] = strftime('%Y-%m-%d %H:%M:%S');
        $this->node->save($node);

        return $node;
    }

    /**
     * Logs user out.
     **/
    public function logOut(Request $request): void
    {
        $session = $this->session->get($request);

        if (!empty($session['user_stack'])) {
            $session['user_id'] = array_pop($session['user_stack']);

            if (empty($session['user_stack'])) {
                unset($session['user_stack']);
            }
        } else {
            unset($session['user_id']);
        }

        $this->session->set($request, $session);
    }

    /**
     * Push new user to the stack.
     *
     * Like 'su' for admins.
     *
     * @param Request $request Session information.
     * @param int     $userId Wanted user id.
     **/
    public function push(Request $request, int $userId): void
    {
        $session = $this->session->get($request);

        if (!empty($session['user_id'])) {
            if (empty($session['user_stack'])) {
                $session['user_stack'] = [];
            }

            $session['user_stack'][] = $session['user_id'];
        }

        $session['user_id'] = $userId;

        $this->session->set($request, $session);
    }

    /**
     * Make sure the user is logged in and has the 'admin' role.
     *
     * @param Request $request Session information.
     *
     * @return array User information.
     **/
    public function requireAdmin(Request $request): array
    {
        return $this->requireRole($request, ['admin']);
    }

    /**
     * Make sure the user has one of the required roles.
     *
     * @param Request $request User information.
     * @param array   $roles   Roles, one of which must exist.
     **/
    public function requireRole(Request $request, array $roles): array
    {
        $user = $this->requireUser($request);

        if (empty($roles)) {
            return $user;
        }

        if (isset($user['role']) and in_array($user['role'], $roles)) {
            return $user;
        }

        throw new \Ufw1\Errors\Forbidden();
    }

    /**
     * Make sure the user is logged in.
     *
     * @param Request $request Session information.
     *
     * @return array User information.
     **/
    public function requireUser(Request $request): array
    {
        $user = $this->getUser($request);

        if (empty($user)) {
            throw new \Ufw1\Errors\Unauthorized();
        }

        if ((int)$user['deleted'] == 1) {
            throw new \Ufw1\Errors\Forbidden();
        }

        if ((int)$user['published'] == 0) {
            throw new \Ufw1\Errors\Forbidden();
        }

        return $user;
    }
}
