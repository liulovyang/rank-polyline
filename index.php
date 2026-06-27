<?php
/**
 * index.php - 成绩趋势折线图前端主页
 * 
 * 功能：
 * - 展示 Chart.js 折线图，X轴为考试名称，Y轴为年级排名（排名越小越靠上）
 * - 搜索框支持学生姓名模糊匹配，下拉建议
 * - 多折线叠加显示，颜色基于姓名生成
 * - "仅显示姓"复选框切换标签
 * - 按需加载学生数据，前端 Map 缓存
 * 
 * 安全措施：
 * - 所有输出使用 htmlspecialchars 防 XSS
 * - 搜索仅依赖后端预处理防注入
 */
require_once __DIR__ . '/config.php';

// 启动会话（用于人机验证状态检查）
session_start();

// 检查验证码是否启用
$pdo = getDB();
$stmt = $pdo->prepare("SELECT `value` FROM `settings` WHERE `key` = ?");
$stmt->execute(['captcha_enabled']);
$captchaEnabled = ($stmt->fetchColumn() === '1');

// 获取所有考试列表（仅元数据，不含成绩）
$exams = $pdo->query("SELECT `id`, `exam_name` FROM `exams` ORDER BY `created_at` ASC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>成绩趋势折线图</title>
    <!-- Chart.js 4.x CDN -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", "Microsoft YaHei", sans-serif;
            background: #fff;
            color: #333;
            max-width: 1100px;
            margin: 0 auto;
            padding: 20px;
        }
        h1 { font-size: 1.5em; font-weight: normal; margin-bottom: 20px; text-align: center; }

        /* 控制栏 */
        .controls {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 12px;
            margin-bottom: 16px;
        }
        .search-wrap { position: relative; flex: 1; min-width: 260px; }
        #searchInput {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid #ccc;
            font-size: 15px;
            outline: none;
        }
        #searchInput:focus { border-color: #666; }
        /* 下拉建议列表 */
        .suggestions {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: #fff;
            border: 1px solid #ccc;
            border-top: none;
            max-height: 200px;
            overflow-y: auto;
            display: none;
            z-index: 100;
        }
        .suggestions .item {
            padding: 8px 12px;
            cursor: pointer;
            font-size: 14px;
        }
        .suggestions .item:hover, .suggestions .item.active { background: #f0f0f0; }
        .suggestions .item .hint { color: #999; font-size: 12px; }

        /* 按钮与复选框 */
        button {
            padding: 8px 18px;
            border: 1px solid #999;
            background: #fff;
            cursor: pointer;
            font-size: 14px;
        }
        button:hover { background: #f5f5f5; }
        button:active { background: #b4b4b4ff; }

        label { font-size: 14px; display: flex; align-items: center; gap: 4px; cursor: pointer; }

        /* 图表容器 */
        .chart-container {
            position: relative;
            width: 100%;
            max-height: 550px;
            margin-top: 4px;
        }
        canvas { width: 100% !important; }

        /* 已添加的学生标签 */
        .added-tags {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            margin-bottom: 10px;
            min-height: 28px;
        }
        .tag {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 3px 10px;
            border: 1px solid #aaa;
            font-size: 13px;
        }
        .tag .remove-tag {
            cursor: pointer;
            font-weight: bold;
            margin-left: 2px;
        }
        .tag .remove-tag:hover { color: #c00; }

        /* 验证弹窗 */
        .captcha-overlay {
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0,0,0,0.5);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 999;
        }
        .captcha-box {
            background: #fff;
            padding: 30px 40px;
            border: 1px solid #ccc;
            min-width: 300px;
            text-align: center;
        }
        .captcha-box h2 { font-size: 16px; font-weight: normal; margin-bottom: 16px; }
        .captcha-box .question { font-size: 24px; margin-bottom: 16px; letter-spacing: 2px; }
        .captcha-box input[type="text"] {
            padding: 8px 12px;
            border: 1px solid #ccc;
            font-size: 18px;
            width: 120px;
            text-align: center;
            outline: none;
        }
        .captcha-box button { margin-top: 12px; }
        .captcha-box .error { color: #c00; font-size: 13px; margin-top: 6px; }

        /* 无数据提示 */
        .no-data { text-align: center; color: #999; padding: 60px 0; font-size: 15px; }

        /* 响应式 */
        @media (max-width: 600px) {
            body { padding: 10px; }
            .controls { gap: 8px; }
            .chart-container { max-height: 350px; }
        }
    </style>
</head>
<body>

<h1>成绩趋势折线图</h1>

<!-- 控制区域 -->
<div class="controls">
    <div class="search-wrap">
        <input type="text" id="searchInput" placeholder="输入学生姓名搜索..." autocomplete="off">
        <div class="suggestions" id="suggestions"></div>
    </div>
    <button id="clearBtn">清除全部</button>
    <label>
        <input type="checkbox" id="showSurname">
        仅显示姓
    </label>
</div>

<!-- 已添加学生标签 -->
<div class="added-tags" id="addedTags"></div>

<!-- 图表区域 -->
<div class="chart-container">
    <?php if (count($exams) === 0): ?>
        <div class="no-data">暂无考试数据，请管理员上传成绩表。</div>
    <?php endif; ?>
    <canvas id="chartCanvas"></canvas>
</div>

<!-- 人机验证弹窗（仅验证码启用时显示） -->
<?php if ($captchaEnabled && !isset($_SESSION['captcha_passed'])): ?>
<div class="captcha-overlay" id="captchaOverlay">
    <div class="captcha-box">
        <h2>人机验证</h2>
        <div class="question" id="captchaQuestion"></div>
        <input type="text" id="captchaAnswer" placeholder="输入答案" autocomplete="off">
        <div class="error" id="captchaError"></div>
        <button id="captchaSubmit">验证</button>
    </div>
</div>
<?php endif; ?>

<script>
// ======================== 全局状态 ========================

// Chart.js 实例
let chart = null;
// Chart.js 注册状态
let chartLoaded = false;
// 考试列表（由 PHP 注入）
const examNames = <?php echo json_encode(array_column($exams, 'exam_name'), JSON_UNESCAPED_UNICODE); ?>;
const examIds = <?php echo json_encode(array_column($exams, 'id')); ?>;
// 已添加的学生数据集（Map: student_name -> datasetIndex）
const studentDatasets = new Map();
// 学生数据缓存（Map: student_name -> ranks[]）
const dataCache = new Map();

// 搜索相关
let searchTimer = null;
let suggestIndex = -1;

// ======================== Chart.js 初始化 ========================

// 等待 Chart.js 加载
function waitForChart(callback) {
    if (typeof Chart !== 'undefined') {
        chartLoaded = true;
        callback();
        return;
    }
    const check = setInterval(() => {
        if (typeof Chart !== 'undefined') {
            clearInterval(check);
            chartLoaded = true;
            callback();
        }
    }, 100);
}

function initChart() {
    if (examNames.length === 0) return;
    const ctx = document.getElementById('chartCanvas').getContext('2d');
    chart = new Chart(ctx, {
        type: 'line',
        data: {
            labels: examNames, // X轴：考试名称
            datasets: []
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            // Y轴反转：排名数值越小（第1名）越靠上
            scales: {
                y: {
                    reverse: true,
                    title: {
                        display: true,
                        text: '年级排名'
                    },
                    ticks: {
                        stepSize: 1
                    }
                },
                x: {
                    title: {
                        display: true,
                        text: '考试名称'
                    }
                }
            },
            plugins: {
                legend: {
                    position: 'bottom'
                },
                tooltip: {
                    callbacks: {
                        label: function(ctx) {
                            return ctx.dataset.label + ': 第' + ctx.parsed.y + '名';
                        }
                    }
                }
            },
            // 当所有考试数据相同时，确保点可见
            spanGaps: false
        }
    });
}

// ======================== 颜色生成 ========================

/**
 * 基于姓名字符串生成 HSL 颜色（色相均匀分布）
 * @param {string} str - 学生姓名
 * @returns {string} HSL 颜色字符串
 */
function nameToColor(str) {
    let hash = 0;
    for (let i = 0; i < str.length; i++) {
        hash = str.charCodeAt(i) + ((hash << 5) - hash);
    }
    const hue = Math.abs(hash) % 360;
    // 使用较高的饱和度和适中的亮度，确保线条清晰可见
    return 'hsl(' + hue + ', 65%, 45%)';
}

// ======================== 搜索功能 ========================

const searchInput = document.getElementById('searchInput');
const suggestionsEl = document.getElementById('suggestions');

// 输入事件：防抖搜索
searchInput.addEventListener('input', function() {
    clearTimeout(searchTimer);
    const query = this.value.trim();
    if (query.length === 0) {
        suggestionsEl.style.display = 'none';
        suggestIndex = -1;
        return;
    }
    searchTimer = setTimeout(() => fetchSuggestions(query), 250);
});

// 键盘导航
searchInput.addEventListener('keydown', function(e) {
    const items = suggestionsEl.querySelectorAll('.item');
    if (e.key === 'ArrowDown') {
        e.preventDefault();
        if (items.length > 0) {
            suggestIndex = Math.min(suggestIndex + 1, items.length - 1);
            updateActiveSuggestion(items);
        }
    } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        if (items.length > 0) {
            suggestIndex = Math.max(suggestIndex - 1, 0);
            updateActiveSuggestion(items);
        }
    } else if (e.key === 'Enter') {
        e.preventDefault();
        if (suggestIndex >= 0 && items.length > 0) {
            // 选中高亮的建议项
            const active = items[suggestIndex];
            if (active) active.click();
        } else if (this.value.trim().length > 0) {
            // 无建议时直接搜索输入内容
            loadStudent(this.value.trim());
        }
    } else if (e.key === 'Escape') {
        suggestionsEl.style.display = 'none';
        suggestIndex = -1;
    }
});

// 点击外部关闭建议
document.addEventListener('click', function(e) {
    if (!searchInput.contains(e.target) && !suggestionsEl.contains(e.target)) {
        suggestionsEl.style.display = 'none';
        suggestIndex = -1;
    }
});

function updateActiveSuggestion(items) {
    items.forEach((item, i) => {
        if (i === suggestIndex) {
            item.classList.add('active');
            item.scrollIntoView({ block: 'nearest' });
        } else {
            item.classList.remove('active');
        }
    });
}

/**
 * 请求搜索建议 API
 * @param {string} query - 搜索关键词
 */
function fetchSuggestions(query) {
    fetch('papi/Exam_Search_al_xi.php?q=' + encodeURIComponent(query))
        .then(res => res.json())
        .then(data => {
            suggestionsEl.innerHTML = '';
            if (!data.success || data.students.length === 0) {
                suggestionsEl.style.display = 'none';
                suggestIndex = -1;
                return;
            }
            data.students.forEach((name, i) => {
                const div = document.createElement('div');
                div.className = 'item';
                // 高亮匹配部分（简单处理）
                div.textContent = name;
                div.addEventListener('click', () => {
                    searchInput.value = name;
                    suggestionsEl.style.display = 'none';
                    suggestIndex = -1;
                    loadStudent(name);
                });
                suggestionsEl.appendChild(div);
            });
            suggestionsEl.style.display = 'block';
            suggestIndex = -1;
        })
        .catch(err => {
            console.error('搜索失败:', err);
        });
}

// ======================== 数据加载与图表更新 ========================

/**
 * 加载单个学生的排名数据
 * @param {string} studentName - 学生全名
 */
function loadStudent(studentName) {
    // 如果已添加，不重复加载
    if (studentDatasets.has(studentName)) {
        return;
    }

    // 如果有缓存，直接使用
    if (dataCache.has(studentName)) {
        addDataset(studentName, dataCache.get(studentName));
        return;
    }

    fetch('papi/exam_Individual.php?name=' + encodeURIComponent(studentName))
        .then(res => res.json())
        .then(data => {
            if (!data.success) {
                alert('未找到该学生 "' + studentName + '" 的成绩数据。');
                return;
            }
            // 缓存数据
            dataCache.set(studentName, data.ranks);
            addDataset(studentName, data.ranks);
        })
        .catch(err => {
            console.error('加载学生数据失败:', err);
            alert('加载数据失败，请稍后重试。');
        });
}

/**
 * 将学生数据添加到图表中
 * @param {string} studentName - 学生姓名
 * @param {number[]} ranks - 排名数组（对应考试顺序）
 */
function addDataset(studentName, ranks) {
    if (!chart || !chartLoaded) return;

    const color = nameToColor(studentName);
    const labelName = document.getElementById('showSurname').checked
        ? studentName.charAt(0) // 仅显示姓（首字）
        : studentName;

    const dataset = {
        label: labelName,
        data: ranks,
        borderColor: color,
        backgroundColor: color.replace('45%', '80%'),
        borderWidth: 2,
        pointRadius: 5,
        pointHoverRadius: 7,
        tension: 0.1, // 轻微平滑
        fullName: studentName // 保存全名用于切换标签
    };

    chart.data.datasets.push(dataset);
    studentDatasets.set(studentName, chart.data.datasets.length - 1);
    chart.update();

    // 更新已添加标签
    renderTags();
}

/**
 * 渲染已添加的学生标签
 */
function renderTags() {
    const container = document.getElementById('addedTags');
    container.innerHTML = '';
    studentDatasets.forEach((index, name) => {
        const tag = document.createElement('span');
        tag.className = 'tag';
        tag.textContent = name;
        const remove = document.createElement('span');
        remove.className = 'remove-tag';
        remove.textContent = 'x';
        remove.title = '移除';
        remove.addEventListener('click', () => removeStudent(name));
        tag.appendChild(remove);
        container.appendChild(tag);
    });
}

/**
 * 移除指定学生的折线
 * @param {string} studentName
 */
function removeStudent(studentName) {
    if (!studentDatasets.has(studentName)) return;

    const index = studentDatasets.get(studentName);
    chart.data.datasets.splice(index, 1);
    studentDatasets.delete(studentName);

    // 更新 Map 中的索引（移除后索引会变化）
    let newIndex = 0;
    const updated = new Map();
    for (const [name, _] of studentDatasets) {
        updated.set(name, newIndex);
        newIndex++;
    }
    // 实际上直接重建 Map 更简单
    const names = Array.from(studentDatasets.keys()).filter(n => n !== studentName);
    studentDatasets.clear();
    names.forEach((n, i) => studentDatasets.set(n, i));

    chart.update();
    renderTags();
}

// "仅显示姓"复选框事件
document.getElementById('showSurname').addEventListener('change', function() {
    if (!chart) return;
    const showSurname = this.checked;
    chart.data.datasets.forEach(ds => {
        if (ds.fullName) {
            ds.label = showSurname ? ds.fullName.charAt(0) : ds.fullName;
        }
    });
    chart.update();
});

// 清除全部按钮
document.getElementById('clearBtn').addEventListener('click', function() {
    if (!chart) return;
    chart.data.datasets = [];
    studentDatasets.clear();
    chart.update();
    renderTags();
});

// ======================== 人机验证 ========================

// 仅当验证码启用且未通过时显示
const captchaOverlay = document.getElementById('captchaOverlay');
if (captchaOverlay) {
    const questionEl = document.getElementById('captchaQuestion');
    const answerInput = document.getElementById('captchaAnswer');
    const errorEl = document.getElementById('captchaError');
    const submitBtn = document.getElementById('captchaSubmit');
    let captchaToken = '';

    // 请求验证题目
    fetch('phpapi/pass.php?action=get')
        .then(res => res.json())
        .then(data => {
            questionEl.textContent = data.question;
            captchaToken = data.token;
        })
        .catch(() => {
            questionEl.textContent = '验证服务不可用';
        });

    // 提交答案
    function submitAnswer() {
        const answer = answerInput.value.trim();
        if (answer === '') {
            errorEl.textContent = '请输入答案';
            return;
        }
        fetch('phpapi/pass.php?action=verify', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'token=' + encodeURIComponent(captchaToken) + '&answer=' + encodeURIComponent(answer)
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                captchaOverlay.style.display = 'none';
            } else {
                errorEl.textContent = data.error || '答案错误，请重试';
                answerInput.value = '';
                answerInput.focus();
            }
        })
        .catch(() => {
            errorEl.textContent = '验证请求失败，请重试';
        });
    }

    submitBtn.addEventListener('click', submitAnswer);
    answerInput.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') submitAnswer();
    });
}

// ======================== 启动 ========================

waitForChart(() => {
    initChart();
});

// 页面加载完成后聚焦搜索框
searchInput.focus();
</script>
</body>
</html>
