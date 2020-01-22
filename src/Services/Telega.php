<?php

/**
 * Simple, pretty dumb atm, telegram notifications.
 **/

declare(strict_types=1);

namespace Ufw1\Services;

use Psr\Log\LoggerInterface;

class Telega
{
    /**
     * @var LoggerInterface
     **/
    protected $logger;

    /**
     * @var array
     **/
    protected $settings;

    public function __construct(array $settings, LoggerInterface $logger)
    {
        $this->logger = $logger;
        $this->settings = $settings;
    }

    public function sendMessage(string $text): bool
    {
        $logger = $this->logger;

        if (!function_exists('curl_init')) {
            $logger->warning('telega: curl not available.');
            return false;
        }

        $st = $this->settings;
        if (empty($st['bot_id']) or empty($st['chat_id'])) {
            $logger->warning('telega: bot/chat not set.');
            return false;
        }

        $url = sprintf(
            'https://api.telegram.org/bot%s/sendMessage?chat_id=%s&text=%s',
            $st['telega']['bot_id'],
            $st['telega']['chat_id'],
            urlencode($text)
        );

        $ch = curl_init();
        curl_setopt($ch, \CURLOPT_URL, $url);

        if (isset($st['telega']['proxy'])) {
            curl_setopt($ch, \CURLOPT_PROXYTYPE, \CURLPROXY_SOCKS5);
            curl_setopt($ch, \CURLOPT_PROXY, $st['telega']['proxy']);
        }

        $res = curl_exec($ch);
        curl_close($ch);

        $this->logger->debug('telega: url={0} res={1}', [$url, $res]);

        return $res !== false;
    }
}
