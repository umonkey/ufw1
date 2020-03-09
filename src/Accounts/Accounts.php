<?php

/**
 * Account management.
 **/

declare(strict_types=1);

namespace Ufw1\Accounts;

use Ufw1\AbstractDomain;
use Ufw1\Mail\Mail;
use Ufw1\Node\Entities\Node;
use Ufw1\Node\Entities\User;
use Ufw1\Node\NodeRepository;
use Ufw1\ResponsePayload;
use Ufw1\Services\Database;
use Ufw1\Services\TaskQueue;
use Ufw1\Session\Session;

class Accounts extends AbstractDomain
{
    /**
     * @var Database
     **/
    protected $db;

    /**
     * @var NodeRepository
     **/
    protected $node;

    /**
     * @var Session
     **/
    protected $session;

    /**
     * @var TaskQueue
     **/
    protected $taskq;

    /**
     * @var Mail
     **/
    protected $mail;

    public function __construct(Database $db, NodeRepository $node, Session $sess, TaskQueue $taskq, Mail $mail)
    {
        $this->db = $db;
        $this->node = $node;
        $this->session = $sess;
        $this->taskq = $taskq;
        $this->mail = $mail;
    }

    /**
     * Log in using email and password.
     *
     * @param string $sessionId Session identifier, if open.
     * @param string $email     User email.
     * @param string $password  User password.
     *
     * @return array Response data.
     **/
    public function logIn(?string $sessionId, string $email, string $password): ResponsePayload
    {
        $nodes = $this->node->where('type = \'user\' AND deleted = 0 AND id IN (SELECT id FROM nodes_user_idx WHERE email = ?)', [trim($email)]);

        if (empty($nodes)) {
            return $this->notfound('User with this email not found.');
        }

        $node = $nodes[0];

        if (empty($node['password'])) {
            return $this->fail(403, 'Password disabled for this user.');
        }

        if (!password_verify($password, $node['password'])) {
            return $this->fail(403, 'Wrong password.');
        }

        $sessionId = $this->setUserId((int)$node['id'], $sessionId);

        return $this->success([
            'sessionId' => $sessionId,
            'redirect' => '/profile',
        ]);
    }

    public function profile(?Node $user): ResponsePayload
    {
        if (null === $user) {
            return $this->forbidden();
        }

        return $this->success([
            'user' => $user,
        ]);
    }

    public function restoreAction(int $uid, string $code, ?string $sessionId): ResponsePayload
    {
        $user = $this->node->get($uid);

        if (null === $user) {
            return $this->notfound();
        }

        if (empty($user['otp_hash'])) {
            return $this->notfound();
        }

        if ($code !== $user['otp_hash']) {
            return $this->notfound();
        }

        $sessionId = $this->setUserId($uid, $sessionId);

        return $this->success([
            'sessionId' => $sessionId,
            'redirect' => '/profile',
        ]);
    }

    public function restorePasswordAction(string $email): ResponsePayload
    {
        $nodes = $this->node->where("type = 'user' AND published = 1 AND deleted = 0 AND id IN (SELECT id FROM nodes_user_idx WHERE email = ?) ORDER BY id LIMIT 1", [$email]);

        if (empty($nodes)) {
            return $this->fail(400, 'Нет пользователя с таким адресом.');
        }

        $node = $nodes[0];

        $node['otp_hash'] = uniqid('', true);
        $node['otp_expire'] = time() + 60 * 60;  // 1 hour

        $this->node->save($node);

        $this->taskq->add('accounts.sendRestoreEmailTask', [
            'id' => (int)$node['id'],
        ]);

        return $this->success([
            'message' => 'Инструкции для восстановления пароля отправлены на почту.  Эту страницу можно закрыть.',
        ]);
    }

    /**
     * Send the restore password email.
     *
     * @param int $userId User id to restore password for.
     **/
    public function sendRestoreEmailTask(array $args): void
    {
        $userId = (int)$args['id'];
        if (null !== ($node = $this->node->get($userId))) {
            $this->mail->sendTemplate($node['email'], 'email/restore-password', [
                'user' => $node,
            ]);
        }
    }

    public function showRestoreFormAction(?User $user): ResponsePayload
    {
        if ($this->isUser($user)) {
            return $this->redirect('/profile');
        }

        return $this->success([
            'showForm' => true,
        ]);
    }

    public function sudo(int $userId, string $sessionId, ?Node $user): ResponsePayload
    {
        if (!$this->isAdmin($user)) {
            return $this->forbidden();
        }

        $user = $this->node->get($userId);
        if (null === $user) {
            return $this->fail(404, 'Пользователь не найден.');
        }

        $session = $this->db->fetchOne('SELECT * FROM sessions WHERE id = ?', [$sessionId]);
        if (empty($session)) {
            return $this->notfound();
        }

        $data = unserialize($session['data']);
        $data['user_id'] = $userId;

        $this->db->update('sessions', [
            'updated' => strftime('%Y-%m-%d %H:%M:%S'),
            'data' => serialize($data),
        ], [
            'id' => $sessionId,
        ]);

        return $this->redirect('/account');
    }

    /**
     * Send the restore password email.
     *
     * Only schedules the action, not really sends the email.
     **/
    protected function sendPasswordEmail(Node $node, Request $request)
    {
        $host = $request->getUri()->getHost();
        $base = $request->getUri()->getBaseUrl();

        $link = "{$base}/account/restore?id={$node['id']}&code={$node['otp_hash']}";

        $to = $node['email'];
        $subject = "Восстановление пароля к {$host}";
        $body = "Для восстановления пароля пройди по ссылке:\n\n{$link}";

        $this->taskq->add('email.send', [
            'to' => $to,
            'subject' => $subject,
            'text' => $body,
        ]);

        $this->logger->info('account: sent restore link: {0}', [$link]);
    }

    protected function setUserId(int $uid, ?string $sessionId): string
    {
        $this->session->load($sessionId);

        $data = $this->session->getData();
        $data['user_id'] = $uid;
        $this->session->setData($data);

        $sessionId = $this->session->save();

        return $sessionId;
    }
}
