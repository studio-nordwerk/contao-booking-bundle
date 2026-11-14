<?php

declare(strict_types=1);

use Contao\DataContainer;
use Contao\DC_Table;
use Nordwerk\BookingBundle\Backend\AppointmentCallbacks;

// Appointments of a booking type. Created only by the booking; the back end can
// cancel them.
$GLOBALS['TL_DCA']['tl_booking_appointment'] = [
    'config' => [
        'dataContainer' => DC_Table::class,
        'ptable' => 'tl_booking_type',
        'notCreatable' => true,
        'notCopyable' => true,
        'enableVersioning' => true,
        'onsubmit_callback' => [[AppointmentCallbacks::class, 'afterSubmit']],
        'sql' => ['keys' => ['id' => 'primary', 'token' => 'unique', 'pid,status,start' => 'index', 'email' => 'index']],
    ],
    'list' => [
        'sorting' => ['mode' => DataContainer::MODE_PARENT, 'fields' => ['start DESC'], 'headerFields' => ['title', 'duration', 'location'], 'panelLayout' => 'filter;search,limit', 'disableGrouping' => true],
        'label' => ['fields' => ['start', 'name'], 'label_callback' => [AppointmentCallbacks::class, 'label']],
        'operations' => ['edit', 'delete', 'show'],
    ],
    'palettes' => [
        'default' => '{appointment_legend},status,start,end,name,email,phone,note;{internal_legend},internalNote',
    ],
    'fields' => [
        'id' => ['sql' => 'int(10) unsigned NOT NULL auto_increment'],
        'pid' => ['foreignKey' => 'tl_booking_type.title', 'sql' => "int(10) unsigned NOT NULL default '0'", 'relation' => ['type' => 'belongsTo', 'load' => 'lazy']],
        'tstamp' => ['sql' => "int(10) unsigned NOT NULL default '0'"],
        'status' => [
            'inputType' => 'select', 'filter' => true, 'options' => ['confirmed', 'cancelled'],
            'reference' => &$GLOBALS['TL_LANG']['tl_booking_appointment']['statuses'],
            'eval' => ['tl_class' => 'w50'],
            'save_callback' => [[AppointmentCallbacks::class, 'saveStatus']],
            'sql' => "varchar(16) NOT NULL default 'confirmed'",
        ],
        'start' => ['inputType' => 'text', 'flag' => DataContainer::SORT_DAY_DESC, 'eval' => ['rgxp' => 'datim', 'disabled' => true, 'tl_class' => 'w50 clr'], 'sql' => "int(10) unsigned NOT NULL default '0'"],
        'end' => ['inputType' => 'text', 'eval' => ['rgxp' => 'datim', 'disabled' => true, 'tl_class' => 'w50'], 'sql' => "int(10) unsigned NOT NULL default '0'"],
        'name' => ['inputType' => 'text', 'search' => true, 'eval' => ['disabled' => true, 'tl_class' => 'w50'], 'sql' => "varchar(120) NOT NULL default ''"],
        'email' => ['inputType' => 'text', 'search' => true, 'eval' => ['disabled' => true, 'tl_class' => 'w50'], 'sql' => "varchar(200) NOT NULL default ''"],
        'phone' => ['inputType' => 'text', 'eval' => ['disabled' => true, 'tl_class' => 'w50'], 'sql' => "varchar(40) NOT NULL default ''"],
        'note' => ['inputType' => 'textarea', 'eval' => ['disabled' => true, 'tl_class' => 'clr'], 'sql' => 'text NULL'],
        'internalNote' => ['inputType' => 'textarea', 'eval' => ['rows' => 3, 'tl_class' => 'clr'], 'sql' => 'text NULL'],
        'title' => ['sql' => "varchar(120) NOT NULL default ''"],
        'location' => ['sql' => "varchar(200) NOT NULL default ''"],
        'timezone' => ['sql' => "varchar(64) NOT NULL default 'Europe/Berlin'"],
        'locale' => ['sql' => "varchar(8) NOT NULL default 'de'"],
        'token' => ['sql' => 'char(64) NULL'],
        'uid' => ['sql' => "varchar(128) NOT NULL default ''"],
        'pageUrl' => ['sql' => "varchar(1024) NOT NULL default ''"],
        'createdAt' => ['sql' => "int(10) unsigned NOT NULL default '0'"],
        'consentAt' => ['sql' => "int(10) unsigned NOT NULL default '0'"],
        'cancelledAt' => ['sql' => "int(10) unsigned NOT NULL default '0'"],
        'cancelledBy' => ['sql' => "varchar(16) NOT NULL default ''"],
        'confirmationSentAt' => ['sql' => "int(10) unsigned NOT NULL default '0'"],
        'merchantSentAt' => ['sql' => "int(10) unsigned NOT NULL default '0'"],
        'cancellationSentAt' => ['sql' => "int(10) unsigned NOT NULL default '0'"],
        'mailAttempts' => ['sql' => "smallint(5) unsigned NOT NULL default '0'"],
        'anonymizedAt' => ['sql' => "int(10) unsigned NOT NULL default '0'"],
    ],
];
