<?php
/**
 * Exam_Search_al_xi.php - 搜索学生姓名 API
 * 
 * 功能：
 * - 支持模糊匹配搜索学生姓名（LIKE 查询）
 * - 返回匹配的学生姓名列表（去重）
 * - 用于前端搜索框的下拉建议
 * 
 * 安全措施：
 * - 使用 PDO 预处理防止 SQL 注入
 * - 搜索输入不做过度过滤，仅依赖预处理
 * - 限制返回结果数量防止资源耗尽
 */
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json; charset=utf-8');

$query = $_GET['q'] ?? '';

if ($query === '') {
    echo json_encode(['success' => false, 'error' => '请提供搜索关键词']);
    exit;
}

$pdo = getDB();

// 使用预处理语句进行模糊搜索
// 搜索输入不做过度过滤，仅依赖预处理防注入
$stmt = $pdo->prepare(
    "SELECT DISTINCT `student_name`
     FROM `scores`
     WHERE `student_name` LIKE ?
     ORDER BY `student_name` ASC
     LIMIT 20"
);
$stmt->execute(['%' . $query . '%']);
$students = $stmt->fetchAll(PDO::FETCH_COLUMN);

echo json_encode([
    'success' => true,
    'students' => $students
], JSON_UNESCAPED_UNICODE);
