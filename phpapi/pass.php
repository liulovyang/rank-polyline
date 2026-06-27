<?php
/**
 * pass.php - 人机验证 API
 * 
 * 功能：
 * - 生成随机数学题（两位数加减或个位数乘法）
 * - 验证用户答案
 * - 题目5分钟有效期，答案存储于 session
 * 
 * 安全措施：
 * - 答案存储于服务端 session，不暴露给客户端
 * - token 机制防止重放攻击
 * - 超时自动失效
 */
require_once __DIR__ . '/../config.php';
session_start();

header('Content-Type: application/json; charset=utf-8');

// 检查验证码功能是否已启用
$pdo = getDB();
$stmt = $pdo->prepare("SELECT `value` FROM `settings` WHERE `key` = ?");
$stmt->execute(['captcha_enabled']);
if ($stmt->fetchColumn() !== '1') {
    echo json_encode(['success' => true, 'disabled' => true]);
    exit;
}

$action = $_GET['action'] ?? '';

// ----- 获取验证题目 -----
if ($action === 'get') {
    // 生成随机数学题
    $type = random_int(0, 2); // 0:加法 1:减法 2:乘法

    switch ($type) {
        case 0: // 两位数加法
            $a = random_int(10, 99);
            $b = random_int(10, 99);
            $answer = $a + $b;
            $question = "$a + $b = ?";
            break;
        case 1: // 两位数减法（确保非负）
            $a = random_int(20, 99);
            $b = random_int(10, $a - 1);
            $answer = $a - $b;
            $question = "$a - $b = ?";
            break;
        case 2: // 个位数乘法
            $a = random_int(2, 9);
            $b = random_int(2, 9);
            $answer = $a * $b;
            $question = "$a x $b = ?";
            break;
    }

    // 生成唯一 token
    $token = bin2hex(random_bytes(16));

    // 答案存入 session，5分钟有效期
    $_SESSION['captcha_token'] = $token;
    $_SESSION['captcha_answer'] = $answer;
    $_SESSION['captcha_time'] = time();
    $_SESSION['captcha_expire'] = 300; // 5分钟

    echo json_encode([
        'success' => true,
        'question' => $question,
        'token' => $token
    ]);
    exit;
}

// ----- 验证答案 -----
if ($action === 'verify') {
    $token = $_POST['token'] ?? '';
    $userAnswer = $_POST['answer'] ?? '';

    // 验证 token 匹配
    if (empty($token) || $token !== ($_SESSION['captcha_token'] ?? '')) {
        echo json_encode(['success' => false, 'error' => '验证已过期，请刷新页面重试。']);
        exit;
    }

    // 检查是否超时（5分钟）
    $captchaTime = $_SESSION['captcha_time'] ?? 0;
    $captchaExpire = $_SESSION['captcha_expire'] ?? 300;
    if (time() - $captchaTime > $captchaExpire) {
        // 清除过期 session
        unset($_SESSION['captcha_token'], $_SESSION['captcha_answer'], $_SESSION['captcha_time'], $_SESSION['captcha_expire']);
        echo json_encode(['success' => false, 'error' => '验证已超时，请刷新页面重试。']);
        exit;
    }

    // 比对答案
    $correctAnswer = $_SESSION['captcha_answer'] ?? null;
    if ($correctAnswer === null || (int)$userAnswer !== (int)$correctAnswer) {
        echo json_encode(['success' => false, 'error' => '答案错误，请重试。']);
        exit;
    }

    // 验证通过：设置 session 标记
    $_SESSION['captcha_passed'] = true;
    $_SESSION['captcha_passed_time'] = time();

    // 清除验证题目的 session
    unset($_SESSION['captcha_token'], $_SESSION['captcha_answer'], $_SESSION['captcha_time'], $_SESSION['captcha_expire']);

    echo json_encode(['success' => true]);
    exit;
}

// 未知操作
echo json_encode(['success' => false, 'error' => '无效请求']);
