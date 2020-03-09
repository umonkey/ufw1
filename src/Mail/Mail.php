<?php

/**
 * Email sending.
 **/

declare(strict_types=1);

namespace Ufw1\Mail;

use Ufw1\AbstractDomain;
use Ufw1\Services\Template;

class Mail extends AbstractDomain
{
    /**
     * @var Template
     **/
    protected $template;

    /**
     * @var array
     **/
    protected $settings;

    public function __construct(Template $template, $settings)
    {
        $this->template = $template;
        $this->settings = $settings;
    }

    public function sendTemplate(string $to, string $templateName, array $data = []): void
    {
        $text = $this->template->render($templateName . '.text.twig', $data);
        $html = $this->template->render($templateName . '.html.twig', $data);

        $subject = 'no subject';

        $this->sendMail($to, $subject, $text, $html);
    }

    public function sendMail(string $to, string $subject, string $text, string $html): void
    {
        $settings = array_replace([
            'sender' => null,
            'sender_name' => null,
            'reply_to' => null,
            'bcc' => null,
            'to' => null,
        ], $this->settings['mail'] ?? []);

        // Extract subject from <title>.
        $html = preg_replace_callback('@<title>(.+?)</title>@', function (array $m) use (&$to) {
            $to = $m[1];
            return '';
        }, $html);

        $headers = "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/html; charset=utf-8\r\n";
        $headers .= "Content-Transfer-Encoding: base64\r\n";

        if ($settings['sender'] and $settings['sender_name']) {
            $headers .= sprintf("From: \"%s\" <%s>\r\n", $this->quote($settings['sender_name']), $settings['sender']);
        } elseif ($settings['sender']) {
            $headers .= sprintf("From: %s\r\n", $settings['sender']);
        }

        if ($settings['bcc']) {
            $headers .= sprintf("Bcc: %s\r\n", $settings['bcc']);
        }

        if ($settings['reply_to']) {
            $headers .= sprintf("Reply-To: %s\r\n", $settings['reply_to']);
        }

        if ($settings['to']) {
            $headers .= sprintf("X-Recipient: %s\r\n", $to);
            $to = $settings['to'];
        }

        $body = trim(chunk_split(base64_encode($html))) . "\r\n";
        $subject = $this->quote($subject);

        if ($settings['sender']) {
            mail($to, $subject, $body, $headers, '-f ' . $settings['sender']);
        } else {
            mail($to, $subject, $body, $headers);
        }
    }

    protected function quote(string $text): string
    {
        return sprintf("=?UTF-8?B?%s?=", base64_encode($text));
    }
}
