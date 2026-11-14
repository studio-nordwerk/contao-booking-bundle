<?php

declare(strict_types=1);

use Nordwerk\BookingBundle\Backend\ContentCallbacks;

// Contao 5.7 does not append visibility fields to custom content element palettes.
$GLOBALS['TL_DCA']['tl_content']['palettes']['appointment_booking'] = '{type_legend},type,headline;{booking_legend},bookingType,bookingPrivacyPage,bookingFallback;{template_legend:hide},customTpl;{protected_legend:hide},protected;{expert_legend:hide},cssID;{invisible_legend:hide},invisible,start,stop';

$GLOBALS['TL_DCA']['tl_content']['fields']['bookingType'] = [
    'inputType' => 'select',
    'options_callback' => [ContentCallbacks::class, 'bookingTypes'],
    'eval' => ['mandatory' => true, 'includeBlankOption' => true, 'tl_class' => 'w50'],
    'sql' => "int(10) unsigned NOT NULL default '0'",
    'relation' => ['table' => 'tl_booking_type', 'type' => 'hasOne', 'load' => 'lazy'],
];
$GLOBALS['TL_DCA']['tl_content']['fields']['bookingPrivacyPage'] = [
    'inputType' => 'pageTree',
    'foreignKey' => 'tl_page.title',
    'eval' => ['mandatory' => true, 'fieldType' => 'radio', 'tl_class' => 'w50 clr'],
    'sql' => "int(10) unsigned NOT NULL default '0'",
    'relation' => ['type' => 'hasOne', 'load' => 'lazy'],
];
$GLOBALS['TL_DCA']['tl_content']['fields']['bookingFallback'] = [
    'inputType' => 'text',
    'eval' => ['maxlength' => 255, 'tl_class' => 'clr long'],
    'sql' => "varchar(255) NOT NULL default ''",
];
