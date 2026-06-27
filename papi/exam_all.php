<?php
/**
 * exam_all.php - 返回所有考试元数据
 * 
 * 功能：
 * - 返回所有考试的名称和ID，按上传时间排序
 * - 供前端获取 X 轴标签使用（备用接口）
 * 
 * 安全措施：
 * - 仅输出 JSON 元数据，不含成绩信息
 * - 使用 PDO 预处理
 */
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json; charset=utf-8');

$pdo = getDB();
$stmt = $pdo->query("SELECT `id`, `exam_name`, `created_at` FROM `exams` ORDER BY `created_at` ASC");
$exams = $stmt->fetchAll();

echo json_encode([
    'success' => true,
    'exams' => $exams
], JSON_UNESCAPED_UNICODE);
