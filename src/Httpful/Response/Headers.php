<?php

declare(strict_types = 1);

namespace Httpful\Response;

use ArrayAccess;
use Countable;
use Exception;

use const PREG_SPLIT_NO_EMPTY;

final class Headers implements ArrayAccess, Countable
{
    private $headers;

    /** @param array $headers */
    private function __construct(array $headers)
    {
        $this->headers = $headers;
    }

    public static function fromString(string $string): self
    {
        $headers = preg_split("/(\r|\n)+/", $string, -1, PREG_SPLIT_NO_EMPTY);
        $parse_headers = array();

        for ($i = 1; $i < count($headers); $i++) {
            [$key, $raw_value] = explode(':', $headers[$i], 2);
            $key = trim($key);
            $value = trim($raw_value);

            if (array_key_exists($key, $parse_headers)) {
                // See HTTP RFC Sec 4.2 Paragraph 5
                // http://www.w3.org/Protocols/rfc2616/rfc2616-sec4.html#sec4.2
                // If a header appears more than once, it must also be able to
                // be represented as a single header with a comma-separated
                // list of values.  We transform accordingly.
                $parse_headers[$key] .= ',' . $value;
            } else {
                $parse_headers[$key] = $value;
            }
        }

        return new self($parse_headers);
    }

    public function offsetExists($offset): bool // phpcs:ignore
    {
        return $this->getCaseInsensitive($offset) !== null;
    }

    public function offsetGet($offset): mixed // phpcs:ignore
    {
        return $this->getCaseInsensitive($offset);
    }

    /** @throws \Exception */
    public function offsetSet($offset, $value): void // phpcs:ignore
    {
        throw new Exception("Headers are read-only.");
    }

    /** @throws \Exception */
    public function offsetUnset($offset): void // phpcs:ignore
    {
        throw new Exception("Headers are read-only.");
    }

    public function count(): int
    {
        return count($this->headers);
    }

    /** @return array */
    public function toArray(): array
    {
        return $this->headers;
    }

    private function getCaseInsensitive(string $key)
    {
        foreach ($this->headers as $header => $value) {
            if (strtolower($key) === strtolower($header)) {
                return $value;
            }
        }

        return null;
    }
}
