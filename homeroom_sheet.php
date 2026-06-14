<?php

require_once __DIR__ . '/lib.php';

$user = require_staff_login();
$isAdmin = arr_get($user, 'role') === 'admin';
$settings = app_settings();
$homeroomTeacherSql = homeroom_teacher_sql('c', 't');
$classSortSql = class_sort_sql('c');
$classWhere = 'WHERE c.active = 1';
$classTypes = '';
$classParams = [];
$userClassId = (int)arr_get($user, 'class_id', 0);
$userClass = $userClassId > 0 ? one_row('SELECT id, class_name FROM classes WHERE id = ? AND active = 1 LIMIT 1', 'i', [$userClassId]) : null;
$userLevel = '';
if ($userClass) {
    $parts = explode('.', (string)$userClass['class_name']);
    $userLevel = isset($parts[1]) ? preg_replace('/[^0-9]/', '', $parts[1]) : '';
}
if (!$isAdmin && (int)arr_get($user, 'class_id', 0) > 0) {
    $classWhere .= ' AND SUBSTRING_INDEX(SUBSTRING_INDEX(c.class_name, ".", 2), ".", -1) = ?';
    $classTypes = 's';
    $classParams[] = $userLevel;
}
$classes = all_rows('SELECT c.*, ' . $homeroomTeacherSql . ' AS teacher_name FROM classes c LEFT JOIN teachers t ON t.id = c.homeroom_teacher_id ' . $classWhere . ' ORDER BY ' . $classSortSql, $classTypes, $classParams);
$defaultClass = one_row('SELECT id FROM classes WHERE class_name = ? AND active = 1 LIMIT 1', 's', ['ม.3.10']);
$defaultClassId = $isAdmin ? (isset($defaultClass['id']) ? (int)$defaultClass['id'] : (isset($classes[0]['id']) ? (int)$classes[0]['id'] : 0)) : $userClassId;
$classId = (int)arr_get($_GET, 'class_id', $defaultClassId);
$allowedClassIds = [];
foreach ($classes as $classRow) {
    $allowedClassIds[] = (int)$classRow['id'];
}
if (!in_array($classId, $allowedClassIds, true)) {
    $classId = $defaultClassId > 0 ? $defaultClassId : (isset($allowedClassIds[0]) ? (int)$allowedClassIds[0] : 0);
}
$academicYear = trim(arr_get($_GET, 'academic_year', $settings['academic_year']));
$term = trim(arr_get($_GET, 'term', $settings['term']));

$selectedClass = $classId ? one_row('SELECT c.*, ' . $homeroomTeacherSql . ' AS teacher_name FROM classes c LEFT JOIN teachers t ON t.id = c.homeroom_teacher_id WHERE c.id = ? LIMIT 1', 'i', [$classId]) : null;
$students = $classId ? all_rows('SELECT * FROM students WHERE class_id = ? AND active = 1 ORDER BY student_no, student_code', 'i', [$classId]) : [];

$sessionWhere = 'WHERE a.class_id = ?';
$sessionTypes = 'i';
$sessionParams = [$classId];
if ($academicYear !== '') {
    $sessionWhere .= ' AND a.academic_year = ?';
    $sessionTypes .= 's';
    $sessionParams[] = $academicYear;
}
if ($term !== '') {
    $sessionWhere .= ' AND a.term = ?';
    $sessionTypes .= 's';
    $sessionParams[] = $term;
}

$sessions = $classId ? all_rows(
    'SELECT a.id, a.attendance_date, a.updated_at, sub.subject_code, sub.subject_name, t.name_th AS teacher_name
     FROM attendance a
     LEFT JOIN subjects sub ON sub.id = a.subject_id
     LEFT JOIN teachers t ON t.id = a.teacher_id
     ' . $sessionWhere . '
     ORDER BY a.attendance_date ASC, a.id ASC',
    $sessionTypes,
    $sessionParams
) : [];

$items = [];
if ($sessions) {
    $ids = [];
    foreach ($sessions as $session) {
        $ids[] = (int)$session['id'];
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));
    $rows = all_rows(
        'SELECT attendance_id, student_id, status, note FROM attendance_items WHERE attendance_id IN (' . $placeholders . ')',
        $types,
        $ids
    );
    foreach ($rows as $row) {
        $items[(int)$row['student_id']][(int)$row['attendance_id']] = $row;
    }
}

$statusShort = [
    'present' => 'ม',
    'absent' => 'ข',
    'leave' => 'ล',
    'late' => 'ส',
    'activity' => 'ก',
];
$statusClass = [
    'present' => 'status-present',
    'absent' => 'status-absent',
    'leave' => 'status-leave',
    'late' => 'status-late',
    'activity' => 'status-activity',
];

$totals = [];
foreach ($sessions as $session) {
    $totals[(int)$session['id']] = ['present' => 0, 'absent' => 0, 'leave' => 0, 'late' => 0, 'activity' => 0, 'blank' => 0];
}
foreach ($students as $student) {
    foreach ($sessions as $session) {
        $sid = (int)$student['id'];
        $aid = (int)$session['id'];
        if (isset($items[$sid][$aid])) {
            $status = $items[$sid][$aid]['status'];
            if (isset($totals[$aid][$status])) {
                $totals[$aid][$status]++;
            }
        } else {
            $totals[$aid]['blank']++;
        }
    }
}

$detailPerPage = 10;
$detailTotal = count($sessions);
$detailTotalPages = max(1, (int)ceil($detailTotal / $detailPerPage));
$detailPage = max(1, (int)arr_get($_GET, 'detail_page', 1));
if ($detailPage > $detailTotalPages) {
    $detailPage = $detailTotalPages;
}
$detailOffset = ($detailPage - 1) * $detailPerPage;
$detailSessions = array_slice($sessions, $detailOffset, $detailPerPage);
$exportQuery = [
    'class_id' => $classId,
    'academic_year' => $academicYear,
    'term' => $term,
    'export' => 'excel',
];
$detailQuery = $_GET;
$detailQuery['class_id'] = $classId;
$detailQuery['academic_year'] = $academicYear;
$detailQuery['term'] = $term;
function detail_page_url($page, $query)
{
    $query['detail_page'] = $page;
    return 'homeroom_sheet.php?' . http_build_query($query);
}

function export_homeroom_sheet_excel($settings, $selectedClass, $academicYear, $term, $students, $sessions, $items, $totals, $statusShort)
{
    $className = isset($selectedClass['class_name']) ? (string)$selectedClass['class_name'] : 'class';
    $safeClassName = preg_replace('/[^A-Za-z0-9ก-๙._-]+/u', '_', $className);
    $fileName = 'homeroom_sheet_' . $safeClassName . '_' . preg_replace('/[^A-Za-z0-9_-]+/', '', (string)$academicYear) . '_' . preg_replace('/[^A-Za-z0-9_-]+/', '', (string)$term) . '.xls';

    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="homeroom_sheet.xls"; filename*=UTF-8\'\'' . rawurlencode($fileName));
    header('Cache-Control: max-age=0');

    echo "\xEF\xBB\xBF";
    ?>
    <!doctype html>
    <html>
    <head>
        <meta charset="utf-8">
        <style>
            table { border-collapse: collapse; }
            th, td { border: 1px solid #999; padding: 4px 6px; mso-number-format: "\@"; }
            th { background: #e8eefc; font-weight: bold; text-align: center; }
            .center { text-align: center; }
            .title { font-size: 16px; font-weight: bold; }
        </style>
    </head>
    <body>
        <table>
            <tr><td colspan="<?= 6 + count($sessions) ?>" class="title"><?= e($settings['school_name']) ?></td></tr>
            <tr><td colspan="<?= 6 + count($sessions) ?>" class="title">เช็คชื่อประจำชั้น <?= e($className) ?> ปี <?= e($academicYear) ?>/<?= e($term) ?></td></tr>
            <tr><td colspan="<?= 6 + count($sessions) ?>">ครูประจำชั้น: <?= e(isset($selectedClass['teacher_name']) ? $selectedClass['teacher_name'] : '-') ?></td></tr>
            <tr><td colspan="<?= 6 + count($sessions) ?>">ม=มา, ข=ขาด, ล=ลา, ส=สาย, ก=กิจกรรม</td></tr>
        </table>
        <br>
        <table>
            <thead>
            <tr>
                <th>เลขที่</th>
                <th>ชื่อ - สกุล</th>
                <?php $n = 1; foreach ($sessions as $session): ?>
                    <th><?= e(thai_short_date($session['attendance_date'])) ?><br><?= $n++ ?></th>
                <?php endforeach; ?>
                <th>ขาด</th>
                <th>ลา</th>
                <th>สาย</th>
                <th>กิจกรรม</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($students as $student): ?>
                <?php $studentTotals = ['absent' => 0, 'leave' => 0, 'late' => 0, 'activity' => 0]; ?>
                <tr>
                    <td class="center"><?= e((string)$student['student_no']) ?></td>
                    <td><?= e(trim($student['prefix_th'] . ' ' . $student['first_name_th'] . ' ' . $student['last_name_th'])) ?></td>
                    <?php foreach ($sessions as $session): ?>
                        <?php
                        $studentId = (int)$student['id'];
                        $attendanceId = (int)$session['id'];
                        $saved = isset($items[$studentId][$attendanceId]) ? $items[$studentId][$attendanceId] : null;
                        $status = $saved ? $saved['status'] : '';
                        if (isset($studentTotals[$status])) {
                            $studentTotals[$status]++;
                        }
                        $abbr = isset($statusShort[$status]) ? $statusShort[$status] : '';
                        ?>
                        <td class="center"><?= e($abbr) ?></td>
                    <?php endforeach; ?>
                    <td class="center"><?= (int)$studentTotals['absent'] ?></td>
                    <td class="center"><?= (int)$studentTotals['leave'] ?></td>
                    <td class="center"><?= (int)$studentTotals['late'] ?></td>
                    <td class="center"><?= (int)$studentTotals['activity'] ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$students): ?><tr><td colspan="<?= 6 + count($sessions) ?>">ยังไม่มีรายชื่อนักเรียนในห้องนี้</td></tr><?php endif; ?>
            </tbody>
            <?php if ($sessions): ?>
            <tfoot>
                <tr>
                    <th></th>
                    <th>รวมขาด</th>
                    <?php foreach ($sessions as $session): ?><th><?= (int)$totals[(int)$session['id']]['absent'] ?></th><?php endforeach; ?>
                    <th colspan="4"></th>
                </tr>
                <tr>
                    <th></th>
                    <th>รวมลา/สาย/กิจกรรม</th>
                    <?php foreach ($sessions as $session): ?>
                        <?php $totalOther = (int)$totals[(int)$session['id']]['leave'] + (int)$totals[(int)$session['id']]['late'] + (int)$totals[(int)$session['id']]['activity']; ?>
                        <th><?= $totalOther ?></th>
                    <?php endforeach; ?>
                    <th colspan="4"></th>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>
        <br>
        <table>
            <thead><tr><th>ครั้งที่</th><th>วันที่</th><th>วิชา</th><th>ครูผู้เช็ค</th><th>อัปเดตล่าสุด</th></tr></thead>
            <tbody>
            <?php $n = 1; foreach ($sessions as $session): ?>
                <tr>
                    <td class="center"><?= $n++ ?></td>
                    <td><?= e(thai_date($session['attendance_date'])) ?></td>
                    <td><?= e(trim(arr_get($session, 'subject_code', '') . ' ' . arr_get($session, 'subject_name', ''))) ?></td>
                    <td><?= e($session['teacher_name']) ?></td>
                    <td><?= e(thai_datetime($session['updated_at'])) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$sessions): ?><tr><td colspan="5">ไม่พบข้อมูล</td></tr><?php endif; ?>
            </tbody>
        </table>
    </body>
    </html>
    <?php
    exit;
}

if (arr_get($_GET, 'export') === 'excel') {
    export_homeroom_sheet_excel($settings, $selectedClass, $academicYear, $term, $students, $sessions, $items, $totals, $statusShort);
}

layout_header('เช็คชื่อประจำชั้น');
?>
<section class="hero">
    <div>
        <p class="eyebrow"><?= e(isset($selectedClass['class_name']) ? $selectedClass['class_name'] : '') ?> ปี <?= e($academicYear) ?>/<?= e($term) ?></p>
        <h1>เช็คชื่อประจำชั้น</h1>
        <p>วันที่แสดงเป็นหัวคอลัมน์แนวตั้ง และใช้ตัวย่อสถานะ ม=มา, ข=ขาด, ล=ลา, ส=สาย, ก=กิจกรรม</p>
    </div>
    <form class="filter" method="get">
        <label>ปีการศึกษา <input name="academic_year" value="<?= e($academicYear) ?>"></label>
        <label>ภาคเรียน <input name="term" value="<?= e($term) ?>"></label>
        <label>ห้องเรียน
            <select name="class_id"><?= options_html($classes, 'id', 'class_name', (string)$classId) ?></select>
        </label>
        <button>ดูรายงาน</button>
        <a class="button btn-outline" href="homeroom_sheet.php?<?= e(http_build_query($exportQuery)) ?>">ส่งออก Excel</a>
    </form>
</section>

<section class="grid stats">
    <div class="stat"><strong><?= count($students) ?></strong><span>นักเรียน</span></div>
    <div class="stat"><strong><?= count($sessions) ?></strong><span>ครั้งที่เช็ค</span></div>
    <div class="stat"><strong><?= e(isset($selectedClass['room_no']) ? $selectedClass['room_no'] : '-') ?></strong><span>ห้องประจำ</span></div>
    <div class="stat"><strong><?= e($academicYear . '/' . $term) ?></strong><span>ปี/ภาคเรียน</span></div>
    <div class="stat"><strong><?= e(isset($selectedClass['teacher_name']) ? $selectedClass['teacher_name'] : '-') ?></strong><span>ครูประจำชั้น</span></div>
</section>

<section class="panel">
    <h2>ตารางเช็คชื่อประจำชั้น <?= e(isset($selectedClass['class_name']) ? $selectedClass['class_name'] : '') ?></h2>
    <div class="legend">
        <span><b class="status-present">ม</b> มา</span>
        <span><b class="status-absent">ข</b> ขาด</span>
        <span><b class="status-leave">ล</b> ลา</span>
        <span><b class="status-late">ส</b> สาย</span>
        <span><b class="status-activity">ก</b> กิจกรรม</span>
    </div>
    <div class="table-wrap sheet-wrap">
        <table class="attendance-sheet">
            <thead>
            <tr>
                <th class="sticky-col no-col">เลขที่</th>
                <th class="sticky-col name-col">ชื่อ - สกุล</th>
                <?php $n = 1; foreach ($sessions as $session): ?>
                    <th class="date-col">
                        <span><?= e(thai_short_date($session['attendance_date'])) ?></span>
                        <small><?= $n++ ?></small>
                    </th>
                <?php endforeach; ?>
                <th class="sum-col">ขาด</th>
                <th class="sum-col">ลา</th>
                <th class="sum-col">สาย</th>
                <th class="sum-col">กิจกรรม</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($students as $student): ?>
                <?php
                $studentTotals = ['absent' => 0, 'leave' => 0, 'late' => 0, 'activity' => 0];
                ?>
                <tr>
                    <td class="sticky-col no-col"><?= e((string)$student['student_no']) ?></td>
                    <td class="sticky-col name-col"><?= e(trim($student['prefix_th'] . ' ' . $student['first_name_th'] . ' ' . $student['last_name_th'])) ?></td>
                    <?php foreach ($sessions as $session): ?>
                        <?php
                        $studentId = (int)$student['id'];
                        $attendanceId = (int)$session['id'];
                        $saved = isset($items[$studentId][$attendanceId]) ? $items[$studentId][$attendanceId] : null;
                        $status = $saved ? $saved['status'] : '';
                        if (isset($studentTotals[$status])) {
                            $studentTotals[$status]++;
                        }
                        $abbr = isset($statusShort[$status]) ? $statusShort[$status] : '';
                        $class = isset($statusClass[$status]) ? $statusClass[$status] : '';
                        ?>
                        <td class="mark <?= e($class) ?>" title="<?= e($saved ? arr_get($saved, 'note', '') : '') ?>"><?= e($abbr) ?></td>
                    <?php endforeach; ?>
                    <td class="sum-col td-absent"><?= (int)$studentTotals['absent'] ?></td>
                    <td class="sum-col td-leave"><?= (int)$studentTotals['leave'] ?></td>
                    <td class="sum-col td-late"><?= (int)$studentTotals['late'] ?></td>
                    <td class="sum-col td-activity"><?= (int)$studentTotals['activity'] ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$students): ?><tr><td colspan="<?= 6 + count($sessions) ?>">ยังไม่มีรายชื่อนักเรียนในห้องนี้</td></tr><?php endif; ?>
            </tbody>
            <?php if ($sessions): ?>
            <tfoot>
                <tr>
                    <th class="sticky-col no-col"></th>
                    <th class="sticky-col name-col">รวมขาด</th>
                    <?php foreach ($sessions as $session): ?><th><?= (int)$totals[(int)$session['id']]['absent'] ?></th><?php endforeach; ?>
                    <th colspan="4"></th>
                </tr>
                <tr>
                    <th class="sticky-col no-col"></th>
                    <th class="sticky-col name-col">รวมลา/สาย/กิจกรรม</th>
                    <?php foreach ($sessions as $session): ?>
                        <?php $totalOther = (int)$totals[(int)$session['id']]['leave'] + (int)$totals[(int)$session['id']]['late'] + (int)$totals[(int)$session['id']]['activity']; ?>
                        <th><?= $totalOther ?></th>
                    <?php endforeach; ?>
                    <th colspan="4"></th>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>
    </div>
    <?php if (!$sessions): ?><div class="notice">ยังไม่มีรายการเช็คชื่อของห้องนี้ในปี/ภาคเรียนที่เลือก</div><?php endif; ?>
</section>

<section class="panel">
    <h2>รายละเอียดคอลัมน์วันที่</h2>
    <div class="table-wrap">
        <table>
            <thead><tr><th>ครั้งที่</th><th>วันที่</th><th>วิชา</th><th>ครูผู้เช็ค</th><th>อัปเดตล่าสุด</th></tr></thead>
            <tbody>
            <?php $n = $detailOffset + 1; foreach ($detailSessions as $session): ?>
                <tr>
                    <td><?= $n++ ?></td>
                    <td><?= e(thai_date($session['attendance_date'])) ?></td>
                    <td><?= e(trim(arr_get($session, 'subject_code', '') . ' ' . arr_get($session, 'subject_name', ''))) ?></td>
                    <td><?= e($session['teacher_name']) ?></td>
                    <td><?= e(thai_datetime($session['updated_at'])) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$sessions): ?><tr><td colspan="5">ไม่พบข้อมูล</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($detailTotalPages > 1): ?>
        <nav class="pagination" aria-label="แบ่งหน้ารายละเอียดคอลัมน์วันที่">
            <a class="<?= $detailPage <= 1 ? 'disabled' : '' ?>" href="<?= e(detail_page_url(1, $detailQuery)) ?>">หน้าแรก</a>
            <a class="<?= $detailPage <= 1 ? 'disabled' : '' ?>" href="<?= e(detail_page_url(max(1, $detailPage - 1), $detailQuery)) ?>">ก่อนหน้า</a>
            <span>หน้า <?= (int)$detailPage ?> / <?= (int)$detailTotalPages ?></span>
            <a class="<?= $detailPage >= $detailTotalPages ? 'disabled' : '' ?>" href="<?= e(detail_page_url(min($detailTotalPages, $detailPage + 1), $detailQuery)) ?>">ถัดไป</a>
            <a class="<?= $detailPage >= $detailTotalPages ? 'disabled' : '' ?>" href="<?= e(detail_page_url($detailTotalPages, $detailQuery)) ?>">หน้าสุดท้าย</a>
        </nav>
    <?php endif; ?>
</section>
<?php layout_footer(); ?>
