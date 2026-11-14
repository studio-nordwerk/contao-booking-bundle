<?php

declare(strict_types=1);

use Contao\DC_Table;

// Request counters per hashed IP address and time window (rate limit of the
// booking routes).
$GLOBALS['TL_DCA']['tl_booking_rate'] = [
    'config' => [
        'dataContainer' => DC_Table::class,
        'closed' => true,
        'notEditable' => true,
        'sql' => ['keys' => ['id' => 'primary', 'bucket' => 'unique', 'expiresAt' => 'index']],
    ],
    'fields' => [
        'id' => ['sql' => 'int(10) unsigned NOT NULL auto_increment'],
        'tstamp' => ['sql' => "int(10) unsigned NOT NULL default '0'"],
        'bucket' => ['sql' => "char(64) NOT NULL default ''"],
        'hits' => ['sql' => "int(10) unsigned NOT NULL default '0'"],
        'expiresAt' => ['sql' => "int(10) unsigned NOT NULL default '0'"],
    ],
];
