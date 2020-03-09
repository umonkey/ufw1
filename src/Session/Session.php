<?php

/**
 * Session access service.
 **/

namespace Ufw1\Session;

use Slim\Http\Request;
use Ufw1\Services\Database;
use Psr\Log\LoggerInterface;

class Session
{
    /**
     * @var Database
     **/
    protected $db;

    /**
     * @var LoggerInterface
     **/
    protected $logger;

    /**
     * Session id.
     *
     * Only set when the session is updated.
     *
     * @var integer
     **/
    protected $id;

    /**
     * Session data, updated with load() and set().
     *
     * @var array
     **/
    protected $data;

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

    public function getData(): array
    {
        return $this->data;
    }

    /**
     * Loads session info and stores its id.
     *
     * @param string $sessionId Session to load and work with.
     **/
    public function load(?string $sessionId): void
    {
        $this->id = $sessionId;

        if (null === $sessionId) {
            $this->data = [];
        } else {
            $row = $this->db->fetchOne('SELECT * FROM sessions WHERE id = ?', [$sessionId]);
            if (null === $row) {
                $this->data = [];
            } elseif (is_array($data = unserialize($row['data']))) {
                $this->data = $data;
            }
        }
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
     * Save session data, return id.
     *
     * @return string Session id.
     **/
    public function save(): string
    {
        $now = strftime('%Y-%m-%d %H:%M:%S');

        if ($this->id === null) {
            $this->id = bin2hex(random_bytes(16));
        }

        $exists = $this->db->fetchOne('SELECT 1 FROM sessions WHERE id = ?', [$this->id]);

        if ($exists !== null) {
            $this->db->update('sessions', [
                'updated' => $now,
                'data' => serialize($this->data),
            ], [
                'id' => $this->id,
            ]);
        } else {
            $this->db->insert('sessions', [
                'id' => $this->id,
                'updated' => $now,
                'data' => serialize($this->data),
            ]);
        }

        return $this->id;
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

    public function setData(array $data): void
    {
        $this->data = $data;
    }

    private function getSessionId(Request $request): ?string
    {
        return $request->getCookieParam('session_id');
    }
}
