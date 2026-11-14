<?php

declare(strict_types=1);

namespace Nordwerk\BookingBundle;

use Symfony\Component\HttpKernel\Bundle\Bundle;

final class NordwerkBookingBundle extends Bundle
{
    public function getPath(): string
    {
        return \dirname(__DIR__);
    }
}
