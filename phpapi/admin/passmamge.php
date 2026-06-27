<?php
/**
 * passmamge.php - 人机验证码开关管理
 * 
 * 功能：
 * - 管理员可开启/关闭人机验证功能
 * - 当前状态实时显示
 * 
 * 安全措施：
 * - 登录状态检查
 * - CSRF token 验证
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

// 获取当前验证码状态
$stmt = $pdo->prepare("SELECT `value` FROM `settings` WHERE `key` = ?");
$stmt->execute(['captcha_enabled']);
$captchaEnabled = ($stmt->fetchColumn() === '1');

// 处理开关请求
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF 验证
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        $message = 'CSRF 验证失败，请刷新页面重试。';
        $messageType = 'error';
    } else {
        $newValue = ($_POST['captcha_state'] ?? '') === '1' ? '1' : '0';
        $update = $pdo->prepare("UPDATE `settings` SET `value` = ? WHERE `key` = 'captcha_enabled'");
        $update->execute([$newValue]);
        $captchaEnabled = ($newValue === '1');
        $message = '验证码已' . ($captchaEnabled ? '开启' : '关闭');
        $messageType = 'success';
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>验证码设置 - 管理员后台</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Microsoft YaHei", sans-serif;
            background: #fff;
            color: #333;
            max-width: 600px;
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

        .status-box {
            border: 1px solid #ccc;
            padding: 24px;
            margin-bottom: 20px;
        }
        .status-box p { font-size: 15px; margin-bottom: 16px; }
        .status-box .status {
            display: inline-block;
            padding: 4px 16px;
            font-size: 14px;
            border: 1px solid #999;
        }
        .status-box .status.on { background: #e8f5e9; }
        .status-box .status.off { background: #ffebee; }

        button {
            padding: 10px 24px;
            border: 1px solid #999;
            background: #fff;
            cursor: pointer;
            font-size: 15px;
        }
        button:hover { background: #f5f5f5; }

        .nav { margin-top: 24px; font-size: 14px; }
        .nav a { color: #333; margin-right: 16px; }
    </style>
</head>
<body>

<h1>验证码设置</h1>

<div class="nav">
    <a href="upload_data.php">数据管理</a>
    <a href="change_password.php">修改密码</a>
    <a href="../../index.php">返回首页</a>
</div>

<?php if ($message !== ''): ?>
    <div class="message <?php echo $messageType; ?>"><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></div>
<?php endif; ?>

<div class="status-box">
    <p>当前状态：
        <span class="status <?php echo $captchaEnabled ? 'on' : 'off'; ?>">
            <?php echo $captchaEnabled ? '已开启' : '已关闭'; ?>
        </span>
    </p>
    <p style="font-size:13px;color:#666;">
        <?php if ($captchaEnabled): ?>
            访问首页时需要先通过数学题验证。
        <?php else: ?>
            访问首页时无需验证，直接显示折线图。
        <?php endif; ?>
    </p>

    <form method="post">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="captcha_state" value="<?php echo $captchaEnabled ? '0' : '1'; ?>">
        <button type="submit">
            <?php echo $captchaEnabled ? '关闭验证码' : '开启验证码'; ?>
        </button>
    </form>
</div>

</body>
</html>
