<?php
/**
 * phpapi/admin/index.php - 管理员后台登录页面
 * 
 * 功能：
 * - 使用 password_hash/password_verify 验证管理员身份
 * - 登录成功后跳转至数据管理页面
 * - 初始账户 admin / admin123
 * 
 * 安全措施：
 * - 密码使用 password_verify 验证（防时序攻击）
 * - 所有输出使用 htmlspecialchars 防 XSS
 * - 登录成功后生成 CSRF token
 */
require_once __DIR__ . '/../../config.php';
session_start();

$error = '';

// 如果已登录，直接跳转到数据管理页
if (!empty($_SESSION['admin_logged_in'])) {
    header('Location: upload_data.php');
    exit;
}

// 处理登录请求
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $error = '请输入用户名和密码';
    } else {
        $pdo = getDB();
        // 使用预处理语句查询用户
        $stmt = $pdo->prepare("SELECT `id`, `username`, `password_hash` FROM `admin_users` WHERE `username` = ? LIMIT 1");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            // 登录成功：设置 session
            $_SESSION['admin_logged_in'] = true;
            $_SESSION['admin_username'] = $user['username'];
            $_SESSION['admin_id'] = $user['id'];

            // 生成 CSRF token
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

            // 登录成功后重定向，防止表单重复提交
            header('Location: upload_data.php');
            exit;
        } else {
            $error = '用户名或密码错误';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>管理员登录</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Microsoft YaHei", sans-serif;
            background: #fff;
            color: #333;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
        }
        .login-box {
            border: 1px solid #ccc;
            padding: 40px;
            min-width: 320px;
        }
        .login-box h1 { font-size: 18px; font-weight: normal; text-align: center; margin-bottom: 24px; }
        .login-box label { display: block; font-size: 14px; margin-bottom: 4px; }
        .login-box input[type="text"],
        .login-box input[type="password"] {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid #ccc;
            font-size: 15px;
            margin-bottom: 16px;
            outline: none;
        }
        .login-box input:focus { border-color: #666; }
        .login-box button {
            width: 100%;
            padding: 10px;
            border: 1px solid #999;
            background: #fff;
            cursor: pointer;
            font-size: 15px;
        }
        .login-box button:hover { background: #f5f5f5; }
        .login-box .error { color: #c00; font-size: 13px; text-align: center; margin-bottom: 12px; }
        .login-box .back { text-align: center; margin-top: 16px; font-size: 13px; }
        .login-box .back a { color: #333; }
    </style>
</head>
<body>

<div class="login-box">
    <h1>管理员登录</h1>

    <?php if ($error !== ''): ?>
        <div class="error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <form method="post" action="">
        <label for="username">用户名</label>
        <input type="text" id="username" name="username" autocomplete="off" autofocus>

        <label for="password">密码</label>
        <input type="password" id="password" name="password" autocomplete="off">

        <button type="submit">登 录</button>
    </form>

    <div class="back"><a href="../../index.php">返回首页</a></div>
</div>

</body>
</html>
