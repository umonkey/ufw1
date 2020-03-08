<?php

/**
 * Saved error messages.
 **/

declare(strict_types=1);

namespace Ufw1\Errors;

use Ufw1\AbstractDomain;
use Ufw1\Node\Entities\Node;
use Ufw1\Node\Entities\User;
use Ufw1\Node\NodeRepository;
use Ufw1\ResponsePayload;
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
     * List recent errors.
     **/
    public function listAction(?User $user): ResponsePayload
    {
        if (!$this->isAdmin($user)) {
            return $this->forbidden();
        }

        $errors = $this->db->fetch('SELECT * FROM errors WHERE `read` = 0 ORDER BY date DESC LIMIT 50', [], function (array $row) {
            return [
                'id' => (int)$row['id'],
                'date' => $row['date'],
                'class' => $row['class'],
                'message' => $row['message'],
            ];
        });

        return $this->success([
            'user' => $user,
            'errors' => $errors,
        ]);
    }

    /**
     * Display an error message.
     **/
    public function showError(int $errorId, ?User $user): ResponsePayload
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

    public function updateAction(int $errorId, bool $read, ?User $user): ResponsePayload
    {
        if (!$this->isAdmin($user)) {
            return $this->forbidden();
        }

        $error = $this->db->fetchOne('SELECT * FROM errors WHERE id = ?', [$errorId]);
        if (empty($error)) {
            return $this->forbidden();
        }

        $this->db->update('errors', [
            'read' => $read ? 1 : 0,
        ], [
            'id' => $errorId,
        ]);

        return $this->redirect("/admin/errors?fixed={$errorId}");
    }
}
