<?php

require_once __DIR__ . '/lib.php';

$user = require_staff_login();
$role = arr_get($user, 'role', '');
if ($role !== 'teacher' && $role !== 'admin') {
    redirect('index.php');
}

$error = '';
$success = '';
$roleLabel = $role === 'admin' ? 'ผู้ดูแลระบบ' : 'ครูประจำชั้น';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $currentPassword = (string)arr_get($_POST, 'current_password', '');
    $newPassword = (string)arr_get($_POST, 'new_password', '');
    $confirmPassword = (string)arr_get($_POST, 'confirm_password', '');

    $row = one_row('SELECT id, password_hash FROM users WHERE id = ? AND role = ? AND active = 1 LIMIT 1', 'is', [(int)$user['id'], $role]);
    if (!$row || !password_verify($currentPassword, $row['password_hash'])) {
        $error = 'รหัสผ่านเดิมไม่ถูกต้อง';
    } elseif (strlen($newPassword) < 6) {
        $error = 'รหัสผ่านใหม่ต้องมีอย่างน้อย 6 ตัวอักษร';
    } elseif ($newPassword !== $confirmPassword) {
        $error = 'ยืนยันรหัสผ่านใหม่ไม่ตรงกัน';
    } else {
        $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
        $stmt = db()->prepare('UPDATE users SET password_hash = ? WHERE id = ? AND role = ?');
        $stmt->bind_param('sis', $passwordHash, $user['id'], $role);
        $stmt->execute();
        $success = 'เปลี่ยนรหัสผ่านเรียบร้อยแล้ว';
    }
}

layout_header('เปลี่ยนรหัสผ่าน');
?>
<section class="auth">
    <form method="post" class="panel narrow login-panel" autocomplete="off">
        <div class="login-panel-head">
            <span><?= e($roleLabel) ?></span>
            <h1>เปลี่ยนรหัสผ่าน</h1>
        </div>
        <?php if ($error): ?><div class="error"><?= e($error) ?></div><?php endif; ?>
        <?php if ($success): ?><div class="notice"><?= e($success) ?></div><?php endif; ?>
        <label>ชื่อผู้ใช้ <input value="<?= e(arr_get($user, 'username', '')) ?>" disabled></label>
        <label>รหัสผ่านเดิม <input type="password" name="current_password" autocomplete="current-password" required></label>
        <label>รหัสผ่านใหม่ <input type="password" name="new_password" autocomplete="new-password" minlength="6" required></label>
        <label>ยืนยันรหัสผ่านใหม่ <input type="password" name="confirm_password" autocomplete="new-password" minlength="6" required></label>
        <button class="primary">บันทึกรหัสผ่านใหม่</button>
    </form>
</section>
<?php layout_footer(); ?>
