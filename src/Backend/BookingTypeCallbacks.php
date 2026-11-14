<?php

declare(strict_types=1);

namespace Nordwerk\BookingBundle\Backend;

use Contao\DataContainer;
use Contao\Date;
use Contao\Input;
use Contao\Message;
use Contao\StringUtil;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Nordwerk\BookingBundle\Domain\WebhookSignature;
use Nordwerk\BookingBundle\Domain\WeeklyWindows;
use Nordwerk\BookingBundle\Infrastructure\BookingTypes;
use Nordwerk\BookingBundle\Infrastructure\FeedCache;
use Nordwerk\BookingBundle\Infrastructure\WebhookOutbox;
use Symfony\Contracts\Translation\TranslatorInterface;

final class BookingTypeCallbacks
{
    public function __construct(
        private readonly Connection $connection,
        private readonly FeedCache $feeds,
        private readonly WebhookOutbox $webhooks,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function validateWindows(mixed $value): string
    {
        $windows = WeeklyWindows::parse((string) $value);

        // Stored in one notation, whatever the editor typed.
        return implode(', ', array_map(static fn (array $w): string => WeeklyWindows::format($w['start']).'–'.WeeklyWindows::format($w['end']), $windows));
    }

    public function validateUrls(mixed $value): string
    {
        $lines = preg_split('/\s+/', trim((string) $value)) ?: [];
        $lines = array_values(array_filter($lines, static fn (string $line): bool => '' !== $line));

        foreach ($lines as $line) {
            if (1 !== preg_match('~^(https?|webcal)://[^\s/$.?#][^\s]*$~i', $line)) {
                throw new \InvalidArgumentException($this->t('„%url%“ ist keine gültige Adresse (https://…).', ['%url%' => $line]));
            }
        }

        return implode("\n", array_unique($lines));
    }

    /**
     * @return array<string, string>
     */
    public function timezones(): array
    {
        $zones = \DateTimeZone::listIdentifiers();

        return array_combine($zones, $zones);
    }

    public function suggestSecret(mixed $value): string
    {
        return '' === (string) $value ? WebhookSignature::generateSecret() : (string) $value;
    }

    public function feedStatus(DataContainer $dc): string
    {
        $type = $this->connection->fetchAssociative('SELECT * FROM tl_booking_type WHERE id = ?', [(int) $dc->id]);
        if (false === $type || [] === BookingTypes::urls((string) $type['feeds'])) {
            return '';
        }
        $this->feeds->refresh($type, time());
        $items = '';

        foreach ($this->feeds->rows((int) $type['id'], BookingTypes::urls((string) $type['feeds'])) as $row) {
            $busy = json_decode((string) $row['busy'], true);
            $ok = '' === (string) $row['error'];
            $state = $ok
                ? $this->t('Zuletzt gelesen %date%, %count% belegte Zeiten.', ['%date%' => Date::parse(Date::getNumericDatimFormat(), (int) $row['checkedAt']), '%count%' => \is_array($busy) ? \count($busy) : 0])
                : $this->t('Nicht erreichbar (%error%). Zuletzt erfolgreich: %date%. Bis dahin gelten die zuletzt gelesenen Zeiten.', ['%error%' => (string) $row['error'], '%date%' => (int) $row['lastSuccessAt'] > 0 ? Date::parse(Date::getNumericDatimFormat(), (int) $row['lastSuccessAt']) : $this->t('nie')]);
            $items .= \sprintf('<li class="%s"><code>%s</code><br>%s</li>', $ok ? 'tl_green' : 'tl_red', StringUtil::specialchars($this->mask((string) $row['url'])), StringUtil::specialchars($state));
        }

        return '<div class="widget clr"><h3>'.$this->t('Stand der Kalender-Abos').'</h3><ul class="booking-status">'.$items.'</ul></div>';
    }

    public function webhookLog(DataContainer $dc): string
    {
        $rows = '';

        foreach ($this->webhooks->log((int) $dc->id, 10) as $delivery) {
            $state = match ((string) $delivery['status']) {
                'delivered' => $this->t('zugestellt'),
                'failed' => $this->t('gescheitert'),
                default => $this->t('wartet (Versuch %n%)', ['%n%' => (int) $delivery['attempts'] + 1]),
            };
            $rows .= \sprintf(
                '<tr><td>%s</td><td>%s</td><td><code>%s</code></td><td class="%s">%s</td><td>%s</td></tr>',
                Date::parse(Date::getNumericDatimFormat(), (int) ($delivery['lastAttemptAt'] ?: $delivery['createdAt'])),
                StringUtil::specialchars((string) $delivery['event']),
                StringUtil::specialchars($this->mask((string) $delivery['url'])),
                'delivered' === $delivery['status'] ? 'tl_green' : ('failed' === $delivery['status'] ? 'tl_red' : 'tl_gray'),
                StringUtil::specialchars($state),
                StringUtil::specialchars(trim(((int) $delivery['responseCode'] > 0 ? 'HTTP '.(int) $delivery['responseCode'].' ' : '').((string) $delivery['error'] !== 'HTTP '.(int) $delivery['responseCode'] ? (string) $delivery['error'] : ''))),
            );
        }
        $table = '' === $rows ? '<p class="tl_help">'.$this->t('Noch keine Zustellungen.').'</p>'
            : '<table class="tl_listing booking-log"><thead><tr><th>'.$this->t('Zeit').'</th><th>'.$this->t('Ereignis').'</th><th>'.$this->t('Adresse').'</th><th>'.$this->t('Status').'</th><th>'.$this->t('Antwort').'</th></tr></thead><tbody>'.$rows.'</tbody></table>';

        return '<div class="widget clr"><h3>'.$this->t('Letzte Zustellungen').'</h3>'.$table
            .'<p><button type="submit" name="booking_test_webhook" value="1" class="tl_submit">'.$this->t('Speichern und Testereignis senden').'</button></p>'
            .'<p class="tl_help tl_tip">'.$this->t('Jede Zustellung trägt die Kopfzeilen webhook-id, webhook-timestamp und webhook-signature (HMAC-SHA256 nach Standard Webhooks). Fehlgeschlagene Zustellungen werden bis zu fünfmal wiederholt.').'</p></div>';
    }

    public function sendTestEvent(DataContainer $dc): void
    {
        if ('1' !== Input::post('booking_test_webhook')) {
            return;
        }
        $type = $this->connection->fetchAssociative('SELECT * FROM tl_booking_type WHERE id = ?', [(int) $dc->id]);
        if (false === $type || [] === BookingTypes::urls((string) $type['webhooks'])) {
            Message::addError($this->t('Bitte zuerst eine Webhook-Adresse eintragen.'));

            return;
        }
        $now = time();
        $ids = $this->webhooks->queueTest($type, $now);
        $this->webhooks->deliverDue($now, $ids);
        $failed = (int) $this->connection->fetchOne("SELECT COUNT(*) FROM tl_booking_webhook WHERE id IN (?) AND status <> 'delivered'", [$ids], [ArrayParameterType::INTEGER]);
        0 === $failed
            ? Message::addConfirmation($this->t('Testereignis zugestellt.'))
            : Message::addError($this->t('Testereignis nicht zugestellt; Details in der Liste der Zustellungen.'));
    }

    /**
     * @param array<string, mixed> $row
     */
    public function label(array $row, string $label): string
    {
        $upcoming = (int) $this->connection->fetchOne("SELECT COUNT(*) FROM tl_booking_appointment WHERE pid = ? AND status = 'confirmed' AND start > ?", [(int) $row['id'], time()]);
        $label .= ' <span class="label-info">'.$this->t('%count% anstehende Termine', ['%count%' => $upcoming]).'</span>';
        if ($this->feeds->hasError($row)) {
            $label .= ' <span class="tl_red">'.$this->t('Kalender-Abo nicht erreichbar').'</span>';
        }

        return $label;
    }

    /**
     * Calendar subscription addresses contain secrets; the back end shows only the
     * host and the end.
     */
    private function mask(string $url): string
    {
        $host = (string) parse_url($url, PHP_URL_HOST);

        return \strlen($url) <= 40 ? $url : $host.'/…'.substr($url, -12);
    }

    /**
     * @param array<string, string|int> $parameters
     */
    private function t(string $message, array $parameters = []): string
    {
        return $this->translator->trans($message, $parameters, 'messages');
    }
}
