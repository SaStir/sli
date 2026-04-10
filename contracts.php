<?php
// contracts.php - Управление договорами с учётом предоплаты
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

// Функции для ролей
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

function getRoleColor($role)
{
    $colors = [
        'admin' => '#e74c3c',
        'manager' => '#3498db',
        'warehouse_keeper' => '#f39c12',
        'accountant' => '#9b59b6',
        'customer' => '#2ecc71'
    ];
    return $colors[$role] ?? '#6c757d';
}

// Обработка оплаты заказа (для клиентов)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'pay') {
    header('Content-Type: application/json');

    try {
        $contract_id = $_POST['contract_id'];
        // Преобразуем сумму оплаты - заменяем запятую на точку
        $payment_amount = str_replace(',', '.', $_POST['amount']);
        $payment_amount = floatval($payment_amount);
        $payment_method = $_POST['payment_method'] ?? 'безналичные';
        $payment_doc = $_POST['payment_document'] ?? 'Платёжное поручение №' . rand(100, 999);

        // Получаем информацию о договоре
        $stmt = $pdo->prepare("SELECT * FROM contracts WHERE id = ?");
        $stmt->execute([$contract_id]);
        $contract = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$contract) {
            echo json_encode(['success' => false, 'message' => 'Договор не найден']);
            exit;
        }

        // Проверяем, что заказ отгружен
        if ($contract['status'] !== 'отгружен') {
            echo json_encode(['success' => false, 'message' => 'Оплатить можно только отгруженный товар']);
            exit;
        }

        // Проверяем права
        if (!$current_user['is_admin'] && $contract['customer_id'] != $current_user['customer_id']) {
            echo json_encode(['success' => false, 'message' => 'У вас нет прав для оплаты этого заказа']);
            exit;
        }

        // Получаем уже оплаченную сумму
        $paid_stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) as total_paid FROM accounting_operations WHERE contract_id = ? AND operation_type = 'оплата'");
        $paid_stmt->execute([$contract_id]);
        $total_paid = $paid_stmt->fetchColumn();

        // Рассчитываем остаток к оплате с учётом предоплаты
        $prepayment_amount = floatval($contract['prepayment_amount'] ?? 0);
        $amount_to_pay = $contract['total_amount'] - $prepayment_amount;
        $remaining = $amount_to_pay - ($total_paid - $prepayment_amount);
        if ($remaining < 0)
            $remaining = 0;

        // Округляем до 2 знаков для сравнения
        $payment_amount = round($payment_amount, 2);
        $remaining = round($remaining, 2);

        if ($payment_amount <= 0) {
            echo json_encode(['success' => false, 'message' => 'Сумма оплаты должна быть больше 0']);
            exit;
        }

        if ($payment_amount > $remaining) {
            echo json_encode(['success' => false, 'message' => 'Сумма превышает остаток. Остаток: ' . number_format($remaining, 2, ',', ' ') . ' ₽']);
            exit;
        }

        $pdo->beginTransaction();

        // Добавляем запись об оплате
        $stmt = $pdo->prepare("INSERT INTO accounting_operations 
            (contract_id, operation_date, operation_type, amount, payment_type, document_number, comment) 
            VALUES (?, NOW(), 'оплата', ?, ?, ?, ?)");

        $stmt->execute([
            $contract_id,
            $payment_amount,
            $payment_method,
            $payment_doc,
            'Оплата по договору №' . $contract['contract_number']
        ]);

        $pdo->commit();

        echo json_encode([
            'success' => true,
            'message' => 'Оплата на сумму ' . number_format($payment_amount, 2, ',', ' ') . ' ₽ успешно проведена'
        ]);

    } catch (Exception $e) {
        if ($pdo->inTransaction())
            $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}
// Обработка подтверждения договора (для админа)
// Обработка подтверждения / отказа договора (для админа)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $current_user['is_admin'] && $_POST['action'] === 'update_status') {
    header('Content-Type: application/json');

    try {
        $contract_id = $_POST['contract_id'];
        $new_status = $_POST['status'];
        $reject_reason = $_POST['reject_reason'] ?? null;

        $stmt = $pdo->prepare("SELECT * FROM contracts WHERE id = ?");
        $stmt->execute([$contract_id]);
        $contract = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$contract) {
            echo json_encode(['success' => false, 'message' => 'Договор не найден']);
            exit;
        }

        if ($new_status === 'подписан') {
            $pdo->beginTransaction();

            $update = $pdo->prepare("UPDATE contracts SET status = ? WHERE id = ?");
            $update->execute(['подписан', $contract_id]);

            // Запись в историю
            $history = $pdo->prepare("INSERT INTO contract_status_history (contract_id, status, changed_by, comment) VALUES (?, 'подписан', ?, 'Договор подтверждён')");
            $history->execute([$contract_id, $current_user['id']]);

            $prepayment_amount = floatval($contract['prepayment_amount'] ?? 0);
            if ($prepayment_amount > 0) {
                $check_payment = $pdo->prepare("SELECT COUNT(*) FROM accounting_operations WHERE contract_id = ? AND operation_type = 'оплата'");
                $check_payment->execute([$contract_id]);
                $payment_exists = $check_payment->fetchColumn();

                if (!$payment_exists) {
                    $stmt = $pdo->prepare("INSERT INTO accounting_operations 
                        (contract_id, operation_date, operation_type, amount, payment_type, document_number, comment) 
                        VALUES (?, NOW(), 'оплата', ?, 'безналичные', ?, ?)");
                    $stmt->execute([
                        $contract_id,
                        $prepayment_amount,
                        'ПРЕДОПЛАТА-' . $contract['contract_number'],
                        'Предоплата по договору №' . $contract['contract_number']
                    ]);
                }
            }

            $pdo->commit();
            echo json_encode(['success' => true, 'message' => 'Договор подтверждён' . ($prepayment_amount > 0 ? ' Предоплата учтена!' : '')]);

        } elseif ($new_status === 'отказ') {
            if (empty($reject_reason)) {
                echo json_encode(['success' => false, 'message' => 'Укажите причину отказа']);
                exit;
            }

            $pdo->beginTransaction();
            $update = $pdo->prepare("UPDATE contracts SET status = 'отказ' WHERE id = ?");
            $update->execute([$contract_id]);

            $history = $pdo->prepare("INSERT INTO contract_status_history (contract_id, status, changed_by, comment) VALUES (?, 'отказ', ?, ?)");
            $history->execute([$contract_id, $current_user['id'], $reject_reason]);

            $pdo->commit();
            echo json_encode(['success' => true, 'message' => 'Договор отклонён']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Неверный статус']);
        }

    } catch (Exception $e) {
        if (isset($pdo) && $pdo->inTransaction())
            $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// Обработка отгрузки товара (админ)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $current_user['is_admin'] && $_POST['action'] === 'ship') {
    header('Content-Type: application/json');

    try {
        $contract_id = $_POST['contract_id'];

        $stmt = $pdo->prepare("SELECT * FROM contracts WHERE id = ? AND status = 'подписан'");
        $stmt->execute([$contract_id]);
        $contract = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$contract) {
            echo json_encode(['success' => false, 'message' => 'Договор не найден или уже отгружен']);
            exit;
        }

        $pdo->beginTransaction();

        // Получаем позиции договора
        $items_stmt = $pdo->prepare("
            SELECT ci.*, p.name as product_name, p.unit 
            FROM contract_items ci
            JOIN products p ON ci.product_id = p.id
            WHERE ci.contract_id = ?
        ");
        $items_stmt->execute([$contract_id]);
        $items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($items as $item) {
            // Проверяем остаток на складе
            $check = $pdo->prepare("SELECT quantity FROM warehouse WHERE product_id = ?");
            $check->execute([$item['product_id']]);
            $available = $check->fetchColumn();

            if ($available < $item['quantity']) {
                throw new Exception("Недостаточно товара на складе: {$item['product_name']}");
            }

            // Списываем со склада
            $update = $pdo->prepare("UPDATE warehouse SET quantity = quantity - ? WHERE product_id = ?");
            $update->execute([$item['quantity'], $item['product_id']]);

            // Добавляем движение в склад
            $movement = $pdo->prepare("INSERT INTO warehouse_movements 
                (product_id, movement_type, quantity, document_type, document_number, document_date, contract_id, comment) 
                VALUES (?, 'расход', ?, 'Отгрузка по договору', ?, NOW(), ?, ?)");
            $movement->execute([
                $item['product_id'],
                $item['quantity'],
                'Отгрузка-' . $contract['contract_number'],
                $contract_id,
                'Отгрузка по договору №' . $contract['contract_number']
            ]);

            // Добавляем запись в бухучёт о реализации
            $accounting = $pdo->prepare("INSERT INTO accounting_operations 
                (contract_id, operation_date, operation_type, amount, payment_type, document_number, comment) 
                VALUES (?, NOW(), 'реализация', ?, 'безналичные', ?, ?)");
            $accounting->execute([
                $contract_id,
                $item['quantity'] * $item['price'],
                'Счет-фактура к договору ' . $contract['contract_number'],
                'Реализация товара'
            ]);
        }

        // Обновляем статус договора
        $update = $pdo->prepare("UPDATE contracts SET status = 'отгружен' WHERE id = ?");
        $update->execute([$contract_id]);

        $pdo->commit();

        echo json_encode(['success' => true, 'message' => 'Товары успешно отгружены со склада']);

    } catch (Exception $e) {
        if ($pdo->inTransaction())
            $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// Получаем список договоров
if ($current_user['is_admin'] || $current_user['role'] == 'manager') {
    $contracts = $pdo->query("
        SELECT c.*, cust.name as customer_name, cust.inn, cust.bank_name, cust.bik, cust.account_number,
               cust.director_name, cust.chief_accountant_name, u.email as manager_email,
               (SELECT COALESCE(SUM(amount), 0) FROM accounting_operations WHERE contract_id = c.id AND operation_type = 'оплата') as total_paid
        FROM contracts c
        JOIN customers cust ON c.customer_id = cust.id
        JOIN users u ON c.created_by = u.id
        ORDER BY c.created_at DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
} else {
    $stmt = $pdo->prepare("
        SELECT c.*, cust.name as customer_name, cust.inn, cust.bank_name, cust.bik, cust.account_number,
               cust.director_name, cust.chief_accountant_name,
               (SELECT COALESCE(SUM(amount), 0) FROM accounting_operations WHERE contract_id = c.id AND operation_type = 'оплата') as total_paid
        FROM contracts c
        JOIN customers cust ON c.customer_id = cust.id
        WHERE c.customer_id = ? OR c.created_by = ?
        ORDER BY c.created_at DESC
    ");
    $stmt->execute([$current_user['customer_id'], $current_user['id']]);
    $contracts = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
// Получаем причины отказа для договоров со статусом "отказ"
$reject_reasons = [];
if (!empty($contracts)) {
    $reject_ids = [];
    foreach ($contracts as $c) {
        if ($c['status'] == 'отказ')
            $reject_ids[] = $c['id'];
    }
    if (!empty($reject_ids)) {
        $placeholders = implode(',', array_fill(0, count($reject_ids), '?'));
        $stmt = $pdo->prepare("
            SELECT h.contract_id, h.comment, h.changed_at 
            FROM contract_status_history h
            WHERE h.contract_id IN ($placeholders) AND h.status = 'отказ'
            ORDER BY h.changed_at DESC
        ");
        $stmt->execute($reject_ids);
        $rejects = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rejects as $rej) {
            $reject_reasons[$rej['contract_id']] = $rej['comment'];
        }
    }
}
// Получаем детали договоров
$contract_items = [];
if (!empty($contracts)) {
    $ids = array_column($contracts, 'id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    $stmt = $pdo->prepare("
        SELECT ci.*, p.name as product_name, p.unit
        FROM contract_items ci
        JOIN products p ON ci.product_id = p.id
        WHERE ci.contract_id IN ($placeholders)
    ");
    $stmt->execute($ids);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($items as $item) {
        $contract_items[$item['contract_id']][] = $item;
    }
}

// Функции для отображения
function getStatusBadge($status)
{
    switch ($status) {
        case 'на подписании':
            return '<span class="badge bg-secondary"><i class="bi bi-pencil"></i> На подписании</span>';
        case 'подписан':
            return '<span class="badge bg-success"><i class="bi bi-check-circle"></i> Подтверждён</span>';
        case 'отгружен':
            return '<span class="badge bg-primary"><i class="bi bi-truck"></i> Отгружен</span>';
        case 'отказ':
            return '<span class="badge bg-danger"><i class="bi bi-x-circle"></i> Отказ</span>';
        case 'в боксе':
            return '<span class="badge bg-info"><i class="bi bi-box-seam"></i> В боксе</span>';
        default:
            return '<span class="badge bg-light text-dark">' . $status . '</span>';
    }
}
function getPaymentStatus($total, $paid, $prepayment = 0)
{
    if ($paid <= 0)
        return 'Не оплачено';
    if ($paid >= $total)
        return 'Оплачено полностью';
    if ($prepayment > 0 && $paid >= $prepayment)
        return 'Предоплата внесена';
    return 'Оплачено частично';
}

function getPaymentClass($total, $paid)
{
    if ($paid <= 0)
        return 'text-danger';
    if ($paid >= $total)
        return 'text-success';
    return 'text-warning';
}

function getPaymentTypeText($type, $percent = null)
{
    switch ($type) {
        case 'full_prepayment':
            return '<span class="text-success">Полная предоплата 100%</span>';
        case 'partial_prepayment':
            return '<span class="text-warning">Частичная предоплата ' . ($percent ?: '0') . '%</span>';
        case 'postpayment':
            return '<span class="text-info">Оплата после отгрузки (отсрочка 10 дней)</span>';
        default:
            return '<span class="text-muted">Не указано</span>';
    }
}

// Функция очистки комментария от автоматически добавленных реквизитов и условий
function cleanOrderComment($comment)
{
    if (empty($comment))
        return '';

    // Ищем первый разделитель --- или "Условия оплаты:" (без учёта регистра)
    $delimiters = ['---', 'Условия оплаты:'];
    $pos = false;
    foreach ($delimiters as $delim) {
        $p = mb_stripos($comment, $delim);
        if ($p !== false) {
            $pos = $p;
            break;
        }
    }
    if ($pos !== false) {
        $comment = trim(mb_substr($comment, 0, $pos));
    }

    // Разбиваем на строки и удаляем строки, содержащие типичные реквизиты или условия оплаты
    $lines = explode("\n", $comment);
    $filtered = [];
    $skipKeywords = [
        'юридическое лицо',
        'инн',
        'банк',
        'бик',
        'р/с',
        'директор',
        'гл. бухгалтер',
        'контактный телефон',
        'условия оплаты',
        'частичная предоплата',
        'полная предоплата',
        'отсрочка',
        'предоплата',
        'остаток оплачивается'
    ];
    foreach ($lines as $line) {
        $lineLower = mb_strtolower(trim($line));
        $skip = false;
        foreach ($skipKeywords as $kw) {
            if (mb_strpos($lineLower, $kw) !== false) {
                $skip = true;
                break;
            }
        }
        if (!$skip && trim($line) !== '') {
            $filtered[] = trim($line);
        }
    }

    $result = implode("\n", $filtered);
    return trim($result);
}
?>
<!DOCTYPE html>
<html lang="ru">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Договоры | Буратино</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
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

        /* Контейнер для карточек договоров — теперь flex-wrap и отступы */
        .contracts-container {
            display: flex;
            flex-direction: column;
            gap: 24px;
        }

        .contract-card {
            background: white;
            border-radius: 15px;
            padding: 20px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.05);
            border-left: 5px solid #2ecc71;
            transition: transform 0.2s;
            /* Убираем margin-bottom, так как gap в контейнере */
            margin-bottom: 0;
            width: 100%;
        }

        .contract-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
        }

        .contract-card.rejected {
            border-left-color: #dc3545;
        }

        .contract-card.draft {
            border-left-color: #6c757d;
        }

        .contract-card.shipped {
            border-left-color: #0d6efd;
        }

        .item-list {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 15px;
            margin-top: 15px;
        }

        .badge {
            padding: 8px 12px;
            font-weight: 500;
        }

        .btn-approve {
            background: linear-gradient(135deg, #2ecc71, #27ae60);
            border: none;
            color: white;
        }

        .btn-approve:hover {
            background: linear-gradient(135deg, #27ae60, #2ecc71);
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(46, 204, 113, 0.3);
        }

        .btn-reject {
            background: linear-gradient(135deg, #e74c3c, #c0392b);
            border: none;
            color: white;
        }

        .btn-reject:hover {
            background: linear-gradient(135deg, #c0392b, #e74c3c);
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(231, 76, 60, 0.3);
        }

        .btn-pay {
            background: linear-gradient(135deg, #2ecc71, #27ae60);
            border: none;
            color: white;
        }

        .btn-pay:hover {
            background: linear-gradient(135deg, #27ae60, #2ecc71);
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(46, 204, 113, 0.3);
        }

        .btn-ship {
            background: linear-gradient(135deg, #0d6efd, #0b5ed7);
            border: none;
            color: white;
        }

        .btn-ship:hover {
            background: linear-gradient(135deg, #0b5ed7, #0d6efd);
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(13, 110, 253, 0.3);
        }

        .payment-info {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 15px;
            margin-top: 15px;
            border-left: 3px solid #2ecc71;
        }

        .payment-progress {
            height: 8px;
            border-radius: 4px;
            background-color: #e9ecef;
            margin: 10px 0;
        }

        .payment-progress .progress-bar {
            background: linear-gradient(135deg, #2ecc71, #27ae60);
            border-radius: 4px;
        }

        .info-block {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 15px;
            margin-top: 15px;
        }

        .info-block h6 {
            color: #2ecc71;
            margin-bottom: 10px;
            border-left: 3px solid #2ecc71;
            padding-left: 10px;
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 10px;
        }

        .info-item {
            display: flex;
            justify-content: space-between;
            align-items: baseline;
            padding: 5px 0;
            border-bottom: 1px dashed #dee2e6;
        }

        .info-label {
            font-size: 0.85rem;
            color: #6c757d;
            font-weight: 500;
        }

        .info-value {
            font-size: 0.9rem;
            font-weight: 500;
            color: #2c3e50;
            text-align: right;
        }

        .comment-box {
            background-color: #fff3cd;
            border-left: 4px solid #ffc107;
            padding: 10px;
            border-radius: 8px;
            margin-top: 10px;
            font-size: 0.9rem;
        }

        .table thead th {
            background-color: #f1f8e9;
            color: #2ecc71;
            font-weight: 600;
        }

        .table tfoot th {
            background-color: #f8f9fa;
        }

        .shipment-date {
            display: inline-block;
            background-color: #e3f2fd;
            color: #0d6efd;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 0.9rem;
            margin-right: 10px;
        }

        .contract-number {
            font-size: 1.1rem;
            font-weight: bold;
            color: #2c3e50;
        }

        .customer-name {
            font-size: 1rem;
            color: #27ae60;
        }

        hr {
            margin: 15px 0;
        }

        #paymentModal .modal-content {
            border: 2px solid #2ecc71;
            border-radius: 15px;
        }

        #paymentModal .modal-header {
            background: linear-gradient(135deg, #2ecc71, #27ae60);
            border-bottom: 2px solid #27ae60;
            border-radius: 13px 13px 0 0;
            color: white;
        }

        #paymentModal .modal-header .btn-close {
            filter: brightness(0) invert(1);
        }

        #paymentModal .modal-footer {
            border-top: 2px solid #2ecc71;
            border-radius: 0 0 13px 13px;
        }

        /* Единое оформление для должности */
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
                    <!-- Каталог только для покупателя -->
                    <?php if ($current_user['role'] == 'customer'): ?>
                        <li class="nav-item">
                            <a class="nav-link" href="catalog.php">
                                <i class="bi bi-box-seam me-1"></i>Каталог
                            </a>
                        </li>
                    <?php endif; ?>

                    <!-- Договоры для всех, кроме кладовщика и бухгалтера -->
                    <?php if (!in_array($current_user['role'], ['warehouse_keeper', 'accountant'])): ?>
                        <li class="nav-item">
                            <a class="nav-link active" href="contracts.php">
                                <i class="bi bi-file-text-fill me-1"></i>Договоры
                            </a>
                        </li>
                    <?php endif; ?>

                    <!-- Клиенты только для админа и менеджера -->
                    <?php if (in_array($current_user['role'], ['admin', 'manager'])): ?>
                        <li class="nav-item">
                            <a class="nav-link" href="clients.php">
                                <i class="bi bi-people-fill me-1"></i>Клиенты
                            </a>
                        </li>
                    <?php endif; ?>

                    <!-- Склад только для админа и кладовщика -->
                    <?php if (in_array($current_user['role'], ['admin', 'warehouse_keeper'])): ?>
                        <li class="nav-item">
                            <a class="nav-link" href="warehouse.php">
                                <i class="bi bi-shop me-1"></i>Склад
                            </a>
                        </li>
                    <?php endif; ?>

                    <!-- Учёт только для админа и бухгалтера -->
                    <?php if (in_array($current_user['role'], ['admin', 'accountant'])): ?>
                        <li class="nav-item">
                            <a class="nav-link" href="accounting.php">
                                <i class="bi bi-calculator-fill me-1"></i>Учёт
                            </a>
                        </li>
                    <?php endif; ?>

                    <!-- Жалобы ТОЛЬКО для админа и менеджера -->
                    <?php if (in_array($current_user['role'], ['admin', 'manager'])): ?>
                        <li class="nav-item">
                            <a class="nav-link" href="complaints_admin.php">
                                <i class="bi bi-exclamation-triangle-fill me-1"></i>Жалобы
                            </a>
                        </li>
                    <?php endif; ?>

                    <!-- Оценка и жалобы ТОЛЬКО для покупателя -->
                    <?php if ($current_user['role'] == 'customer'): ?>
                        <li class="nav-item">
                            <a class="nav-link" href="feedback.php">
                                <i class="bi bi-chat-dots-fill me-1"></i>Оценка и жалобы
                            </a>
                        </li>
                    <?php endif; ?>
                </ul>
                <div class="d-flex align-items-center">
                    <div class="text-white me-3">
                        <i class="bi bi-person-circle me-1"></i>
                        <span><?= htmlspecialchars($current_user['email']) ?></span>
                        <span class="role-badge-custom">
                            <?= getRoleName($current_user['role']) ?>
                        </span>
                    </div>
                    <a href="logout.php" class="btn btn-outline-light btn-sm" title="Выйти">
                        <i class="bi bi-box-arrow-right"></i>
                    </a>
                </div>
            </div>
        </div>
    </nav>

    <!-- Основной контейнер -->
    <main class="container-fluid px-4">

        <!-- Заголовок -->
        <div class="page-header d-flex justify-content-between align-items-center">
            <div>
                <h2 class="mb-1">
                    <i class="bi bi-file-text-fill me-2" style="color: #2ecc71;"></i>
                    Управление договорами
                </h2>
                <p class="text-muted small">
                    <?php if ($current_user['is_admin'] || $current_user['role'] == 'manager'): ?>
                        Все договоры клиентов
                    <?php else: ?>
                        Ваши договоры
                    <?php endif; ?>
                </p>
            </div>
        </div>

        <!-- Список договоров — обёртка с flex-контейнером -->
        <div class="contracts-container">
            <?php if (empty($contracts)): ?>
                <div class="text-center py-5">
                    <i class="bi bi-inbox fs-1 text-muted d-block mb-3"></i>
                    <h5 class="text-muted">Нет договоров</h5>
                    <?php if ($current_user['role'] == 'customer'): ?>
                        <p class="text-muted">
                            Перейдите в <a href="catalog.php" class="text-success">каталог</a>, чтобы сделать заказ
                        </p>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <?php foreach ($contracts as $contract):
                    $status_class = '';
                    if ($contract['status'] == 'отказ')
                        $status_class = 'rejected';
                    if ($contract['status'] == 'черновик' || $contract['status'] == 'на подписании')
                        $status_class = 'draft';
                    if ($contract['status'] == 'отгружен')
                        $status_class = 'shipped';

                    $items = $contract_items[$contract['id']] ?? [];

                    $total = array_sum(array_map(function ($item) {
                        return $item['quantity'] * $item['price'];
                    }, $items));
                    $reject_reason = $reject_reasons[$contract['id']] ?? null;

                    $paid = floatval($contract['total_paid'] ?? 0);
                    $prepayment = floatval($contract['prepayment_amount'] ?? 0);
                    $remaining = $contract['total_amount'] - $paid;
                    $payment_status = getPaymentStatus($contract['total_amount'], $paid, $prepayment);
                    $payment_class = getPaymentClass($contract['total_amount'], $paid);
                    $payment_percent = $contract['total_amount'] > 0 ? min(100, round(($paid / $contract['total_amount']) * 100)) : 0;

                    $shipment_date = !empty($contract['desired_shipment_date']) ? date('d.m.Y', strtotime($contract['desired_shipment_date'])) : null;
                    $contract_date = !empty($contract['contract_date']) ? date('d.m.Y', strtotime($contract['contract_date'])) : date('d.m.Y', strtotime($contract['created_at']));

                    // Рассчитываем остаток к оплате после отгрузки с учётом предоплаты
                    $amount_to_pay = $contract['total_amount'] - $prepayment;
                    $remaining_to_pay = $amount_to_pay - ($paid - $prepayment);
                    if ($remaining_to_pay < 0)
                        $remaining_to_pay = 0;
                    ?>
                    <div class="contract-card <?= $status_class ?>">
                        <div class="row">
                            <div class="col-12">
                                <!-- Заголовок договора -->
                                <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap">
                                    <div>
                                        <span class="contract-number">Договор №
                                            <?= htmlspecialchars($contract['contract_number']) ?>
                                        </span>
                                        <span class="customer-name ms-2">(
                                            <?= htmlspecialchars($contract['customer_name']) ?>)
                                        </span>
                                    </div>
                                    <div>
                                        <?= getStatusBadge($contract['status']) ?>
                                    </div>
                                </div>

                                <!-- Основная информация о договоре -->
                                <div class="info-block">
                                    <h6><i class="bi bi-info-circle me-2"></i>Основная информация</h6>
                                    <div class="info-grid">
                                        <div class="info-item">
                                            <span class="info-label">Дата договора:</span>
                                            <span class="info-value"><?= $contract_date ?></span>
                                        </div>
                                        <div class="info-item">
                                            <span class="info-label">Дата отгрузки:</span>
                                            <span class="info-value"><?= $shipment_date ?? 'Не указана' ?></span>
                                        </div>
                                        <div class="info-item">
                                            <span class="info-label">Сумма договора:</span>
                                            <span
                                                class="info-value"><?= number_format($contract['total_amount'], 2, ',', ' ') ?>
                                                ₽</span>
                                        </div>
                                        <?php if ($current_user['is_admin'] && isset($contract['manager_email'])): ?>
                                            <div class="info-item">
                                                <span class="info-label">Менеджер:</span>
                                                <span class="info-value"><?= htmlspecialchars($contract['manager_email']) ?></span>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <!-- Условия оплаты -->
                                <div class="info-block">
                                    <h6><i class="bi bi-credit-card me-2"></i>Условия оплаты</h6>
                                    <div class="info-grid">
                                        <div class="info-item">
                                            <span class="info-label">Тип оплаты:</span>
                                            <span
                                                class="info-value"><?= getPaymentTypeText($contract['payment_type'] ?? 'postpayment', $contract['prepayment_percent'] ?? null) ?></span>
                                        </div>
                                        <?php if (($contract['payment_type'] ?? '') == 'partial_prepayment' && $prepayment > 0): ?>
                                            <div class="info-item">
                                                <span class="info-label">Сумма предоплаты:</span>
                                                <span class="info-value text-warning"><?= number_format($prepayment, 2, ',', ' ') ?>
                                                    ₽</span>
                                            </div>
                                            <div class="info-item">
                                                <span class="info-label">Остаток после отгрузки:</span>
                                                <span
                                                    class="info-value"><?= number_format($contract['total_amount'] - $prepayment, 2, ',', ' ') ?>
                                                    ₽</span>
                                            </div>
                                        <?php elseif (($contract['payment_type'] ?? '') == 'full_prepayment'): ?>
                                            <div class="info-item">
                                                <span class="info-label">Требуется оплатить:</span>
                                                <span
                                                    class="info-value text-success"><?= number_format($contract['total_amount'], 2, ',', ' ') ?>
                                                    ₽</span>
                                            </div>
                                        <?php elseif (($contract['payment_type'] ?? '') == 'postpayment'): ?>
                                            <div class="info-item">
                                                <span class="info-label">Срок оплаты:</span>
                                                <span class="info-value text-info">10 дней после отгрузки</span>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <!-- Юридическая информация покупателя -->
                                <div class="info-block">
                                    <h6><i class="bi bi-building me-2"></i>Информация о покупателе</h6>
                                    <div class="info-grid">
                                        <div class="info-item">
                                            <span class="info-label">Полное наименование:</span>
                                            <span class="info-value"><?= htmlspecialchars($contract['customer_name']) ?></span>
                                        </div>
                                        <div class="info-item">
                                            <span class="info-label">ИНН:</span>
                                            <span
                                                class="info-value"><?= htmlspecialchars($contract['inn'] ?? 'Не указан') ?></span>
                                        </div>
                                        <?php if (!empty($contract['bank_name'])): ?>
                                            <div class="info-item">
                                                <span class="info-label">Банк:</span>
                                                <span class="info-value"><?= htmlspecialchars($contract['bank_name']) ?></span>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (!empty($contract['bik'])): ?>
                                            <div class="info-item">
                                                <span class="info-label">БИК:</span>
                                                <span class="info-value"><?= htmlspecialchars($contract['bik']) ?></span>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (!empty($contract['account_number'])): ?>
                                            <div class="info-item">
                                                <span class="info-label">Расчётный счёт:</span>
                                                <span class="info-value"><?= htmlspecialchars($contract['account_number']) ?></span>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (!empty($contract['director_name'])): ?>
                                            <div class="info-item">
                                                <span class="info-label">Директор:</span>
                                                <span class="info-value"><?= htmlspecialchars($contract['director_name']) ?></span>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (!empty($contract['chief_accountant_name'])): ?>
                                            <div class="info-item">
                                                <span class="info-label">Гл. бухгалтер:</span>
                                                <span
                                                    class="info-value"><?= htmlspecialchars($contract['chief_accountant_name']) ?></span>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (!empty($contract['customer_phone'])): ?>
                                            <div class="info-item">
                                                <span class="info-label">Контактный телефон:</span>
                                                <span class="info-value"><?= htmlspecialchars($contract['customer_phone']) ?></span>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <!-- Информация об оплате для отгруженных заказов -->
                                <?php if ($contract['status'] == 'отгружен'): ?>
                                    <div class="payment-info">
                                        <div class="d-flex justify-content-between align-items-center mb-2">
                                            <span class="fw-bold">Статус оплаты:</span>
                                            <span class="fw-bold <?= $payment_class ?>"><?= $payment_status ?></span>
                                        </div>

                                        <div class="row small mb-2">
                                            <div class="col-md-4">
                                                <span class="text-muted">Сумма заказа:</span>
                                                <span
                                                    class="fw-bold ms-1"><?= number_format($contract['total_amount'], 2, ',', ' ') ?>
                                                    ₽</span>
                                            </div>
                                            <div class="col-md-4">
                                                <span class="text-muted">Оплачено:</span>
                                                <span class="fw-bold text-success ms-1"><?= number_format($paid, 2, ',', ' ') ?>
                                                    ₽</span>
                                            </div>
                                            <div class="col-md-4">
                                                <span class="text-muted">Остаток:</span>
                                                <span
                                                    class="fw-bold <?= $remaining_to_pay > 0 ? 'text-warning' : 'text-success' ?> ms-1">
                                                    <?= number_format($remaining_to_pay, 2, ',', ' ') ?> ₽
                                                </span>
                                            </div>
                                        </div>

                                        <div class="progress payment-progress">
                                            <div class="progress-bar" style="width: <?= $payment_percent ?>%"></div>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <!-- Состав заказа -->
                                <?php if (!empty($items)): ?>
                                    <div class="item-list">
                                        <h6 class="mb-3">Состав заказа:</h6>
                                        <div class="table-responsive">
                                            <table class="table table-sm table-hover">
                                                <thead>
                                                    <tr>
                                                        <th>№</th>
                                                        <th>Товар</th>
                                                        <th>Кол-во</th>
                                                        <th>Цена</th>
                                                        <th>Сумма</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php $counter = 1;
                                                    foreach ($items as $item): ?>
                                                        <tr>
                                                            <td><?= $counter++ ?></td>
                                                            <td><?= htmlspecialchars($item['product_name']) ?></td>
                                                            <td><?= number_format($item['quantity'], 0, ',', ' ') ?>
                                                                <?= $item['unit'] ?>
                                                            </td>
                                                            <td><?= number_format($item['price'], 2, ',', ' ') ?> ₽</td>
                                                            <td><?= number_format($item['quantity'] * $item['price'], 2, ',', ' ') ?> ₽
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                                <tfoot>
                                                    <tr>
                                                        <th colspan="4" class="text-end">Итого:</th>
                                                        <th class="text-success"><?= number_format($total, 2, ',', ' ') ?> ₽</th>
                                                    </tr>
                                                </tfoot>
                                            </table>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <!-- Комментарий к заказу -->
                                <?php
                                $user_comment = cleanOrderComment($contract['order_comment'] ?? '');
                                if (!empty($user_comment)):
                                    ?>
                                    <div class="comment-box">
                                        <i class="bi bi-chat-quote me-2"></i>
                                        <strong>Комментарий к заказу:</strong>
                                        <div class="mt-1"><?= nl2br(htmlspecialchars($user_comment)) ?></div>
                                    </div>
                                <?php endif; ?>
                                <!-- Причина отказа (если статус "отказ") -->
                                <?php if ($contract['status'] == 'отказ' && $reject_reason): ?>
                                    <div class="reject-reason-box"
                                        style="background-color: #f8d7da; border-left: 4px solid #dc3545; padding: 10px; border-radius: 8px; margin-top: 10px; font-size: 0.9rem; color: #721c24;">
                                        <i class="bi bi-exclamation-triangle-fill me-2"></i>
                                        <strong>Причина отказа:</strong>
                                        <div class="mt-1"><?= nl2br(htmlspecialchars($reject_reason)) ?></div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Панель действий -->
                        <div class="row mt-3">
                            <div class="col-12 text-end">
                                <!-- Кнопка PDF договора (всегда доступна) -->
                                <a href="contract_pdf.php?id=<?= $contract['id'] ?>" class="btn btn-info" target="_blank">
                                    <i class="bi bi-file-pdf me-2"></i> PDF договора
                                </a>

                                <!-- Кнопки для админа (только для статуса "на подписании") -->
                                <?php if ($current_user['is_admin'] && $contract['status'] == 'на подписании'): ?>
                                    <button class="btn btn-approve approve-btn ms-2" data-id="<?= $contract['id'] ?>"
                                        data-number="<?= htmlspecialchars($contract['contract_number']) ?>">
                                        <i class="bi bi-check-circle me-2"></i> Подтвердить договор
                                    </button>
                                    <button class="btn btn-reject reject-btn ms-2" data-id="<?= $contract['id'] ?>"
                                        data-number="<?= htmlspecialchars($contract['contract_number']) ?>">
                                        <i class="bi bi-x-circle me-2"></i> Отказать
                                    </button>
                                <?php endif; ?>

                                <!-- Кнопка платежных документов (показываем если есть оплаты) -->
                                <?php
                                // Проверяем, есть ли оплаты по договору
                                $check_payments = $pdo->prepare("SELECT COUNT(*) FROM accounting_operations WHERE contract_id = ? AND operation_type = 'оплата'");
                                $check_payments->execute([$contract['id']]);
                                $has_payments = $check_payments->fetchColumn() > 0;
                                ?>
                                <?php if ($has_payments): ?>
                                    <button class="btn btn-info ms-2"
                                        onclick="showPaymentDocuments(<?= $contract['id'] ?>, '<?= htmlspecialchars($contract['contract_number']) ?>')">
                                        <i class="bi bi-credit-card-2-front me-1"></i>Платежные документы
                                        <?= $check_payments->fetchColumn() ?>
                                    </button>
                                <?php endif; ?>

                                <!-- Кнопка оплаты для клиента (только для отгруженных и если есть остаток) -->
                                <?php if ($current_user['role'] == 'customer' && $contract['status'] == 'отгружен' && $remaining_to_pay > 0): ?>
                                    <button class="btn btn-pay pay-btn ms-2" data-id="<?= $contract['id'] ?>"
                                        data-number="<?= htmlspecialchars($contract['contract_number']) ?>"
                                        data-total="<?= $contract['total_amount'] ?>" data-paid="<?= $paid ?>"
                                        data-remaining="<?= $remaining_to_pay ?>" data-prepayment="<?= $prepayment ?>">
                                        <i class="bi bi-credit-card me-2"></i>
                                        Оплатить
                                        <br>
                                        <small><?= number_format($remaining_to_pay, 2, ',', ' ') ?> ₽</small>
                                    </button>
                                <?php endif; ?>

                                <!-- Статусные бейджи -->
                                <?php if ($contract['status'] == 'отгружен' && $paid >= $contract['total_amount']): ?>
                                    <span class="badge bg-success p-3 ms-2">
                                        <i class="bi bi-check-circle me-2"></i> Оплачено полностью
                                    </span>
                                <?php endif; ?>

                                <?php if ($contract['status'] == 'на подписании' && $current_user['role'] == 'customer'): ?>
                                    <span class="badge bg-warning text-dark p-3 ms-2">
                                        <i class="bi bi-hourglass-split me-2"></i> Ожидает подтверждения
                                    </span>
                                <?php endif; ?>

                                <?php if ($contract['status'] == 'отказ'): ?>
                                    <span class="badge bg-danger p-3 ms-2">
                                        <i class="bi bi-x-circle me-2"></i> Отказ
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

    </main>

    <!-- МОДАЛЬНОЕ ОКНО ОПЛАТЫ -->
    <div class="modal fade" id="paymentModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title">Оплата заказа</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="paymentForm">
                        <input type="hidden" name="action" value="pay">
                        <input type="hidden" name="contract_id" id="payment_contract_id">
                        <input type="hidden" name="remaining_amount" id="payment_remaining_hidden">

                        <div class="mb-3">
                            <label class="form-label fw-bold">Номер договора</label>
                            <input type="text" class="form-control" id="payment_contract_number" readonly
                                style="background-color: #f0fff0; border: 2px solid #2ecc71;">
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Сумма заказа</label>
                                <input type="text" class="form-control" id="payment_total" readonly
                                    style="background-color: #f0fff0; border: 2px solid #2ecc71;">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Уже оплачено</label>
                                <input type="text" class="form-control text-success fw-bold" id="payment_paid" readonly
                                    style="background-color: #f0fff0; border: 2px solid #2ecc71;">
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-bold">Остаток к оплате</label>
                            <div id="payment_remaining_display" class="form-control text-warning fw-bold"
                                style="background-color: #fff3cd; border: 2px solid #ffc107;">
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-bold">Сумма оплаты <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text bg-success text-white">₽</span>
                                <input type="number" step="0.01" min="0.01" class="form-control" id="payment_amount"
                                    name="amount" required style="border: 2px solid #2ecc71;">
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Способ оплаты</label>
                            <input type="text" class="form-control" value="Безналичный расчёт" readonly
                                style="border: 2px solid #2ecc71; background-color: #f8f9fa;">
                            <input type="hidden" name="payment_method" value="безналичные">
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Номер платёжного документа</label>
                            <input type="text" class="form-control" name="payment_document" id="payment_document_input"
                                placeholder="Генерируется автоматически" style="border: 2px solid #2ecc71;">
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Отмена</button>
                    <button type="button" class="btn btn-success" id="confirmPaymentBtn">Провести оплату</button>
                </div>
            </div>
        </div>
    </div>

    <!-- МОДАЛЬНОЕ ОКНО ПЛАТЕЖНЫХ ДОКУМЕНТОВ -->
    <div class="modal fade" id="paymentDocsModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title"><i class="bi bi-credit-card-2-front me-2"></i>Платежные документы</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="paymentDocsContent">
                    <div class="text-center py-4">
                        <div class="spinner-border text-success" role="status"></div>
                        <p class="mt-2">Загрузка...</p>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Закрыть</button>
                </div>
            </div>
        </div>
    </div>
    <!-- МОДАЛЬНОЕ ОКНО ДЛЯ ПРИЧИНЫ ОТКАЗА -->
    <div class="modal fade" id="rejectReasonModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title"><i class="bi bi-exclamation-triangle-fill me-2"></i>Причина отказа</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="reject_reason" class="form-label fw-bold">Укажите причину отказа договора</label>
                        <div class="modal-body">
                            <div class="mb-3">

                                <textarea class="form-control" id="reject_reason" rows="3"
                                    placeholder="Введите причину отказа..."></textarea>
                            </div>
                            <input type="hidden" id="reject_contract_id" value="">
                            <input type="hidden" id="reject_contract_number" value="">
                        </div>
                    </div>
                    <input type="hidden" id="reject_contract_id" value="">
                    <input type="hidden" id="reject_contract_number" value="">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                    <button type="button" class="btn btn-danger" id="confirmRejectBtn">Отказать</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Скрипты -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <script>
        $(document).ready(function () {
            // Открытие модального окна оплаты
            $('.pay-btn').click(function () {
                const id = $(this).data('id');
                const number = $(this).data('number');
                const total = parseFloat($(this).data('total'));
                const paid = parseFloat($(this).data('paid'));
                let remaining = parseFloat($(this).data('remaining'));
                const prepayment = parseFloat($(this).data('prepayment')) || 0;

                // Убеждаемся, что remaining - число
                if (isNaN(remaining)) remaining = 0;

                const randomNum = 'ПП-' + Math.floor(1000 + Math.random() * 9000);

                $('#payment_contract_id').val(id);
                $('#payment_contract_number').val(number);
                $('#payment_total').val(total.toLocaleString('ru-RU') + ' ₽');
                $('#payment_paid').val(paid.toLocaleString('ru-RU') + ' ₽');

                // Сохраняем остаток как число (без форматирования)
                $('#payment_remaining_hidden').val(remaining);

                if (prepayment > 0) {
                    $('#payment_remaining_display').html(`
                    <div class="small text-muted">Предоплата: ${prepayment.toLocaleString('ru-RU')} ₽</div>
                    <div class="fw-bold">К оплате: ${remaining.toLocaleString('ru-RU')} ₽</div>
                `);
                } else {
                    $('#payment_remaining_display').html(remaining.toLocaleString('ru-RU') + ' ₽');
                }

                $('#payment_amount').attr('max', remaining);
                $('#payment_amount').val(remaining.toFixed(2));
                $('#payment_document_input').val(randomNum);

                $('#paymentModal').modal('show');
            });

            // Подтверждение оплаты
            $('#confirmPaymentBtn').click(function () {
                // Получаем сумму оплаты
                let amount = $('#payment_amount').val();
                // Заменяем запятую на точку для корректного parseFloat
                amount = amount.replace(',', '.');
                amount = parseFloat(amount);

                // Получаем остаток из скрытого поля
                let remaining = $('#payment_remaining_hidden').val();
                remaining = parseFloat(remaining);

                console.log('Amount:', amount, 'Remaining:', remaining);

                if (isNaN(amount) || amount <= 0) {
                    Swal.fire({ icon: 'error', title: 'Ошибка', text: 'Введите корректную сумму' });
                    return;
                }

                if (amount > remaining) {
                    Swal.fire({
                        icon: 'error',
                        title: 'Ошибка',
                        text: 'Сумма оплаты не может превышать остаток. Остаток: ' + remaining.toLocaleString('ru-RU') + ' ₽'
                    });
                    return;
                }

                Swal.fire({
                    title: 'Подтверждение оплаты',
                    html: `Провести оплату на сумму <strong>${amount.toLocaleString('ru-RU')} ₽</strong>?`,
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonColor: '#2ecc71',
                    cancelButtonColor: '#6c757d',
                    confirmButtonText: 'Да, оплатить',
                    cancelButtonText: 'Отмена'
                }).then((result) => {
                    if (result.isConfirmed) {
                        // Отправляем сумму с точкой
                        $('#payment_amount').val(amount);

                        $.ajax({
                            url: 'contracts.php',
                            type: 'POST',
                            data: $('#paymentForm').serialize(),
                            dataType: 'json',
                            success: function (response) {
                                if (response.success) {
                                    Swal.fire({ icon: 'success', title: 'Оплата проведена!', text: response.message, timer: 2000 })
                                        .then(() => { $('#paymentModal').modal('hide'); location.reload(); });
                                } else {
                                    Swal.fire({ icon: 'error', title: 'Ошибка', text: response.message });
                                }
                            },
                            error: function (xhr, status, error) {
                                Swal.fire({ icon: 'error', title: 'Ошибка', text: 'Произошла ошибка при отправке запроса' });
                            }
                        });
                    }
                });
            });

            // Подтверждение договора (админ)
            $('.approve-btn').click(function () {
                const id = $(this).data('id');
                const number = $(this).data('number');

                Swal.fire({
                    title: 'Подтверждение договора',
                    html: `Подтвердить договор <strong>№${number}</strong>?`,
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonColor: '#2ecc71',
                    cancelButtonColor: '#6c757d',
                    confirmButtonText: 'Да, подтвердить',
                    cancelButtonText: 'Отмена'
                }).then((result) => {
                    if (result.isConfirmed) {
                        $.ajax({
                            url: 'contracts.php',
                            type: 'POST',
                            data: { action: 'update_status', contract_id: id, status: 'подписан' },
                            dataType: 'json',
                            success: function (response) {
                                if (response.success) {
                                    Swal.fire({ icon: 'success', title: 'Успешно!', text: response.message, timer: 2000 })
                                        .then(() => location.reload());
                                } else {
                                    Swal.fire({ icon: 'error', title: 'Ошибка', text: response.message });
                                }
                            }
                        });
                    }
                });
            });

            // Отказ договора (админ)
            // Отказ договора (админ) - открываем модальное окно для ввода причины
            $('.reject-btn').click(function () {
                const id = $(this).data('id');
                const number = $(this).data('number');
                $('#reject_contract_id').val(id);
                $('#reject_contract_number').val(number);
                $('#reject_reason').val('');
                $('#rejectReasonModal').modal('show');
            });

            $('#confirmRejectBtn').click(function () {
                const id = $('#reject_contract_id').val();
                const number = $('#reject_contract_number').val();
                const reason = $('#reject_reason').val().trim();
                if (!reason) {
                    Swal.fire({ icon: 'warning', title: 'Внимание', text: 'Пожалуйста, укажите причину отказа' });
                    return;
                }
                Swal.fire({
                    title: 'Отказ договора',
                    html: `Отклонить договор <strong>№${number}</strong> с указанной причиной?`,
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#dc3545',
                    cancelButtonColor: '#6c757d',
                    confirmButtonText: 'Да, отказать',
                    cancelButtonText: 'Отмена'
                }).then((result) => {
                    if (result.isConfirmed) {
                        $.ajax({
                            url: 'contracts.php',
                            type: 'POST',
                            data: { action: 'update_status', contract_id: id, status: 'отказ', reject_reason: reason },
                            dataType: 'json',
                            success: function (response) {
                                if (response.success) {
                                    Swal.fire({ icon: 'success', title: 'Успешно!', text: response.message, timer: 2000 })
                                        .then(() => location.reload());
                                } else {
                                    Swal.fire({ icon: 'error', title: 'Ошибка', text: response.message });
                                }
                            }
                        });
                    }
                });
            });

            // Отгрузка товара (админ)
            $('.ship-btn').click(function () {
                const id = $(this).data('id');
                const number = $(this).data('number');

                Swal.fire({
                    title: 'Отгрузка товара',
                    html: `Отгрузить товары по договору <strong>№${number}</strong>?<br><br>Товары будут списаны со склада.`,
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonColor: '#0d6efd',
                    cancelButtonColor: '#6c757d',
                    confirmButtonText: 'Да, отгрузить',
                    cancelButtonText: 'Отмена'
                }).then((result) => {
                    if (result.isConfirmed) {
                        $.ajax({
                            url: 'contracts.php',
                            type: 'POST',
                            data: { action: 'ship', contract_id: id },
                            dataType: 'json',
                            success: function (response) {
                                if (response.success) {
                                    Swal.fire({ icon: 'success', title: 'Успешно!', text: response.message, timer: 2000 })
                                        .then(() => location.reload());
                                } else {
                                    Swal.fire({ icon: 'error', title: 'Ошибка', text: response.message });
                                }
                            }
                        });
                    }
                });
            });
        });

        // Функция отображения платежных документов
        function showPaymentDocuments(contractId, contractNumber) {
            $('#paymentDocsContent').html(`
        <div class="text-center py-4">
            <div class="spinner-border text-success" role="status"></div>
            <p class="mt-2">Загрузка документов...</p>
        </div>
    `);
            $('#paymentDocsModal').modal('show');

            $.ajax({
                url: 'get_payment_documents.php',
                type: 'GET',
                data: { contract_id: contractId },
                dataType: 'json',
                success: function (data) {
                    let html = `
                <div class="list-group">
                    <div class="list-group-item bg-light">
                        <strong><i class="bi bi-file-text me-2"></i>Договор №${contractNumber}</strong>
                        <span class="badge bg-success float-end">Всего платежей: ${(data.prepayment ? 1 : 0) + data.payments.length}</span>
                    </div>
            `;

                    // Сначала показываем предоплату (если есть)
                    if (data.prepayment) {
                        html += `
                    <div class="list-group-item" style="border-left: 4px solid #ffc107;">
                        <div class="d-flex justify-content-between align-items-center flex-wrap">
                            <div>
                                <i class="bi bi-cash-stack text-warning me-2"></i>
                                <strong>💰 ПРЕДОПЛАТА</strong>
                                <div class="small text-muted mt-1">
                                    <div>Сумма: <span class="fw-bold text-success">${data.prepayment.amount}</span></div>
                                    <div>Дата: ${data.prepayment.date}</div>
                                    <div>Документ: ${data.prepayment.document_number}</div>
                                    ${data.prepayment.comment ? `<div>Назначение: ${data.prepayment.comment}</div>` : ''}
                                </div>
                            </div>
                            <button class="btn btn-sm btn-outline-warning mt-2 mt-sm-0" onclick="showPaymentDoc('${data.prepayment.document_number}', '${data.prepayment.amount}', '${contractNumber}', '${data.prepayment.date}', 'Предоплата')">
                                <i class="bi bi-eye"></i> Просмотр
                            </button>
                        </div>
                    </div>
                `;
                    }

                    // Затем показываем все последующие платежи
                    if (data.payments && data.payments.length > 0) {
                        data.payments.forEach(function (payment, index) {
                            const paymentType = index === 0 && !data.prepayment ? 'Первый платёж' : `Платёж ${index + 1}`;
                            html += `
                        <div class="list-group-item" style="border-left: 4px solid #2ecc71;">
                            <div class="d-flex justify-content-between align-items-center flex-wrap">
                                <div>
                                    <i class="bi bi-receipt text-primary me-2"></i>
                                    <strong>📄 ${paymentType}</strong>
                                    <div class="small text-muted mt-1">
                                        <div>Сумма: <span class="fw-bold text-primary">${payment.amount}</span></div>
                                        <div>Дата: ${payment.date}</div>
                                        <div>Документ: ${payment.document_number}</div>
                                        ${payment.comment ? `<div>Назначение: ${payment.comment}</div>` : ''}
                                    </div>
                                </div>
                                <button class="btn btn-sm btn-outline-primary mt-2 mt-sm-0" onclick="showPaymentDoc('${payment.document_number}', '${payment.amount}', '${contractNumber}', '${payment.date}', '${paymentType}')">
                                    <i class="bi bi-eye"></i> Просмотр
                                </button>
                            </div>
                        </div>
                    `;
                        });
                    }

                    if ((!data.payments || data.payments.length === 0) && !data.prepayment) {
                        html += `
                    <div class="list-group-item text-center text-muted">
                        <i class="bi bi-inbox fs-1 d-block mb-2"></i>
                        <p>Нет платежных документов по данному договору</p>
                    </div>
                `;
                    }

                    html += `</div>`;
                    $('#paymentDocsContent').html(html);
                },
                error: function (xhr, status, error) {
                    console.error('Ошибка:', error);
                    $('#paymentDocsContent').html(`
                <div class="alert alert-danger m-3">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i>
                    Ошибка загрузки документов: ${error}
                </div>
            `);
                }
            });
        }

        // Функция отображения конкретного платежного документа
        function showPaymentDoc(docNumber, amount, contractNumber, date, paymentType) {
            const currentDate = date || new Date().toLocaleDateString('ru-RU');
            const typeText = paymentType || (docNumber.includes('ПРЕДОПЛАТА') ? 'Предоплата' : 'Платёж');

            const content = `
        <div style="font-family: 'Times New Roman', serif; padding: 20px;">
            <div style="text-align: center; margin-bottom: 20px;">
                <h3>ПЛАТЁЖНОЕ ПОРУЧЕНИЕ №${docNumber}</h3>
                <p>от "${currentDate}"</p>
                
            </div>
            <table style="width: 100%; border-collapse: collapse; margin-bottom: 20px; border: 1px solid #000;">
                <tr><td style="padding: 8px; border: 1px solid #000; width: 30%; background: #f5f5f5;"><strong>ИНН</strong></td><td colspan="3" style="padding: 8px; border: 1px solid #000;">7842123456</td></tr>
                <tr><td style="padding: 8px; border: 1px solid #000; background: #f5f5f5;"><strong>КПП</strong></td><td colspan="3" style="padding: 8px; border: 1px solid #000;">784201001</td></tr>
                <tr><td style="padding: 8px; border: 1px solid #000; background: #f5f5f5;"><strong>Плательщик</strong></td><td colspan="3" style="padding: 8px; border: 1px solid #000;">ООО "Буратино"</td></tr>
                <tr><td style="padding: 8px; border: 1px solid #000; background: #f5f5f5;"><strong>Счёт плательщика</strong></td><td colspan="3" style="padding: 8px; border: 1px solid #000;">40702810123450009999</td></tr>
                <tr><td style="padding: 8px; border: 1px solid #000; background: #f5f5f5;"><strong>Банк плательщика</strong></td><td colspan="3" style="padding: 8px; border: 1px solid #000;">АО "Альфа-Банк" г. Москва</td></tr>
                <tr><td style="padding: 8px; border: 1px solid #000; background: #f5f5f5;"><strong>БИК</strong></td><td colspan="3" style="padding: 8px; border: 1px solid #000;">044525593</td></tr>
                <tr><td style="padding: 8px; border: 1px solid #000; background: #f5f5f5;"><strong>Счёт получателя</strong></td><td colspan="3" style="padding: 8px; border: 1px solid #000;">40702810123450001234</td></tr>
                <tr><td style="padding: 8px; border: 1px solid #000; background: #f5f5f5;"><strong>Получатель</strong></td><td colspan="3" style="padding: 8px; border: 1px solid #000;">По договору №${contractNumber}</td></tr>
                <tr>
                    <td style="padding: 8px; border: 1px solid #000; background: #f5f5f5;"><strong>Сумма</strong></td>
                    <td colspan="3" style="padding: 8px; border: 1px solid #000;"><strong style="font-size: 1.2rem;">${amount}</strong></td>
                </tr>
                <tr><td style="padding: 8px; border: 1px solid #000; background: #f5f5f5;"><strong>Назначение платежа</strong></td>
                    <td colspan="3" style="padding: 8px; border: 1px solid #000;">${typeText} по договору №${contractNumber}</td>
                </tr>
            </table>
            <div style="margin-top: 30px; display: flex; justify-content: space-between;">
                <div>Руководитель _________________</div>
                <div>Главный бухгалтер _________________</div>
            </div>
            <div style="margin-top: 20px; text-align: center; font-size: 12px; color: #666;">
                
            </div>
        </div>
    `;

            Swal.fire({
                title: 'Платёжный документ',
                html: content,
                width: '900px',
                showCancelButton: true,
                confirmButtonText: '<i class="bi bi-printer me-2"></i>Печать',
                cancelButtonText: 'Закрыть',
                confirmButtonColor: '#2ecc71'
            }).then((result) => {
                if (result.isConfirmed) {
                    const printWindow = window.open('', '_blank');
                    printWindow.document.write(`
                <html>
                <head>
                    <title>Платёжное поручение №${docNumber}</title>
                    <meta charset="utf-8">
                    <style>
                        body { padding: 30px; font-family: 'Times New Roman', serif; }
                        table { width: 100%; border-collapse: collapse; }
                        td { padding: 8px; border: 1px solid #000; }
                        @media print {
                            body { margin: 0; padding: 20px; }
                        }
                    </style>
                </head>
                <body>${content}</body>
                </html>
            `);
                    printWindow.document.close();
                    printWindow.print();
                }
            });
        }
    </script>

</body>

</html>