<?php

declare(strict_types=1);

$GLOBALS['TL_LANG']['tl_booking_type']['title_legend'] = 'Appointment type';
$GLOBALS['TL_LANG']['tl_booking_type']['rules_legend'] = 'Duration and period';
$GLOBALS['TL_LANG']['tl_booking_type']['windows_legend'] = 'Weekly time windows';
$GLOBALS['TL_LANG']['tl_booking_type']['mail_legend'] = 'Mails';
$GLOBALS['TL_LANG']['tl_booking_type']['feeds_legend'] = 'Busy times from calendars';
$GLOBALS['TL_LANG']['tl_booking_type']['webhooks_legend'] = 'Webhooks';
$GLOBALS['TL_LANG']['tl_booking_type']['privacy_legend'] = 'Privacy';
$GLOBALS['TL_LANG']['tl_booking_type']['publish_legend'] = 'Publishing';
$GLOBALS['TL_LANG']['tl_booking_type']['title'] = ['Title', 'Name of the appointment type, e.g. “First consultation”.'];
$GLOBALS['TL_LANG']['tl_booking_type']['businessName'] = ['Business name', 'Sender name of the mails and organiser in the calendar entry.'];
$GLOBALS['TL_LANG']['tl_booking_type']['businessEmail'] = ['Business email', 'Reply address for guests and organiser in the calendar entry.'];
$GLOBALS['TL_LANG']['tl_booking_type']['location'] = ['Location', 'Address, “Phone” or “Video call”. Optional.'];
$GLOBALS['TL_LANG']['tl_booking_type']['duration'] = ['Duration in minutes', 'Length of an appointment.'];
$GLOBALS['TL_LANG']['tl_booking_type']['buffer'] = ['Buffer in minutes', 'Free time after every appointment.'];
$GLOBALS['TL_LANG']['tl_booking_type']['leadTime'] = ['Lead time in hours', 'Bookings must be made this many hours ahead.'];
$GLOBALS['TL_LANG']['tl_booking_type']['horizon'] = ['Period in days', 'Appointments can be booked this many days ahead.'];
$GLOBALS['TL_LANG']['tl_booking_type']['timezone'] = ['Time zone', 'The time windows apply in this time zone, across daylight saving changes.'];

foreach (['monday' => 'Monday', 'tuesday' => 'Tuesday', 'wednesday' => 'Wednesday', 'thursday' => 'Thursday', 'friday' => 'Friday', 'saturday' => 'Saturday', 'sunday' => 'Sunday'] as $day => $label) {
    $GLOBALS['TL_LANG']['tl_booking_type'][$day] = [$label, 'Time windows as “09:00–12:00, 14:00–17:00”. Empty = no appointments on this day.'];
}
$GLOBALS['TL_LANG']['tl_booking_type']['notifyEmails'] = ['Notify', 'These addresses get every booking and cancellation. Empty = business email.'];
$GLOBALS['TL_LANG']['tl_booking_type']['mailNote'] = ['Note in the confirmation', 'Shown in the confirmation mail and the calendar entry.'];
$GLOBALS['TL_LANG']['tl_booking_type']['feeds'] = ['Calendar subscriptions (ICS)', 'One subscription address per line. Busy times from them cannot be booked. Keep the addresses secret.'];
$GLOBALS['TL_LANG']['tl_booking_type']['webhooks'] = ['Webhook addresses', 'One address per line. “booked” and “cancelled” are sent there as JSON POST.'];
$GLOBALS['TL_LANG']['tl_booking_type']['webhookSecret'] = ['Webhook secret', 'Key of the signature (HMAC-SHA256).'];
$GLOBALS['TL_LANG']['tl_booking_type']['webhookPersonalData'] = ['Send contact details', 'Name, email, phone and request of the guest in the webhook.'];
$GLOBALS['TL_LANG']['tl_booking_type']['retentionDays'] = ['Delete contact details after (days)', 'Contact details are removed this many days after the appointment.'];
$GLOBALS['TL_LANG']['tl_booking_type']['published'] = ['Bookable', 'Only bookable types show free times on the website.'];
