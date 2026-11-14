<?php

declare(strict_types=1);

$GLOBALS['TL_LANG']['tl_booking_type']['title_legend'] = 'Terminart';
$GLOBALS['TL_LANG']['tl_booking_type']['rules_legend'] = 'Dauer und Zeitraum';
$GLOBALS['TL_LANG']['tl_booking_type']['windows_legend'] = 'Wöchentliche Zeitfenster';
$GLOBALS['TL_LANG']['tl_booking_type']['mail_legend'] = 'Mails';
$GLOBALS['TL_LANG']['tl_booking_type']['feeds_legend'] = 'Belegte Zeiten aus Kalendern';
$GLOBALS['TL_LANG']['tl_booking_type']['webhooks_legend'] = 'Webhooks';
$GLOBALS['TL_LANG']['tl_booking_type']['privacy_legend'] = 'Datenschutz';
$GLOBALS['TL_LANG']['tl_booking_type']['publish_legend'] = 'Veröffentlichung';
$GLOBALS['TL_LANG']['tl_booking_type']['title'] = ['Titel', 'Name der Terminart, z. B. „Erstgespräch“. Steht in Kalender, Mails und Kalendereintrag.'];
$GLOBALS['TL_LANG']['tl_booking_type']['businessName'] = ['Name des Betriebs', 'Absendername der Mails und Organisator im Kalendereintrag.'];
$GLOBALS['TL_LANG']['tl_booking_type']['businessEmail'] = ['E-Mail des Betriebs', 'Antwortadresse der Gäste und Organisator im Kalendereintrag.'];
$GLOBALS['TL_LANG']['tl_booking_type']['location'] = ['Ort', 'Adresse, „Telefon“ oder „Videogespräch“. Optional.'];
$GLOBALS['TL_LANG']['tl_booking_type']['duration'] = ['Dauer in Minuten', 'Länge eines Termins.'];
$GLOBALS['TL_LANG']['tl_booking_type']['buffer'] = ['Puffer in Minuten', 'Freie Zeit nach jedem Termin, auch zu belegten Zeiten aus Kalendern.'];
$GLOBALS['TL_LANG']['tl_booking_type']['leadTime'] = ['Vorlauf in Stunden', 'So viele Stunden im Voraus muss gebucht werden.'];
$GLOBALS['TL_LANG']['tl_booking_type']['horizon'] = ['Zeitraum in Tagen', 'So viele Tage im Voraus sind Termine buchbar.'];
$GLOBALS['TL_LANG']['tl_booking_type']['timezone'] = ['Zeitzone', 'Die Zeitfenster gelten in dieser Zeitzone, auch über die Zeitumstellung hinweg.'];

foreach (['monday' => 'Montag', 'tuesday' => 'Dienstag', 'wednesday' => 'Mittwoch', 'thursday' => 'Donnerstag', 'friday' => 'Freitag', 'saturday' => 'Samstag', 'sunday' => 'Sonntag'] as $day => $label) {
    $GLOBALS['TL_LANG']['tl_booking_type'][$day] = [$label, 'Zeitfenster als „09:00–12:00, 14:00–17:00“. Leer = an diesem Tag keine Termine.'];
}
$GLOBALS['TL_LANG']['tl_booking_type']['notifyEmails'] = ['Meldung an', 'Diese Adressen bekommen jede Buchung und Absage (mehrere mit Komma trennen). Leer = E-Mail des Betriebs.'];
$GLOBALS['TL_LANG']['tl_booking_type']['mailNote'] = ['Zusatz in der Bestätigung', 'Steht in der Bestätigungsmail und im Kalendereintrag, z. B. Anfahrt oder „Den Link zum Videogespräch schicken wir am Vortag.“'];
$GLOBALS['TL_LANG']['tl_booking_type']['feeds'] = ['Kalender-Abos (ICS)', 'Eine Abo-Adresse je Zeile (Outlook: „Kalender veröffentlichen“ → ICS, Google: „Geheime Adresse im iCal-Format“, iCloud: „Öffentlicher Kalender“). Belegte Zeiten daraus sind nicht buchbar. Die Adressen sind geheim; nicht weitergeben.'];
$GLOBALS['TL_LANG']['tl_booking_type']['webhooks'] = ['Webhook-Adressen', 'Eine Adresse je Zeile. Bei „gebucht“ und „abgesagt“ geht ein JSON-POST dorthin.'];
$GLOBALS['TL_LANG']['tl_booking_type']['webhookSecret'] = ['Webhook-Geheimnis', 'Schlüssel der Signatur (HMAC-SHA256). Der Empfänger prüft damit, dass die Nachricht von dieser Website kommt.'];
$GLOBALS['TL_LANG']['tl_booking_type']['webhookPersonalData'] = ['Kontaktdaten mitsenden', 'Name, E-Mail, Telefon und Anliegen des Gastes im Webhook. Nur an Systeme senden, mit denen ein Vertrag zur Auftragsverarbeitung besteht.'];
$GLOBALS['TL_LANG']['tl_booking_type']['retentionDays'] = ['Kontaktdaten löschen nach (Tagen)', 'So viele Tage nach dem Termin werden Name, E-Mail, Telefon und Anliegen automatisch entfernt.'];
$GLOBALS['TL_LANG']['tl_booking_type']['published'] = ['Buchbar', 'Nur buchbare Terminarten zeigen freie Zeiten auf der Website.'];
