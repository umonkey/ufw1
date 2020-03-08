<?php

namespace Ufw1;

use Ufw1\Node\Entities\Node;
use Ufw1\ResponsePayload;

abstract class AbstractDomain
{
    protected function fail(int $code, string $message, array $props = []): ResponsePayload
    {
        return ResponsePayload::error($code, $message, $props);
    }

    protected function forbidden(): ResponsePayload
    {
        return $this->fail(403, 'Forbidden.');
    }

    protected function notfound(): ResponsePayload
    {
        return $this->fail(404, 'Not found.');
    }

    protected function redirect(string $target, int $status = 302): ResponsePayload
    {
        return ResponsePayload::redirect($target, $status);
    }

    protected function success(array $data): ResponsePayload
    {
        return ResponsePayload::data($data);
    }

    protected function unauthorized(): ResponsePayload
    {
        return $this->fail(401, 'Unauthorized.');
    }

    protected function isAdmin(?Node $user): bool
    {
        if (!$this->isUser($user)) {
            return false;
        }

        if ($user['role'] !== 'admin') {
            return false;
        }

        return true;
    }

    protected function isUser(?Node $user): bool
    {
        if (null === $user) {
            return false;
        }

        if ((int)$user['published'] == 0 || (int)$user['deleted'] == 1) {
            return false;
        }

        return true;
    }
}
