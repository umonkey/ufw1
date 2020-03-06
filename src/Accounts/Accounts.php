<?php

namespace Ufw1\Accounts;

use Ufw1\AbstractDomain;
use Ufw1\ResponsePayload;
use Ufw1\Services\NodeRepository;
use Ufw1\Services\Database;
use Ufw1\Session\Session;

class Accounts extends AbstractDomain
{
    /**
     * @var Database
     **/
    protected $db;

    /**
     * @var NodeRepository
     **/
    protected $node;

    /**
     * @var Session
     **/
    protected $session;

    public function __construct(Database $db, NodeRepository $node, Session $sess)
    {
        $this->db = $db;
        $this->node = $node;
        $this->session = $sess;
    }

    /**
     * Log in using email and password.
     *
     * @param string $sessionId Session identifier, if open.
     * @param string $email     User email.
     * @param string $password  User password.
     *
     * @return array Response data.
     **/
    public function logIn(?string $sessionId, string $email, string $password): ResponsePayload
    {
        $nodes = $this->node->where('type = \'user\' AND deleted = 0 AND id IN (SELECT id FROM nodes_user_idx WHERE email = ?)', [trim($email)]);

        if (empty($nodes)) {
            return $this->notfound('User with this email not found.');
        }

        $node = $nodes[0];

        if (empty($node['password'])) {
            return $this->fail(403, 'Password disabled for this user.');
        }

        if (!password_verify($password, $node['password'])) {
            return $this->fail(403, 'Wrong password.');
        }

        $this->session->load($sessionId);

        $data = $this->session->getData();
        $data['user_id'] = (int)$node['id'];
        $this->session->setData($data);

        $sessionId = $this->session->save();

        return $this->success([
            'sessionId' => $sessionId,
            'redirect' => '/profile',
        ]);
    }

    public function profile(?array $user): ResponsePayload
    {
        if (null === $user) {
            return $this->forbidden();
        }

        return $this->success([
            'user' => $user,
        ]);
    }

    public function sudo(int $userId, string $sessionId, ?array $user): ResponsePayload
    {
        if (!$this->isAdmin($user)) {
            return $this->forbidden();
        }

        $user = $this->node->get($userId);
        if (null === $user) {
            return $this->fail(404, 'Пользователь не найден.');
        }

        $session = $this->db->fetchOne('SELECT * FROM sessions WHERE id = ?', [$sessionId]);
        if (empty($session)) {
            return $this->notfound();
        }

        $data = unserialize($session['data']);
        $data['user_id'] = $userId;

        $this->db->update('sessions', [
            'updated' => strftime('%Y-%m-%d %H:%M:%S'),
            'data' => serialize($data),
        ], [
            'id' => $sessionId,
        ]);

        return $this->redirect('/account');
    }
}
