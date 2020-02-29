<?php

namespace Ufw1\Accounts;

use Ufw1\AbstractDomain;
use Ufw1\Services\NodeRepository;
use Ufw1\Services\Database;

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

    public function __construct(Database $db, NodeRepository $node)
    {
        $this->db = $db;
        $this->node = $node;
    }

    public function profile(?array $user): array
    {
        if (null === $user) {
            return $this->forbidden();
        }

        return [
            'response' => [
                'user' => $user,
            ],
        ];
    }

    public function sudo(int $userId, string $sessionId, ?array $user): array
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

        return [
            'redirect' => '/account',
        ];
    }
}
