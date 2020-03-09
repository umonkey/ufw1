<?php

/**
 * Email sending.
 **/

declare(strict_types=1);

namespace Ufw1\Mail;

use Ufw1\AbstractDomain;
use Ufw1\Services\Template;
use PHPMailer\PHPMailer\PHPMailer;

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

        $mailer = new PHPMailer(true);

        if ($settings['sender'] and $settings['sender_name']) {
            $mailer->setFrom($settings['sender'], $settings['sender_name']);
        } elseif ($settings['sender']) {
            $mailer->setFrom($settings['sender']);
        }

        if ($settings['to']) {
            $mailer->addAddress($settings['to']);
            $mailer->addCustomHeader('X-Recipient', $to);
        } else {
            $mailer->addAddress($to);
        }

        if ($settings['bcc']) {
            $mailer->addBcc($settings['bcc']);
        }

        $mailer->isHTML(true);
        $mailer->Body = $html;
        $mailer->AltBody = $text;
        $mailer->Subject = $subject;

        $mailer->send();
    }
}
