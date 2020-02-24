<?php

/**
 * Common action code.
 **/

declare(strict_types=1);

namespace Ufw1;

use Slim\Http\Request;

abstract class AbstractAction
{
    protected function addCommonData(Request $request, array &$responseData): void
    {
        if (!empty($responseData['response'])) {
            $responseData['response']['request'] = [
                'base' => $request->getUri()->getBaseUrl(),
                'host' => $request->getUri()->getHost(),
                'path' => $request->getUri()->getPath(),
                'uri' => strval($request->getUri()),
                'get' => $request->getQueryParams(),
            ];
        }
    }
}
