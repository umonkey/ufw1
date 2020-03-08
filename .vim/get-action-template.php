#!/usr/bin/env php
<?php

$USAGE = <<<EOF
Print template for an action file.
Usage: %s src/NameSpace/Actions/SomeAction.php

EOF;


$TEMPLATE = <<<'EOF'
<?php

/**
 * Do something.
 **/

declare(strict_types=1);

namespace {{rootNamespace}}\{{namespace}};

use Slim\Http\Request;
use Slim\Http\Response;
use Ufw1\AbstractAction;
use Ufw1\Services\AuthService;
use Ufw1\Services\Database;
use {{rootNamespace}}\{{packageNamespace}}\{{packageNamespace}}Domain;
use {{rootNamespace}}\{{packageNamespace}}\Responders\{{actionName}}Responder;

class {{actionName}}Action extends AbstractAction
{
    /**
     * @var {{packageNamespace}}Domain
     **/
    protected $domain;

    /**
     * @var {{actionName}}Responder
     **/
    protected $responder;

    /**
     * @var AuthService
     **/
    protected $auth;

    /**
     * @var Database
     **/
    protected $db;

    public function __construct({{packageNamespace}}Domain $domain, {{actionName}}Responder $responder, AuthService $auth, Database $db)
    {
        $this->domain = $domain;
        $this->responder = $responder;
        $this->auth = $auth;
        $this->db = $db;
    }

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $this->db->beginTransaction();

        // (1) Collect input data.
        $data = $request->getParams();
        $user = $this->auth->getUser($request);

        // (2) Process data.
        $responseData = $this->domain->{{methodName}}($data, $user);

        // (3) Show response.
        $this->addCommonData($request, $responseData);
        $response = $this->responder->getResponse($response, $responseData);

        // (4) Commit on no error.
        if ($response->getStatusCode() === 200) {
            $this->db->commit();
        }

        return $response;
    }
}
EOF;

if (count($argv) < 2) {
    printf($USAGE, $argv[0]);
    exit(1);
}

$data = [];
$data['rootNamespace'] = get_root_namsepace($argv[2] ?? null);
$data['filePath'] = $argv[1];
$data['fileName'] = basename($argv[1]);
$data['dirName'] = dirname($argv[1]);
$data['namespace'] = str_replace('/', '\\', substr(dirname($argv[1]), 4));
$data['packageNamespace'] = str_replace('/', '\\', substr(dirname(dirname($argv[1])), 4));

if (!preg_match('@^(.+)Action\.php$@', $data['fileName'], $m)) {
    printf("File name must end with Action.php\n");
    exit(1);
} else {
    $data['actionName'] = $m[1];
}

$data['methodName'] = strtolower(substr($data['actionName'], 0, 1)) . substr($data['actionName'], 1);

$text = $TEMPLATE;
foreach ($data as $k => $v) {
    $text = str_replace('{{' . $k . '}}', $v, $text);
}

die(rtrim($text));


function get_root_namespace(?string $arg)
{
    if (null !== $arg) {
        return $arg;
    }

    if (file_exists('.root_ns')) {
        return trmi(file_get_contents('.root_ns'));
    }

    return 'Ufw1';
}
