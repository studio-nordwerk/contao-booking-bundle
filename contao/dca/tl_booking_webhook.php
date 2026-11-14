<?php

declare(strict_types=1);

use Contao\DC_Table;

// Webhook deliveries (outbox and log). Shown in the booking type form, no module
// of its own.
$GLOBALS['TL_DCA']['tl_booking_webhook'] = [
    'config' => [
        'dataContainer' => DC_Table::class,
        'closed' => true,
        'notEditable' => true,
        'sql' => ['keys' => ['id' => 'primary', 'status,nextAttemptAt' => 'index', 'pid' => 'index']],
    ],
    'fields' => [
        'id' => ['sql' => 'int(10) unsigned NOT NULL auto_increment'],
        'pid' => ['sql' => "int(10) unsigned NOT NULL default '0'"],
        'tstamp' => ['sql' => "int(10) unsigned NOT NULL default '0'"],
        'appointmentId' => ['sql' => "int(10) unsigned NOT NULL default '0'"],
        'event' => ['sql' => "varchar(32) NOT NULL default ''"],
        'url' => ['sql' => "varchar(1024) NOT NULL default ''"],
        'deliveryId' => ['sql' => "varchar(64) NOT NULL default ''"],
        'payload' => ['sql' => 'mediumtext NULL'],
        'status' => ['sql' => "varchar(16) NOT NULL default 'pending'"],
        'attempts' => ['sql' => "smallint(5) unsigned NOT NULL default '0'"],
        'nextAttemptAt' => ['sql' => "int(10) unsigned NOT NULL default '0'"],
        'lastAttemptAt' => ['sql' => "int(10) unsigned NOT NULL default '0'"],
        'responseCode' => ['sql' => "smallint(5) unsigned NOT NULL default '0'"],
        'error' => ['sql' => "varchar(255) NOT NULL default ''"],
        'createdAt' => ['sql' => "int(10) unsigned NOT NULL default '0'"],
    ],
];
