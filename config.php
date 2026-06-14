<?php

date_default_timezone_set('Asia/Bangkok');

define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', 'root');
define('DB_NAME', 'edu_check');
define('APP_NAME', 'ระบบเช็คชื่อนักเรียนออนไลน์');

$GLOBALS['ATTENDANCE_STATUSES'] = [
    'present' => 'มา',
    'absent' => 'ขาด',
    'leave' => 'ลา',
    'late' => 'มาสาย',
    'activity' => 'กิจกรรม',
];
