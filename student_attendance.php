<?php

require_once __DIR__ . '/lib.php';

$user = require_student_login();
$settings = app_settings();
$studentId = (int)arr_get($user, 'student_id', 0);
$academicYear = trim(arr_get($_GET, 'academic_year', $settings['academic_year']));
$term = trim(arr_get($_GET, 'term', $settings['term']));

$student = one_row(
    'SELECT s.*, c.class_name, c.room_no
     FROM students s
     JOIN classes c ON c.id = s.class_id
     WHERE s.id = ? AND s.active = 1
     LIMIT 1',
    'i',
    [$studentId]
);

if (!$student) {
    session_destroy();
    redirect('login.php?type=student');
}

$where = 'WHERE a.class_id = ?';
$types = 'i';
$params = [(int)$student['class_id']];
if ($academicYear !== '') {
    $where .= ' AND a.academic_year = ?';
    $types .= 's';
    $params[] = $academicYear;
}
if ($term !== '') {
    $where .= ' AND a.term = ?';
    $types .= 's';
    $params[] = $term;
}

$rows = all_rows(
    'SELECT a.id,
            a.attendance_date,
            a.updated_at,
            sub.subject_code,
            sub.subject_name,
            ai.status,
            ai.note
     FROM attendance a
     LEFT JOIN attendance_items ai ON ai.attendance_id = a.id AND ai.student_id = ?
     LEFT JOIN subjects sub ON sub.id = a.subject_id
     ' . $where . '
     ORDER BY a.attendance_date DESC, a.id DESC',
    'i' . $types,
    array_merge([$studentId], $params)
);

$statusLabels = [
    'present' => 'มา',
    'absent' => 'ขาด',
    'leave' => 'ลา',
    'late' => 'มาสาย',
    'activity' => 'กิจกรรม',
    '' => 'ยังไม่บันทึก',
];
$statusClasses = [
    'present' => 'status-present',
    'absent' => 'status-absent',
    'leave' => 'status-leave',
    'late' => 'status-late',
    'activity' => 'status-activity',
    '' => '',
];
$totals = ['present' => 0, 'absent' => 0, 'leave' => 0, 'late' => 0, 'activity' => 0, '' => 0];
foreach ($rows as $row) {
    $status = (string)arr_get($row, 'status', '');
    if (!isset($totals[$status])) {
        $status = '';
    }
    $totals[$status]++;
}
$recorded = count($rows) - (int)$totals[''];
$presentPct = $recorded > 0 ? round(((int)$totals['present'] / $recorded) * 100, 1) : 0;
$studentName = trim($student['prefix_th'] . $student['first_name_th'] . ' ' . $student['last_name_th']);

layout_header('การมาเรียนของฉัน');
?>
<section class="hero student-hero">
    <div>
        <p class="eyebrow">รหัสนักเรียน <?= e($student['student_code']) ?></p>
        <h1><?= e($studentName) ?></h1>
        <p><?= e($student['class_name']) ?> ห้องประจำ <?= e($student['room_no'] ?: '-') ?> ปี <?= e($academicYear) ?>/<?= e($term) ?></p>
    </div>
    <form class="filter" method="get">
        <label>ปีการศึกษา <input name="academic_year" value="<?= e($academicYear) ?>"></label>
        <label>ภาคเรียน <input name="term" value="<?= e($term) ?>"></label>
        <button>ดูข้อมูล</button>
    </form>
</section>

<section class="grid stats student-stats">
    <div class="stat"><strong><?= count($rows) ?></strong><span>ครั้งที่มีการเช็คชื่อ</span></div>
    <div class="stat"><strong><?= e((string)$presentPct) ?>%</strong><span>อัตราการมาเรียน</span></div>
    <div class="stat"><strong><?= (int)$totals['present'] ?></strong><span>มา</span></div>
    <div class="stat"><strong><?= (int)$totals['absent'] ?></strong><span>ขาด</span></div>
    <div class="stat"><strong><?= (int)$totals['late'] ?></strong><span>มาสาย</span></div>
</section>

<section class="panel">
    <h2>รายละเอียดการมาเรียน</h2>
    <div class="legend">
        <span><b class="status-present">ม</b> มา</span>
        <span><b class="status-absent">ข</b> ขาด</span>
        <span><b class="status-leave">ล</b> ลา</span>
        <span><b class="status-late">ส</b> มาสาย</span>
        <span><b class="status-activity">ก</b> กิจกรรม</span>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
            <tr>
                <th>วันที่</th>
                <th>รายการ</th>
                <th>สถานะ</th>
                <th>หมายเหตุ</th>
                <th>อัปเดตล่าสุด</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <?php
                $status = (string)arr_get($row, 'status', '');
                if (!isset($statusLabels[$status])) {
                    $status = '';
                }
                $subject = trim(arr_get($row, 'subject_code', '') . ' ' . arr_get($row, 'subject_name', ''));
                ?>
                <tr>
                    <td><?= e(thai_date($row['attendance_date'])) ?></td>
                    <td><?= e($subject !== '' ? $subject : 'เช็คชื่อ') ?></td>
                    <td><span class="student-status <?= e($statusClasses[$status]) ?>"><?= e($statusLabels[$status]) ?></span></td>
                    <td><?= e(arr_get($row, 'note', '') ?: '-') ?></td>
                    <td><?= e(thai_datetime($row['updated_at'])) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?><tr><td colspan="5">ยังไม่มีข้อมูลการเช็คชื่อในปี/ภาคเรียนที่เลือก</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
<?php layout_footer(); ?>
