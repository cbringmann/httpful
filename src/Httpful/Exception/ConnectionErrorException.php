<?php

declare(strict_types = 1);

namespace Httpful\Exception;

use Exception;

class ConnectionErrorException extends Exception
{
    private string $curlErrorNumber;

    private string $curlErrorString;

    public function getCurlErrorNumber(): string
    {
        return $this->curlErrorNumber;
    }

    /** @return $this */
    public function setCurlErrorNumber(string $curlErrorNumber)
    {
        $this->curlErrorNumber = $curlErrorNumber;

        return $this;
    }

    public function getCurlErrorString(): string
    {
        return $this->curlErrorString;
    }

    /** @return $this */
    public function setCurlErrorString(string $curlErrorString)
    {
        $this->curlErrorString = $curlErrorString;

        return $this;
    }
}
