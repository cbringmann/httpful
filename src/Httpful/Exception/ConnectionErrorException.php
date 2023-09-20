<?php

declare(strict_types = 1);

namespace Httpful\Exception;

use Exception;

class ConnectionErrorException extends Exception
{
    private  int|string $curlErrorNumber;

    private string $curlErrorString;

    public function getCurlErrorNumber(): int|string
    {
        return $this->curlErrorNumber;
    }

    /** @return $this */
    public function setCurlErrorNumber(int|string $curlErrorNumber)
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
