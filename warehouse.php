<?php
// warehouse.php - Склад с боксами по заказам и документами
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

$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$current_user = $stmt->fetch(PDO::FETCH_ASSOC);

// Проверка роли: только админ и кладовщик имеют доступ к складу
if (!in_array($current_user['role'], ['admin', 'warehouse_keeper'])) {
    if ($current_user['role'] == 'manager') {
        header('Location: contracts.php');
    } elseif ($current_user['role'] == 'accountant') {
        header('Location: accounting.php');
    } else {
        header('Location: catalog.php');
    }
    exit;
}

// Получаем все движения товара (приход/расход) с документами
$movements = $pdo->query("
    SELECT wm.*, p.name as product_name, p.unit, 
           c.contract_number, cust.name as customer_name,
           wb.box_number
    FROM warehouse_movements wm
    LEFT JOIN products p ON wm.product_id = p.id
    LEFT JOIN contracts c ON wm.contract_id = c.id
    LEFT JOIN customers cust ON c.customer_id = cust.id
    LEFT JOIN warehouse_boxes wb ON wm.box_id = wb.id
    ORDER BY wm.document_date DESC, wm.created_at DESC
")->fetchAll(PDO::FETCH_ASSOC);

// Получаем все боксы
$boxes = $pdo->query("
    SELECT b.*, c.contract_number, c.customer_id, cust.name as customer_name,
           c.desired_shipment_date, c.status, c.total_amount
    FROM warehouse_boxes b
    JOIN contracts c ON b.contract_id = c.id
    JOIN customers cust ON c.customer_id = cust.id
    ORDER BY b.created_at DESC
")->fetchAll(PDO::FETCH_ASSOC);

// Получаем товары в боксах
$box_items = [];
if (!empty($boxes)) {
    $ids = array_column($boxes, 'id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("
        SELECT bi.*, p.name as product_name, p.unit
        FROM box_items bi
        JOIN products p ON bi.product_id = p.id
        WHERE bi.box_id IN ($placeholders)
    ");
    $stmt->execute($ids);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($items as $item) {
        $box_items[$item['box_id']][] = $item;
    }
}

// Получаем общий склад
$stock = $pdo->query("
    SELECT w.*, p.name, p.unit, p.current_price 
    FROM warehouse w
    JOIN products p ON w.product_id = p.id
    ORDER BY p.name
")->fetchAll(PDO::FETCH_ASSOC);

// Получаем список всех товаров
$products = $pdo->query("SELECT id, name, unit FROM products ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// Получаем договоры для прихода
$contracts_list = $pdo->query("
    SELECT c.id, c.contract_number, cust.name as customer_name 
    FROM contracts c 
    JOIN customers cust ON c.customer_id = cust.id 
    ORDER BY c.created_at DESC LIMIT 50
")->fetchAll(PDO::FETCH_ASSOC);

// Получаем договоры, готовые к отгрузке (для создания бокса)
$ready_contracts = $pdo->query("
    SELECT c.*, cust.name as customer_name
    FROM contracts c
    JOIN customers cust ON c.customer_id = cust.id
    WHERE c.status = 'подписан' AND c.id NOT IN (SELECT contract_id FROM warehouse_boxes)
    ORDER BY c.created_at DESC
")->fetchAll(PDO::FETCH_ASSOC);

// Обработка создания бокса
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_box') {
    header('Content-Type: application/json');

    try {
        $contract_id = $_POST['contract_id'];

        $check = $pdo->prepare("SELECT id FROM warehouse_boxes WHERE contract_id = ?");
        $check->execute([$contract_id]);
        if ($check->fetch()) {
            echo json_encode(['success' => false, 'message' => 'Бокс для этого заказа уже создан']);
            exit;
        }

        $pdo->beginTransaction();

        $box_number = 'BOX-' . date('Ymd') . '-' . rand(100, 999);

        $stmt = $pdo->prepare("INSERT INTO warehouse_boxes (contract_id, box_number) VALUES (?, ?)");
        $stmt->execute([$contract_id, $box_number]);
        $box_id = $pdo->lastInsertId();

        $items = $pdo->prepare("
            SELECT ci.*, p.name, p.unit
            FROM contract_items ci
            JOIN products p ON ci.product_id = p.id
            WHERE ci.contract_id = ?
        ");
        $items->execute([$contract_id]);
        $contract_items = $items->fetchAll(PDO::FETCH_ASSOC);

        foreach ($contract_items as $item) {
            $stmt = $pdo->prepare("INSERT INTO box_items (box_id, product_id, quantity, price) VALUES (?, ?, ?, ?)");
            $stmt->execute([$box_id, $item['product_id'], $item['quantity'], $item['price']]);
        }

        $pdo->commit();

        echo json_encode(['success' => true, 'message' => 'Бокс создан: ' . $box_number]);

    } catch (Exception $e) {
        if ($pdo->inTransaction())
            $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// Обработка комплектации бокса (только резервирование, без списания)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'move_to_box') {
    header('Content-Type: application/json');

    try {
        $box_id = $_POST['box_id'];

        $pdo->beginTransaction();

        $box_info = $pdo->prepare("
            SELECT wb.*, c.contract_number, c.customer_id, cust.name as customer_name
            FROM warehouse_boxes wb
            JOIN contracts c ON wb.contract_id = c.id
            JOIN customers cust ON c.customer_id = cust.id
            WHERE wb.id = ?
        ");
        $box_info->execute([$box_id]);
        $box = $box_info->fetch(PDO::FETCH_ASSOC);

        if (!$box) {
            throw new Exception("Бокс не найден");
        }

        $box_items = $pdo->prepare("SELECT * FROM box_items WHERE box_id = ?");
        $box_items->execute([$box_id]);
        $items = $box_items->fetchAll(PDO::FETCH_ASSOC);

        // Проверяем наличие товара на складе
        foreach ($items as $item) {
            $check = $pdo->prepare("SELECT quantity FROM warehouse WHERE product_id = ?");
            $check->execute([$item['product_id']]);
            $available = $check->fetchColumn();

            if ($available < $item['quantity']) {
                throw new Exception("Недостаточно товара на складе для резервирования");
            }
        }

        // Обновляем статус договора на "в боксе"
        $stmt = $pdo->prepare("UPDATE contracts SET status = 'в боксе' WHERE id = ?");
        $stmt->execute([$box['contract_id']]);

        $pdo->commit();

        echo json_encode(['success' => true, 'message' => 'Бокс скомплектован, товар зарезервирован']);

    } catch (Exception $e) {
        if ($pdo->inTransaction())
            $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// Обработка отгрузки бокса (списание товара со склада)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'ship_box') {
    header('Content-Type: application/json');

    try {
        $box_id = $_POST['box_id'];

        $pdo->beginTransaction();

        $box_info = $pdo->prepare("
            SELECT wb.*, c.contract_number, c.customer_id, cust.name as customer_name, c.total_amount
            FROM warehouse_boxes wb
            JOIN contracts c ON wb.contract_id = c.id
            JOIN customers cust ON c.customer_id = cust.id
            WHERE wb.id = ?
        ");
        $box_info->execute([$box_id]);
        $box = $box_info->fetch(PDO::FETCH_ASSOC);

        if (!$box) {
            throw new Exception("Бокс не найден");
        }

        $contract_id = $box['contract_id'];

        $box_items = $pdo->prepare("
            SELECT bi.*, p.name, p.unit
            FROM box_items bi
            JOIN products p ON bi.product_id = p.id
            WHERE bi.box_id = ?
        ");
        $box_items->execute([$box_id]);
        $items = $box_items->fetchAll(PDO::FETCH_ASSOC);

        // Проверяем наличие товара на складе
        foreach ($items as $item) {
            $check = $pdo->prepare("SELECT quantity FROM warehouse WHERE product_id = ?");
            $check->execute([$item['product_id']]);
            $available = $check->fetchColumn();

            if ($available < $item['quantity']) {
                throw new Exception("Недостаточно товара на складе: {$item['name']}");
            }
        }

        $shipment_number = 'Акт-отгрузки-' . date('Ymd') . '-' . $box_id;
        $total_amount = 0;
        $items_list = [];

        // СПИСЫВАЕМ товар со склада и создаем записи
        foreach ($items as $item) {
            $total_amount += $item['quantity'] * $item['price'];
            $items_list[] = $item['name'] . ' - ' . $item['quantity'] . ' ' . $item['unit'];

            $update = $pdo->prepare("UPDATE warehouse SET quantity = quantity - ? WHERE product_id = ?");
            $update->execute([$item['quantity'], $item['product_id']]);

            $movement = $pdo->prepare("
                INSERT INTO warehouse_movements 
                (product_id, movement_type, quantity, document_type, document_number, document_date, contract_id, comment, box_id) 
                VALUES (?, 'расход', ?, 'Отгрузка бокса', ?, NOW(), ?, ?, ?)
            ");
            $movement->execute([
                $item['product_id'],
                $item['quantity'],
                $shipment_number,
                $contract_id,
                'Отгрузка товара клиенту',
                $box_id
            ]);
        }

        // Общая запись
        /*
        $summary = $pdo->prepare("
            INSERT INTO warehouse_movements 
            (product_id, movement_type, quantity, document_type, document_number, document_date, contract_id, comment, box_id) 
            VALUES (0, 'расход', ?, 'Отгрузка бокса', ?, NOW(), ?, ?, ?)
        ");
        $summary->execute([
            array_sum(array_column($items, 'quantity')),
            $shipment_number,
            $contract_id,
            "Отгрузка товаров:\n" . implode("\n", $items_list),
            $box_id
        ]);
        */

        // Запись в бухучёт
        $accounting = $pdo->prepare("INSERT INTO accounting_operations 
            (contract_id, operation_date, operation_type, amount, payment_type, document_number, comment) 
            VALUES (?, NOW(), 'реализация', ?, 'безналичные', ?, ?)");
        $accounting->execute([
            $contract_id,
            $total_amount,
            $shipment_number,
            'Реализация товара по договору №' . $box['contract_number']
        ]);

        $update = $pdo->prepare("UPDATE contracts SET status = 'отгружен' WHERE id = ?");
        $update->execute([$contract_id]);

        $pdo->commit();

        echo json_encode(['success' => true, 'message' => 'Бокс отгружен. Документ: ' . $shipment_number]);

    } catch (Exception $e) {
        if ($pdo->inTransaction())
            $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// Обработка добавления прихода на склад (несколько товаров в одном документе)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_stock') {
    header('Content-Type: application/json');

    try {
        $contract_id = $_POST['contract_id'] ?: null;
        $items_data = json_decode($_POST['items'], true);
        $comment = $_POST['comment'] ?? '';

        if (empty($items_data)) {
            echo json_encode(['success' => false, 'message' => 'Добавьте хотя бы один товар']);
            exit;
        }

        $pdo->beginTransaction();

        $document_number = 'ПО-' . date('Ymd') . '-' . rand(100, 999);
        $total_quantity = 0;
        $items_list = [];

        foreach ($items_data as $item) {
            $product_id = $item['product_id'];
            $quantity = floatval($item['quantity']);

            if ($quantity <= 0)
                continue;

            $total_quantity += $quantity;

            $product_info = $pdo->prepare("SELECT name, unit FROM products WHERE id = ?");
            $product_info->execute([$product_id]);
            $product = $product_info->fetch(PDO::FETCH_ASSOC);

            $items_list[] = $product['name'] . ' - ' . $quantity . ' ' . $product['unit'];

            $stmt = $pdo->prepare("INSERT INTO warehouse_movements 
                (product_id, movement_type, quantity, document_type, document_number, document_date, contract_id, comment, created_at) 
                VALUES (?, 'приход', ?, 'Приходный ордер', ?, NOW(), ?, ?, NOW())");
            $stmt->execute([$product_id, $quantity, $document_number, $contract_id, $comment]);

            $check = $pdo->prepare("SELECT id FROM warehouse WHERE product_id = ?");
            $check->execute([$product_id]);
            if ($check->fetch()) {
                $update = $pdo->prepare("UPDATE warehouse SET quantity = quantity + ? WHERE product_id = ?");
                $update->execute([$quantity, $product_id]);
            } else {
                $insert = $pdo->prepare("INSERT INTO warehouse (product_id, quantity) VALUES (?, ?)");
                $insert->execute([$product_id, $quantity]);
            }
        }

        // Общая запись
        $summary = $pdo->prepare("INSERT INTO warehouse_movements 
            (product_id, movement_type, quantity, document_type, document_number, document_date, contract_id, comment, created_at) 
            VALUES (0, 'приход', ?, 'Приходный ордер', ?, NOW(), ?, ?, NOW())");
        $summary->execute([$total_quantity, $document_number, $contract_id, "Приход товаров:\n" . implode("\n", $items_list)]);

        $pdo->commit();

        echo json_encode(['success' => true, 'message' => 'Товары добавлены на склад. Документ: ' . $document_number]);

    } catch (Exception $e) {
        if ($pdo->inTransaction())
            $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// Обработка списания товара
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'remove_stock') {
    header('Content-Type: application/json');

    try {
        $product_id = $_POST['product_id'];
        $quantity = floatval($_POST['quantity']);
        $document_number = $_POST['document_number'] ?? 'РН-' . date('Ymd') . '-' . rand(100, 999);
        $comment = $_POST['comment'] ?? '';

        if ($quantity <= 0) {
            echo json_encode(['success' => false, 'message' => 'Количество должно быть больше 0']);
            exit;
        }

        $check = $pdo->prepare("SELECT quantity FROM warehouse WHERE product_id = ?");
        $check->execute([$product_id]);
        $available = $check->fetchColumn();

        if ($available < $quantity) {
            echo json_encode(['success' => false, 'message' => 'Недостаточно товара на складе']);
            exit;
        }

        $pdo->beginTransaction();

        $stmt = $pdo->prepare("INSERT INTO warehouse_movements 
            (product_id, movement_type, quantity, document_type, document_number, document_date, comment, created_at) 
            VALUES (?, 'расход', ?, 'Расходная накладная', ?, NOW(), ?, NOW())");
        $stmt->execute([$product_id, $quantity, $document_number, $comment]);

        $update = $pdo->prepare("UPDATE warehouse SET quantity = quantity - ? WHERE product_id = ?");
        $update->execute([$quantity, $product_id]);

        $pdo->commit();

        echo json_encode(['success' => true, 'message' => 'Товар списан со склада. Документ: ' . $document_number]);

    } catch (Exception $e) {
        if ($pdo->inTransaction())
            $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

$role_names = [
    'admin' => 'Директор',
    'manager' => 'Менеджер',
    'warehouse_keeper' => 'Кладовщик',
    'accountant' => 'Бухгалтер',
    'customer' => 'Покупатель'
];

function getMovementTypeBadge($type)
{
    if ($type == 'приход') {
        return '<span class="badge bg-success"><i class="bi bi-arrow-down-circle"></i> Приход</span>';
    } else {
        return '<span class="badge bg-danger"><i class="bi bi-arrow-up-circle"></i> Расход</span>';
    }
}
?>
<!DOCTYPE html>
<html lang="ru">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Склад | Буратино</title>
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

        .box-card {
            background: white;
            border-radius: 15px;
            padding: 20px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.05);
            margin-bottom: 20px;
            border-left: 5px solid #0d6efd;
            transition: transform 0.2s;
        }

        .box-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
        }

        .stock-card {
            background: white;
            border-radius: 15px;
            padding: 20px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.05);
            margin-bottom: 20px;
        }

        .quantity-low {
            color: #e74c3c;
            font-weight: bold;
        }

        .quantity-normal {
            color: #27ae60;
            font-weight: bold;
        }

        .btn-primary {
            background: linear-gradient(135deg, #2ecc71, #27ae60);
            border: none;
        }

        .btn-ship {
            background: linear-gradient(135deg, #0d6efd, #0b5ed7);
            border: none;
            color: white;
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

        .movement-table td {
            vertical-align: middle;
        }

        .doc-link {
            text-decoration: none;
            font-weight: 500;
        }

        .doc-link:hover {
            text-decoration: underline;
        }
    </style>
</head>

<body>

    <nav class="navbar navbar-expand-lg navbar-dark bg-dark mb-4">
        <div class="container-fluid">
            <a class="navbar-brand" href="#"><i class="bi bi-tree-fill me-2" style="color: #2ecc71;"></i>Буратино</a>
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
                        <li class="nav-item"><a class="nav-link active" href="warehouse.php"><i
                                    class="bi bi-shop me-1"></i>Склад</a></li>
                    <?php endif; ?>
                    <?php if (in_array($current_user['role'], ['admin', 'accountant'])): ?>
                        <li class="nav-item"><a class="nav-link" href="accounting.php"><i
                                    class="bi bi-calculator-fill me-1"></i>Учёт</a></li>
                    <?php endif; ?>
                    <?php if (in_array($current_user['role'], ['admin', 'manager'])): ?>
                        <li class="nav-item"><a class="nav-link" href="complaints_admin.php"><i
                                    class="bi bi-exclamation-triangle-fill me-1"></i>Жалобы</a></li>
                    <?php endif; ?>
                </ul>
                <div class="d-flex align-items-center">
                    <div class="text-white me-3">
                        <i class="bi bi-person-circle me-1"></i>
                        <span><?= htmlspecialchars($current_user['email']) ?></span>
                        <span
                            class="role-badge-custom"><?= $role_names[$current_user['role']] ?? $current_user['role'] ?></span>
                    </div>
                    <a href="logout.php" class="btn btn-outline-light btn-sm"><i class="bi bi-box-arrow-right"></i></a>
                </div>
            </div>
        </div>
    </nav>

    <main class="container-fluid px-4">

        <div class="page-header d-flex justify-content-between align-items-center">
            <div>
                <h2 class="mb-1"><i class="bi bi-shop me-2" style="color: #2ecc71;"></i>Склад</h2>
                <p class="text-muted small">Управление боксами по заказам и складскими документами</p>
            </div>
            <div>
                <button type="button" class="btn btn-primary me-2" data-bs-toggle="modal"
                    data-bs-target="#addStockModal">
                    <i class="bi bi-plus-circle me-2"></i>Приход товара
                </button>
                <button type="button" class="btn btn-warning" data-bs-toggle="modal" data-bs-target="#removeStockModal">
                    <i class="bi bi-dash-circle me-2"></i>Расход товара
                </button>
            </div>
        </div>

        <!-- Боксы по заказам -->
        <div class="row mt-2">
            <div class="col-12 d-flex justify-content-between align-items-center">
                <h4 class="mb-3"><i class="bi bi-box-seam me-2"></i>Боксы по заказам</h4>
                <?php if (!empty($ready_contracts)): ?>
                    <button type="button" class="btn btn-primary mb-3" data-bs-toggle="modal"
                        data-bs-target="#createBoxModal">
                        <i class="bi bi-plus-circle me-2"></i>Создать бокс
                    </button>
                <?php endif; ?>
            </div>
        </div>

        <?php if (empty($boxes)): ?>
            <div class="text-center py-5">
                <i class="bi bi-inbox fs-1 text-muted d-block mb-3"></i>
                <h5 class="text-muted">Нет созданных боксов</h5>
                <?php if (!empty($ready_contracts)): ?>
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createBoxModal">
                        <i class="bi bi-plus-circle me-2"></i>Создать бокс
                    </button>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <?php foreach ($boxes as $box):
                $items = $box_items[$box['id']] ?? [];
                $total = array_sum(array_map(function ($item) {
                    return $item['quantity'] * $item['price']; }, $items));
                ?>
                <div class="box-card">
                    <div class="row align-items-start">
                        <div class="col-md-7">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <h5 class="mb-0">Бокс <?= htmlspecialchars($box['box_number']) ?></h5>
                                <span class="badge bg-primary">
                                    <?php if ($box['status'] == 'в боксе'): ?>Скомплектован
                                    <?php elseif ($box['status'] == 'отгружен'): ?>Отгружен
                                    <?php else: ?>В обработке<?php endif; ?>
                                </span>
                            </div>
                            <p class="mb-1"><i class="bi bi-file-text me-2"></i>Заказ:
                                <?= htmlspecialchars($box['contract_number']) ?></p>
                            <p class="mb-1"><i class="bi bi-building me-2"></i>Клиент:
                                <?= htmlspecialchars($box['customer_name']) ?></p>
                            <p class="mb-1 text-muted small"><i class="bi bi-calendar me-2"></i>Дата отгрузки:
                                <?= date('d.m.Y', strtotime($box['desired_shipment_date'])) ?></p>

                            <?php if (!empty($items)): ?>
                                <div class="mt-3">
                                    <h6 class="mb-2">Состав бокса:</h6>
                                    <div class="table-responsive">
                                        <table class="table table-sm">
                                            <thead>
                                                <tr>
                                                    <th>Товар</th>
                                                    <th>Кол-во</th>
                                                    <th>Цена</th>
                                                    <th>Сумма</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($items as $item): ?>
                                                    <tr>
                                                        <td><?= htmlspecialchars($item['product_name']) ?></td>
                                                        <td><?= number_format($item['quantity'], 0, ',', ' ') ?>                 <?= $item['unit'] ?>
                                                        </td>
                                                        <td><?= number_format($item['price'], 2, ',', ' ') ?> ₽</td>
                                                        <td><?= number_format($item['quantity'] * $item['price'], 2, ',', ' ') ?> ₽</td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                            <tfoot>
                                                <tr>
                                                    <th colspan="3" class="text-end">Итого:</th>
                                                    <th class="text-success"><?= number_format($total, 2, ',', ' ') ?> ₽</th>
                                                </tr>
                                            </tfoot>
                                        </table>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-5 text-md-end">
                            <?php if ($box['status'] == 'подписан' && ($current_user['role'] == 'admin' || $current_user['role'] == 'warehouse_keeper')): ?>
                                <button class="btn btn-primary w-100 mb-2 move-to-box-btn" data-id="<?= $box['id'] ?>"
                                    data-number="<?= htmlspecialchars($box['box_number']) ?>">
                                    <i class="bi bi-arrow-right-circle me-2"></i>Скомплектовать бокс
                                </button>
                            <?php endif; ?>
                            <?php if ($box['status'] == 'в боксе' && ($current_user['role'] == 'admin' || $current_user['role'] == 'warehouse_keeper')): ?>
                                <button class="btn btn-ship w-100 ship-box-btn" data-id="<?= $box['id'] ?>"
                                    data-number="<?= htmlspecialchars($box['box_number']) ?>">
                                    <i class="bi bi-truck me-2"></i>Отгрузить бокс
                                </button>
                            <?php endif; ?>
                            <?php if ($box['status'] == 'отгружен'): ?>
                                <span class="badge bg-success p-3 w-100"><i class="bi bi-check-circle me-2"></i>Отгружен
                                    <?= date('d.m.Y', strtotime($box['created_at'])) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <!-- Общий склад -->
        <div class="stock-card mt-4">
            <h5 class="mb-3"><i class="bi bi-boxes me-2"></i>Общий склад</h5>
            <div class="table-responsive">
                <table id="stockTable" class="table table-hover">
                    <thead class="table-light">
                        <tr>
                            <th>ID</th>
                            <th>Наименование</th>
                            <th>Ед. изм.</th>
                            <th>Количество</th>
                            <th>Цена</th>
                            <th>Сумма</th>
                            <th>Последнее обновление</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($stock as $item):
                            $total = $item['quantity'] * $item['current_price'];
                            $low_stock = $item['quantity'] < 10; ?>
                            <tr>
                                <td><?= $item['product_id'] ?></td>
                                <td><?= htmlspecialchars($item['name']) ?></td>
                                <td><?= $item['unit'] ?></td>
                                <td class="<?= $low_stock ? 'quantity-low' : 'quantity-normal' ?>">
                                    <?= number_format($item['quantity'], 0, ',', ' ') ?></td>
                                <td><?= number_format($item['current_price'], 2, ',', ' ') ?> ₽</td>
                                <td><?= number_format($total, 2, ',', ' ') ?> ₽</td>
                                <td><?= date('d.m.Y H:i', strtotime($item['last_updated'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Таблица приход/расход -->
        <div class="stock-card mt-4">
            <h5 class="mb-3"><i class="bi bi-arrow-left-right me-2"></i>Движение товара (приход/расход)</h5>
            <div class="table-responsive">
                <table id="movementsTable" class="table table-hover">
                    <thead class="table-light">
                        <tr>
                            <th>Дата</th>
                            <th>Тип</th>
                            <th>Товар</th>
                            <th>Кол-во</th>
                            <th>Документ</th>
                            <th>Основание</th>
                            <th>Комментарий</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($movements as $move): ?>
                            <tr>
                                <td><?= date('d.m.Y H:i', strtotime($move['document_date'])) ?></td>
                                <td><?= getMovementTypeBadge($move['movement_type']) ?></td>
                                <td>
                                    <?php if ($move['product_id'] == 0): ?>
                                        <span class="text-primary fw-bold">📦 ВСЕ ТОВАРЫ</span>
                                    <?php else: ?>
                                        <?= htmlspecialchars($move['product_name']) ?> (<?= htmlspecialchars($move['unit']) ?>)
                                    <?php endif; ?>
                                </td>
                                <td class="fw-bold"><?= number_format($move['quantity'], 0, ',', ' ') ?>
                                    <?= $move['unit'] ?? 'шт' ?></td>
                                <td>
                                    <?php
                                    $doc_type = ($move['movement_type'] == 'приход') ? 'Приходный ордер' : 'Расходная накладная';
                                    if ($move['document_type'] == 'Отгрузка бокса')
                                        $doc_type = 'Акт отгрузки';
                                    $doc_number = htmlspecialchars($move['document_number']);
                                    ?>
                                    <a href="generate_stock_document.php?id=<?= $move['id'] ?>"
                                        class="doc-link text-primary" target="_blank">
                                        <i class="bi bi-file-text me-1"></i><?= $doc_type ?> №<?= $doc_number ?>
                                    </a>
                                </td>
                                <td>
                                    <?php if ($move['contract_number']): ?>
                                        Договор №<?= htmlspecialchars($move['contract_number']) ?>
                                    <?php elseif ($move['box_number']): ?>
                                        Бокс <?= htmlspecialchars($move['box_number']) ?>
                                    <?php else: ?>
                                        —
                                    <?php endif; ?>
                                </td>
                                <td><small class="text-muted"><?= htmlspecialchars($move['comment'] ?? '') ?></small></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </main>

    <!-- Модальное окно создания бокса -->
    <div class="modal fade" id="createBoxModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="bi bi-box-seam me-2"></i>Создать бокс</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="createBoxForm">
                        <input type="hidden" name="action" value="create_box">
                        <div class="mb-3">
                            <label class="form-label">Выберите заказ</label>
                            <select class="form-select" name="contract_id" required>
                                <option value="">Выберите заказ</option>
                                <?php foreach ($ready_contracts as $contract): ?>
                                    <option value="<?= $contract['id'] ?>">
                                        №<?= htmlspecialchars($contract['contract_number']) ?> -
                                        <?= htmlspecialchars($contract['customer_name']) ?>
                                        (<?= number_format($contract['total_amount'], 2, ',', ' ') ?> ₽)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                    <button type="button" class="btn btn-primary" id="saveCreateBoxBtn"><i
                            class="bi bi-check2-circle me-2"></i>Создать бокс</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Модальное окно прихода товара (несколько товаров) -->
    <div class="modal fade" id="addStockModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title"><i class="bi bi-plus-circle me-2"></i>Приход товара</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="addStockForm">
                        <input type="hidden" name="action" value="add_stock">
                        <input type="hidden" name="items" id="items_json">

                        <div class="mb-3">
                            <label class="form-label">Договор (основание)</label>
                            <select class="form-select" name="contract_id">
                                <option value="">Без договора</option>
                                <?php foreach ($contracts_list as $contract): ?>
                                    <option value="<?= $contract['id'] ?>">
                                        №<?= htmlspecialchars($contract['contract_number']) ?> -
                                        <?= htmlspecialchars($contract['customer_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-bold">Товары</label>
                            <div id="items_container">
                                <div class="row mb-2 item-row">
                                    <div class="col-md-6">
                                        <select class="form-select product-select" name="product_id[]" required>
                                            <option value="">Выберите товар</option>
                                            <?php foreach ($products as $product): ?>
                                                <option value="<?= $product['id'] ?>">
                                                    <?= htmlspecialchars($product['name']) ?> (<?= $product['unit'] ?>)
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-4">
                                        <input type="number" step="1" min="0" class="form-control quantity-input"
                                            name="quantity[]" placeholder="Количество" required>
                                    </div>
                                    <div class="col-md-2">
                                        <button type="button" class="btn btn-danger remove-item-btn"
                                            style="display: none;">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-primary mt-2" id="add_item_btn">
                                <i class="bi bi-plus-circle me-1"></i> Добавить товар
                            </button>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Комментарий</label>
                            <textarea class="form-control" name="comment" rows="2"
                                placeholder="Общий комментарий к приходу"></textarea>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                    <button type="button" class="btn btn-success" id="saveAddStockBtn"><i
                            class="bi bi-check2-circle me-2"></i>Добавить</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Модальное окно расхода товара -->
    <div class="modal fade" id="removeStockModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-warning text-white">
                    <h5 class="modal-title"><i class="bi bi-dash-circle me-2"></i>Расход товара</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="removeStockForm">
                        <input type="hidden" name="action" value="remove_stock">
                        <div class="mb-3">
                            <label class="form-label">Товар</label>
                            <select class="form-select" name="product_id" required>
                                <option value="">Выберите товар</option>
                                <?php foreach ($products as $product): ?>
                                    <option value="<?= $product['id'] ?>"><?= htmlspecialchars($product['name']) ?>
                                        (<?= $product['unit'] ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Количество</label>
                            <input type="number" step="1" min="0" class="form-control" name="quantity" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Номер документа (Расходная накладная)</label>
                            <input type="text" class="form-control" name="document_number"
                                placeholder="РН-20260406-001">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Комментарий</label>
                            <textarea class="form-control" name="comment" rows="2"></textarea>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                    <button type="button" class="btn btn-warning" id="saveRemoveStockBtn"><i
                            class="bi bi-check2-circle me-2"></i>Списать</button>
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
            $('#stockTable').DataTable({ language: { url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/ru.json' }, pageLength: 10, order: [[0, 'asc']] });
            $('#movementsTable').DataTable({ language: { url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/ru.json' }, pageLength: 15, order: [[0, 'desc']] });

            // Создание бокса
            $('#saveCreateBoxBtn').click(function () {
                $.ajax({
                    url: 'warehouse.php', type: 'POST', data: $('#createBoxForm').serialize(), dataType: 'json', success: function (response) {
                        if (response.success) Swal.fire({ icon: 'success', title: 'Успешно!', text: response.message, timer: 1500 }).then(() => location.reload());
                        else Swal.fire({ icon: 'error', title: 'Ошибка', text: response.message });
                    }
                });
            });

            // Комплектация бокса
            $('.move-to-box-btn').click(function () {
                const id = $(this).data('id'), number = $(this).data('number');
                Swal.fire({ title: 'Комплектация бокса', html: `Скомплектовать бокс <strong>${number}</strong>?`, icon: 'question', showCancelButton: true, confirmButtonColor: '#2ecc71', confirmButtonText: 'Да, комплектовать' }).then((result) => {
                    if (result.isConfirmed) $.ajax({
                        url: 'warehouse.php', type: 'POST', data: { action: 'move_to_box', box_id: id }, dataType: 'json', success: function (response) {
                            if (response.success) Swal.fire({ icon: 'success', title: 'Успешно!', text: response.message, timer: 1500 }).then(() => location.reload());
                            else Swal.fire({ icon: 'error', title: 'Ошибка', text: response.message });
                        }
                    });
                });
            });

            // Отгрузка бокса
            $('.ship-box-btn').click(function () {
                const id = $(this).data('id'), number = $(this).data('number');
                Swal.fire({ title: 'Отгрузка бокса', html: `Отгрузить бокс <strong>${number}</strong>?`, icon: 'question', showCancelButton: true, confirmButtonColor: '#0d6efd', confirmButtonText: 'Да, отгрузить' }).then((result) => {
                    if (result.isConfirmed) $.ajax({
                        url: 'warehouse.php', type: 'POST', data: { action: 'ship_box', box_id: id }, dataType: 'json', success: function (response) {
                            if (response.success) Swal.fire({ icon: 'success', title: 'Успешно!', text: response.message, timer: 1500 }).then(() => location.reload());
                            else Swal.fire({ icon: 'error', title: 'Ошибка', text: response.message });
                        }
                    });
                });
            });

            // Добавление строки товара
            $('#add_item_btn').click(function () {
                const newRow = $('.item-row:first').clone();
                newRow.find('select').val('');
                newRow.find('.quantity-input').val('');
                newRow.find('.remove-item-btn').show();
                $('#items_container').append(newRow);
            });

            // Удаление строки товара
            $(document).on('click', '.remove-item-btn', function () {
                if ($('.item-row').length > 1) {
                    $(this).closest('.item-row').remove();
                } else {
                    Swal.fire('Внимание', 'Должен быть хотя бы один товар', 'warning');
                }
            });

            // Сохранение прихода
            $('#saveAddStockBtn').click(function () {
                const items = [];
                let hasError = false;

                $('.item-row').each(function () {
                    const product_id = $(this).find('.product-select').val();
                    const quantity = $(this).find('.quantity-input').val();

                    if (!product_id) {
                        Swal.fire('Ошибка', 'Выберите товар', 'error');
                        hasError = true;
                        return false;
                    }
                    if (!quantity || quantity <= 0) {
                        Swal.fire('Ошибка', 'Введите корректное количество', 'error');
                        hasError = true;
                        return false;
                    }

                    items.push({ product_id: product_id, quantity: quantity });
                });

                if (hasError) return;

                $('#items_json').val(JSON.stringify(items));

                $.ajax({
                    url: 'warehouse.php',
                    type: 'POST',
                    data: $('#addStockForm').serialize(),
                    dataType: 'json',
                    success: function (response) {
                        if (response.success) {
                            Swal.fire({ icon: 'success', title: 'Успешно!', text: response.message, timer: 1500 }).then(() => location.reload());
                        } else {
                            Swal.fire({ icon: 'error', title: 'Ошибка', text: response.message });
                        }
                    }
                });
            });

            // Списание товара
            $('#saveRemoveStockBtn').click(function () {
                $.ajax({
                    url: 'warehouse.php', type: 'POST', data: $('#removeStockForm').serialize(), dataType: 'json', success: function (response) {
                        if (response.success) Swal.fire({ icon: 'success', title: 'Успешно!', text: response.message, timer: 1500 }).then(() => location.reload());
                        else Swal.fire({ icon: 'error', title: 'Ошибка', text: response.message });
                    }
                });
            });
        });
    </script>

</body>

</html>