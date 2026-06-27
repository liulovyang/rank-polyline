<?php
/**
 * api/index.php - 蜜罐文件
 * 
 * 功能：
 * - 记录访问者 IP 到 /logs/ 目录
 * - 返回 404 状态码
 * - 用于迷惑扫描器，同时记录潜在恶意访问
 * 
 * 安全措施：
 * - 日志存储在 Web 根目录外的 /logs/ 文件夹
 * - 不暴露任何系统信息
 */

// 获取访问者 IP
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
$requestUri = $_SERVER['REQUEST_URI'] ?? '';
$time = date('Y-m-d H:i:s');

// 日志目录（相对于本项目根目录的 logs/）
$logDir = __DIR__ . '/../logs/';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0755, true);
}

// 写入日志
$logFile = $logDir . 'honeypot_api.log';
$logLine = "[$time] IP: $ip | URI: $requestUri | UA: $userAgent" . PHP_EOL;
@file_put_contents($logFile, $logLine, FILE_APPEND | LOCK_EX);

// 返回 404
http_response_code(404);
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <title>404 Not Found</title>
</head>
<body>
    <h1>404 Not Found</h1>
</body>
</html>
