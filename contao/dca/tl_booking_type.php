<?php

declare(strict_types=1);

use Contao\DataContainer;
use Contao\DC_Table;
use Nordwerk\BookingBundle\Backend\BookingTypeCallbacks;

$weekday = static fn (): array => [
    'inputType' => 'text',
    'eval' => ['maxlength' => 255, 'tl_class' => 'w50'],
    'save_callback' => [[BookingTypeCallbacks::class, 'validateWindows']],
    'sql' => "varchar(255) NOT NULL default ''",
];

// Booking types ("Terminarten"): the rules for free times, the mails and the webhooks.
$GLOBALS['TL_DCA']['tl_booking_type'] = [
    'config' => [
        'dataContainer' => DC_Table::class,
        'ctable' => ['tl_booking_appointment'],
        'switchToEdit' => true,
        'enableVersioning' => true,
        'onsubmit_callback' => [[BookingTypeCallbacks::class, 'sendTestEvent']],
        'sql' => ['keys' => ['id' => 'primary']],
    ],
    'list' => [
        'sorting' => ['mode' => DataContainer::MODE_SORTED, 'fields' => ['title'], 'flag' => DataContainer::SORT_INITIAL_LETTER_ASC, 'panelLayout' => 'search,limit'],
        'label' => ['fields' => ['title', 'duration'], 'format' => '%s <span class="label-info">[%s Min.]</span>', 'label_callback' => [BookingTypeCallbacks::class, 'label']],
        'global_operations' => ['all'],
        'operations' => ['edit', 'children', 'copy', 'delete', 'toggle', 'show'],
    ],
    'palettes' => [
        'default' => '{title_legend},title,businessName,businessEmail,location;{rules_legend},duration,buffer,leadTime,horizon,timezone;{windows_legend},monday,tuesday,wednesday,thursday,friday,saturday,sunday;{mail_legend},notifyEmails,mailNote;{feeds_legend},feeds,feedStatus;{webhooks_legend},webhooks,webhookSecret,webhookPersonalData,webhookLog;{privacy_legend},retentionDays;{publish_legend},published',
    ],
    'fields' => [
        'id' => ['sql' => 'int(10) unsigned NOT NULL auto_increment'],
        'tstamp' => ['sql' => "int(10) unsigned NOT NULL default '0'"],
        'title' => ['inputType' => 'text', 'search' => true, 'eval' => ['mandatory' => true, 'maxlength' => 120, 'tl_class' => 'w50'], 'sql' => "varchar(120) NOT NULL default ''"],
        'businessName' => ['inputType' => 'text', 'eval' => ['mandatory' => true, 'maxlength' => 120, 'tl_class' => 'w50'], 'sql' => "varchar(120) NOT NULL default ''"],
        'businessEmail' => ['inputType' => 'text', 'eval' => ['mandatory' => true, 'rgxp' => 'email', 'maxlength' => 200, 'tl_class' => 'w50'], 'sql' => "varchar(200) NOT NULL default ''"],
        'location' => ['inputType' => 'text', 'eval' => ['maxlength' => 200, 'tl_class' => 'w50'], 'sql' => "varchar(200) NOT NULL default ''"],
        'duration' => ['inputType' => 'text', 'eval' => ['mandatory' => true, 'rgxp' => 'natural', 'minval' => 5, 'maxval' => 480, 'tl_class' => 'w50'], 'sql' => "smallint(5) unsigned NOT NULL default '30'"],
        'buffer' => ['inputType' => 'text', 'eval' => ['rgxp' => 'natural', 'maxval' => 240, 'tl_class' => 'w50'], 'sql' => "smallint(5) unsigned NOT NULL default '0'"],
        'leadTime' => ['inputType' => 'text', 'eval' => ['rgxp' => 'natural', 'maxval' => 720, 'tl_class' => 'w50'], 'sql' => "smallint(5) unsigned NOT NULL default '24'"],
        'horizon' => ['inputType' => 'text', 'eval' => ['mandatory' => true, 'rgxp' => 'natural', 'minval' => 1, 'maxval' => 365, 'tl_class' => 'w50'], 'sql' => "smallint(5) unsigned NOT NULL default '28'"],
        'timezone' => [
            'inputType' => 'select',
            'options_callback' => [BookingTypeCallbacks::class, 'timezones'],
            'eval' => ['mandatory' => true, 'chosen' => true, 'tl_class' => 'w50'],
            'sql' => "varchar(64) NOT NULL default 'Europe/Berlin'",
        ],
        'monday' => $weekday(),
        'tuesday' => $weekday(),
        'wednesday' => $weekday(),
        'thursday' => $weekday(),
        'friday' => $weekday(),
        'saturday' => $weekday(),
        'sunday' => $weekday(),
        'notifyEmails' => ['inputType' => 'text', 'eval' => ['rgxp' => 'emails', 'maxlength' => 1000, 'tl_class' => 'clr long'], 'sql' => 'text NULL'],
        'mailNote' => ['inputType' => 'textarea', 'eval' => ['maxlength' => 2000, 'rows' => 3, 'tl_class' => 'clr'], 'sql' => 'text NULL'],
        'feeds' => [
            'inputType' => 'textarea',
            'eval' => ['rows' => 3, 'decodeEntities' => true, 'tl_class' => 'clr', 'placeholder' => 'https://outlook.office365.com/owa/calendar/…/calendar.ics'],
            'save_callback' => [[BookingTypeCallbacks::class, 'validateUrls']],
            'sql' => 'text NULL',
        ],
        'feedStatus' => ['input_field_callback' => [BookingTypeCallbacks::class, 'feedStatus']],
        'webhooks' => [
            'inputType' => 'textarea',
            'eval' => ['rows' => 2, 'decodeEntities' => true, 'tl_class' => 'clr', 'placeholder' => 'https://example.com/hooks/termine'],
            'save_callback' => [[BookingTypeCallbacks::class, 'validateUrls']],
            'sql' => 'text NULL',
        ],
        'webhookSecret' => [
            'inputType' => 'text',
            'eval' => ['maxlength' => 128, 'decodeEntities' => true, 'doNotCopy' => true, 'tl_class' => 'w50'],
            'load_callback' => [[BookingTypeCallbacks::class, 'suggestSecret']],
            'sql' => "varchar(128) NOT NULL default ''",
        ],
        'webhookPersonalData' => ['inputType' => 'checkbox', 'eval' => ['tl_class' => 'w50 m12'], 'sql' => "char(1) NOT NULL default '1'"],
        'webhookLog' => ['input_field_callback' => [BookingTypeCallbacks::class, 'webhookLog']],
        'retentionDays' => ['inputType' => 'text', 'eval' => ['mandatory' => true, 'rgxp' => 'natural', 'minval' => 1, 'maxval' => 3650, 'tl_class' => 'w50'], 'sql' => "smallint(5) unsigned NOT NULL default '365'"],
        'published' => ['inputType' => 'checkbox', 'toggle' => true, 'filter' => true, 'eval' => ['doNotCopy' => true, 'tl_class' => 'w50'], 'sql' => "char(1) NOT NULL default ''"],
    ],
];
