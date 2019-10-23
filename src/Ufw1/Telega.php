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
        $st = $this->container->get('settings');
        if (empty($st['telega']['bot_id']) or empty($st['telega']['chat_id'])) {
            // TODO: add some logging, maybe?
            return false;
        }

        $url = sprintf('https://api.telegram.org/bot%s/sendMessage?chat_id=%s&text=%s',
            $st['telega']['bot_id'], $st['telega']['chat_id'], urlencode($text));

        $res = file_get_contents($url);

        $this->container->get('logger')->debug('telega: url={0} res={1}', [$url, $res]);

        return $res !== false;
    }
}
