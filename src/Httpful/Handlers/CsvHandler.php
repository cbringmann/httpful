<?php

declare(strict_types = 1);

/**
 * Mime Type: text/csv
 *
 * @author Raja Kapur <rajak@twistedthrottle.com>
 */

namespace Httpful\Handlers;

use Exception;

class CsvHandler extends MimeHandlerAdapter
{
    /** @throws \Exception */
    public function parse(string $body): mixed
    {
        if (empty($body)) {
            return null;
        }

        $parsed = array();
        $fp = fopen('data://text/plain;base64,' . base64_encode($body), 'r');

        while (($r = fgetcsv($fp)) !== false) {
            $parsed[] = $r;
        }

        if (empty($parsed)) {
            throw new Exception("Unable to parse response as CSV");
        }

        return $parsed;
    }

    public function serialize(mixed $payload): string
    {
        $fp = fopen('php://temp/maxmemory:' . (6 * 1024 * 1024), 'r+');
        $i = 0;

        foreach ($payload as $fields) {
            if ($i++ === 0) {
                fputcsv($fp, array_keys($fields));
            }

            fputcsv($fp, $fields);
        }

        rewind($fp);
        $data = stream_get_contents($fp);
        fclose($fp);

        return $data;
    }
}
