<?php

/**
 * Basic node entity.
 **/

declare(strict_types=1);

namespace Ufw1\Node\Entities;

use ArrayObject;

class Node extends ArrayObject
{
    public static function fromArray(array $props): self
    {
        switch ($props['type']) {
            case 'user':
                return new User($props);
            case 'file':
                return new File($props);
            default:
                return new static($props);
        }
    }

    public function update(array $props): void
    {
        foreach ($props as $k => $v) {
            $this[$k] = $v;
        }
    }
}
