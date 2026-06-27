<?php
/**
 * upload_data.php - 管理员数据管理页
 * 
 * 功能：
 * - 上传 Excel 成绩表（.xlsx）
 * - 上传后预览表头和数据行，管理员通过下拉菜单选择姓名列和排名列
 * - 同一行数据对应同一学生
 * - 删除已有考试（需 CSRF token 验证）
 * 
 * 安全措施：
 * - 登录状态检查
 * - CSRF token 验证所有写操作
 * - 文件上传白名单（仅 .xlsx）
 * - 文件名随机重命名防止路径遍历
 * - 所有 SQL 使用 PDO 预处理
 * - 所有输出使用 htmlspecialchars
 */
require_once __DIR__ . '/../../config.php';
session_start();

// ----- 登录状态检查 -----
if (empty($_SESSION['admin_logged_in'])) {
    header('Location: index.php');
    exit;
}

// ----- 检查 SimpleXLSX 库是否存在 -----
// 注意：SimpleXLSX 使用 namespace Shuchkin，必须使用完整命名空间
use Shuchkin\SimpleXLSX;

$simpleXlsxPath = __DIR__ . '/SimpleXLSX.php';
$hasSimpleXLSX = file_exists($simpleXlsxPath);
if ($hasSimpleXLSX) {
    require_once $simpleXlsxPath;
}

// 判断类是否可用（使用完整命名空间）
$canParseXlsx = ($hasSimpleXLSX && class_exists('\\Shuchkin\\SimpleXLSX'));

$pdo = getDB();
$message = '';
$messageType = ''; // 'success' or 'error'
$previewHeaders = null;
$previewRows = null;
$uploadFileName = '';

// 上传目录
$uploadDir = __DIR__ . '/../../zuhdxxbzapi/UplOad/exam_Upload/';
if (!is_dir($uploadDir)) {
    @mkdir($uploadDir, 0755, true);
}

// ----- CSRF 验证函数 -----
function verifyCsrf(): bool {
    $token = $_POST['csrf_token'] ?? '';
    return hash_equals($_SESSION['csrf_token'] ?? '', $token);
}

// ======================== 处理文件上传 ========================

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['excel_file'])) {
    // CSRF 验证
    if (!verifyCsrf()) {
        $message = 'CSRF 验证失败，请刷新页面重试。';
        $messageType = 'error';
    } elseif (!$canParseXlsx) {
        $message = 'SimpleXLSX.php 库未正确安装。请下载后放置到 phpapi/admin/ 目录。';
        $messageType = 'error';
    } else {
        $examName = trim($_POST['exam_name'] ?? '');
        if ($examName === '') {
            $message = '请输入考试名称';
            $messageType = 'error';
        } else {
            $file = $_FILES['excel_file'];

            // 检查上传错误
            if ($file['error'] !== UPLOAD_ERR_OK) {
                $message = '文件上传失败，错误码：' . $file['error'];
                $messageType = 'error';
            } else {
                // 严格验证文件扩展名（仅允许 .xlsx）
                $originalName = $file['name'];
                $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
                $allowedExts = ['xlsx'];

                if (!in_array($ext, $allowedExts, true)) {
                    $message = '仅允许上传 .xlsx 格式的 Excel 文件。';
                    $messageType = 'error';
                } else {
                    // 随机重命名文件，防止路径遍历和冲突
                    $newFileName = bin2hex(random_bytes(16)) . '.' . $ext;
                    $destPath = $uploadDir . $newFileName;

                    if (move_uploaded_file($file['tmp_name'], $destPath)) {
                        $uploadFileName = $newFileName;

                        // 解析 Excel 文件获取表头预览
                        try {
                            $xlsx = SimpleXLSX::parse($destPath);
                            if ($xlsx) {
                                $rows = $xlsx->rows();
                                if (count($rows) > 0) {
                                    $previewHeaders = $rows[0];
                                    // 预览前8行数据（方便管理员辨认列含义）
                                    $previewRows = array_slice($rows, 1, 8);
                                } else {
                                    $message = 'Excel 文件为空。';
                                    $messageType = 'error';
                                    @unlink($destPath);
                                }
                            } else {
                                $errorMsg = SimpleXLSX::parseError();
                                $message = '无法解析 Excel 文件。' . ($errorMsg ? ' 错误信息：' . htmlspecialchars($errorMsg, ENT_QUOTES, 'UTF-8') : '');
                                $messageType = 'error';
                                @unlink($destPath);
                            }
                        } catch (Exception $e) {
                            $message = 'Excel 解析失败：' . $e->getMessage();
                            $messageType = 'error';
                            @unlink($destPath);
                        }
                    } else {
                        $message = '文件保存失败，请检查上传目录权限。';
                        $messageType = 'error';
                    }
                }
            }
        }
    }
}

// ======================== 处理列选择与数据导入 ========================

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'import') {
    // CSRF 验证
    if (!verifyCsrf()) {
        $message = 'CSRF 验证失败，请刷新页面重试。';
        $messageType = 'error';
    } elseif (!$canParseXlsx) {
        $message = 'SimpleXLSX.php 库未正确安装。';
        $messageType = 'error';
    } else {
        $examName = trim($_POST['exam_name'] ?? '');
        $nameColIndex = (int)($_POST['name_col'] ?? -1);
        $rankColIndex = (int)($_POST['rank_col'] ?? -1);
        $savedFilePath = $_POST['saved_file'] ?? '';

        if ($examName === '' || $nameColIndex < 0 || $rankColIndex < 0 || $savedFilePath === '') {
            $message = '参数不完整，请重新上传。';
            $messageType = 'error';
        } elseif ($nameColIndex === $rankColIndex) {
            $message = '姓名列和排名列不能为同一列。';
            $messageType = 'error';
        } else {
            $filePath = $uploadDir . basename($savedFilePath);

            if (!file_exists($filePath)) {
                $message = '上传文件已过期，请重新上传。';
                $messageType = 'error';
            } else {
                try {
                    $xlsx = SimpleXLSX::parse($filePath);
                    if (!$xlsx) {
                        throw new RuntimeException('Excel 解析失败：' . (SimpleXLSX::parseError() ?: '未知错误'));
                    }

                    $rows = $xlsx->rows();
                    $headers = $rows[0] ?? [];
                    // 跳过表头行
                    $dataRows = array_slice($rows, 1);

                    // 检查列索引是否有效
                    $colCount = count($headers);
                    if ($nameColIndex >= $colCount || $rankColIndex >= $colCount) {
                        $message = '列索引超出表头范围';
                        $messageType = 'error';
                    } else {
                        $pdo->beginTransaction();
                        try {
                            // 检查考试名称是否已存在
                            $checkStmt = $pdo->prepare("SELECT `id` FROM `exams` WHERE `exam_name` = ? LIMIT 1");
                            $checkStmt->execute([$examName]);
                            if ($checkStmt->fetch()) {
                                throw new RuntimeException('考试名称 "' . htmlspecialchars($examName, ENT_QUOTES, 'UTF-8') . '" 已存在，请使用不同名称。');
                            }

                            // 创建考试记录
                            $insertExam = $pdo->prepare("INSERT INTO `exams` (`exam_name`) VALUES (?)");
                            $insertExam->execute([$examName]);
                            $examId = $pdo->lastInsertId();

                            // 插入成绩数据（预处理防注入）
                            // 同一行对应同一学生
                            $insertScore = $pdo->prepare(
                                "INSERT INTO `scores` (`exam_id`, `student_name`, `rank`) VALUES (?, ?, ?)"
                            );

                            $importedCount = 0;
                            $skippedCount = 0;
                            foreach ($dataRows as $row) {
                                // 安全获取单元格内容，处理 SimpleXLSX 返回的混合类型
                                $studentName = trim((string)($row[$nameColIndex] ?? ''));
                                $rankStr = trim((string)($row[$rankColIndex] ?? ''));

                                // 跳过空行
                                if ($studentName === '' || $rankStr === '') {
                                    $skippedCount++;
                                    continue;
                                }

                                // 排名必须是数字
                                if (!is_numeric($rankStr)) {
                                    $skippedCount++;
                                    continue;
                                }

                                $insertScore->execute([$examId, $studentName, (int)$rankStr]);
                                $importedCount++;
                            }

                            $pdo->commit();
                            $detail = $skippedCount > 0 ? "（跳过 $skippedCount 行无效数据）" : '';
                            $message = "考试 \"$examName\" 导入成功！共导入 $importedCount 条成绩记录。" . $detail;
                            $messageType = 'success';

                            // 清理临时文件
                            @unlink($filePath);
                        } catch (Exception $e) {
                            $pdo->rollBack();
                            $message = '导入失败：' . $e->getMessage();
                            $messageType = 'error';
                        }
                    }
                } catch (Exception $e) {
                    $message = '解析失败：' . $e->getMessage();
                    $messageType = 'error';
                }
            }
        }
    }
}

// ======================== 处理删除考试 ========================

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    // CSRF 验证
    if (!verifyCsrf()) {
        $message = 'CSRF 验证失败，请刷新页面重试。';
        $messageType = 'error';
    } else {
        $examId = (int)($_POST['exam_id'] ?? 0);
        if ($examId <= 0) {
            $message = '无效的考试ID';
            $messageType = 'error';
        } else {
            // 使用预处理删除
            $stmt = $pdo->prepare("DELETE FROM `exams` WHERE `id` = ?");
            $stmt->execute([$examId]);

            if ($stmt->rowCount() > 0) {
                $message = '考试已删除（关联成绩数据已同步清除）';
                $messageType = 'success';
            } else {
                $message = '未找到该考试';
                $messageType = 'error';
            }
        }
    }
}

// ======================== 处理退出登录 ========================

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'logout') {
    // 退出也需要 CSRF 验证
    if (verifyCsrf()) {
        session_destroy();
        header('Location: index.php');
        exit;
    }
}

// ----- 获取已上传的考试列表 -----
$exams = $pdo->query("SELECT `id`, `exam_name`, `created_at` FROM `exams` ORDER BY `created_at` ASC")->fetchAll();

// 获取每个考试的学生数量
$examStats = [];
foreach ($exams as $exam) {
    $stmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM `scores` WHERE `exam_id` = ?");
    $stmt->execute([$exam['id']]);
    $examStats[$exam['id']] = $stmt->fetchColumn();
}

// 生成列下拉选项
function generateColOptions($headerCount, $selected = -1) {
    $html = '';
    for ($i = 0; $i < $headerCount; $i++) {
        $sel = ($i === $selected) ? ' selected' : '';
        $html .= '<option value="' . $i . '"' . $sel . '>列 ' . $i . '</option>';
    }
    return $html;
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>数据管理 - 管理员后台</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Microsoft YaHei", sans-serif;
            background: #fff;
            color: #333;
            max-width: 1000px;
            margin: 0 auto;
            padding: 20px;
        }
        h1 { font-size: 1.3em; font-weight: normal; margin-bottom: 8px; }
        h2 { font-size: 1.1em; font-weight: normal; margin: 24px 0 12px; border-bottom: 1px solid #ddd; padding-bottom: 6px; }

        .header-row {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        .header-row .nav a { color: #333; font-size: 14px; margin-left: 16px; }

        .message {
            padding: 10px 16px;
            margin-bottom: 16px;
            font-size: 14px;
        }
        .message.success { background: #e8f5e9; border: 1px solid #a5d6a7; }
        .message.error { background: #ffebee; border: 1px solid #ef9a9a; }

        /* 表单 */
        .form-group { margin-bottom: 14px; }
        .form-group label { display: block; font-size: 14px; margin-bottom: 4px; }
        .form-group input[type="text"],
        .form-group input[type="file"],
        .form-group select {
            padding: 6px 10px;
            border: 1px solid #ccc;
            font-size: 14px;
            width: 100%;
            max-width: 400px;
            outline: none;
        }
        .form-group input:focus, .form-group select:focus { border-color: #666; }
        button {
            padding: 8px 20px;
            border: 1px solid #999;
            background: #fff;
            cursor: pointer;
            font-size: 14px;
        }
        button:hover { background: #f5f5f5; }
        button.danger { border-color: #c00; color: #c00; }
        button.danger:hover { background: #fff0f0; }

        /* 预览表格 */
        .preview-table {
            width: 100%;
            border-collapse: collapse;
            margin: 12px 0;
            font-size: 13px;
        }
        .preview-table th, .preview-table td {
            border: 1px solid #ddd;
            padding: 6px 10px;
            text-align: left;
            max-width: 150px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .preview-table th { background: #f5f5f5; font-weight: normal; }

        /* 列选择区域 */
        .col-select-row {
            display: flex;
            gap: 30px;
            margin-top: 16px;
            flex-wrap: wrap;
            align-items: flex-end;
        }
        .col-select-row .form-group { max-width: 250px; }

        /* 已有考试表格 */
        .exam-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }
        .exam-table th, .exam-table td {
            border: 1px solid #ddd;
            padding: 8px 12px;
            text-align: left;
        }
        .exam-table th { background: #f5f5f5; font-weight: normal; }
        .exam-table .delete-form { display: inline; }

        .note { font-size: 12px; color: #999; margin-top: 4px; }
        .back-link { font-size: 14px; margin-top: 24px; }
        .back-link a { color: #333; }
    </style>
</head>
<body>

<div class="header-row">
    <h1>管理员后台 - 数据管理</h1>
    <div class="nav">
        <span>当前用户：<?php echo htmlspecialchars($_SESSION['admin_username'] ?? '', ENT_QUOTES, 'UTF-8'); ?></span>
        <a href="change_password.php">修改密码</a>
        <a href="passmamge.php">验证码设置</a>
        <a href="../../index.php">返回首页</a>
        <form method="post" style="display:inline;">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="action" value="logout">
            <button type="submit" style="padding:4px 12px;font-size:13px;">退出</button>
        </form>
    </div>
</div>

<?php if ($message !== ''): ?>
    <div class="message <?php echo $messageType; ?>"><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></div>
<?php endif; ?>

<?php if (!$hasSimpleXLSX): ?>
    <div class="message error">
        注意：SimpleXLSX.php 解析库未找到。请下载 <code>SimpleXLSX.php</code> 并放置到 <code>phpapi/admin/</code> 目录。
        <br>下载地址：<a href="https://github.com/shuchkin/simplexlsx" target="_blank">https://github.com/shuchkin/simplexlsx</a>
    </div>
<?php endif; ?>

<!-- 上传 Excel 表单 -->
<h2>上传考试成绩表</h2>
<form method="post" enctype="multipart/form-data">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">

    <div class="form-group">
        <label for="exam_name">考试名称</label>
        <input type="text" id="exam_name" name="exam_name" placeholder="例如：2024年1月期中考试" required>
    </div>

    <div class="form-group">
        <label for="excel_file">选择 Excel 文件（.xlsx）</label>
        <input type="file" id="excel_file" name="excel_file" accept=".xlsx" required>
    </div>

    <button type="submit">上传并预览</button>
</form>

<!-- 列选择预览（上传后显示） -->
<?php if ($previewHeaders !== null): ?>
    <h2>预览数据 - 选择列</h2>
    <p class="note">
        请查看下表头和数据预览，选择哪一列为「姓名」，哪一列为「成绩排名」。
        <br>同一行数据代表同一位学生的信息。
    </p>

    <table class="preview-table">
        <thead>
            <tr>
                <th>索引</th>
                <?php foreach ($previewHeaders as $i => $header): ?>
                    <th><?php echo htmlspecialchars((string)$i, ENT_QUOTES, 'UTF-8'); ?></th>
                <?php endforeach; ?>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>表头</td>
                <?php foreach ($previewHeaders as $header): ?>
                    <td><?php echo htmlspecialchars((string)$header, ENT_QUOTES, 'UTF-8'); ?></td>
                <?php endforeach; ?>
            </tr>
            <?php if ($previewRows): ?>
                <?php foreach ($previewRows as $rowIdx => $row): ?>
                    <tr>
                        <td>行 <?php echo $rowIdx + 2; ?></td>
                        <?php foreach ($row as $cell): ?>
                            <td><?php echo htmlspecialchars((string)$cell, ENT_QUOTES, 'UTF-8'); ?></td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <form method="post" style="margin-top:16px;">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="action" value="import">
        <input type="hidden" name="exam_name" value="<?php echo htmlspecialchars($_POST['exam_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="saved_file" value="<?php echo htmlspecialchars($uploadFileName, ENT_QUOTES, 'UTF-8'); ?>">

        <div class="col-select-row">
            <div class="form-group">
                <label for="name_col">姓名列</label>
                <select id="name_col" name="name_col" required>
                    <option value="">-- 请选择 --</option>
                    <?php echo generateColOptions(count($previewHeaders)); ?>
                </select>
            </div>

            <div class="form-group">
                <label for="rank_col">排名列</label>
                <select id="rank_col" name="rank_col" required>
                    <option value="">-- 请选择 --</option>
                    <?php echo generateColOptions(count($previewHeaders)); ?>
                </select>
            </div>

            <button type="submit">确认导入</button>
        </div>
    </form>
<?php endif; ?>

<!-- 已有考试列表 -->
<h2>已有考试数据</h2>
<?php if (count($exams) === 0): ?>
    <p style="color:#999;font-size:14px;">暂无考试数据，请上传 Excel 成绩表。</p>
<?php else: ?>
    <table class="exam-table">
        <thead>
            <tr>
                <th>ID</th>
                <th>考试名称</th>
                <th>学生数量</th>
                <th>上传时间</th>
                <th>操作</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($exams as $exam): ?>
                <tr>
                    <td><?php echo htmlspecialchars((string)$exam['id'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars($exam['exam_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo (int)($examStats[$exam['id']] ?? 0); ?></td>
                    <td><?php echo htmlspecialchars($exam['created_at'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td>
                        <!-- 删除考试表单（CSRF 保护） -->
                        <form method="post" class="delete-form" onsubmit="return confirm('确定删除考试 \"<?php echo htmlspecialchars($exam['exam_name'], ENT_QUOTES, 'UTF-8'); ?>\" 及其所有成绩数据吗？此操作不可撤销。');">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="exam_id" value="<?php echo (int)$exam['id']; ?>">
                            <button type="submit" class="danger">删除</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<div class="back-link"><a href="../../index.php">返回首页</a></div>

</body>
</html>
