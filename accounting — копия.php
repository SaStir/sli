<?php
// accounting.php - Бухгалтерский учёт с выбором периода и документами
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

require_once 'config.php';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Ошибка подключения к БД: " . $e->getMessage());
}

// Получаем информацию о текущем пользователе
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$current_user = $stmt->fetch(PDO::FETCH_ASSOC);

// Проверка роли: только админ и бухгалтер имеют доступ к учёту
if (!in_array($current_user['role'], ['admin', 'accountant'])) {
    if ($current_user['role'] == 'warehouse_keeper') {
        header('Location: warehouse.php');
    } elseif ($current_user['role'] == 'manager') {
        header('Location: contracts.php');
    } elseif ($current_user['role'] == 'customer') {
        header('Location: catalog.php');
    } else {
        header('Location: login.php');
    }
    exit;
}

// Получаем параметры периода из GET-запроса
$period = isset($_GET['period']) ? $_GET['period'] : 'month';
$custom_start = isset($_GET['start_date']) ? $_GET['start_date'] : null;
$custom_end = isset($_GET['end_date']) ? $_GET['end_date'] : null;

// Функция для получения даты начала периода
function getStartDate($period, $custom_start = null, $custom_end = null)
{
    if ($period === 'custom' && $custom_start) {
        return $custom_start;
    }

    switch ($period) {
        case 'day':
            return date('Y-m-d');
        case 'week':
            return date('Y-m-d', strtotime('monday this week'));
        case 'month':
            return date('Y-m-01');
        case 'year':
            return date('Y-01-01');
        case 'all':
        default:
            return '2020-01-01';
    }
}

function getEndDate($period, $custom_end = null)
{
    if ($period === 'custom' && $custom_end) {
        return $custom_end;
    }

    switch ($period) {
        case 'day':
            return date('Y-m-d');
        case 'week':
            return date('Y-m-d', strtotime('sunday this week'));
        case 'month':
            return date('Y-m-t');
        case 'year':
            return date('Y-12-31');
        case 'all':
        default:
            return date('Y-m-d');
    }
}

$start_date = getStartDate($period, $custom_start, $custom_end);
$end_date = getEndDate($period, $custom_end);

// Получаем продажи за период
$sales_period = $pdo->prepare("
    SELECT COALESCE(SUM(amount), 0) as period_sales 
    FROM accounting_operations 
    WHERE operation_type = 'реализация' 
    AND operation_date BETWEEN ? AND ?
");
$sales_period->execute([$start_date, $end_date]);
$sales_period_amount = $sales_period->fetchColumn();

// Получаем оплаты за период
$payments_period = $pdo->prepare("
    SELECT COALESCE(SUM(amount), 0) as period_payments 
    FROM accounting_operations 
    WHERE operation_type = 'оплата' 
    AND operation_date BETWEEN ? AND ?
");
$payments_period->execute([$start_date, $end_date]);
$payments_period_amount = $payments_period->fetchColumn();

// Прибыль за период (реализации - оплаты)
$profit_period = $sales_period_amount - $payments_period_amount;

// Функции для отображения роли
function getRoleName($role)
{
    $names = [
        'admin' => 'Директор',
        'manager' => 'Менеджер',
        'warehouse_keeper' => 'Кладовщик',
        'accountant' => 'Бухгалтер',
        'customer' => 'Покупатель'
    ];
    return $names[$role] ?? $role;
}

// Получаем все операции за период
$operations = $pdo->prepare("
    SELECT ao.*, 
           c.contract_number,
           c.customer_id,
           cust.name as customer_name
    FROM accounting_operations ao
    LEFT JOIN contracts c ON ao.contract_id = c.id
    LEFT JOIN customers cust ON c.customer_id = cust.id
    WHERE ao.operation_date BETWEEN ? AND ?
    ORDER BY ao.operation_date DESC, ao.created_at DESC
");
$operations->execute([$start_date, $end_date]);
$operations = $operations->fetchAll(PDO::FETCH_ASSOC);

// Функция для форматирования дат для отображения
function getPeriodDisplayText($period, $start_date, $end_date)
{
    switch ($period) {
        case 'day':
            return date('d.m.Y', strtotime($start_date));
        case 'week':
            return date('d.m.Y', strtotime($start_date)) . ' - ' . date('d.m.Y', strtotime($end_date));
        case 'month':
            return date('F Y', strtotime($start_date));
        case 'year':
            return date('Y', strtotime($start_date));
        case 'custom':
            return date('d.m.Y', strtotime($start_date)) . ' - ' . date('d.m.Y', strtotime($end_date));
        default:
            return 'За всё время';
    }
}

// Получаем движение товара за период
$stock_movements = $pdo->prepare("
    SELECT 
        wm.*,
        p.name as product_name,
        p.unit,
        c.contract_number,
        cust.name as customer_name,
        COALESCE(wb.box_number, '—') as box_number
    FROM warehouse_movements wm
    JOIN products p ON wm.product_id = p.id
    LEFT JOIN contracts c ON wm.contract_id = c.id
    LEFT JOIN customers cust ON c.customer_id = cust.id
    LEFT JOIN warehouse_boxes wb ON wm.box_id = wb.id
    WHERE wm.document_date BETWEEN ? AND ?
    ORDER BY wm.document_date DESC, wm.created_at DESC
");
$stock_movements->execute([$start_date, $end_date]);
$stock_movements = $stock_movements->fetchAll(PDO::FETCH_ASSOC);

// Получаем дебиторскую задолженность (актуальную на сегодня)
$debt_data = $pdo->query("
    SELECT 
        c.id as contract_id,
        c.contract_number,
        c.valid_from,
        c.payment_deadline,
        cust.name as customer_name,
        c.total_amount,
        COALESCE(SUM(CASE WHEN ao.operation_type = 'оплата' THEN ao.amount ELSE 0 END), 0) as paid_amount,
        c.total_amount - COALESCE(SUM(CASE WHEN ao.operation_type = 'оплата' THEN ao.amount ELSE 0 END), 0) as debt_amount
    FROM contracts c
    JOIN customers cust ON c.customer_id = cust.id
    LEFT JOIN accounting_operations ao ON c.id = ao.contract_id
    WHERE c.status IN ('подписан', 'отгружен', 'в боксе')
    GROUP BY c.id, c.contract_number, c.valid_from, c.payment_deadline, cust.name, c.total_amount
    HAVING debt_amount > 0
    ORDER BY c.payment_deadline ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Разделяем на текущую и просроченную задолженность
$current_debts = [];
$overdue_debts = [];
$total_current_debt = 0;
$total_overdue_debt = 0;

foreach ($debt_data as $debt) {
    $deadline = new DateTime($debt['payment_deadline']);
    $today = new DateTime();
    $days_overdue = $today > $deadline ? $deadline->diff($today)->days : 0;

    if ($days_overdue > 0) {
        $overdue_debts[] = $debt;
        $total_overdue_debt += $debt['debt_amount'];
    } else {
        $current_debts[] = $debt;
        $total_current_debt += $debt['debt_amount'];
    }
}

// Аналитика жалоб
$complaints_stats = $pdo->query("
    SELECT 
        COUNT(*) as total,
        COUNT(CASE WHEN status = 'открыта' THEN 1 END) as open,
        COUNT(CASE WHEN status = 'в работе' THEN 1 END) as in_progress,
        COUNT(CASE WHEN status = 'решена' THEN 1 END) as resolved,
        COUNT(CASE WHEN status = 'отклонена' THEN 1 END) as rejected
    FROM complaints
")->fetch(PDO::FETCH_ASSOC);

// Аналитика удовлетворённости
$feedback_analysis = $pdo->query("
    SELECT 
        COUNT(*) as total_feedbacks,
        AVG(rating) as avg_rating,
        SUM(CASE WHEN rating = 5 THEN 1 ELSE 0 END) as rating_5,
        SUM(CASE WHEN rating = 4 THEN 1 ELSE 0 END) as rating_4,
        SUM(CASE WHEN rating = 3 THEN 1 ELSE 0 END) as rating_3,
        SUM(CASE WHEN rating = 2 THEN 1 ELSE 0 END) as rating_2,
        SUM(CASE WHEN rating = 1 THEN 1 ELSE 0 END) as rating_1
    FROM customer_feedbacks
")->fetch(PDO::FETCH_ASSOC);

// Получаем продажи по товарам за период
$sales_by_product = $pdo->prepare("
    SELECT 
        p.id,
        p.name AS product_name,
        p.unit,
        SUM(ci.quantity) AS total_quantity,
        SUM(ci.quantity * ci.price) AS total_amount
    FROM contract_items ci
    JOIN products p ON ci.product_id = p.id
    JOIN contracts c ON ci.contract_id = c.id
    WHERE c.status IN ('отгружен', 'подписан')
    AND c.contract_date BETWEEN ? AND ?
    GROUP BY p.id, p.name, p.unit
    ORDER BY total_quantity DESC
");
$sales_by_product->execute([$start_date, $end_date]);
$sales_by_product = $sales_by_product->fetchAll(PDO::FETCH_ASSOC);

$total_units_sold = array_sum(array_column($sales_by_product, 'total_quantity'));

// Анализ по покупателям за период
$customer_analysis = $pdo->prepare("
    SELECT 
        cust.id,
        cust.name as customer_name,
        COUNT(DISTINCT c.id) as contracts_count,
        COUNT(DISTINCT CASE WHEN c.status = 'подписан' THEN c.id END) as completed_contracts,
        COALESCE(SUM(CASE WHEN ao.operation_type = 'реализация' THEN ao.amount ELSE 0 END), 0) as total_sales,
        COALESCE(SUM(CASE WHEN ao.operation_type = 'оплата' THEN ao.amount ELSE 0 END), 0) as total_paid,
        COALESCE(
            SUM(CASE WHEN ao.operation_type = 'реализация' THEN ao.amount ELSE 0 END) -
            SUM(CASE WHEN ao.operation_type = 'оплата' THEN ao.amount ELSE 0 END)
        , 0) as debt,
        COUNT(DISTINCT comp.id) as complaints
    FROM customers cust
    LEFT JOIN contracts c ON cust.id = c.customer_id
    LEFT JOIN accounting_operations ao ON c.id = ao.contract_id AND ao.operation_date BETWEEN ? AND ?
    LEFT JOIN complaints comp ON c.id = comp.contract_id
    GROUP BY cust.id, cust.name
    HAVING total_sales > 0 OR contracts_count > 0
    ORDER BY total_sales DESC
");
$customer_analysis->execute([$start_date, $end_date]);
$customer_analysis = $customer_analysis->fetchAll(PDO::FETCH_ASSOC);

function formatAmount($amount)
{
    return number_format($amount, 2, ',', ' ') . ' ₽';
}

function getSatisfactionStars($rating)
{
    $stars = '';
    for ($i = 1; $i <= 5; $i++) {
        if ($i <= floor($rating)) {
            $stars .= '<i class="bi bi-star-fill" style="color: #ffc107; font-size: 1rem;"></i>';
        } elseif ($i - 0.5 <= $rating) {
            $stars .= '<i class="bi bi-star-half" style="color: #ffc107; font-size: 1rem;"></i>';
        } else {
            $stars .= '<i class="bi bi-star" style="color: #ffc107; font-size: 1rem;"></i>';
        }
    }
    return $stars;
}

function getMovementTypeBadge($type)
{
    switch ($type) {
        case 'приход':
            return '<span class="badge bg-success"><i class="bi bi-arrow-down-circle"></i> Приход</span>';
        case 'расход':
            return '<span class="badge bg-danger"><i class="bi bi-arrow-up-circle"></i> Расход</span>';
        default:
            return '<span class="badge bg-secondary">' . $type . '</span>';
    }
}
?>
<!DOCTYPE html>
<html lang="ru">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Учёт и аналитика | Буратино</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body {
            background-color: #f8f9fa;
        }

        .navbar {
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }

        .nav-link.active {
            color: #2ecc71 !important;
            font-weight: 600;
        }

        .page-header {
            border-bottom: 2px solid #2ecc71;
            margin-bottom: 1.5rem;
            padding-bottom: 0.75rem;
        }

        .kpi-card {
            background: white;
            border-radius: 15px;
            padding: 20px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.05);
            height: 100%;
            border-left: 4px solid #2ecc71;
            transition: transform 0.2s;
        }

        .kpi-card:hover {
            transform: translateY(-3px);
        }

        .kpi-title {
            font-size: 14px;
            color: #6c757d;
            text-transform: uppercase;
        }

        .kpi-value {
            font-size: 24px;
            font-weight: bold;
            margin: 10px 0;
        }

        .chart-container {
            background: white;
            border-radius: 15px;
            padding: 20px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.05);
            margin-bottom: 20px;
        }

        .debt-positive {
            color: #e74c3c !important;
        }

        .table-container {
            background: white;
            border-radius: 15px;
            padding: 20px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.05);
            margin-bottom: 20px;
        }

        .period-selector {
            background: white;
            border-radius: 15px;
            padding: 15px 20px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.05);
            margin-bottom: 20px;
        }

        .period-btn {
            margin-right: 5px;
            margin-bottom: 5px;
        }

        .period-btn.active {
            background: linear-gradient(135deg, #2ecc71, #27ae60);
            color: white;
            border-color: #2ecc71;
        }

        .role-badge-custom {
            display: inline-block;
            background-color: #2ecc71;
            color: white;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 500;
            margin-left: 8px;
            border: 1px solid rgba(255, 255, 255, 0.3);
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        }

        .profit-positive {
            color: #27ae60;
        }

        .profit-negative {
            color: #e74c3c;
        }

        .doc-link {
            text-decoration: none;
            cursor: pointer;
        }

        .doc-link:hover {
            text-decoration: underline;
        }
    </style>
</head>

<body>

    <!-- Навигационная панель -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark mb-4">
        <div class="container-fluid">
            <a class="navbar-brand" href="#">
                <i class="bi bi-tree-fill me-2" style="color: #2ecc71;"></i>Буратино
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav me-auto">
                    <?php if ($current_user['role'] == 'customer'): ?>
                        <li class="nav-item"><a class="nav-link" href="catalog.php"><i
                                    class="bi bi-box-seam me-1"></i>Каталог</a></li>
                    <?php endif; ?>
                    <?php if (!in_array($current_user['role'], ['warehouse_keeper', 'accountant'])): ?>
                        <li class="nav-item"><a class="nav-link" href="contracts.php"><i
                                    class="bi bi-file-text-fill me-1"></i>Договоры</a></li>
                    <?php endif; ?>
                    <?php if (in_array($current_user['role'], ['admin', 'manager'])): ?>
                        <li class="nav-item"><a class="nav-link" href="clients.php"><i
                                    class="bi bi-people-fill me-1"></i>Клиенты</a></li>
                    <?php endif; ?>
                    <?php if (in_array($current_user['role'], ['admin', 'warehouse_keeper'])): ?>
                        <li class="nav-item"><a class="nav-link" href="warehouse.php"><i
                                    class="bi bi-shop me-1"></i>Склад</a></li>
                    <?php endif; ?>
                    <?php if (in_array($current_user['role'], ['admin', 'accountant'])): ?>
                        <li class="nav-item"><a class="nav-link active" href="accounting.php"><i
                                    class="bi bi-calculator-fill me-1"></i>Учёт</a></li>
                    <?php endif; ?>
                    <?php if (in_array($current_user['role'], ['admin', 'manager'])): ?>
                        <li class="nav-item"><a class="nav-link" href="complaints_admin.php"><i
                                    class="bi bi-exclamation-triangle-fill me-1"></i>Жалобы</a></li>
                    <?php endif; ?>
                </ul>
                <div class="d-flex align-items-center gap-2">
                    <div class="text-white me-2">
                        <i class="bi bi-person-circle me-1"></i>
                        <span><?= htmlspecialchars($current_user['email']) ?></span>
                        <span class="role-badge-custom"><?= getRoleName($current_user['role']) ?></span>
                    </div>
                    <a href="logout.php" class="btn btn-outline-light btn-sm"><i class="bi bi-box-arrow-right"></i></a>
                </div>
            </div>
        </div>
    </nav>

    <main class="container-fluid px-4">

        <div class="page-header d-flex justify-content-between align-items-center">
            <div>
                <h2 class="mb-1"><i class="bi bi-calculator-fill me-2" style="color: #2ecc71;"></i>Бухгалтерский учёт и
                    аналитика</h2>
                <p class="text-muted small">Ключевые показатели эффективности (KPI) и финансовый анализ</p>
            </div>
        </div>

        <!-- Выбор периода -->
        <div class="period-selector">
            <div class="row align-items-center">
                <div class="col-md-3 mb-2 mb-md-0">
                    <label class="fw-bold"><i class="bi bi-calendar-week me-2"></i>Период:</label>
                </div>
                <div class="col-md-9">
                    <div class="btn-group flex-wrap" role="group">
                        <a href="?period=day"
                            class="btn btn-outline-secondary period-btn <?= $period == 'day' ? 'active' : '' ?>">День</a>
                        <a href="?period=week"
                            class="btn btn-outline-secondary period-btn <?= $period == 'week' ? 'active' : '' ?>">Неделя</a>
                        <a href="?period=month"
                            class="btn btn-outline-secondary period-btn <?= $period == 'month' ? 'active' : '' ?>">Месяц</a>
                        <a href="?period=year"
                            class="btn btn-outline-secondary period-btn <?= $period == 'year' ? 'active' : '' ?>">Год</a>
                        <button type="button" class="btn btn-outline-secondary period-btn" data-bs-toggle="collapse"
                            data-bs-target="#customDateCollapse">
                            Произвольный
                        </button>
                    </div>

                    <div class="collapse mt-3 <?= $period == 'custom' ? 'show' : '' ?>" id="customDateCollapse">
                        <form method="GET" action="" class="row g-2 align-items-end">
                            <input type="hidden" name="period" value="custom">
                            <div class="col-auto">
                                <label class="form-label small">с</label>
                                <input type="date" name="start_date" class="form-control form-control-sm"
                                    value="<?= $custom_start ?: date('Y-m-01') ?>">
                            </div>
                            <div class="col-auto">
                                <label class="form-label small">по</label>
                                <input type="date" name="end_date" class="form-control form-control-sm"
                                    value="<?= $custom_end ?: date('Y-m-d') ?>">
                            </div>
                            <div class="col-auto">
                                <button type="submit" class="btn btn-sm btn-primary">Применить</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            <div class="mt-2 text-muted small">
                <i class="bi bi-info-circle me-1"></i>
                Период: <?= getPeriodDisplayText($period, $start_date, $end_date) ?>
            </div>
        </div>

        <!-- KPI Cards -->
        <div class="row mb-4">
            <div class="col-md-4 mb-3">
                <div class="kpi-card">
                    <div class="kpi-title">Объём продаж</div>
                    <div class="kpi-value text-success"><?= formatAmount($sales_period_amount) ?></div>
                    <small class="text-muted">за выбранный период</small>
                </div>
            </div>
            <div class="col-md-4 mb-3">
                <div class="kpi-card">
                    <div class="kpi-title">Поступило оплат</div>
                    <div class="kpi-value text-primary"><?= formatAmount($payments_period_amount) ?></div>
                    <small class="text-muted">за выбранный период</small>
                </div>
            </div>
            <div class="col-md-4 mb-3">
                <div class="kpi-card">
                    <div class="kpi-title">Прибыль</div>
                    <div class="kpi-value <?= $profit_period >= 0 ? 'profit-positive' : 'profit-negative' ?>">
                        <?= formatAmount($profit_period) ?>
                    </div>
                    <small class="text-muted">за выбранный период</small>
                </div>
            </div>
        </div>

        <div class="row mb-4">
            <div class="col-md-4 mb-3">
                <div class="kpi-card">
                    <div class="kpi-title">Текущая задолженность</div>
                    <div class="kpi-value text-warning"><?= formatAmount($total_current_debt) ?></div>
                    <small class="text-muted">долг без просрочки</small>
                </div>
            </div>
            <div class="col-md-4 mb-3">
                <div class="kpi-card">
                    <div class="kpi-title">Просроченная задолженность</div>
                    <div class="kpi-value text-danger"><?= formatAmount($total_overdue_debt) ?></div>
                    <small class="text-muted">требует взыскания</small>
                </div>
            </div>
            <div class="col-md-4 mb-3">
                <div class="kpi-card">
                    <div class="kpi-title">Оценка удовлетворённости качеством</div>
                    <div class="kpi-value">
                        <?php
                        $avg_rating = round($feedback_analysis['avg_rating'] ?? 0, 1);
                        echo getSatisfactionStars($avg_rating);
                        ?>
                        <span style="font-size: 1rem;">(<?= $avg_rating ?>)</span>
                    </div>
                    <small class="text-muted">На основе <?= $feedback_analysis['total_feedbacks'] ?? 0 ?> оценок</small>
                </div>
            </div>
        </div>

        <!-- Графики -->
        <div class="row">
            <div class="col-md-6">
                <div class="chart-container">
                    <h5 class="mb-3"><i class="bi bi-pie-chart me-2" style="color: #2ecc71;"></i>Структура задолженности
                    </h5>
                    <canvas id="debtChart" style="max-height: 300px;"></canvas>
                </div>
            </div>
            <div class="col-md-6">
                <div class="chart-container">
                    <h5 class="mb-3"><i class="bi bi-bar-chart me-2" style="color: #2ecc71;"></i>Топ-5 покупателей (за
                        период)</h5>
                    <canvas id="customersChart" style="max-height: 300px;"></canvas>
                </div>
            </div>
        </div>

        <!-- Движение товара -->
        <div class="table-container">
            <h5 class="mb-3"><i class="bi bi-arrow-left-right me-2" style="color: #2ecc71;"></i>Движение товара (за
                период)</h5>
            <div class="table-responsive">
                <table id="movementsTable" class="table table-hover">
                    <thead class="table-light">
                        <tr>
                            <th>Дата</th>
                            <th>Тип операции</th>
                            <th>Товар</th>
                            <th>Кол-во</th>
                            <th>Ед. изм.</th>
                            <th>Документ</th>
                            <th>Договор</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($stock_movements as $movement): ?>
                            <tr>
                                <td><?= date('d.m.Y', strtotime($movement['document_date'])) ?></td>
                                <td><?= getMovementTypeBadge($movement['movement_type']) ?></td>
                                <td><?= htmlspecialchars($movement['product_name']) ?></td>
                                <td class="fw-bold"><?= number_format($movement['quantity'], 0, ',', ' ') ?></td>
                                <td><?= htmlspecialchars($movement['unit']) ?></td>
                                <td>
                                    <?php if ($movement['document_number']): ?>
                                        <a href="generate_stock_document.php?id=<?= $movement['id'] ?>&type=<?= $movement['movement_type'] ?>"
                                            target="_blank" class="doc-link text-primary">
                                            <i
                                                class="bi bi-file-text me-1"></i><?= $movement['movement_type'] == 'приход' ? 'Приходный ордер' : 'Расходная накладная' ?>
                                            №<?= htmlspecialchars($movement['document_number']) ?>
                                        </a>
                                    <?php else: ?>
                                        —
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($movement['contract_number']): ?>
                                        <?= htmlspecialchars($movement['contract_number']) ?>
                                    <?php else: ?>
                                        —
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Анализ по покупателям за период -->
        <div class="table-container">
            <h5 class="mb-3"><i class="bi bi-people me-2" style="color: #2ecc71;"></i>Анализ по покупателям (за период)
            </h5>
            <div class="table-responsive">
                <table id="customersTable" class="table table-hover">
                    <thead class="table-light">
                        <tr>
                            <th>Покупатель</th>
                            <th>Договоров</th>
                            <th>Выполнено</th>
                            <th>Продажи</th>
                            <th>Оплачено</th>
                            <th>Претензии</th>
                            <th>% оплат</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($customer_analysis as $cust):
                            $payment_percent = $cust['total_sales'] > 0 ? round(($cust['total_paid'] / $cust['total_sales']) * 100) : 0;
                            ?>
                            <tr>
                                <td><?= htmlspecialchars($cust['customer_name']) ?></td>
                                <td><?= $cust['contracts_count'] ?></td>
                                <td><?= $cust['completed_contracts'] ?></td>
                                <td class="text-success"><?= formatAmount($cust['total_sales']) ?></td>
                                <td class="text-success"><?= formatAmount($cust['total_paid']) ?></td>
                                <td>
                                    <?php if ($cust['complaints'] > 0): ?>
                                        <span class="badge bg-danger"><?= $cust['complaints'] ?></span>
                                    <?php else: ?>
                                        <span class="badge bg-success">0</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="progress" style="height: 20px;">
                                        <div class="progress-bar bg-success" style="width: <?= $payment_percent ?>%;">
                                            <?= $payment_percent ?>%
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Продажи по товарам за период -->
        <div class="table-container">
            <h5 class="mb-3"><i class="bi bi-box-seam me-2" style="color: #2ecc71;"></i>Продажи по товарам (за период)
            </h5>
            <div class="table-responsive">
                <table id="productsSalesTable" class="table table-hover">
                    <thead class="table-light">
                        <tr>
                            <th>Товар</th>
                            <th>Ед. изм.</th>
                            <th>Продано (кол-во)</th>
                            <th>Сумма (₽)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($sales_by_product as $product): ?>
                            <tr>
                                <td><?= htmlspecialchars($product['product_name']) ?></td>
                                <td><?= htmlspecialchars($product['unit']) ?></td>
                                <td class="fw-bold"><?= number_format($product['total_quantity'], 0, ',', ' ') ?></td>
                                <td><?= number_format($product['total_amount'], 2, ',', ' ') ?> ₽</td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot class="table-light">
                        <tr class="fw-bold">
                            <td colspan="2">ИТОГО:</td>
                            <td><?= number_format($total_units_sold, 0, ',', ' ') ?> ед.</td>
                            <td><?= number_format(array_sum(array_column($sales_by_product, 'total_amount')), 2, ',', ' ') ?>
                                ₽</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

        <!-- Все операции за период -->
        <div class="table-container">
            <h5 class="mb-3"><i class="bi bi-list-columns me-2" style="color: #2ecc71;"></i>Все операции (за период)
            </h5>
            <div class="table-responsive">
                <table id="operationsTable" class="table table-hover">
                    <thead class="table-light">
                        <tr>
                            <th>Дата</th>
                            <th>Тип</th>
                            <th>Договор</th>
                            <th>Покупатель</th>
                            <th>Сумма</th>
                            <th>Документ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($operations as $op): ?>
                            <tr>
                                <td><?= date('d.m.Y', strtotime($op['operation_date'])) ?></td>
                                <td>
                                    <?php if ($op['operation_type'] == 'реализация'): ?>
                                        <span class="badge bg-success">Реализация</span>
                                    <?php elseif ($op['operation_type'] == 'оплата'): ?>
                                        <span class="badge bg-primary">Оплата</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary"><?= $op['operation_type'] ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars($op['contract_number'] ?? '—') ?></td>
                                <td><?= htmlspecialchars($op['customer_name'] ?? '—') ?></td>
                                <td class="fw-bold"><?= formatAmount($op['amount']) ?></td>
                                <td>
                                    <?php if ($op['operation_type'] == 'оплата' && $op['document_number']): ?>
                                        <a href="#" class="doc-link text-primary"
                                            onclick="showPaymentDocument('<?= htmlspecialchars($op['document_number']) ?>', '<?= formatAmount($op['amount']) ?>', '<?= htmlspecialchars($op['contract_number'] ?? '—') ?>')">
                                            <i class="bi bi-receipt me-1"></i><?= htmlspecialchars($op['document_number']) ?>
                                        </a>
                                    <?php elseif ($op['operation_type'] == 'реализация' && $op['contract_id']): ?>
                                        <a href="generate_documents.php?id=<?= $op['contract_id'] ?>&type=invoice"
                                            target="_blank" class="doc-link text-primary">
                                            <i class="bi bi-file-pdf me-1"></i>Счет-фактура
                                        </a>
                                    <?php else: ?>
                                        <?= htmlspecialchars($op['document_number'] ?? '—') ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </main>

    <!-- Модальное окно платежного документа -->
    <div class="modal fade" id="paymentDocumentModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title"><i class="bi bi-receipt me-2"></i>Платёжный документ</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="paymentDocumentContent">
                    <!-- Содержимое будет загружено через JS -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Закрыть</button>
                    <button type="button" class="btn btn-success" id="printPaymentDocBtn"><i
                            class="bi bi-printer me-1"></i>Печать</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <script>
        $(document).ready(function () {
            $('#operationsTable').DataTable({
                language: { url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/ru.json' },
                pageLength: 10,
                order: [[0, 'desc']]
            });
            $('#customersTable').DataTable({
                language: { url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/ru.json' },
                pageLength: 10,
                order: [[3, 'desc']]
            });
            $('#productsSalesTable').DataTable({
                language: { url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/ru.json' },
                pageLength: 10,
                order: [[2, 'desc']]
            });
            $('#movementsTable').DataTable({
                language: { url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/ru.json' },
                pageLength: 15,
                order: [[0, 'desc']]
            });
        });

        // График задолженности
        const debtCtx = document.getElementById('debtChart').getContext('2d');
        new Chart(debtCtx, {
            type: 'doughnut',
            data: {
                labels: ['Текущая задолженность', 'Просроченная задолженность'],
                datasets: [{
                    data: [<?= $total_current_debt ?>, <?= $total_overdue_debt ?>],
                    backgroundColor: ['#f39c12', '#e74c3c'],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                plugins: { legend: { position: 'bottom' } }
            }
        });

        // График топ-5 покупателей
        const customersCtx = document.getElementById('customersChart').getContext('2d');
        const topCustomers = <?= json_encode(array_slice($customer_analysis, 0, 5)) ?>;
        new Chart(customersCtx, {
            type: 'bar',
            data: {
                labels: topCustomers.map(c => c.customer_name.substring(0, 15) + (c.customer_name.length > 15 ? '...' : '')),
                datasets: [{
                    label: 'Объём продаж',
                    data: topCustomers.map(c => c.total_sales),
                    backgroundColor: '#2ecc71',
                    borderRadius: 5
                }]
            },
            options: {
                responsive: true,
                plugins: { legend: { display: false } },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function (value) {
                                return value.toLocaleString() + ' ₽';
                            }
                        }
                    }
                }
            }
        });

        // Функция показа платежного документа
        function showPaymentDocument(docNumber, amount, contractNumber) {
            const formattedAmount = amount;
            const content = `
                <div style="font-family: 'Times New Roman', serif;">
                    <div style="text-align: center; margin-bottom: 20px;">
                        <h3>ПЛАТЁЖНОЕ ПОРУЧЕНИЕ №${docNumber}</h3>
                        <p>от "${new Date().toLocaleDateString('ru-RU')}"</p>
                    </div>
                    <table style="width: 100%; border-collapse: collapse; margin-bottom: 20px; border: 1px solid #000;">
                        <tr style="border: 1px solid #000;">
                            <td style="padding: 8px; border: 1px solid #000; width: 30%;"><strong>ИНН</strong></td>
                            <td style="padding: 8px; border: 1px solid #000;" colspan="3">7842123456</td>
                        </tr>
                        <tr style="border: 1px solid #000;">
                            <td style="padding: 8px; border: 1px solid #000;"><strong>КПП</strong></td>
                            <td style="padding: 8px; border: 1px solid #000;" colspan="3">784201001</td>
                        </tr>
                        <tr style="border: 1px solid #000;">
                            <td style="padding: 8px; border: 1px solid #000;"><strong>Плательщик</strong></td>
                            <td style="padding: 8px; border: 1px solid #000;" colspan="3">ООО "Буратино"</td>
                         </tr>
                        <tr style="border: 1px solid #000;">
                            <td style="padding: 8px; border: 1px solid #000;"><strong>Счёт плательщика</strong></td>
                            <td style="padding: 8px; border: 1px solid #000;" colspan="3">40702810123450009999</td>
                         </tr>
                        <tr style="border: 1px solid #000;">
                            <td style="padding: 8px; border: 1px solid #000;"><strong>Банк плательщика</strong></td>
                            <td style="padding: 8px; border: 1px solid #000;" colspan="3">АО "Альфа-Банк" г. Москва</td>
                         </tr>
                        <tr style="border: 1px solid #000;">
                            <td style="padding: 8px; border: 1px solid #000;"><strong>БИК</strong></td>
                            <td style="padding: 8px; border: 1px solid #000;" colspan="3">044525593</td>
                         </tr>
                        <tr style="border: 1px solid #000;">
                            <td style="padding: 8px; border: 1px solid #000;"><strong>Счёт получателя</strong></td>
                            <td style="padding: 8px; border: 1px solid #000;" colspan="3">40702810123450001234</td>
                         </tr>
                        <tr style="border: 1px solid #000;">
                            <td style="padding: 8px; border: 1px solid #000;"><strong>Получатель</strong></td>
                            <td style="padding: 8px; border: 1px solid #000;" colspan="3">По договору №${contractNumber}</td>
                         </tr>
                        <tr style="border: 1px solid #000; background-color: #f5f5f5;">
                            <td style="padding: 8px; border: 1px solid #000;"><strong>Сумма</strong></td>
                            <td style="padding: 8px; border: 1px solid #000;" colspan="3"><strong class="text" style="font-size: 1.2rem;">${formattedAmount}</strong></td>
                         </tr>
                        <tr style="border: 1px solid #000;">
                            <td style="padding: 8px; border: 1px solid #000;"><strong>Назначение платежа</strong></td>
                            <td style="padding: 8px; border: 1px solid #000;" colspan="3">Оплата по договору №${contractNumber}</td>
                         </tr>
                    </table>
                    <div style="margin-top: 30px;">
                        <div style="display: flex; justify-content: space-between;">
                            <div>
                                <p>Руководитель организации<br>_________________ И.И. Иванов</p>
                            </div>
                            <div>
                                <p>Главный бухгалтер<br>_________________ Е.В. Соколова</p>
                            </div>
                        </div>
                    </div>
                    <div style="margin-top: 20px; text-align: center;">
                        <p><small class="text-muted">Документ сформирован автоматически</small></p>
                    </div>
                </div>
            `;
            document.getElementById('paymentDocumentContent').innerHTML = content;
            new bootstrap.Modal(document.getElementById('paymentDocumentModal')).show();
        }

        // Печать платежного документа
        document.getElementById('printPaymentDocBtn').addEventListener('click', function () {
            const printContent = document.getElementById('paymentDocumentContent').innerHTML;
            const printWindow = window.open('', '_blank');
            printWindow.document.write(`
                <!DOCTYPE html>
                <html>
                <head>
                    <title>Платёжное поручение</title>
                    <meta charset="utf-8">
                    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
                    <style>
                        body { padding: 30px; font-family: 'Times New Roman', Times, serif; }
                        table { width: 100%; border-collapse: collapse; }
                        td { padding: 8px; border: 1px solid #000; }
                        @media print {
                            body { margin: 0; padding: 20px; }
                            .btn { display: none; }
                        }
                    </style>
                </head>
                <body>${printContent}</body>
                </html>
            `);
            printWindow.document.close();
            printWindow.print();
        });
    </script>

</body>

</html>