<?php
/**
 * exam_Individual.php - 返回单个学生的排名数据
 * 
 * 功能：
 * - 根据学生姓名返回其在所有考试中的排名
 * - 排名顺序与考试上传时间一致
 * - 按需请求，避免一次性加载所有学生数据
 * 
 * 安全措施：
 * - 使用 PDO 预处理防止 SQL 注入
 * - 搜索输入不做过度过滤，仅依赖预处理
 */
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json; charset=utf-8');

$studentName = $_GET['name'] ?? '';

if ($studentName === '') {
    echo json_encode(['success' => false, 'error' => '请提供学生姓名']);
    exit;
}

$pdo = getDB();

// 使用预处理语句查询，搜索输入不做过度过滤
$stmt = $pdo->prepare(
    "SELECT e.`exam_name`, s.`rank`, e.`id` as exam_id
     FROM `scores` s
     JOIN `exams` e ON s.`exam_id` = e.`id`
     WHERE s.`student_name` = ?
     ORDER BY e.`created_at` ASC"
);
$stmt->execute([$studentName]);
$results = $stmt->fetchAll();

if (count($results) === 0) {
    echo json_encode(['success' => false, 'error' => '未找到该学生的成绩数据']);
    exit;
}

// 提取排名数组（按考试顺序）
$ranks = array_column($results, 'rank');
// 转换为整数
$ranks = array_map('intval', $ranks);

echo json_encode([
    'success' => true,
    'student_name' => $studentName,
    'ranks' => $ranks
], JSON_UNESCAPED_UNICODE);
