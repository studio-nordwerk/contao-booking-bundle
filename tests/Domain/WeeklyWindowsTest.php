<?php

declare(strict_types=1);

namespace Nordwerk\BookingBundle\Tests\Domain;

use Nordwerk\BookingBundle\Domain\WeeklyWindows;
use PHPUnit\Framework\TestCase;

final class WeeklyWindowsTest extends TestCase
{
    public function testReadsTheUsualNotations(): void
    {
        $this->assertSame([['start' => 540, 'end' => 720], ['start' => 840, 'end' => 1020]], WeeklyWindows::parse('14.00-17:00; 09:00–12:00'));
        $this->assertSame([['start' => 1320, 'end' => 1440]], WeeklyWindows::parse('22:00 bis 24:00'));
        $this->assertSame([], WeeklyWindows::parse(''));
    }

    public function testRejectsWindowsThatWouldOfferWrongTimes(): void
    {
        foreach (['9 bis 12', '12:00–09:00', '10:00–10:00', '25:00–26:00', '09:60–10:00'] as $value) {
            try {
                WeeklyWindows::parse($value);
                $this->fail('Accepted: '.$value);
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
