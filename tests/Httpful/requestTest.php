<?php

declare(strict_types = 1);

/** @author nick fox <quixand gmail com> */

namespace Httpful\Test;

use Httpful\Exception\ConnectionErrorException;
use Httpful\Request;
use PHPUnit\Framework\TestCase;

class requestTest extends TestCase
{
    /** @author Nick Fox */
    public function testGet_InvalidURL(): void
    {
        // Silence the default logger via whenError override
        $caught = false;

        try {
            Request::get('unavailable.url')->whenError(static function($error): void {
            })->send();
        } catch (ConnectionErrorException) {
            $caught = true;
        }

        $this->assertTrue($caught);
    }
}
