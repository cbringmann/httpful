<?php

declare(strict_types = 1);

/**
 * Handlers are used to parse and serialize payloads for specific
 * mime types. You can register a custom handler via the register
 * method. You can also override a default parser in this way.
 */

namespace Httpful\Handlers;

class MimeHandlerAdapter
{
    public function __construct(array $args = array())
    {
        $this->init($args);
    }

    /**
     * Initial setup of
     *
     * @param array $args
     */
    public function init(array $args): void
    {
    }

    public function parse(string $body): mixed
    {
        return $body;
    }

    function serialize(mixed $payload): string
    {
        return (string) $payload;
    }

    protected function stripBom($body)
    {
        if (substr($body, 0, 3) === "\xef\xbb\xbf") { // UTF-8
            $body = substr($body, 3);
        } elseif (substr($body, 0, 4) === "\xff\xfe\x00\x00" || substr($body, 0, 4) === "\x00\x00\xfe\xff") { // UTF-32
            $body = substr($body, 4);
        } elseif (substr($body, 0, 2) === "\xff\xfe" || substr($body, 0, 2) === "\xfe\xff") { // UTF-16
            $body = substr($body, 2);
        }

        return $body;
    }
}
