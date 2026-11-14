<?php

declare(strict_types=1);

// Back end module "Terminbuchung": booking types with their appointments as
// child records.
$GLOBALS['BE_MOD']['content']['booking'] = ['tables' => ['tl_booking_type', 'tl_booking_appointment']];
