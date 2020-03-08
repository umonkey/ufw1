<?php

/**
 * User profile.
 **/

declare(strict_types=1);

namespace Ufw1\Node\Entities;

class User extends Node
{
    public function getRole(): string
    {
        return $this['role'] ?? 'nobody';
    }

    public function setPassword(string $password): void
    {
        $this['password'] = password_hash($password, PASSWORD_DEFAULT);
    }

    public function verifyPassword(string $password): bool
    {
        if (empty($this['password'])) {
            return false;
        }

        return password_verify($password, $this['password']);
    }
}
