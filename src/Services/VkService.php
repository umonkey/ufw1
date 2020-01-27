<?php

/**
 * vk.com api client.
 **/

declare(strict_types=1);

namespace Ufw1\Services;

use Ufw1\Services\HttpService;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Ufw1\Errors\Unavailable;

class VkService
{
    /**
     * @var LoggerInterface
     **/
    private $logger;

    /**
     * @var array
     **/
    private $settings;

    /**
     * HTTP Client.
     *
     * @var HttpService
     **/
    private $http;

    /**
     * Authentication token.  Saved upon authenticating.
     *
     * @var string
     **/
    private $token;

    public function __construct(LoggerInterface $logger, HttpService $http, array $settings)
    {
        $this->logger = $logger;
        $this->http = $http;
        $this->settings = $settings;

        $this->validateSettings($settings);
    }

    /**
     * Call a random vk.com api method.
     *
     * @param string $methodName Method name to call.
     * @param array  $args       Method arguments.
     * @param string $token      Authentication token.
     *
     * @return array Response or null.
     **/
    public function call(string $methodName, array $args = array(), string $token = null): ?array
    {
        if ($token === null) {
            $token = $this->token;
        }

        if ($token) {
            $args["access_token"] = $token;
        }

        $res = $this->fetchJSON("https://api.vk.com/method/{$methodName}", $args);

        if (!empty($res["error"]["error_msg"])) {
            $this->logger->error("vk error: {res}", [
                "res" => $res,
            ]);

            throw new RuntimeException($res["error"]["error_msg"]);
        }

        if (isset($res["response"])) {
            return $res["response"];
        }

        return null;
    }

    /**
     * https://vk.com/dev/permissions
     **/
    public function getLoginURL($scope = "status,email", $state = null)
    {
        $args = [
            'client_id' => $this->settings['oauth_id'],
            'redirect_uri' => $this->getRedirectURI(),
            'display' => 'page',
            'scope' => $scope,
            'response_type' => 'code',
            'v' => '5.92',
        ];

        if ($state) {
            $args['state'] = $state;
        }

        $url = $this->http->buildURL("https://oauth.vk.com/authorize", $args);

        return $url;
    }

    public function getProfileInfo(): array
    {
        $res = $this->call('account.getInfo', [
            'v' => '5.92',
        ], $this->token);

        return $res;
    }

    public function getToken(string $code): array
    {
        $res = $this->fetchJSON("https://oauth.vk.com/access_token", array(
            "client_id" => $this->settings["oauth_id"],
            "client_secret" => $this->settings["oauth_secret"],
            "redirect_uri" => $this->getRedirectURI(),
            "code" => $code,
            ));

        if (empty($res["access_token"])) {
            throw new RuntimeException("no access token");
        }

        $this->token = $res["access_token"];

        return $res;
    }

    public function uploadPhoto(string $path, array $args): array
    {
        if (!function_exists("curl_init"))
            throw new Unavailable('curl not installed');

        if (!is_readable($path))
            throw new \RuntimeException('photo is not readable');

        $args = array_merge([
            'album_id' => null,
            'caption' => null,
            'latitude' => null,
            'longitude' => null,
        ], $args);

        $res = $this->call('photos.getUploadServer', [
            'album_id' => $args['album_id'],
        ]);

        if (empty($res['upload_url'])) {
            throw new Unavailable('no upload_url');
        }

        $this->logger->debug('upload url: {0}, uploading file: {1}', [$res['upload_url'], $path]);

        $ch = curl_init($res['upload_url']);

        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, [
            'file1' => curl_file_create($path),
        ]);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: multipart/form-data; charset=UTF-8',
        ]);

        $res = curl_exec($ch);
        curl_close($ch);

        if ($res === false) {
            throw new Unavailable('bad upload response');
        }

        $res = json_decode($res, true);

        $res = $this->call('photos.save', [
            'album_id' => $args['album_id'],
            'server' => $res['server'],
            'photos_list' => $res['photos_list'],
            'hash' => $res['hash'],
            'latitude' => $args['latitude'],
            'longitude' => $args['longitude'],
            'caption' => $args['caption'],
        ]);

        return $res;
    }

    /**
     * Returns the URI where vk.com returns us.
     *
     * Usually looks like https://example.com/login/vk
     **/
    private function getRedirectUri(): string
    {
        return $this->settings['oauth_return_uri'];
    }

    private function fetchJSON(string $url, array $args): array
    {
        $res = $this->http->post($url, $args);
        if (false === $res['data']) {
            throw new \RuntimeException('error calling vk api');
        }

        switch ($res['status']) {
        case 401:
            throw new \RuntimeException('bad token or auth code');
        }

        if ($res['status'] >= 400) {
            $this->logger->debug('request failed, uri: {0}, response: {1}', [$url, $res]);
            debug($url, $args, $res);
            throw new RuntimeException($res['status_text']);
        }

        return json_decode($res['data'], true);
    }

    /**
     * Make sure all config options are set.
     *
     * @param array $settings vk.com settings.
     **/
    private function validateSettings(array $settings): void
    {
        $keys = [
            'oauth_id',
            'oauth_key',
            'oauth_secret',
            'oauth_return_uri',
        ];

        foreach ($keys as $key) {
            if (empty($settings[$key])) {
                $this->logger->error('vk.com {key} not set.', [
                    'key' => $key,
                ]);

                throw new RuntimeException('vk.com oauth not configured');
            }
        }
    }
}
