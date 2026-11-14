<?php

declare(strict_types=1);

use Contao\DC_Table;

// Cache of the busy times from calendar subscriptions, with the last error
// per address.
$GLOBALS['TL_DCA']['tl_booking_feed'] = [
    'config' => [
        'dataContainer' => DC_Table::class,
        'closed' => true,
        'notEditable' => true,
        'sql' => ['keys' => ['id' => 'primary', 'pid,urlHash' => 'unique']],
    ],
    'fields' => [
        'id' => ['sql' => 'int(10) unsigned NOT NULL auto_increment'],
        'pid' => ['sql' => "int(10) unsigned NOT NULL default '0'"],
        'tstamp' => ['sql' => "int(10) unsigned NOT NULL default '0'"],
        'url' => ['sql' => 'text NULL'],
        'urlHash' => ['sql' => "char(64) NOT NULL default ''"],
        'checkedAt' => ['sql' => "int(10) unsigned NOT NULL default '0'"],
        'lastSuccessAt' => ['sql' => "int(10) unsigned NOT NULL default '0'"],
        'error' => ['sql' => "varchar(255) NOT NULL default ''"],
        'busy' => ['sql' => 'mediumtext NULL'],
    ],
];
