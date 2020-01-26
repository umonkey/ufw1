<?php

/**
 * Some repository.
 *
 * TODO: ...
 **/

declare(strict_types=1);

namespace Ufw1\Services;

use Psr\Log\LoggerInterface;

class [:VIM_EVAL:]expand('%:p:t:r')[:END_EVAL:]
{
    /**
     * @var LoggerInterface
     **/
    private $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }
}
