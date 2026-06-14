<?php

require_once __DIR__ . '/lib.php';

$settings = app_settings();
$academicYear = trim(arr_get($_GET, 'academic_year', $settings['academic_year']));
$term = trim(arr_get($_GET, 'term', $settings['term']));
$date = arr_get($_GET, 'date', date('Y-m-d'));
$dateMode = arr_get($_GET, 'date_mode', $date === 'all' ? 'all' : 'date') === 'all' ? 'all' : 'date';
if ($dateMode === 'all') {
    $date = 'all';
}
$dateLabel = $dateMode === 'all' ? 'ทุกวันที่' : thai_date($date);

$attendanceWhere = 'WHERE 1=1';
$types = '';
$params = [];
if ($academicYear !== '') {
    $attendanceWhere .= ' AND a.academic_year = ?';
    $types .= 's';
    $params[] = $academicYear;
}
if ($term !== '') {
    $attendanceWhere .= ' AND a.term = ?';
    $types .= 's';
    $params[] = $term;
}
if ($dateMode !== 'all') {
    $attendanceWhere .= ' AND a.attendance_date = ?';
    $types .= 's';
    $params[] = $date;
}

$levelSql = "SUBSTRING_INDEX(SUBSTRING_INDEX(c.class_name, '.', 2), '.', -1)";
$levelSummary = all_rows(
    'SELECT ' . $levelSql . ' AS level_no,
            COUNT(c.id) AS class_total,
            SUM(COALESCE(sc.active_students, 0)) AS active_students,
            SUM(CASE WHEN COALESCE(att.check_total, 0) > 0 THEN 1 ELSE 0 END) AS checked_classes,
            SUM(COALESCE(att.check_total, 0)) AS check_total,
            SUM(COALESCE(att.checked_days, 0)) AS checked_days,
            SUM(COALESCE(att.recorded_students, 0)) AS recorded_students,
            SUM(COALESCE(att.present_total, 0)) AS present_total,
            SUM(COALESCE(att.absent_total, 0)) AS absent_total,
            SUM(COALESCE(att.leave_total, 0)) AS leave_total,
            SUM(COALESCE(att.late_total, 0)) AS late_total,
            SUM(COALESCE(att.activity_total, 0)) AS activity_total,
            MIN(att.first_checked) AS first_checked,
            MAX(att.latest_checked) AS latest_checked,
            MAX(att.latest_updated) AS latest_updated
     FROM classes c
     LEFT JOIN (
        SELECT class_id, COUNT(*) AS active_students
        FROM students
        WHERE active = 1
        GROUP BY class_id
     ) sc ON sc.class_id = c.id
     LEFT JOIN (
        SELECT a.class_id,
                COUNT(DISTINCT a.id) AS check_total,
                COUNT(DISTINCT a.attendance_date) AS checked_days,
                COUNT(ai.id) AS recorded_students,
                SUM(ai.status = "present") AS present_total,
                SUM(ai.status = "absent") AS absent_total,
                SUM(ai.status = "leave") AS leave_total,
                SUM(ai.status = "late") AS late_total,
                SUM(ai.status = "activity") AS activity_total,
                MIN(a.attendance_date) AS first_checked,
                MAX(a.attendance_date) AS latest_checked,
                MAX(a.updated_at) AS latest_updated
         FROM attendance a
         LEFT JOIN attendance_items ai ON ai.attendance_id = a.id
         ' . $attendanceWhere . '
         GROUP BY a.class_id
     ) att ON att.class_id = c.id
     WHERE c.active = 1
     GROUP BY level_no
     ORDER BY CAST(level_no AS UNSIGNED)',
    $types,
    $params
);

$totals = [
    'class_total' => 0,
    'active_students' => 0,
    'checked_classes' => 0,
    'check_total' => 0,
    'checked_days' => 0,
    'recorded_students' => 0,
    'present_total' => 0,
    'absent_total' => 0,
    'leave_total' => 0,
    'late_total' => 0,
    'activity_total' => 0,
];
$latestChecked = '';
$latestUpdated = '';
foreach ($levelSummary as $row) {
    foreach ($totals as $key => $value) {
        $totals[$key] += (int)arr_get($row, $key, 0);
    }
    if (!empty($row['latest_checked']) && $row['latest_checked'] > $latestChecked) {
        $latestChecked = $row['latest_checked'];
    }
    if (!empty($row['latest_updated']) && $row['latest_updated'] > $latestUpdated) {
        $latestUpdated = $row['latest_updated'];
    }
}

function pct($part, $total)
{
    $total = (int)$total;
    if ($total <= 0) {
        return 0;
    }
    return round(((int)$part / $total) * 100, 1);
}

$coveragePct = pct($totals['checked_classes'], $totals['class_total']);
$presentPct = pct($totals['present_total'], $totals['recorded_students']);
$absentPct = pct($totals['absent_total'], $totals['recorded_students']);

layout_header('Dashboard สรุปการเช็คชื่อ');
?>
<section class="hero dashboard-hero">
    <div>
        <p class="eyebrow"><?= e($settings['school_name']) ?></p>
        <h1>Dashboard สรุปการเช็คชื่อทั้งโรงเรียน</h1>
        <p>ปีการศึกษา <?= e($academicYear) ?> ภาคเรียนที่ <?= e($term) ?> วันที่ <?= e($dateLabel) ?><?= $latestChecked ? ' ข้อมูลล่าสุด ' . e(thai_date($latestChecked)) : '' ?></p>
    </div>
    <form class="filter" method="get">
        <label>ปีการศึกษา <input name="academic_year" value="<?= e($academicYear) ?>"></label>
        <label>ภาคเรียน <input name="term" value="<?= e($term) ?>"></label>
        <label>แสดงข้อมูล
            <select name="date_mode">
                <option value="date"<?= $dateMode === 'date' ? ' selected' : '' ?>>เลือกวันที่</option>
                <option value="all"<?= $dateMode === 'all' ? ' selected' : '' ?>>ทั้งหมด</option>
            </select>
        </label>
        <label>วันที่ <input type="date" name="date" value="<?= e($dateMode === 'all' ? date('Y-m-d') : $date) ?>"></label>
        <button>อัปเดต</button>
    </form>
</section>

<section class="dashboard-grid">
    <div class="metric-card primary-metric">
        <span>ความครอบคลุมห้องเรียน</span>
        <strong><?= e((string)$coveragePct) ?>%</strong>
        <div class="progress"><i style="width: <?= e((string)$coveragePct) ?>%"></i></div>
        <small><?= (int)$totals['checked_classes'] ?> จาก <?= (int)$totals['class_total'] ?> ห้องมีรายการเช็คชื่อ</small>
    </div>
    <div class="metric-card">
        <span>นักเรียนทั้งหมด</span>
        <strong><?= number_format((int)$totals['active_students']) ?></strong>
        <small>จากห้อง active ทั้งหมด</small>
    </div>
    <div class="metric-card">
        <span>จำนวนครั้งที่เช็ค</span>
        <strong><?= number_format((int)$totals['check_total']) ?></strong>
        <small><?= number_format((int)$totals['recorded_students']) ?> รายการนักเรียนที่บันทึก</small>
    </div>
    <div class="metric-card">
        <span>อัตรามาเรียน</span>
        <strong><?= e((string)$presentPct) ?>%</strong>
        <small>ขาด <?= e((string)$absentPct) ?>%</small>
    </div>
</section>

<section class="panel">
    <h2>สรุปตามระดับชั้น</h2>
    <div class="table-wrap">
        <table>
            <thead>
            <tr>
                <th>ระดับชั้น</th>
                <th>ห้องทั้งหมด</th>
                <th>ห้องที่เช็คแล้ว</th>
                <th>นักเรียน</th>
                <th>ครั้งที่เช็ค</th>
                <th>วันที่เช็ค</th>
                <th>บันทึกนักเรียน</th>
                <th>มา</th>
                <th>ขาด</th>
                <th>ลา</th>
                <th>สาย</th>
                <th>กิจกรรม</th>
                <th>อัตรามา</th>
                <th>อัปเดตล่าสุด</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($levelSummary as $row): ?>
                <tr>
                    <td><strong>ม.<?= e($row['level_no']) ?></strong></td>
                    <td><?= (int)$row['class_total'] ?></td>
                    <td><?= (int)$row['checked_classes'] ?></td>
                    <td><?= number_format((int)$row['active_students']) ?></td>
                    <td><?= number_format((int)$row['check_total']) ?></td>
                    <td><?= number_format((int)$row['checked_days']) ?></td>
                    <td><?= number_format((int)$row['recorded_students']) ?></td>
                    <td class="td-present"><?= number_format((int)$row['present_total']) ?></td>
                    <td class="td-absent"><?= number_format((int)$row['absent_total']) ?></td>
                    <td class="td-leave"><?= number_format((int)$row['leave_total']) ?></td>
                    <td class="td-late"><?= number_format((int)$row['late_total']) ?></td>
                    <td class="td-activity"><?= number_format((int)$row['activity_total']) ?></td>
                    <td><?= e((string)pct($row['present_total'], $row['recorded_students'])) ?>%</td>
                    <td><?= e(thai_datetime($row['latest_updated'])) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$levelSummary): ?><tr><td colspan="14">ไม่พบข้อมูลระดับชั้น</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<?php layout_footer(); ?>
