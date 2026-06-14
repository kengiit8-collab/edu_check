<?php

require_once __DIR__ . '/lib.php';

$requestedType = arr_get($_GET, 'type', 'teacher');
$loginType = in_array($requestedType, ['teacher', 'admin', 'student'], true) ? $requestedType : 'teacher';
$pageTitleMap = [
    'teacher' => 'เข้าสู่ระบบครูประจำชั้น',
    'admin' => 'เข้าสู่ระบบผู้ดูแลระบบ',
    'student' => 'ตรวจสอบการมาเรียน',
];
$buttonTextMap = [
    'teacher' => 'เข้าสู่ระบบครู',
    'admin' => 'เข้าสู่ระบบผู้ดูแล',
    'student' => 'ตรวจสอบข้อมูล',
];
$badgeTextMap = [
    'teacher' => 'ครูประจำชั้น',
    'admin' => 'ผู้ดูแลระบบ',
    'student' => 'นักเรียน/ผู้ปกครอง',
];
$pageTitle = $pageTitleMap[$loginType];
$buttonText = $buttonTextMap[$loginType];
$error = '';

if (!isset($_SESSION['login_math']) || !is_array($_SESSION['login_math'])) {
    $_SESSION['login_math'] = ['a' => random_int(1, 9), 'b' => random_int(1, 9)];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedType = arr_get($_POST, 'login_type', 'teacher');
    $loginType = in_array($postedType, ['teacher', 'admin', 'student'], true) ? $postedType : 'teacher';
    $pageTitle = $pageTitleMap[$loginType];
    $buttonText = $buttonTextMap[$loginType];
    $username = trim(arr_get($_POST, 'login_user', arr_get($_POST, 'username', '')));
    $password = (string)arr_get($_POST, 'login_pass', arr_get($_POST, 'password', ''));
    $mathAnswer = (int)arr_get($_POST, 'math_answer', -1);
    $expectedAnswer = (int)$_SESSION['login_math']['a'] + (int)$_SESSION['login_math']['b'];

    if ($mathAnswer !== $expectedAnswer) {
        $error = 'กรุณาตอบคำถามบวกเลขให้ถูกต้อง';
    } else {
        if ($loginType === 'student') {
            $stmt = db()->prepare('SELECT s.*, c.class_name FROM students s JOIN classes c ON c.id = s.class_id WHERE s.student_code = ? AND s.active = 1 LIMIT 1');
            $stmt->bind_param('s', $username);
            $stmt->execute();
            $student = $stmt->get_result()->fetch_assoc();
            if ($student && hash_equals((string)$student['student_code'], $password)) {
                unset($_SESSION['login_math']);
                $studentName = trim($student['prefix_th'] . $student['first_name_th'] . ' ' . $student['last_name_th']);
                $_SESSION['user'] = [
                    'id' => 0,
                    'username' => $student['student_code'],
                    'role' => 'student',
                    'student_id' => (int)$student['id'],
                    'student_code' => $student['student_code'],
                    'student_name' => $studentName,
                    'class_id' => (int)$student['class_id'],
                    'class_name' => $student['class_name'],
                ];
                redirect('student_attendance.php');
            }
        } else {
            $stmt = db()->prepare('SELECT u.*, t.name_th AS teacher_name, c.class_name FROM users u LEFT JOIN teachers t ON t.id = u.teacher_id LEFT JOIN classes c ON c.id = u.class_id WHERE u.username = ? AND u.active = 1 LIMIT 1');
            $stmt->bind_param('s', $username);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();
            if ($user && $user['role'] === $loginType && password_verify($password, $user['password_hash'])) {
                unset($_SESSION['login_math']);
                $_SESSION['user'] = [
                    'id' => (int)$user['id'],
                    'username' => $user['username'],
                    'role' => $user['role'],
                    'teacher_id' => $user['teacher_id'] ? (int)$user['teacher_id'] : null,
                    'teacher_name' => $user['teacher_name'],
                    'class_id' => isset($user['class_id']) && $user['class_id'] ? (int)$user['class_id'] : null,
                    'class_name' => isset($user['class_name']) ? $user['class_name'] : null,
                ];
                if ($user['role'] === 'teacher' && isset($user['class_id']) && $user['class_id']) {
                    redirect('admin.php?page=check&class_id=' . (int)$user['class_id'] . '&date=' . date('Y-m-d'));
                }
                redirect('admin.php');
            }
        }
        $error = 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง';
    }
    $_SESSION['login_math'] = ['a' => random_int(1, 9), 'b' => random_int(1, 9)];
}

$mathA = (int)$_SESSION['login_math']['a'];
$mathB = (int)$_SESSION['login_math']['b'];

layout_header($pageTitle);
?>
<section class="auth">
    <form method="post" class="panel narrow login-panel" autocomplete="off">
        <input type="hidden" name="login_type" value="<?= e($loginType) ?>">
        <div class="login-panel-head">
            <span><?= e($badgeTextMap[$loginType]) ?></span>
            <h1><?= e($pageTitle) ?></h1>
        </div>
        <?php if ($error): ?><div class="error"><?= e($error) ?></div><?php endif; ?>
        <label><?= $loginType === 'student' ? 'รหัสนักเรียน' : 'ชื่อผู้ใช้' ?> <input name="login_user" autocomplete="off" autocapitalize="none" spellcheck="false" required></label>
        <label>รหัสผ่าน <input type="password" name="login_pass" autocomplete="new-password" required></label>
        <label>คำถามยืนยัน <span class="math-question"><?= $mathA ?> + <?= $mathB ?> = ?</span><input type="number" name="math_answer" min="0" max="99" inputmode="numeric" autocomplete="off" required></label>
        <button class="primary"><?= e($buttonText) ?></button>
    </form>
</section>
<?php layout_footer(); ?>
