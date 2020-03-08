<?php

/**
 * Basic node entity.
 **/

declare(strict_types=1);

namespace Ufw1\Node\Entities;

use ArrayObject;

class Node extends ArrayObject
{
    public function update(array $props): void
    {
        foreach ($props as $k => $v) {
            $this[$k] = $v;
        }
    }
}
