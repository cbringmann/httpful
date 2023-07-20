<?php

declare(strict_types = 1);

namespace Httpful;

use Httpful\Handlers\CsvHandler;
use Httpful\Handlers\FormHandler;
use Httpful\Handlers\JsonHandler;
use Httpful\Handlers\XmlHandler;

/**
 * Bootstrap class that facilitates autoloading. A naive
 * PSR-0 autoloader.
 *
 * @author Nate Good <me@nategood.com>
 */
class Bootstrap
{
    const DIR_GLUE = DIRECTORY_SEPARATOR;
    const NS_GLUE = '\\';

    public static $registered = false;

    /**
     * Register the autoloader and any other setup needed
     */
    public static function init(): void
    {
        spl_autoload_register(array('\Httpful\Bootstrap', 'autoload'));
        self::registerHandlers();
    }

    /**
     * The autoload magic (PSR-0 style)
     */
    public static function autoload(string $classname): void
    {
        self::_autoload(dirname(dirname(__FILE__)), $classname);
    }

    /**
     * Register the autoloader and any other setup needed
     */
    public static function pharInit(): void
    {
        spl_autoload_register(array('\Httpful\Bootstrap', 'pharAutoload'));
        self::registerHandlers();
    }

    /**
     * Phar specific autoloader
     */
    public static function pharAutoload(string $classname): void
    {
        self::_autoload('phar://httpful.phar', $classname);
    }

    /**
     * Register default mime handlers. Is idempotent.
     */
    public static function registerHandlers(): void
    {
        if (self::$registered === true) {
            return;
        }

        // @todo check a conf file to load from that instead of
        // hardcoding into the library?
        $handlers = array(
            Mime::CSV => new CsvHandler(),
            Mime::FORM => new FormHandler(),
            Mime::JSON => new JsonHandler(),
            Mime::XML => new XmlHandler(),
        );

        foreach ($handlers as $mime => $handler) {
            // Don't overwrite if the handler has already been registered
            if (Httpful::hasParserRegistered($mime)) {
                continue;
            }

            Httpful::register($mime, $handler);
        }

        self::$registered = true;
    }

    private static function _autoload(string $base, string $classname): void
    {
        $parts = explode(self::NS_GLUE, $classname);
        $path = $base . self::DIR_GLUE . implode(self::DIR_GLUE, $parts) . '.php';

        if (file_exists($path)) {
            require_once $path;
        }
    }
}
