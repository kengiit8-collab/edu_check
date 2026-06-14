<?php

require_once __DIR__ . '/config.php';

ini_set('default_charset', 'UTF-8');
if (function_exists('mb_internal_encoding')) {
    mb_internal_encoding('UTF-8');
}
if (!headers_sent()) {
    header('Content-Type: text/html; charset=UTF-8');
}
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function db()
{
    static $mysqli = null;
    if ($mysqli instanceof mysqli) {
        return $mysqli;
    }

    if (function_exists('mysqli_report')) {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    }
    $mysqli = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    $mysqli->set_charset('utf8mb4');
    return $mysqli;
}

function ensure_attendance_room_note_column()
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;
    $row = one_row("SHOW COLUMNS FROM attendance LIKE 'room_note'");
    if (!$row) {
        db()->query('ALTER TABLE attendance ADD COLUMN room_note TEXT NULL AFTER term');
    }
}

function e($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function redirect($to)
{
    header('Location: ' . $to);
    exit;
}

function current_user()
{
    return isset($_SESSION['user']) ? $_SESSION['user'] : null;
}

function require_login()
{
    $user = current_user();
    if (!$user) {
        redirect('login.php');
    }
    return $user;
}

function require_staff_login()
{
    $user = require_login();
    $role = arr_get($user, 'role', '');
    if ($role !== 'admin' && $role !== 'teacher') {
        redirect('student_attendance.php');
    }
    return $user;
}

function require_student_login()
{
    $user = require_login();
    if (arr_get($user, 'role', '') !== 'student') {
        redirect('index.php');
    }
    return $user;
}

function require_admin()
{
    $user = require_login();
    if ((isset($user['role']) ? $user['role'] : '') !== 'admin') {
        http_response_code(403);
        exit('ไม่มีสิทธิ์เข้าถึงหน้านี้');
    }
    return $user;
}

function setting($key, $fallback = null)
{
    $stmt = db()->prepare('SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1');
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return isset($row['setting_value']) ? $row['setting_value'] : $fallback;
}

function set_setting($key, $value)
{
    $stmt = db()->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
    $stmt->bind_param('ss', $key, $value);
    $stmt->execute();
}

function app_settings()
{
    return [
        'school_name' => setting('school_name', 'โรงเรียนตัวอย่าง'),
        'academic_year' => setting('academic_year', '2569'),
        'term' => setting('term', '1'),
        'logo_path' => setting('logo_path', 'img/logo.png'),
    ];
}

function options_html($rows, $valueKey, $labelKey, $selected = null)
{
    $html = '';
    foreach ($rows as $row) {
        $value = (string)$row[$valueKey];
        $isSelected = $selected !== null && $selected === $value ? ' selected' : '';
        $html .= '<option value="' . e($value) . '"' . $isSelected . '>' . e((string)$row[$labelKey]) . '</option>';
    }
    return $html;
}

function all_rows($sql, $types = '', $params = [])
{
    $stmt = db()->prepare($sql);
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

function one_row($sql, $types = '', $params = [])
{
    $rows = all_rows($sql, $types, $params);
    return isset($rows[0]) ? $rows[0] : null;
}

function xlsx_cell_column_index($cellRef)
{
    $letters = preg_replace('/[^A-Z]/', '', strtoupper((string)$cellRef));
    $index = 0;
    for ($i = 0; $i < strlen($letters); $i++) {
        $index = ($index * 26) + (ord($letters[$i]) - 64);
    }
    return max(0, $index - 1);
}

function xlsx_shared_string_text($node)
{
    $parts = [];
    $texts = $node->xpath('.//*[local-name()="t"]');
    if ($texts) {
        foreach ($texts as $textNode) {
            $parts[] = (string)$textNode;
        }
    }
    return implode('', $parts);
}

function read_xlsx_rows($filePath)
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('ระบบ PHP ยังไม่เปิดใช้งาน ZipArchive จึงอ่านไฟล์ .xlsx ไม่ได้');
    }

    $zip = new ZipArchive();
    if ($zip->open($filePath) !== true) {
        throw new RuntimeException('ไม่สามารถเปิดไฟล์ Excel ได้');
    }

    $sharedStrings = [];
    $sharedXmlText = $zip->getFromName('xl/sharedStrings.xml');
    if ($sharedXmlText !== false) {
        $sharedXml = simplexml_load_string($sharedXmlText);
        if ($sharedXml) {
            foreach ($sharedXml->xpath('//*[local-name()="si"]') as $si) {
                $sharedStrings[] = xlsx_shared_string_text($si);
            }
        }
    }

    $sheetPath = 'xl/worksheets/sheet1.xml';
    $workbookText = $zip->getFromName('xl/workbook.xml');
    $relsText = $zip->getFromName('xl/_rels/workbook.xml.rels');
    if ($workbookText !== false && $relsText !== false) {
        $workbookXml = simplexml_load_string($workbookText);
        $relsXml = simplexml_load_string($relsText);
        if ($workbookXml && $relsXml) {
            $sheetNodes = $workbookXml->xpath('//*[local-name()="sheet"]');
            $relId = $sheetNodes && isset($sheetNodes[0]['id']) ? (string)$sheetNodes[0]['id'] : '';
            foreach ($relsXml->xpath('//*[local-name()="Relationship"]') as $rel) {
                if ((string)$rel['Id'] === $relId && (string)$rel['Target'] !== '') {
                    $target = ltrim((string)$rel['Target'], '/');
                    $sheetPath = strpos($target, 'xl/') === 0 ? $target : 'xl/' . $target;
                    break;
                }
            }
        }
    }

    $sheetText = $zip->getFromName($sheetPath);
    $zip->close();
    if ($sheetText === false) {
        throw new RuntimeException('ไม่พบ sheet แรกในไฟล์ Excel');
    }

    $sheetXml = simplexml_load_string($sheetText);
    if (!$sheetXml) {
        throw new RuntimeException('อ่านข้อมูลใน sheet ไม่สำเร็จ');
    }

    $rows = [];
    foreach ($sheetXml->xpath('//*[local-name()="sheetData"]/*[local-name()="row"]') as $rowNode) {
        $row = [];
        foreach ($rowNode->xpath('./*[local-name()="c"]') as $cell) {
            $cellRef = isset($cell['r']) ? (string)$cell['r'] : '';
            $colIndex = xlsx_cell_column_index($cellRef);
            $type = isset($cell['t']) ? (string)$cell['t'] : '';
            $valueNode = $cell->xpath('./*[local-name()="v"]');
            $inlineNode = $cell->xpath('./*[local-name()="is"]');
            $value = '';
            if ($type === 's' && $valueNode) {
                $sharedIndex = (int)$valueNode[0];
                $value = isset($sharedStrings[$sharedIndex]) ? $sharedStrings[$sharedIndex] : '';
            } elseif ($type === 'inlineStr' && $inlineNode) {
                $value = xlsx_shared_string_text($inlineNode[0]);
            } elseif ($valueNode) {
                $value = (string)$valueNode[0];
            }
            $row[$colIndex] = trim($value);
        }
        if ($row) {
            ksort($row);
            $maxIndex = max(array_keys($row));
            $normalized = [];
            for ($i = 0; $i <= $maxIndex; $i++) {
                $normalized[] = isset($row[$i]) ? $row[$i] : '';
            }
            if (implode('', $normalized) !== '') {
                $rows[] = $normalized;
            }
        }
    }
    return $rows;
}

function attendance_statuses()
{
    return $GLOBALS['ATTENDANCE_STATUSES'];
}

function normalize_class_name($className)
{
    return str_replace('/', '.', trim((string)$className));
}

function class_sort_sql($classAlias = 'c')
{
    $classNameSql = 'REPLACE(' . $classAlias . '.class_name, "/", ".")';
    return 'SUBSTRING_INDEX(' . $classNameSql . ', ".", 1), '
        . 'CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(' . $classNameSql . ', ".", 2), ".", -1) AS UNSIGNED), '
        . 'CAST(SUBSTRING_INDEX(' . $classNameSql . ', ".", -1) AS UNSIGNED), '
        . $classAlias . '.class_name';
}

function homeroom_teacher_sql($classAlias = 'c', $fallbackTeacherAlias = 't')
{
    return 'COALESCE((
        SELECT GROUP_CONCAT(t2.name_th ORDER BY cht2.sort_order SEPARATOR ", ")
        FROM class_homeroom_teachers cht2
        JOIN teachers t2 ON t2.id = cht2.teacher_id
        WHERE cht2.class_id = ' . $classAlias . '.id
    ), ' . $fallbackTeacherAlias . '.name_th)';
}

function weekday_name($weekday)
{
    $names = ['', 'จันทร์', 'อังคาร', 'พุธ', 'พฤหัสฯ', 'ศุกร์', 'เสาร์', 'อาทิตย์'];
    return isset($names[(int)$weekday]) ? $names[(int)$weekday] : '';
}

function thai_month_name($month)
{
    $months = [
        1 => 'มกราคม',
        2 => 'กุมภาพันธ์',
        3 => 'มีนาคม',
        4 => 'เมษายน',
        5 => 'พฤษภาคม',
        6 => 'มิถุนายน',
        7 => 'กรกฎาคม',
        8 => 'สิงหาคม',
        9 => 'กันยายน',
        10 => 'ตุลาคม',
        11 => 'พฤศจิกายน',
        12 => 'ธันวาคม',
    ];
    return isset($months[(int)$month]) ? $months[(int)$month] : '';
}

function thai_date($date, $fallback = '-')
{
    if (!$date || $date === '0000-00-00') {
        return $fallback;
    }
    $timestamp = strtotime((string)$date);
    if ($timestamp === false) {
        return $fallback;
    }
    return (int)date('j', $timestamp) . ' ' . thai_month_name((int)date('n', $timestamp)) . ' ' . ((int)date('Y', $timestamp) + 543);
}

function thai_datetime($datetime, $fallback = '-')
{
    if (!$datetime || $datetime === '0000-00-00 00:00:00') {
        return $fallback;
    }
    $timestamp = strtotime((string)$datetime);
    if ($timestamp === false) {
        return $fallback;
    }
    return thai_date(date('Y-m-d', $timestamp), $fallback) . ' ' . date('H:i', $timestamp) . ' น.';
}

function thai_short_date($date, $fallback = '-')
{
    if (!$date || $date === '0000-00-00') {
        return $fallback;
    }
    $timestamp = strtotime((string)$date);
    if ($timestamp === false) {
        return $fallback;
    }
    return date('d/m', $timestamp) . '/' . substr((string)((int)date('Y', $timestamp) + 543), -2);
}

function arr_get($array, $key, $fallback = null)
{
    return isset($array[$key]) ? $array[$key] : $fallback;
}

function layout_header($title)
{
    $settings = app_settings();
    $user = current_user();
    $userContext = '';
    if ($user) {
        if (arr_get($user, 'role') === 'teacher' && (int)arr_get($user, 'class_id', 0) > 0) {
            $homeroomTeacherSql = homeroom_teacher_sql('c', 't');
            $class = one_row(
                'SELECT c.class_name, ' . $homeroomTeacherSql . ' AS teacher_name
                 FROM classes c
                 LEFT JOIN teachers t ON t.id = c.homeroom_teacher_id
                 WHERE c.id = ? LIMIT 1',
                'i',
                [(int)$user['class_id']]
            );
            if ($class) {
                $userContext = 'ครูประจำชั้น ' . $class['class_name'] . ' : ' . $class['teacher_name'];
            } else {
                $userContext = 'ครูประจำชั้น';
            }
        } elseif (arr_get($user, 'role') === 'admin') {
            $userContext = 'ผู้ดูแลระบบ : ' . arr_get($user, 'username', 'admin');
        } elseif (arr_get($user, 'role') === 'student') {
            $studentName = arr_get($user, 'student_name', arr_get($user, 'username', 'นักเรียน'));
            $className = arr_get($user, 'class_name', '');
            $userContext = 'นักเรียน : ' . $studentName . ($className ? ' ' . $className : '');
        }
    }
    ?>
    <!doctype html>
    <html lang="th">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title><?= e($title) ?> - <?= e(APP_NAME) ?></title>
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=TH+Sarabun+New:wght@400;700&family=Sarabun:wght@300;400;500;600;700&display=swap" rel="stylesheet">
        <link rel="stylesheet" href="assets/style.css">
    </head>
    <body>
    <header class="topbar">
        <a class="brand" href="index.php">
            <img class="brand-logo" src="<?= e($settings['logo_path']) ?>" alt="<?= e($settings['school_name']) ?>">
            <span class="brand-text">
                <span><?= e(APP_NAME) ?></span>
                <small><?= e($settings['school_name']) ?> ปี <?= e($settings['academic_year']) ?>/<?= e($settings['term']) ?></small>
            </span>
        </a>
        <?php if ($userContext): ?>
            <div class="user-context"><?= e($userContext) ?></div>
        <?php endif; ?>
        <?php $cp = basename($_SERVER['PHP_SELF']); ?>
        <?php $adminPage = arr_get($_GET, 'page', 'check'); ?>
        <?php $settingPages = ['reports', 'settings', 'students', 'classes', 'teachers', 'import_teachers', 'subjects', 'schedules', 'users', 'import_students']; ?>
        <nav>
            <a href="index.php"<?= $cp==='index.php'?' class="active"':'' ?>>หน้าหลัก</a>
            <?php if ($user && arr_get($user, 'role') === 'student'): ?>
                <a href="check_summary.php"<?= $cp==='check_summary.php'?' class="active"':'' ?>>สรุปเช็คชื่อ</a>
                <a href="student_attendance.php"<?= $cp==='student_attendance.php'?' class="active"':'' ?>>การมาเรียนของฉัน</a>
                <a href="logout.php">ออกจากระบบ</a>
            <?php elseif ($user): ?>
                <a href="admin.php"<?= $cp==='admin.php' && !in_array($adminPage, $settingPages, true) ? ' class="active"' : '' ?>>เช็คชื่อ</a>
                <a href="check_summary.php"<?= $cp==='check_summary.php'?' class="active"':'' ?>>สรุปเช็คชื่อ</a>
                <a href="homeroom_sheet.php"<?= $cp==='homeroom_sheet.php'?' class="active"':'' ?>>รายงานตามห้องเรียน</a>
                <?php if (arr_get($user, 'role') === 'admin'): ?>
                    <a href="admin.php?page=settings"<?= $cp==='admin.php' && in_array($adminPage, $settingPages, true) ? ' class="active"' : '' ?>>ตั้งค่า</a>
                <?php endif; ?>
                <?php if (in_array(arr_get($user, 'role'), ['teacher', 'admin'], true)): ?>
                    <a href="change_password.php"<?= $cp==='change_password.php'?' class="active"':'' ?>>เปลี่ยนรหัสผ่าน</a>
                <?php endif; ?>
                <a href="logout.php">ออกจากระบบ</a>
            <?php else: ?>
                <a href="check_summary.php"<?= $cp==='check_summary.php'?' class="active"':'' ?>>สรุปเช็คชื่อ</a>
                <a href="login.php?type=student"<?= $cp==='login.php' && arr_get($_GET, 'type') === 'student' ? ' class="active"' : '' ?>>นักเรียน/ผู้ปกครอง</a>
                <a href="login.php?type=teacher"<?= $cp==='login.php' && !in_array(arr_get($_GET, 'type'), ['admin', 'student'], true) ? ' class="active"' : '' ?>>ครูประจำชั้น</a>
                <a href="login.php?type=admin"<?= $cp==='login.php' && arr_get($_GET, 'type') === 'admin' ? ' class="active"' : '' ?>>ผู้ดูแลระบบ</a>
            <?php endif; ?>
        </nav>
    </header>
    <main class="page">
    <?php
}

function layout_footer()
{
    $settings = app_settings();
    ?>
    </main>
    <footer class="site-footer">
        <div>&copy; <?= e($settings['academic_year']) ?> <?= e($settings['school_name']) ?> - <?= e(APP_NAME) ?></div>
        <div>Developed by <a href="https://krukengblog.com/about-us/2775/" target="_blank" rel="noopener">Somkiat Sacon</a></div>
    </footer>
    </body>
    </html>
    <?php
}

function flash($message = null)
{
    if ($message !== null) {
        $_SESSION['flash'] = $message;
        return null;
    }
    $message = isset($_SESSION['flash']) ? $_SESSION['flash'] : null;
    unset($_SESSION['flash']);
    return $message;
}

function flash_html()
{
    $message = flash();
    if ($message) {
        echo '<div class="notice">' . e($message) . '</div>';
    }
}

