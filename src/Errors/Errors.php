<?php

/**
 * Saved error messages.
 **/

declare(strict_types=1);

namespace Ufw1\Errors;

use Ufw1\AbstractDomain;
use Ufw1\ResponsePayload;
use Ufw1\Services\NodeRepository;
use Ufw1\Services\Database;

class Errors extends AbstractDomain
{
    /**
     * @var Database
     **/
    protected $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /**
     * Display an error message.
     **/
    public function showError(int $errorId, ?array $user): ResponsePayload
    {
        if (!$this->isAdmin($user)) {
            return $this->forbidden();
        }

        $error = $this->db->fetchOne('SELECT * FROM errors WHERE id = ?', [$errorId]);

        if (null === $error) {
            return $this->notfound();
        }

        $error['headers'] = unserialize($error['headers']);

        return $this->success([
            'user' => $user,
            'error' => $error,
        ]);
    }
}
