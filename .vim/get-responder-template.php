#!/usr/bin/env php
<?php

$USAGE = <<<EOF
Print template for a responder file.
Usage: %s src/NameSpace/Responders/SomeResponder.php

EOF;


$TEMPLATE = <<<'EOF'
<?php

/**
 * Display something.
 **/

declare(strict_types=1);

namespace {{rootNamespace}}\{{namespace}};

use Slim\Http\Response;
use Ufw1\AbstractResponder;
use Ufw1\Services\Template;

class {{responderName}}Responder extends AbstractResponder
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
        if ($common = $this->getCommonResponse($response, $responseData)) {
            return $common;
        }

        $templateNames = $this->getTemplateNames($responseData);
        $html = $this->template->render($templateNames, $responseData['response']);

        $response->getBody()->write($html);
        return $response->withHeader('content-type', 'text/html');
    }

    protected function getTemplateNames(array $responseData): array
    {
        return [
            "{{template}}",
        ];
    }
}
EOF;

if (count($argv) < 2) {
    printf($USAGE, $argv[0]);
    exit(1);
}

$data = [];
$data['rootNamespace'] = $argv[2] ?? 'Ufw1';
$data['filePath'] = $argv[1];
$data['fileName'] = basename($argv[1]);
$data['dirName'] = dirname($argv[1]);
$data['namespace'] = str_replace('/', '\\', substr(dirname($argv[1]), 4));
$data['packageNamespace'] = str_replace('/', '\\', substr(dirname(dirname($argv[1])), 4));

if (!preg_match('@^(.+)Responder\.php$@', $data['fileName'], $m)) {
    printf("File name must end with Responder.php\n");
    exit(1);
} else {
    $data['responderName'] = $m[1];
}

$data['methodName'] = strtolower(substr($data['responderName'], 0, 1)) . substr($data['responderName'], 1);
$data['template'] = mb_strtolower("pages/{$data['packageNamespace']}-{$data['responderName']}.twig");

$text = $TEMPLATE;
foreach ($data as $k => $v) {
    $text = str_replace('{{' . $k . '}}', $v, $text);
}

die(rtrim($text));
