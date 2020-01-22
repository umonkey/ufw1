<?php

/**
 * Task queue service.
 **/

declare(strict_types=1);

namespace Ufw1\Services;

use Psr\Log\LoggerInterface;

class TaskQueue
{
    /**
     * @var Database
     **/
    protected $database;

    /**
     * @var LoggerInterface
     **/
    protected $logger;

    /**
     * @var array
     **/
    protected $settings;

    protected $ping = false;

    public function __construct(Database $database, LoggerInterface $logger, array $settings)
    {
        $this->database = $database;
        $this->logger = $logger;
    }

    public function add(string $action, array $data = [], int $priority = 0): int
    {
        $logger = $this->logger;

        $data['__action'] = $action;

        $id = $this->database->insert('taskq', [
            'added' => strftime('%Y-%m-%d %H:%M:%S'),
            'priority' => $priority,
            'payload' => serialize($data),
        ]);

        $logger->debug('taskq: task {id} added with action {action}.', [
            'id' => $id,
            'action' => $action,
        ]);

        // Ping the server once per request.
        if ($this->ping === false) {
            $this->ping = true;

            $settings = $this->settings;
            if (!empty($settings['taskq']['ping_url'])) {
                $url = $settings['taskq']['ping_url'];
                @file_get_contents($url);

                $logger->info('taskq: ping sent to {url}', [
                    'url' => $url,
                ]);
            } else {
                $logger->info('taskq: ping_url not set.');
            }
        }

        return $id;
    }
}
