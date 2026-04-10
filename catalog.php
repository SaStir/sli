<?php
// catalog.php - Каталог товаров с выбором даты отгрузки
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

require 'config.php';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Ошибка подключения к БД: " . $e->getMessage());
}

// Получаем информацию о пользователе с данными покупателя
$stmt = $pdo->prepare("SELECT u.*, u.role, c.name as customer_name, c.id as customer_id,
                              c.inn, c.bank_name, c.bik, c.account_number,
                              c.director_name, c.chief_accountant_name
                        FROM users u 
                        LEFT JOIN customers c ON u.customer_id = c.id 
                        WHERE u.id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

// Если админ пытается зайти в каталог - редирект на договоры
if ($user['is_admin']) {
    header('Location: contracts.php');
    exit;
}

if (!$user) {
    session_destroy();
    header('Location: index.php');
    exit;
}

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

// Определяем тип организации
$organization_type = '';
if (!empty($user['customer_name'])) {
    if (stripos($user['customer_name'], 'ИП') === 0) {
        $organization_type = 'ИП';
    } elseif (stripos($user['customer_name'], 'ООО') === 0) {
        $organization_type = 'ООО';
    } elseif (stripos($user['customer_name'], 'ЗАО') === 0) {
        $organization_type = 'ЗАО';
    } elseif (stripos($user['customer_name'], 'АО') === 0) {
        $organization_type = 'АО';
    } else {
        $organization_type = 'Организация';
    }
}

// Обработка создания договора из корзины
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_order') {
    header('Content-Type: application/json');

    try {
        $cart = json_decode($_POST['cart'], true);
        $desired_date = $_POST['desired_date'] ?? null;
        $customer_phone = $_POST['customer_phone'] ?? '';
        $order_comment = $_POST['order_comment'] ?? '';

        // Тип оплаты
        $payment_type = $_POST['payment_type'] ?? 'full_prepayment';
        $prepayment_percent = isset($_POST['prepayment_percent']) ? (int) $_POST['prepayment_percent'] : null;

        // Проверка процента предоплаты для частичной предоплаты
        if ($payment_type === 'partial_prepayment') {
            if (!$prepayment_percent || $prepayment_percent <= 0 || $prepayment_percent >= 100) {
                echo json_encode(['success' => false, 'message' => 'Укажите корректный процент предоплаты (от 1 до 99)']);
                exit;
            }
        }

        // Юридическая информация
        $legal_entity_name = $_POST['legal_entity_name'] ?? '';
        $legal_inn = $_POST['legal_inn'] ?? '';
        $legal_bank_name = $_POST['legal_bank_name'] ?? '';
        $legal_bik = $_POST['legal_bik'] ?? '';
        $legal_account_number = $_POST['legal_account_number'] ?? '';
        $legal_director = $_POST['legal_director'] ?? '';
        $legal_accountant = $_POST['legal_accountant'] ?? '';

        if (empty($cart)) {
            echo json_encode(['success' => false, 'message' => 'Корзина пуста']);
            exit;
        }

        // Проверка даты отгрузки
        if (!$desired_date) {
            echo json_encode(['success' => false, 'message' => 'Укажите желаемую дату отгрузки']);
            exit;
        }

        $tomorrow = date('Y-m-d', strtotime('+1 day'));

        if ($desired_date < $tomorrow) {
            echo json_encode(['success' => false, 'message' => 'Дата отгрузки не может быть раньше завтрашнего дня']);
            exit;
        }

        // Проверяем, что у пользователя есть customer_id
        if (!$user['customer_id']) {
            echo json_encode(['success' => false, 'message' => 'Ваш аккаунт не привязан к покупателю. Обратитесь к администратору.']);
            exit;
        }

        $pdo->beginTransaction();

        // Генерируем номер договора
        $contract_number = 'ДОГ-' . date('Ymd') . '-' . rand(100, 999);

        // Формируем комментарий с юридической информацией и условиями оплаты
        $legal_info = "Юридическое лицо: {$legal_entity_name}\n";
        $legal_info .= "ИНН: {$legal_inn}\n";
        $legal_info .= "Банк: {$legal_bank_name}\n";
        $legal_info .= "БИК: {$legal_bik}\n";
        $legal_info .= "Р/с: {$legal_account_number}\n";
        if ($legal_director)
            $legal_info .= "Директор: {$legal_director}\n";
        if ($legal_accountant)
            $legal_info .= "Гл. бухгалтер: {$legal_accountant}\n";

        // Добавляем информацию об условиях оплаты
        $payment_info = "\n\n---\nУсловия оплаты:\n";
        switch ($payment_type) {
            case 'full_prepayment':
                $payment_info .= "Полная предоплата 100% до отгрузки";
                break;
            case 'partial_prepayment':
                $payment_info .= "Частичная предоплата {$prepayment_percent}% от суммы заказа. Остаток оплачивается после отгрузки";
                break;
            case 'postpayment':
                $payment_info .= "Оплата после отгрузки (отсрочка платежа 10 дней)";
                break;
            default:
                $payment_info .= "Полная предоплата 100% до отгрузки";
        }

        $full_comment = $order_comment ? $order_comment . "\n\n---\n" . $legal_info . $payment_info : $legal_info . $payment_info;

        // Создаём договор с информацией об оплате
        $stmt = $pdo->prepare("INSERT INTO contracts 
    (customer_id, contract_number, contract_date, desired_shipment_date, status, total_amount, valid_from, payment_deadline, created_by, customer_phone, order_comment, payment_type, prepayment_percent) 
    VALUES (?, ?, NOW(), ?, 'на подписании', 0, NOW(), DATE_ADD(NOW(), INTERVAL 10 DAY), ?, ?, ?, ?, ?)");
        $stmt->execute([$user['customer_id'], $contract_number, $desired_date, $user['id'], $customer_phone, $full_comment, $payment_type, $prepayment_percent]);
        $contract_id = $pdo->lastInsertId();

        $total_amount = 0;

        // Добавляем позиции и одновременно резервируем товар на складе
        foreach ($cart as $item) {
            $stmt = $pdo->prepare("INSERT INTO contract_items 
                (contract_id, product_id, quantity, price) 
                VALUES (?, ?, ?, ?)");
            $stmt->execute([$contract_id, $item['id'], $item['qty'], $item['price']]);

            $total_amount += $item['qty'] * $item['price'];

            // ----- НОВАЯ ЛОГИКА: добавляем товар на склад -----
            $product_id = $item['id'];
            $quantity = $item['qty'];

            // 1. Обновляем основную таблицу склада warehouse
            $check_warehouse = $pdo->prepare("SELECT id FROM warehouse WHERE product_id = ?");
            $check_warehouse->execute([$product_id]);
            if ($check_warehouse->fetch()) {
                $update_warehouse = $pdo->prepare("UPDATE warehouse SET quantity = quantity + ? WHERE product_id = ?");
                $update_warehouse->execute([$quantity, $product_id]);
            } else {
                $insert_warehouse = $pdo->prepare("INSERT INTO warehouse (product_id, quantity) VALUES (?, ?)");
                $insert_warehouse->execute([$product_id, $quantity]);
            }

            // 2. Записываем движение в warehouse_movements (приход)
            $movement = $pdo->prepare("INSERT INTO warehouse_movements 
                (product_id, movement_type, quantity, document_type, document_number, document_date, contract_id, comment) 
                VALUES (?, 'приход', ?, 'Резервирование по договору', ?, NOW(), ?, ?)");
            $movement->execute([$product_id, $quantity, $contract_number, $contract_id, 'Резервирование товара по договору']);
        }

        // Обновляем общую сумму и рассчитываем сумму предоплаты
        $prepayment_amount = 0;
        if ($payment_type === 'full_prepayment') {
            $prepayment_amount = $total_amount;
        } elseif ($payment_type === 'partial_prepayment') {
            $prepayment_amount = $total_amount * $prepayment_percent / 100;
        }

        $stmt = $pdo->prepare("UPDATE contracts SET total_amount = ?, prepayment_amount = ? WHERE id = ?");
        $stmt->execute([$total_amount, $prepayment_amount, $contract_id]);

        $pdo->commit();

        // Формируем сообщение с условиями оплаты
        $payment_message = "";
        switch ($payment_type) {
            case 'full_prepayment':
                $payment_message = "Требуется полная предоплата: " . number_format($prepayment_amount, 2, ',', ' ') . " ₽";
                break;
            case 'partial_prepayment':
                $payment_message = "Требуется предоплата {$prepayment_percent}%: " . number_format($prepayment_amount, 2, ',', ' ') . " ₽. Остаток: " . number_format($total_amount - $prepayment_amount, 2, ',', ' ') . " ₽ после отгрузки";
                break;
            case 'postpayment':
                $payment_message = "Оплата после отгрузки в течение 10 дней";
                break;
        }

        echo json_encode([
            'success' => true,
            'message' => 'Договор успешно создан! Номер договора: ' . $contract_number . '. ' . $payment_message,
            'contract_id' => $contract_id
        ]);

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// Получаем товары
$products_stmt = $pdo->query("
    SELECT p.*
    FROM products p
    ORDER BY p.name ASC
");
$products = $products_stmt->fetchAll(PDO::FETCH_ASSOC);

// Минимальная дата для выбора (завтра)
$min_date = date('Y-m-d', strtotime('+1 day'));
?>
<!DOCTYPE html>
<html lang="ru">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Каталог | Буратино</title>
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

        .product-card {
            border: none;
            transition: all 0.3s;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.05);
            height: 100%;
        }

        .product-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.1);
        }

        .price-val {
            font-weight: bold;
            color: #198754;
            font-size: 1.2rem;
        }

        .user-email {
            background-color: rgba(255, 255, 255, 0.1);
            padding: 5px 12px;
            border-radius: 20px;
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .user-email i {
            color: #2ecc71;
        }

        .cart-badge {
            position: relative;
            top: -10px;
            right: 5px;
            font-size: 0.7rem;
        }

        #cartSidebar {
            width: 550px;
        }

        .btn-success {
            background: linear-gradient(135deg, #2ecc71, #27ae60);
            border: none;
        }

        .btn-success:hover {
            background: linear-gradient(135deg, #27ae60, #2ecc71);
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(46, 204, 113, 0.3);
        }

        .form-section {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 15px;
        }

        .form-section-title {
            font-size: 0.9rem;
            font-weight: 600;
            margin-bottom: 10px;
            color: #2c3e50;
            border-left: 3px solid #2ecc71;
            padding-left: 10px;
        }

        .cart-item-controls {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .cart-item-qty-input {
            width: 70px;
            text-align: center;
        }

        .cart-item-price {
            min-width: 100px;
            text-align: right;
        }

        .legal-info-badge {
            font-size: 0.7rem;
            background: #e9ecef;
            padding: 2px 6px;
            border-radius: 4px;
            color: #495057;
        }

        .edit-icon {
            cursor: pointer;
            color: #6c757d;
            font-size: 0.8rem;
            margin-left: 5px;
        }

        .edit-icon:hover {
            color: #2ecc71;
        }

        .payment-option {
            border: 2px solid #dee2e6;
            border-radius: 10px;
            padding: 12px;
            margin-bottom: 10px;
            cursor: pointer;
            transition: all 0.2s;
        }

        .payment-option:hover {
            border-color: #2ecc71;
            background-color: #f0fff4;
        }

        .payment-option.selected {
            border-color: #2ecc71;
            background-color: #e8f5e9;
        }

        .payment-option-title {
            font-weight: bold;
            margin-bottom: 5px;
        }

        .payment-option-desc {
            font-size: 0.8rem;
            color: #6c757d;
        }

        .prepayment-slider {
            margin-top: 15px;
            padding: 10px;
            background: #fff;
            border-radius: 8px;
            border: 1px solid #dee2e6;
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
                <li class="nav-item">
                    <a class="nav-link active" href="catalog.php">
                        <i class="bi bi-box-seam me-1"></i>Каталог
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="contracts.php">
                        <i class="bi bi-file-text-fill me-1"></i>Договоры
                    </a>
                </li>
		<li class="nav-item">
    		    <a class="nav-link" href="feedback.php">
        	    	<i class="bi bi-chat-dots-fill me-1"></i>Оценка и жалобы
    		    </a>
		</li>
            </ul>
            <div class="d-flex align-items-center">
                <div class="text-white me-3">
                    <i class="bi bi-person-circle me-1"></i>
                    <span><?= htmlspecialchars($user['email']) ?></span>
                    <!-- Единое оформление должности -->
                    <span class="role-badge-custom">
                        Покупатель
                    </span>
                </div>
                <a href="logout.php" class="btn btn-outline-light btn-sm" title="Выйти">
                    <i class="bi bi-box-arrow-right"></i>
                </a>
            </div>
        </div>
    </div>
</nav>

    <!-- Кнопка корзины -->
    <div class="container-fluid px-4 mb-3">
        <div class="d-flex justify-content-end">
            <button class="btn btn-success position-relative" type="button" data-bs-toggle="offcanvas"
                data-bs-target="#cartSidebar">
                <i class="bi bi-cart3 me-2"></i>Заказ
                <span
                    class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger cart-badge d-none"
                    id="cartBadge">0</span>
            </button>
        </div>
    </div>

    <!-- Каталог товаров -->
    <div class="container-fluid px-4">
        <div class="row">
            <?php if (empty($products)): ?>
                <div class="col-12 text-center py-5">
                    <p class="text-muted">В каталоге пока нет доступных товаров.</p>
                </div>
            <?php else: ?>
                <?php foreach ($products as $product): ?>
                    <div class="col-md-4 mb-4">
                        <div class="card product-card">
                            <div class="card-body d-flex flex-column">
                                <h5 class="card-title"><?= htmlspecialchars($product['name']) ?></h5>
                                <p class="text-muted small mb-2">
                                    <?= htmlspecialchars($product['description'] ?? 'Пиломатериал высокого качества') ?>
                                </p>

                                <div class="mt-auto">
                                    <p class="price-val mb-2">
                                        <?= number_format($product['current_price'], 2, ',', ' ') ?> ₽
                                        <small class="text-muted">/ <?= htmlspecialchars($product['unit'] ?? 'м³') ?></small>
                                    </p>

                                    <button class="btn btn-success w-100 add-to-cart mt-2" data-id="<?= $product['id'] ?>"
                                        data-name="<?= htmlspecialchars($product['name']) ?>"
                                        data-price="<?= $product['current_price'] ?>"
                                        data-unit="<?= htmlspecialchars($product['unit'] ?? 'м³') ?>">
                                        <i class="bi bi-plus-lg me-1"></i> Заказать
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Корзина (offcanvas) с выбором даты и формами -->
    <div class="offcanvas offcanvas-end" tabindex="-1" id="cartSidebar">
        <div class="offcanvas-header bg-light">
            <h5 class="offcanvas-title">Оформление договора</h5>
            <button type="button" class="btn-close" data-bs-dismiss="offcanvas"></button>
        </div>
        <div class="offcanvas-body d-flex flex-column">
            <div id="cartContent" class="flex-grow-1">
                <p class="text-center text-muted">Корзина пуста</p>
            </div>

            <!-- Формы для заполнения информации -->
            <div id="orderForms" class="d-none">
                <!-- Юридическая информация -->
                <div class="form-section">
                    <div class="form-section-title">
                        <i class="bi bi-building me-1"></i> Юридическая информация
                        <span class="legal-info-badge ms-2"><?= htmlspecialchars($organization_type) ?></span>
                        <i class="bi bi-pencil-square edit-icon" id="editLegalBtn" title="Редактировать"></i>
                    </div>
                    <div id="legalInfoDisplay">
                        <div class="row mb-2">
                            <div class="col-4 text-muted small">Наименование:</div>
                            <div class="col-8 fw-medium"><?= htmlspecialchars($user['customer_name'] ?? 'Не указано') ?>
                            </div>
                        </div>
                        <div class="row mb-2">
                            <div class="col-4 text-muted small">ИНН:</div>
                            <div class="col-8 fw-medium"><?= htmlspecialchars($user['inn'] ?? 'Не указан') ?></div>
                        </div>
                        <div class="row mb-2">
                            <div class="col-4 text-muted small">Банк:</div>
                            <div class="col-8"><?= htmlspecialchars($user['bank_name'] ?? 'Не указан') ?></div>
                        </div>
                        <div class="row mb-2">
                            <div class="col-4 text-muted small">БИК:</div>
                            <div class="col-8"><?= htmlspecialchars($user['bik'] ?? 'Не указан') ?></div>
                        </div>
                        <div class="row mb-2">
                            <div class="col-4 text-muted small">Р/счет:</div>
                            <div class="col-8"><?= htmlspecialchars($user['account_number'] ?? 'Не указан') ?></div>
                        </div>
                        <?php if ($user['director_name']): ?>
                            <div class="row mb-2">
                                <div class="col-4 text-muted small">Директор:</div>
                                <div class="col-8"><?= htmlspecialchars($user['director_name']) ?></div>
                            </div>
                        <?php endif; ?>
                        <?php if ($user['chief_accountant_name']): ?>
                            <div class="row mb-2">
                                <div class="col-4 text-muted small">Гл. бухгалтер:</div>
                                <div class="col-8"><?= htmlspecialchars($user['chief_accountant_name']) ?></div>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div id="legalInfoEdit" style="display: none;">
                        <div class="mb-2">
                            <label class="form-label small fw-bold">Наименование организации</label>
                            <input type="text" class="form-control form-control-sm" id="legalEntityName"
                                value="<?= htmlspecialchars($user['customer_name'] ?? '') ?>">
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-2">
                                <label class="form-label small fw-bold">ИНН</label>
                                <input type="text" class="form-control form-control-sm" id="legalInn"
                                    value="<?= htmlspecialchars($user['inn'] ?? '') ?>">
                            </div>
                            <div class="col-md-6 mb-2">
                                <label class="form-label small fw-bold">БИК</label>
                                <input type="text" class="form-control form-control-sm" id="legalBik"
                                    value="<?= htmlspecialchars($user['bik'] ?? '') ?>">
                            </div>
                        </div>
                        <div class="mb-2">
                            <label class="form-label small fw-bold">Наименование банка</label>
                            <input type="text" class="form-control form-control-sm" id="legalBankName"
                                value="<?= htmlspecialchars($user['bank_name'] ?? '') ?>">
                        </div>
                        <div class="mb-2">
                            <label class="form-label small fw-bold">Расчетный счет</label>
                            <input type="text" class="form-control form-control-sm" id="legalAccountNumber"
                                value="<?= htmlspecialchars($user['account_number'] ?? '') ?>">
                        </div>
                        <div class="mb-2">
                            <label class="form-label small fw-bold">Директор (ФИО)</label>
                            <input type="text" class="form-control form-control-sm" id="legalDirector"
                                value="<?= htmlspecialchars($user['director_name'] ?? '') ?>">
                        </div>
                        <div class="mb-2">
                            <label class="form-label small fw-bold">Главный бухгалтер (ФИО)</label>
                            <input type="text" class="form-control form-control-sm" id="legalAccountant"
                                value="<?= htmlspecialchars($user['chief_accountant_name'] ?? '') ?>">
                        </div>
                        <button type="button" class="btn btn-sm btn-success mt-2" id="saveLegalBtn">
                            <i class="bi bi-check-lg"></i> Сохранить
                        </button>
                        <button type="button" class="btn btn-sm btn-secondary mt-2" id="cancelLegalBtn">
                            <i class="bi bi-x-lg"></i> Отмена
                        </button>
                    </div>
                </div>

                <!-- Выбор типа оплаты -->
                <div class="form-section">
                    <div class="form-section-title">
                        <i class="bi bi-credit-card me-1"></i> Условия оплаты
                    </div>

                    <div id="paymentTypeSelector">
                        <div class="payment-option" data-type="full_prepayment">
                            <div class="payment-option-title">
                                <i class="bi bi-cash-stack text-success me-2"></i>
                                Полная предоплата
                            </div>
                            <div class="payment-option-desc">
                                100% оплата до отгрузки товара
                            </div>
                        </div>

                        <div class="payment-option" data-type="partial_prepayment">
                            <div class="payment-option-title">
                                <i class="bi bi-percent text-success me-2"></i>
                                Частичная предоплата
                            </div>
                            <div class="payment-option-desc">
                                Оплата процента от суммы заказа, остаток после отгрузки
                            </div>
                        </div>
                    </div>

                    <input type="hidden" id="selectedPaymentType" name="selected_payment_type" value="full_prepayment">

                    <div id="prepaymentPercentBlock" class="prepayment-slider" style="display: none;">
                        <label class="form-label small fw-bold">Процент предоплаты: <span
                                id="prepaymentPercentValue">50</span>%</label>
                        <input type="range" class="form-range" id="prepaymentPercent" min="1" max="99" value="50"
                            step="1">
                        <div class="form-text text-muted small">
                            Выберите процент предоплаты от суммы заказа
                        </div>
                    </div>
                </div>

                <div class="form-section">
                    <div class="form-section-title">
                        <i class="bi bi-person me-1"></i> Контактная информация
                    </div>
                    <div class="mb-2">
                        <label class="form-label small fw-bold">Контактный телефон</label>
                        <input type="tel" class="form-control form-control-sm" id="customerPhone"
                            placeholder="+7 (___) ___-__-__" value="<?= htmlspecialchars($user['phone'] ?? '') ?>">
                    </div>
                </div>

                <div class="form-section">
                    <div class="form-section-title">
                        <i class="bi bi-calendar me-1"></i> Дата отгрузки
                    </div>
                    <div class="mb-2">
                        <label class="form-label small fw-bold">Желаемая дата отгрузки</label>
                        <input type="date" class="form-control form-control-sm" id="desiredDate" min="<?= $min_date ?>"
                            value="<?= $min_date ?>" required>
                        <div class="form-text text-muted small">
                            <i class="bi bi-info-circle"></i> Минимальная дата:
                            <?= date('d.m.Y', strtotime('+1 day')) ?>
                        </div>
                    </div>
                </div>

                <div class="form-section">
                    <div class="form-section-title">
                        <i class="bi bi-chat me-1"></i> Комментарий к заказу
                    </div>
                    <div class="mb-2">
                        <textarea class="form-control form-control-sm" id="orderComment" rows="2"
                            placeholder="Дополнительная информация, особые пожелания..."></textarea>
                    </div>
                </div>
            </div>

            <div id="cartFooter" class="border-top pt-3 mt-3 d-none">
                <div class="d-flex justify-content-between mb-3 fw-bold">
                    <span>Итого:</span>
                    <span class="text-success" id="totalSum">0 ₽</span>
                </div>
                <button class="btn btn-success w-100" id="checkoutBtn">
                    <i class="bi bi-file-text me-2"></i>Оформить договор
                </button>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <script>
        // Загрузка корзины
        let cart = JSON.parse(localStorage.getItem('cart')) || [];

        // Состояние редактирования юр. информации
        let isEditingLegal = false;

        // Выбранный тип оплаты
        let selectedPaymentType = 'full_prepayment';

        function saveCart() {
            localStorage.setItem('cart', JSON.stringify(cart));
            renderCart();
        }

        function updateItemQuantity(index, newQty) {
            if (newQty < 0) newQty = 0;

            if (newQty === 0) {
                cart.splice(index, 1);
            } else {
                cart[index].qty = newQty;
            }
            saveCart();
        }

        function renderCart() {
            const content = document.getElementById('cartContent');
            const footer = document.getElementById('cartFooter');
            const badge = document.getElementById('cartBadge');
            const orderForms = document.getElementById('orderForms');
            const totalSumSpan = document.getElementById('totalSum');

            if (cart.length === 0) {
                content.innerHTML = '<p class="text-center text-muted">Пусто</p>';
                footer.classList.add('d-none');
                badge.classList.add('d-none');
                orderForms.classList.add('d-none');
                return;
            }

            footer.classList.remove('d-none');
            badge.classList.remove('d-none');
            orderForms.classList.remove('d-none');

            let totalItems = cart.reduce((sum, item) => sum + item.qty, 0);
            let totalSum = cart.reduce((sum, item) => sum + (item.price * item.qty), 0);
            badge.innerText = totalItems;
            totalSumSpan.innerText = totalSum.toLocaleString() + ' ₽';

            content.innerHTML = cart.map((item, index) => {
                const itemTotal = item.price * item.qty;
                return `
                <div class="mb-3 border-bottom pb-2">
                    <div class="fw-bold mb-2">${escapeHtml(item.name)}</div>
                    <div class="d-flex justify-content-between align-items-center">
                        <div class="cart-item-controls">
                            <button class="btn btn-sm btn-outline-secondary" onclick="changeQuantity(${index}, -1)">
                                <i class="bi bi-dash"></i>
                            </button>
                            <input type="number" class="form-control form-control-sm cart-item-qty-input" 
                                   value="${item.qty}" min="0" step="1"
                                   onchange="updateItemQuantity(${index}, parseInt(this.value) || 0)">
                            <button class="btn btn-sm btn-outline-secondary" onclick="changeQuantity(${index}, 1)">
                                <i class="bi bi-plus"></i>
                            </button>
                            <span class="text-muted ms-2">${escapeHtml(item.unit)}</span>
                        </div>
                        <div class="cart-item-price text-end">
                            <div class="small text-muted">${item.price.toLocaleString()} ₽ × ${item.qty}</div>
                            <div class="fw-bold text-success">${itemTotal.toLocaleString()} ₽</div>
                        </div>
                        <button class="btn btn-sm btn-outline-danger ms-2" onclick="removeFromCart(${index})">
                            <i class="bi bi-trash"></i>
                        </button>
                    </div>
                </div>`;
            }).join('');
        }

        // Функция для экранирования HTML
        function escapeHtml(str) {
            if (!str) return '';
            return str.replace(/[&<>]/g, function (m) {
                if (m === '&') return '&amp;';
                if (m === '<') return '&lt;';
                if (m === '>') return '&gt;';
                return m;
            });
        }

        function changeQuantity(index, delta) {
            const item = cart[index];
            const newQty = item.qty + delta;
            updateItemQuantity(index, newQty);
        }

        function removeFromCart(index) {
            cart.splice(index, 1);
            saveCart();
        }

        // Функции для работы с юридической информацией
        function toggleLegalEdit(showEdit) {
            const displayDiv = document.getElementById('legalInfoDisplay');
            const editDiv = document.getElementById('legalInfoEdit');

            if (showEdit) {
                displayDiv.style.display = 'none';
                editDiv.style.display = 'block';
                isEditingLegal = true;
            } else {
                displayDiv.style.display = 'block';
                editDiv.style.display = 'none';
                isEditingLegal = false;
            }
        }

        function saveLegalInfo() {
            const entityName = document.getElementById('legalEntityName').value;
            const inn = document.getElementById('legalInn').value;
            const bik = document.getElementById('legalBik').value;
            const bankName = document.getElementById('legalBankName').value;
            const accountNumber = document.getElementById('legalAccountNumber').value;
            const director = document.getElementById('legalDirector').value;
            const accountant = document.getElementById('legalAccountant').value;

            const displayDiv = document.getElementById('legalInfoDisplay');
            displayDiv.innerHTML = `
                <div class="row mb-2">
                    <div class="col-4 text-muted small">Наименование:</div>
                    <div class="col-8 fw-medium">${escapeHtml(entityName) || 'Не указано'}</div>
                </div>
                <div class="row mb-2">
                    <div class="col-4 text-muted small">ИНН:</div>
                    <div class="col-8 fw-medium">${escapeHtml(inn) || 'Не указан'}</div>
                </div>
                <div class="row mb-2">
                    <div class="col-4 text-muted small">Банк:</div>
                    <div class="col-8">${escapeHtml(bankName) || 'Не указан'}</div>
                </div>
                <div class="row mb-2">
                    <div class="col-4 text-muted small">БИК:</div>
                    <div class="col-8">${escapeHtml(bik) || 'Не указан'}</div>
                </div>
                <div class="row mb-2">
                    <div class="col-4 text-muted small">Р/счет:</div>
                    <div class="col-8">${escapeHtml(accountNumber) || 'Не указан'}</div>
                </div>
                ${director ? `
                <div class="row mb-2">
                    <div class="col-4 text-muted small">Директор:</div>
                    <div class="col-8">${escapeHtml(director)}</div>
                </div>` : ''}
                ${accountant ? `
                <div class="row mb-2">
                    <div class="col-4 text-muted small">Гл. бухгалтер:</div>
                    <div class="col-8">${escapeHtml(accountant)}</div>
                </div>` : ''}
            `;

            toggleLegalEdit(false);
        }

        function getLegalInfo() {
            if (isEditingLegal) {
                return {
                    entity_name: document.getElementById('legalEntityName').value,
                    inn: document.getElementById('legalInn').value,
                    bik: document.getElementById('legalBik').value,
                    bank_name: document.getElementById('legalBankName').value,
                    account_number: document.getElementById('legalAccountNumber').value,
                    director: document.getElementById('legalDirector').value,
                    accountant: document.getElementById('legalAccountant').value
                };
            } else {
                const displayDiv = document.getElementById('legalInfoDisplay');
                const rows = displayDiv.querySelectorAll('.row');
                let legalInfo = {
                    entity_name: '',
                    inn: '',
                    bik: '',
                    bank_name: '',
                    account_number: '',
                    director: '',
                    accountant: ''
                };

                rows.forEach(row => {
                    const label = row.querySelector('.col-4')?.innerText.replace(':', '');
                    const value = row.querySelector('.col-8')?.innerText;
                    if (label === 'Наименование') legalInfo.entity_name = value;
                    if (label === 'ИНН') legalInfo.inn = value;
                    if (label === 'БИК') legalInfo.bik = value;
                    if (label === 'Банк') legalInfo.bank_name = value;
                    if (label === 'Р/счет') legalInfo.account_number = value;
                    if (label === 'Директор') legalInfo.director = value;
                    if (label === 'Гл. бухгалтер') legalInfo.accountant = value;
                });

                return legalInfo;
            }
        }

        // Функции для работы с выбором типа оплаты
        function initPaymentSelector() {
            const options = document.querySelectorAll('.payment-option');
            const prepaymentBlock = document.getElementById('prepaymentPercentBlock');
            const prepaymentSlider = document.getElementById('prepaymentPercent');
            const prepaymentValue = document.getElementById('prepaymentPercentValue');

            options.forEach(option => {
                option.addEventListener('click', function () {
                    // Убираем выделение со всех
                    options.forEach(opt => opt.classList.remove('selected'));
                    // Выделяем текущий
                    this.classList.add('selected');

                    const type = this.dataset.type;
                    selectedPaymentType = type;
                    document.getElementById('selectedPaymentType').value = type;

                    // Показываем/скрываем слайдер процента предоплаты
                    if (type === 'partial_prepayment') {
                        prepaymentBlock.style.display = 'block';
                    } else {
                        prepaymentBlock.style.display = 'none';
                    }
                });
            });

            // Выбираем первый по умолчанию
            if (options.length > 0) {
                options[0].classList.add('selected');
            }

            // Обновляем значение процента при изменении слайдера
            if (prepaymentSlider) {
                prepaymentSlider.addEventListener('input', function () {
                    prepaymentValue.innerText = this.value;
                });
            }
        }

        function getPaymentInfo() {
            const paymentType = selectedPaymentType;
            let prepaymentPercent = null;

            if (paymentType === 'partial_prepayment') {
                prepaymentPercent = parseInt(document.getElementById('prepaymentPercent').value);
            }

            return {
                payment_type: paymentType,
                prepayment_percent: prepaymentPercent
            };
        }

        // Добавление в корзину
        document.querySelectorAll('.add-to-cart').forEach(btn => {
            btn.addEventListener('click', function () {
                const id = this.dataset.id;
                const name = this.dataset.name;
                const price = parseFloat(this.dataset.price);
                const unit = this.dataset.unit;

                const existingItem = cart.find(item => item.id == id);

                if (existingItem) {
                    existingItem.qty += 1;
                } else {
                    cart.push({ id, name, price, qty: 1, unit });
                }

                saveCart();

                Swal.fire({
                    icon: 'success',
                    title: 'Товар добавлен',
                    text: `${name}`,
                    timer: 1500,
                    showConfirmButton: false
                });
            });
        });

        // Обработчики для юридической информации
        document.getElementById('editLegalBtn')?.addEventListener('click', () => toggleLegalEdit(true));
        document.getElementById('saveLegalBtn')?.addEventListener('click', saveLegalInfo);
        document.getElementById('cancelLegalBtn')?.addEventListener('click', () => toggleLegalEdit(false));

        // Инициализация выбора типа оплаты
        initPaymentSelector();

        // Оформление договора
        document.getElementById('checkoutBtn').addEventListener('click', function () {
            if (cart.length === 0) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Корзина пуста',
                    text: 'Добавьте товары в корзину'
                });
                return;
            }

            const desiredDate = document.getElementById('desiredDate').value;
            const customerPhone = document.getElementById('customerPhone').value;
            const orderComment = document.getElementById('orderComment').value;
            const legalInfo = getLegalInfo();
            const paymentInfo = getPaymentInfo();

            if (!desiredDate) {
                Swal.fire({
                    icon: 'error',
                    title: 'Ошибка',
                    text: 'Укажите желаемую дату отгрузки'
                });
                return;
            }

            const tomorrow = new Date();
            tomorrow.setDate(tomorrow.getDate() + 1);
            tomorrow.setHours(0, 0, 0, 0);

            const selectedDate = new Date(desiredDate);
            selectedDate.setHours(0, 0, 0, 0);

            if (selectedDate < tomorrow) {
                Swal.fire({
                    icon: 'error',
                    title: 'Ошибка',
                    text: 'Дата отгрузки не может быть раньше завтрашнего дня'
                });
                return;
            }

            const zeroItems = cart.filter(item => item.qty <= 0);
            if (zeroItems.length > 0) {
                Swal.fire({
                    icon: 'error',
                    title: 'Ошибка',
                    text: 'У всех товаров количество должно быть больше 0'
                });
                return;
            }

            const itemsList = cart.map(item =>
                `${escapeHtml(item.name)} - ${item.qty} ${escapeHtml(item.unit)} × ${item.price.toLocaleString()} ₽ = ${(item.price * item.qty).toLocaleString()} ₽`
            ).join('<br>');

            const totalSum = cart.reduce((sum, item) => sum + (item.price * item.qty), 0);

            // Формируем описание условий оплаты для подтверждения
            let paymentDescription = '';
            switch (paymentInfo.payment_type) {
                case 'full_prepayment':
                    paymentDescription = `<span class="text-success">Полная предоплата 100% (${totalSum.toLocaleString()} ₽) до отгрузки</span>`;
                    break;
                case 'partial_prepayment':
                    const prepayAmount = totalSum * paymentInfo.prepayment_percent / 100;
                    const remainingAmount = totalSum - prepayAmount;
                    paymentDescription = `<span class="text-warning">Частичная предоплата ${paymentInfo.prepayment_percent}% (${prepayAmount.toLocaleString()} ₽)</span><br>
                                          <span class="text-muted small">Остаток ${remainingAmount.toLocaleString()} ₽ оплачивается после отгрузки</span>`;
                    break;
                case 'postpayment':
                    paymentDescription = `<span class="text-info">Оплата после отгрузки (отсрочка 10 дней)</span>`;
                    break;
            }

            const legalInfoHtml = `
                <hr>
                <p><strong>Юридическая информация:</strong></p>
                <p class="small">${escapeHtml(legalInfo.entity_name) || 'Не указано'}<br>
                ИНН: ${escapeHtml(legalInfo.inn) || '—'}<br>
                БИК: ${escapeHtml(legalInfo.bik) || '—'}<br>
                Банк: ${escapeHtml(legalInfo.bank_name) || '—'}<br>
                Р/с: ${escapeHtml(legalInfo.account_number) || '—'}<br>
                ${legalInfo.director ? 'Директор: ' + escapeHtml(legalInfo.director) + '<br>' : ''}
                ${legalInfo.accountant ? 'Гл. бухгалтер: ' + escapeHtml(legalInfo.accountant) : ''}
                </p>
                <hr>
                <p><strong>Условия оплаты:</strong></p>
                <p class="small">${paymentDescription}</p>
            `;

            Swal.fire({
                title: 'Подтверждение договора',
                html: `
                    <div style="text-align: left;">
                        <p><strong>Дата отгрузки:</strong> ${new Date(desiredDate).toLocaleDateString()}</p>
                        <p><strong>Телефон:</strong> ${escapeHtml(customerPhone) || 'не указан'}</p>
                        ${legalInfoHtml}
                        <hr>
                        <p><strong>Состав заказа:</strong></p>
                        <p class="small">${itemsList}</p>
                        <hr>
                        <p><strong class="text-success">Итого: ${totalSum.toLocaleString()} ₽</strong></p>
                    </div>
                `,
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#2ecc71',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Да, оформить',
                cancelButtonText: 'Отмена'
            }).then((result) => {
                if (result.isConfirmed) {
                    const formData = new URLSearchParams();
                    formData.append('action', 'create_order');
                    formData.append('desired_date', desiredDate);
                    formData.append('customer_phone', customerPhone || '');
                    formData.append('order_comment', orderComment || '');
                    formData.append('cart', JSON.stringify(cart));
                    formData.append('legal_entity_name', legalInfo.entity_name);
                    formData.append('legal_inn', legalInfo.inn);
                    formData.append('legal_bank_name', legalInfo.bank_name);
                    formData.append('legal_bik', legalInfo.bik);
                    formData.append('legal_account_number', legalInfo.account_number);
                    formData.append('legal_director', legalInfo.director);
                    formData.append('legal_accountant', legalInfo.accountant);
                    formData.append('payment_type', paymentInfo.payment_type);
                    if (paymentInfo.prepayment_percent) {
                        formData.append('prepayment_percent', paymentInfo.prepayment_percent);
                    }

                    fetch('catalog.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: formData.toString()
                    })
                        .then(response => response.json())
                        .then(data => {
                            if (data.success) {
                                cart = [];
                                saveCart();

                                Swal.fire({
                                    icon: 'success',
                                    title: 'Договор создан!',
                                    text: data.message,
                                    confirmButtonColor: '#2ecc71'
                                }).then(() => {
                                    window.location.href = 'contracts.php';
                                });
                            } else {
                                Swal.fire({
                                    icon: 'error',
                                    title: 'Ошибка',
                                    text: data.message
                                });
                            }
                        })
                        .catch(() => {
                            Swal.fire({
                                icon: 'error',
                                title: 'Ошибка',
                                text: 'Произошла ошибка при создании договора'
                            });
                        });
                }
            });
        });

        // Инициализация
        renderCart();
    </script>
	<style>
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

</body>

</html>