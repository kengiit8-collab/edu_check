<?php

require_once __DIR__ . '/lib.php';

$settings = app_settings();
$date = arr_get($_GET, 'date', date('Y-m-d'));
$dateMode = arr_get($_GET, 'date_mode', $date === 'all' ? 'all' : 'date') === 'all' ? 'all' : 'date';
if ($dateMode === 'all') {
    $date = 'all';
}
$dateLabel = $dateMode === 'all' ? 'ทุกวันที่' : thai_date($date);
$dateFilterSql = $dateMode === 'all' ? '' : 'WHERE a.attendance_date = ?';
$dateTypes = $dateMode === 'all' ? '' : 's';
$dateParams = $dateMode === 'all' ? [] : [$date];
$homeroomTeacherSql = homeroom_teacher_sql('c', 't');
$classOrderSql = class_sort_sql('c');

$classSummary = all_rows(
    'SELECT c.id, c.class_name, c.room_no, ' . $homeroomTeacherSql . ' AS teacher_name,
            (SELECT COUNT(*) FROM students s WHERE s.class_id = c.id AND s.active = 1) AS student_total,
            COALESCE(day.check_total, 0) AS check_total,
            COALESCE(day.recorded_total, 0) AS recorded_total,
            COALESCE(day.present_total, 0) AS present_total,
            COALESCE(day.absent_total, 0) AS absent_total,
            COALESCE(day.leave_total, 0) AS leave_total,
            COALESCE(day.late_total, 0) AS late_total,
            COALESCE(day.activity_total, 0) AS activity_total,
            day.latest_updated
     FROM classes c
     LEFT JOIN teachers t ON t.id = c.homeroom_teacher_id
     LEFT JOIN (
        SELECT a.class_id,
                COUNT(DISTINCT a.id) AS check_total,
                COUNT(ai.id) AS recorded_total,
                SUM(ai.status = "present") AS present_total,
                SUM(ai.status = "absent") AS absent_total,
                SUM(ai.status = "leave") AS leave_total,
                SUM(ai.status = "late") AS late_total,
                SUM(ai.status = "activity") AS activity_total,
                MAX(a.updated_at) AS latest_updated
         FROM attendance a
         LEFT JOIN attendance_items ai ON ai.attendance_id = a.id
         ' . $dateFilterSql . '
         GROUP BY a.class_id
     ) day ON day.class_id = c.id
     WHERE c.active = 1
     ORDER BY ' . $classOrderSql,
    $dateTypes,
    $dateParams
);

$levels = [];
$totals = [
    'student_total' => 0,
    'check_total' => 0,
    'recorded_total' => 0,
    'present_total' => 0,
    'absent_total' => 0,
    'leave_total' => 0,
    'late_total' => 0,
    'activity_total' => 0,
];

foreach ($classSummary as $row) {
    $parts = explode('.', (string)$row['class_name']);
    $level = isset($parts[1]) && $parts[1] !== '' ? 'ม.' . $parts[1] : 'อื่นๆ';
    if (!isset($levels[$level])) {
        $levels[$level] = [];
    }
    $levels[$level][] = $row;

    foreach ($totals as $key => $value) {
        $totals[$key] += (int)arr_get($row, $key, 0);
    }
}

layout_header('หน้าหลัก');
flash_html();
?>
<section class="hero">
    <div>
        <p class="eyebrow"><?= e($settings['school_name']) ?></p>
        <h1>ข้อมูลการเช็คชื่อออนไลน์ทุกห้อง</h1>
        <p>ปีการศึกษา <?= e($settings['academic_year']) ?> ภาคเรียนที่ <?= e($settings['term']) ?> วันที่ <?= e($dateLabel) ?></p>    </div>
    <form class="filter" method="get">
        <label>แสดงข้อมูล
            <select name="date_mode">
                <option value="date"<?= $dateMode === 'date' ? ' selected' : '' ?>>เลือกวันที่</option>
                <option value="all"<?= $dateMode === 'all' ? ' selected' : '' ?>>ทั้งหมด</option>
            </select>
        </label>
        <label>วันที่ <input type="date" name="date" value="<?= e($dateMode === 'all' ? date('Y-m-d') : $date) ?>"></label>
        <button>ดูข้อมูล</button>
    </form>
</section>

<section class="grid stats">
    <div class="stat"><strong><?= (int)$totals['student_total'] ?></strong><span>นักเรียนทั้งหมด</span></div>
    <div class="stat"><strong><?= (int)$totals['present_total'] ?></strong><span>มา</span></div>
    <div class="stat"><strong><?= (int)$totals['absent_total'] ?></strong><span>ขาด</span></div>
    <div class="stat"><strong><?= (int)$totals['leave_total'] ?></strong><span>ลา</span></div>
    <div class="stat"><strong><?= (int)$totals['late_total'] ?></strong><span>สาย</span></div>
</section>

<section class="panel">
    <h2>สรุปการเช็คชื่อทุกห้อง แยกตามระดับชั้น</h2>
    <?php foreach ($levels as $level => $rows): ?>
        <div class="level-block">
            <h3><?= e($level) ?></h3>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>ห้องเรียน</th>
                            <th>ห้องประจำ</th>
                            <th>ครูประจำชั้น</th>
                            <th>นักเรียน</th>
                            <th>เช็คแล้วกี่ครั้ง</th>
                            <th>บันทึกแล้ว</th>
                            <th>มา</th>
                            <th>ขาด</th>
                            <th>ลา</th>
                            <th>สาย</th>
                            <th>กิจกรรม</th>
                            <th>อัปเดตล่าสุด</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td><?= e($row['class_name']) ?></td>
                            <td><?= e($row['room_no']) ?></td>
                            <td><?= e($row['teacher_name']) ?></td>
                            <td><?= (int)$row['student_total'] ?></td>
                            <td><?= (int)$row['check_total'] ?></td>
                            <td><?= (int)$row['recorded_total'] ?></td>
                            <td class="td-present"><?= (int)$row['present_total'] ?></td>
                            <td class="td-absent"><?= (int)$row['absent_total'] ?></td>
                            <td class="td-leave"><?= (int)$row['leave_total'] ?></td>
                            <td class="td-late"><?= (int)$row['late_total'] ?></td>
                            <td class="td-activity"><?= (int)$row['activity_total'] ?></td>
                            <td><?= e(thai_datetime($row['latest_updated'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endforeach; ?>
</section>

<?php layout_footer(); ?>
