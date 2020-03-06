<?php

/**
 * Array extension to carry data between domain logic and the responder.
 * Has some handy methods.
 **/

declare(strict_types=1);

namespace Ufw1;

use ArrayObject;

class ResponsePayload extends ArrayObject
{
    public static function error(int $code, string $message, array $props = []): self
    {
        return new self([
            'error' => array_replace($props, [
                'code' => $code,
                'message' => $message,
            ]),
        ]);
    }

    public static function redirect(string $target, int $status = 302): self
    {
        return new self([
            'redirect' => $target,
            'status' => $status,
        ]);
    }

    public static function refresh(): self
    {
        return new self([
            'refresh' => true,
        ]);
    }

    public static function data(array $data): self
    {
        return new self([
            'response' => $data,
        ]);
    }

    public function isError(): bool
    {
        return !empty($this['error']);
    }

    public function isRedirect(): bool
    {
        return !empty($this['redirect']);
    }

    public function isRefresh(): bool
    {
        return !empty($this['refresh']);
    }

    public function isOK(): bool
    {
        return !empty($this['response']);
    }
}
