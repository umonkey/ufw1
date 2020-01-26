<?php

/**
 * Session access service.
 **/

declare(strict_types=1);

namespace Ufw1\Services;

use Psr\Log\LoggerInterface;
use Slim\Http\Request;

class SessionService
{
    /**
     * @var Database
     **/
    private $db;

    /**
     * @var LoggerInterface
     **/
    private $logger;

    /**
     * Session id.
     *
     * Only set when the session is updated.
     *
     * @var integer
     **/
    private $id;

    public function __construct(Database $db, LoggerInterface $logger)
    {
        $this->db = $db;
        $this->logger = $logger;
    }

    /**
     * Get session contents for the current request.
     *
     * Returns an empty array if the session does not exist.
     *
     * @param Request $request Used to access cookies, etc.
     *
     * @return array Session data.
     **/
    public function get(Request $request): array
    {
        $id = $this->getSessionId($request);

        if (!empty($id)) {
            $row = $this->db->fetchOne('SELECT data FROM sessions WHERE id = ?', [$id]);
            if (!empty($row)) {
                return unserialize($row['data']);
            }
        }

        return [];
    }

    /**
     * Delete sessions unused for 1+ month.
     **/
    public function purge(): void
    {
        $since = strftime('%Y-%m-%d %H:%M:%S', time() - 86400 * 30);
        $count = $this->db->query('DELETE FROM sessions WHERE updated < ?', $since);

        $this->logger->info('deleted {count} old sessions.', [
            'count' => $count,
        ]);
    }

    /**
     * Update session data.
     *
     * Creates a new session if needed.
     * Sets the cookie if needed.
     **/
    public function set(Request $request, array $data): void
    {
        if (!($id = $this->getSessionId($request))) {
            $id = bin2hex(random_bytes(16));
            setcookie('session_id', $id, time() + 86400 * 30, '/');

            $this->logger->info('session {id} created.', [
                    'id' => $id,
            ]);
        }

        $this->id = $id;

        $now = strftime('%Y-%m-%d %H:%M:%S');

        $this->db->query('REPLACE INTO `sessions` (`id`, `updated`, `data`) '
            . 'VALUES (?, ?, ?)', [$id, $now, serialize($data)]);
    }

    private function getSessionId(Request $request): ?string
    {
        return $request->getCookieParam('session_id');
    }
}
