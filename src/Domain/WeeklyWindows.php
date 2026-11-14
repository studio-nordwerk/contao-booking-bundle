<?php

declare(strict_types=1);

namespace Nordwerk\BookingBundle\Domain;

/**
 * Weekly opening windows of a booking type: one text field per weekday in the back end,
 * for example "09:00–12:00, 14:00–17:00". Weekday 1 is Monday, 7 is Sunday.
 */
final class WeeklyWindows
{
    /**
     * Column of tl_booking_type per weekday.
     */
    public const COLUMNS = [1 => 'monday', 2 => 'tuesday', 3 => 'wednesday', 4 => 'thursday', 5 => 'friday', 6 => 'saturday', 7 => 'sunday'];

    /**
     * @return list<array{start: int, end: int}> minutes since midnight
     *
     * @throws \InvalidArgumentException with a German message for the back end
     */
    public static function parse(string $value): array
    {
        $windows = [];

        foreach (preg_split('/[,;\n]+/', $value) ?: [] as $part) {
            $part = trim($part);
            if ('' === $part) {
                continue;
            }
            if (1 !== preg_match('/^(\d{1,2})[:.](\d{2})\s*(?:-|–|—|bis)\s*(\d{1,2})[:.](\d{2})$/u', $part, $match)) {
                throw new \InvalidArgumentException(\sprintf('„%s“ ist kein Zeitfenster. Bitte so angeben: 09:00–12:00, 14:00–17:00', $part));
            }
            $start = self::minutes((int) $match[1], (int) $match[2]);
            // 24:00 is the end of the day.
            $end = 24 === (int) $match[3] && 0 === (int) $match[4] ? 1440 : self::minutes((int) $match[3], (int) $match[4]);
            if (null === $start || null === $end) {
                throw new \InvalidArgumentException(\sprintf('„%s“ enthält eine ungültige Uhrzeit.', $part));
            }
            if ($start >= $end) {
                throw new \InvalidArgumentException(\sprintf('In „%s“ muss der Beginn vor dem Ende liegen.', $part));
            }
            $windows[] = ['start' => $start, 'end' => $end];
        }
        usort($windows, static fn (array $a, array $b): int => $a['start'] <=> $b['start']);

        return $windows;
    }

    /**
     * All windows of a booking type row; invalid fields count as closed.
     *
     * @param array<string, mixed> $row
     *
     * @return list<array{weekday: int, start: int, end: int}>
     */
    public static function fromRow(array $row): array
    {
        $windows = [];

        foreach (self::COLUMNS as $weekday => $column) {
            try {
                foreach (self::parse((string) ($row[$column] ?? '')) as $window) {
                    $windows[] = ['weekday' => $weekday, ...$window];
                }
            } catch (\InvalidArgumentException) {
            }
        }

        return $windows;
    }

    public static function format(int $minutes): string
    {
        return \sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }

    private static function minutes(int $hour, int $minute): int|null
    {
        return $hour >= 0 && $hour <= 23 && $minute >= 0 && $minute <= 59 ? $hour * 60 + $minute : null;
    }
}
