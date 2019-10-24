<?php
/**
 * Simple, pretty dumb atm, telegram notifications.
 **/

namespace Ufw1;

class Telega
{
    protected $container;

    public function __construct($container)
    {
        $this->container = $container;
    }

    public function sendMessage($text)
    {
        $logger = $this->container->get('logger');

        if (!function_exists('curl_init')) {
            $logger->warning('telega: curl not available.');
            return false;
        }

        $st = $this->container->get('settings');
        if (empty($st['telega']['bot_id']) or empty($st['telega']['chat_id'])) {
            $logger->warning('telega: bot/chat not set.');
            return false;
        }

        $url = sprintf('https://api.telegram.org/bot%s/sendMessage?chat_id=%s&text=%s',
            $st['telega']['bot_id'], $st['telega']['chat_id'], urlencode($text));

        $ch = curl_init();
        curl_setopt($ch, \CURLOPT_URL, $url);

        if (isset($st['telega']['proxy'])) {
            curl_setopt($ch, \CURLOPT_PROXYTYPE, \CURLPROXY_SOCKS5);
            curl_setopt($ch, \CURLOPT_PROXY, $st['telega']['proxy']);
        }

        $res = curl_exec($ch);
        curl_close($ch);

        $res = file_get_contents($url);

        $this->container->get('logger')->debug('telega: url={0} res={1}', [$url, $res]);

        return $res !== false;
    }
}
