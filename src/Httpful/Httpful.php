<?php

declare(strict_types = 1);

namespace Httpful;

use Httpful\Handlers\MimeHandlerAdapter;

class Httpful
{
    const VERSION = '0.3.0';

    private static $mimeRegistrar = array();
    private static $default = null;

    public static function register(string $mimeType, MimeHandlerAdapter $handler): void
    {
        self::$mimeRegistrar[$mimeType] = $handler;
    }

    /** @param string $mimeType defaults to MimeHandlerAdapter */
    public static function get(?string $mimeType = null): MimeHandlerAdapter
    {
        if (isset(self::$mimeRegistrar[$mimeType])) {
            return self::$mimeRegistrar[$mimeType];
        }

        if (empty(self::$default)) {
            self::$default = new MimeHandlerAdapter();
        }

        return self::$default;
    }

    /**
     * Does this particular Mime Type have a parser registered
     * for it?
     */
    public static function hasParserRegistered(string $mimeType): bool
    {
        return isset(self::$mimeRegistrar[$mimeType]);
    }
}
