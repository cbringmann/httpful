<?php

declare(strict_types = 1);

namespace Httpful;

/**
 * Class to organize the Mime stuff a bit more
 *
 * @author Nate Good <me@nategood.com>
 */
class Mime
{
    const JSON = 'application/json';
    const XML = 'application/xml';
    const XHTML = 'application/html+xml';
    const FORM = 'application/x-www-form-urlencoded';
    const UPLOAD = 'multipart/form-data';
    const PLAIN = 'text/plain';
    const JS = 'text/javascript';
    const HTML = 'text/html';
    const YAML = 'application/x-yaml';
    const CSV = 'text/csv';

    /**
     * Map short name for a mime type
     * to a full proper mime type
     */
    public static $mimes = array(
        'csv' => self::CSV,
        'form' => self::FORM,
        'html' => self::HTML,
        'javascript' => self::JS,
        'js' => self::JS,
        'json' => self::JSON,
        'plain' => self::PLAIN,
        'text' => self::PLAIN,
        'upload' => self::UPLOAD,
        'xhtml' => self::XHTML,
        'xml' => self::XML,
        'yaml' => self::YAML,
    );

    /**
     * Get the full Mime Type name from a "short name".
     * Returns the short if no mapping was found.
     *
     * @param string $short_name common name for mime type (e.g. json)
     * @return string full mime type (e.g. application/json)
     */
    public static function getFullMime(string $short_name): string
    {
        return array_key_exists($short_name, self::$mimes)
            ? self::$mimes[$short_name]
            : $short_name;
    }

    public static function supportsMimeType(string $short_name): bool
    {
        return array_key_exists($short_name, self::$mimes);
    }
}
