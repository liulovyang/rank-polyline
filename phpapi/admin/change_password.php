<?php
/**
 * change_password.php - 管理员修改密码
 * 
 * 功能：
 * - 验证旧密码后允许设置新密码
 * - 新密码长度至少6位
 * 
 * 安全措施：
 * - 登录状态检查
 * - CSRF token 验证
 * - password_hash / password_verify
 * - 所有 SQL 使用 PDO 预处理
 * - 所有输出使用 htmlspecialchars
 */
require_once __DIR__ . '/../../config.php';
session_start();

// 登录状态检查
if (empty($_SESSION['admin_logged_in'])) {
    header('Location: index.php');
    exit;
}

$pdo = getDB();
$message = '';
$messageType = '';

// 处理修改密码请求
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF 验证
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        $message = 'CSRF 验证失败，请刷新页面重试。';
        $messageType = 'error';
    } else {
        $oldPassword = $_POST['old_password'] ?? '';
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if ($oldPassword === '' || $newPassword === '' || $confirmPassword === '') {
            $message = '请填写所有密码字段';
            $messageType = 'error';
        } elseif (mb_strlen($newPassword) < 6) {
            $message = '新密码长度不能少于6位';
            $messageType = 'error';
        } elseif ($newPassword !== $confirmPassword) {
            $message = '两次输入的新密码不一致';
            $messageType = 'error';
        } else {
            // 获取当前用户的密码哈希
            $stmt = $pdo->prepare("SELECT `password_hash` FROM `admin_users` WHERE `id` = ? LIMIT 1");
            $stmt->execute([$_SESSION['admin_id']]);
            $user = $stmt->fetch();

            if (!$user) {
                $message = '用户不存在';
                $messageType = 'error';
            } elseif (!password_verify($oldPassword, $user['password_hash'])) {
                $message = '旧密码错误';
                $messageType = 'error';
            } else {
                // 更新密码
                $newHash = password_hash($newPassword, PASSWORD_BCRYPT);
                $update = $pdo->prepare("UPDATE `admin_users` SET `password_hash` = ? WHERE `id` = ?");
                $update->execute([$newHash, $_SESSION['admin_id']]);

                $message = '密码修改成功';
                $messageType = 'success';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>修改密码 - 管理员后台</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Microsoft YaHei", sans-serif;
            background: #fff;
            color: #333;
            max-width: 500px;
            margin: 0 auto;
            padding: 20px;
        }
        h1 { font-size: 1.3em; font-weight: normal; margin-bottom: 20px; }

        .message {
            padding: 10px 16px;
            margin-bottom: 16px;
            font-size: 14px;
        }
        .message.success { background: #e8f5e9; border: 1px solid #a5d6a7; }
        .message.error { background: #ffebee; border: 1px solid #ef9a9a; }

        .form-box {
            border: 1px solid #ccc;
            padding: 30px;
        }
        .form-group { margin-bottom: 16px; }
        .form-group label { display: block; font-size: 14px; margin-bottom: 4px; }
        .form-group input[type="password"] {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid #ccc;
            font-size: 15px;
            outline: none;
        }
        .form-group input:focus { border-color: #666; }

        button {
            width: 100%;
            padding: 10px;
            border: 1px solid #999;
            background: #fff;
            cursor: pointer;
            font-size: 15px;
            margin-top: 4px;
        }
        button:hover { background: #f5f5f5; }

        .nav { margin-top: 20px; font-size: 14px; }
        .nav a { color: #333; margin-right: 16px; }
    </style>
</head>
<body>

<h1>修改密码</h1>

<div class="nav">
    <a href="upload_data.php">数据管理</a>
    <a href="passmamge.php">验证码设置</a>
    <a href="../../index.php">返回首页</a>
</div>

<?php if ($message !== ''): ?>
    <div class="message <?php echo $messageType; ?>"><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></div>
<?php endif; ?>

<div class="form-box">
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">

        <div class="form-group">
            <label for="old_password">旧密码</label>
            <input type="password" id="old_password" name="old_password" autocomplete="off" required>
        </div>

        <div class="form-group">
            <label for="new_password">新密码（至少6位）</label>
            <input type="password" id="new_password" name="new_password" autocomplete="off" required>
        </div>

        <div class="form-group">
            <label for="confirm_password">确认新密码</label>
            <input type="password" id="confirm_password" name="confirm_password" autocomplete="off" required>
        </div>

        <button type="submit">修改密码</button>
    </form>
</div>

</body>
</html>
