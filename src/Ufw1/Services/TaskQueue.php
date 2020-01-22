<?php

/**
 * Task queue service.
 **/

namespace Ufw1\Services;

class TaskQueueService extends \Ufw1\Service
{
    protected $ping = false;

    public function add($action, array $data = [], $priority = 0)
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
