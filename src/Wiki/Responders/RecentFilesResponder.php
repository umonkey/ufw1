<?php

/**
 * Display recent files.
 **/

declare(strict_types=1);

namespace Ufw1\Wiki\Responders;

use Slim\Http\Response;
use Ufw1\AbstractResponder;
use Ufw1\Services\Template;

class RecentFilesResponder extends AbstractResponder
{
    /**
     * @var Template;
     **/
    protected $template;

    public function __construct(Template $template)
    {
        $this->template = $template;
    }

    public function getResponse(Response $response, array $responseData): Response
    {
        if ($error = $this->getCommonJsonResponse($response, $responseData)) {
            return $error;
        }

        return $response->withJSON([
            'files' => $responseData['response']['files'],
        ]);
    }
}
