<?php
/**
 * config.php - 数据库连接与自动初始化
 * 
 * 安全措施：
 * - 使用 PDO 预处理防止 SQL 注入
 * - 数据库凭据集中管理
 * - 自动创建所需数据表
 */

// 数据库配置
define('DB_HOST', '127.0.0.1');
define('DB_PORT', '3306');
define('DB_NAME', 're');
define('DB_USER', 're');
define('DB_PASS', 'FBzD8EwGHzDA7b5m');
define('DB_CHARSET', 'utf8mb4');

/**
 * 获取 PDO 数据库连接实例（单例模式）
 * @return PDO
 */
function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false, // 使用真正的预处理，防注入
        ];
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    }
    return $pdo;
}

/**
 * 初始化数据库表结构
 * 首次运行时自动创建所需的表
 */
function initDatabase(): void {
    $pdo = getDB();

    // 创建考试表：存储每次考试的基本信息
    $pdo->exec("CREATE TABLE IF NOT EXISTS `exams` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `exam_name` VARCHAR(255) NOT NULL COMMENT '考试名称',
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '上传时间',
        UNIQUE KEY `uk_exam_name` (`exam_name`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='考试元数据表'");

    // 创建成绩表：存储每个学生在每次考试中的排名
    $pdo->exec("CREATE TABLE IF NOT EXISTS `scores` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `exam_id` INT NOT NULL COMMENT '关联考试ID',
        `student_name` VARCHAR(100) NOT NULL COMMENT '学生姓名',
        `rank` INT NOT NULL COMMENT '年级排名',
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX `idx_exam_id` (`exam_id`),
        INDEX `idx_student_name` (`student_name`),
        UNIQUE KEY `uk_exam_student` (`exam_id`, `student_name`),
        FOREIGN KEY (`exam_id`) REFERENCES `exams`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='学生成绩排名表'");

    // 创建管理员表：存储后台登录账户
    $pdo->exec("CREATE TABLE IF NOT EXISTS `admin_users` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `username` VARCHAR(50) NOT NULL UNIQUE COMMENT '用户名',
        `password_hash` VARCHAR(255) NOT NULL COMMENT 'password_hash 哈希密码',
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='管理员账户表'");

    // 创建设置表：存储系统开关等配置
    $pdo->exec("CREATE TABLE IF NOT EXISTS `settings` (
        `key` VARCHAR(50) PRIMARY KEY,
        `value` VARCHAR(255) NOT NULL DEFAULT ''
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='系统设置表'");

    // 插入默认管理员账户 admin / admin123（仅在表为空时）
    $stmt = $pdo->query("SELECT COUNT(*) as cnt FROM `admin_users`");
    $row = $stmt->fetch();
    if ($row['cnt'] == 0) {
        $hash = password_hash('admin123', PASSWORD_BCRYPT);
        $insert = $pdo->prepare("INSERT INTO `admin_users` (`username`, `password_hash`) VALUES (?, ?)");
        $insert->execute(['admin', $hash]);
    }

    // 插入默认设置：验证码默认开启
    $stmt = $pdo->query("SELECT COUNT(*) as cnt FROM `settings` WHERE `key` = 'captcha_enabled'");
    $row = $stmt->fetch();
    if ($row['cnt'] == 0) {
        $insert = $pdo->prepare("INSERT INTO `settings` (`key`, `value`) VALUES (?, ?)");
        $insert->execute(['captcha_enabled', '1']);
    }
}

// 自动执行初始化
initDatabase();
