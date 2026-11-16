# Contao Terminbuchung

Terminbuchung für Contao 5.7: Besucher wählen auf der Website einen freien Termin (Monatskalender, Uhrzeiten, Formular) und sind sofort gebucht. Sie bekommen eine Bestätigung mit Kalendereintrag (ICS) und einem Link zum Absagen, der Betrieb bekommt eine Meldung. Belegte Zeiten aus Outlook, Google oder iCloud werden berücksichtigt, und bei „gebucht“ und „abgesagt“ geht auf Wunsch ein signierter Webhook an ein System Ihrer Wahl. Das Paket heißt `nordwerk/contao-booking-bundle`, braucht PHP 8.3 und Contao 5.7.12 oder neuer und steht unter der MIT-Lizenz.

## Einrichten

1. Im Contao Manager nach `nordwerk/contao-booking-bundle` suchen oder mit `composer require nordwerk/contao-booking-bundle` installieren, danach die Datenbankmigration ausführen.
2. Backend → **Inhalte → Terminbuchung** → neue Terminart anlegen: Titel (z. B. „Erstgespräch“), Name und E-Mail des Betriebs, Dauer, Puffer, Vorlauf, Zeitraum, Zeitzone und die Zeitfenster je Wochentag (`09:00–12:00, 14:00–17:00`). „Buchbar“ anhaken.
3. Auf der gewünschten Seite das Inhaltselement **Terminbuchung** einfügen, Terminart und Datenschutzseite wählen.
4. Prüfen, dass Contao Mails versenden kann (`MAILER_DSN`) und unter **System → Einstellungen** eine Absenderadresse der Website eingetragen ist. Die Mails gehen von dieser Adresse aus; Antworten der Gäste landen bei der E-Mail des Betriebs.
5. Der Contao-Cron sollte laufen (Systemcron mit `contao:cron` oder der Web-Cron von Contao). Er wiederholt gescheiterte Mails und Webhooks, liest Kalender-Abos neu und löscht Kontaktdaten nach der eingestellten Frist.

## Regeln

- Buchbar sind nur Zeiten, die gerade frei sind: im Zeitfenster, nach dem Vorlauf, im Zeitraum, mit Puffer zu anderen Terminen und zu belegten Zeiten aus Kalender-Abos. Die Zeitfenster folgen der Wanduhr, auch über die Zeitumstellung.
- Gleichzeitige Anfragen für dieselbe Zeit: Genau eine bekommt den Termin, die andere die Meldung „gerade vergeben“.
- Je Mailadresse höchstens drei künftige Termine je Terminart, höchstens 40 Buchungen je Terminart in 24 Stunden, dazu eine Grenze je IP-Adresse.
- Ein nicht erreichbares Kalender-Abo blockiert keine Buchung; es gelten die zuletzt gelesenen Zeiten, und der Fehler steht in der Terminart und in der Liste.

## Webhooks

Je Terminart eine oder mehrere Adressen. Gesendet wird ein JSON-POST nach der Buchung bzw. Absage, signiert nach [Standard Webhooks](https://www.standardwebhooks.com/): Kopfzeilen `webhook-id`, `webhook-timestamp` und `webhook-signature` (`v1,<Base64 von HMAC-SHA256 über "id.timestamp.body">`; ein Geheimnis `whsec_…` wird Base64-dekodiert). Ereignisse: `appointment.booked`, `appointment.cancelled`, `test`. Gescheiterte Zustellungen werden nach 1, 5, 30 Minuten, 2 und 12 Stunden wiederholt. „Kontaktdaten mitsenden“ steuert, ob Name, E-Mail, Telefon und Anliegen im Webhook stehen.

```json
{
  "type": "appointment.booked",
  "timestamp": "2026-11-13T12:39:48+01:00",
  "data": {
    "appointment": {
      "id": 19,
      "status": "confirmed",
      "start": "2026-11-14T10:00:00+01:00",
      "end": "2026-11-14T10:30:00+01:00",
      "timezone": "Europe/Berlin",
      "title": "Erstgespräch",
      "location": "Videogespräch",
      "bookingType": { "id": 1, "title": "Erstgespräch" },
      "attendee": { "name": "…", "email": "…", "phone": "", "note": "…" }
    }
  }
}
```

## Anpassen

- Template `content_element/appointment_booking` im Template Studio überschreiben (Blöcke `booking_intro`, `booking_pick`, `booking_form`, `booking_consent`, `booking_done`).
- Texte über die Übersetzungen (`translations/messages.de|en.yaml`, Schlüssel sind die deutschen Texte).
- Aussehen über CSS-Variablen (`--booking-accent`, `--booking-ink`, `--booking-radius` …) oder eigene Regeln: Die Standardstile haben keine Spezifität.

## Rechtliches

Einwilligungstext, Mailtexte und Datenschutzhinweise sind Vorschläge und vor dem Einsatz zu prüfen. Kontaktdaten werden nach der eingestellten Frist automatisch entfernt.

## Fertig gestaltet

Das Bundle bringt eine schlichte Standardgestaltung mit, die Schrift und Farben der Website übernimmt. Eine fertige Website für Selbstständige mit gestalteter Buchung gibt es als [Studio-Theme von Nordwerk](https://nordwerk.studio/contao); eine Live-Demo steht unter <https://studio-contao-theme.nordwerk.dev>.

## Mitmachen

Dieses Repository ist ein Nur-lese-Abbild des Bundles. Fehler und Wünsche bitte als Issue melden.
