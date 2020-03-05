<?php

namespace Ufw1;

abstract class AbstractDomain
{
    protected function fail(int $code, string $message): array
    {
        return [
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ];
    }

    protected function forbidden(): array
    {
        return $this->fail(403, 'Forbidden.');
    }

    protected function notfound(): array
    {
        return $this->fail(404, 'Not found.');
    }

    protected function success(array $data): array
    {
        return [
            'response' => $data,
        ];
    }

    protected function isAdmin(?array $user): bool
    {
        if (!$this->isUser($user)) {
            return false;
        }

        if ($user['role'] !== 'admin') {
            return false;
        }

        return true;
    }

    protected function isUser(?array $user): bool
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
