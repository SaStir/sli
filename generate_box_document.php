<?php
// generate_box_document.php - Генерация единого документа отгрузки бокса
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

require_once 'config.php';

$movement_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$movement_id) {
    die('Не указан ID документа');
}

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Ошибка подключения к БД: " . $e->getMessage());
}

// Получаем информацию о движении
$stmt = $pdo->prepare("
    SELECT wm.*, p.name as product_name, p.unit, 
           c.contract_number, cust.name as customer_name,
           cust.inn, cust.bank_name, cust.bik, cust.account_number,
           cust.director_name, cust.chief_accountant_name,
           wb.box_number
    FROM warehouse_movements wm
    LEFT JOIN products p ON wm.product_id = p.id
    LEFT JOIN contracts c ON wm.contract_id = c.id
    LEFT JOIN customers cust ON c.customer_id = cust.id
    LEFT JOIN warehouse_boxes wb ON wm.box_id = wb.id
    WHERE wm.id = ?
");
$stmt->execute([$movement_id]);
$movement = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$movement) {
    die('Документ не найден');
}

// Для отладки - раскомментируйте чтобы увидеть данные
// echo '<pre>'; print_r($movement); echo '</pre>';

// Получаем все товары из бокса по box_id
$items = [];
$grand_total = 0;

if ($movement['box_id'] > 0) {
    // Получаем товары из бокса
    $box_items = $pdo->prepare("
        SELECT bi.*, p.name as product_name, p.unit
        FROM box_items bi
        JOIN products p ON bi.product_id = p.id
        WHERE bi.box_id = ?
    ");
    $box_items->execute([$movement['box_id']]);
    $items = $box_items->fetchAll(PDO::FETCH_ASSOC);
    
    // Вычисляем общую сумму
    foreach ($items as $item) {
        $grand_total += $item['quantity'] * $item['price'];
    }
}

$document_type = 'Акт отгрузки';
$document_number = $movement['document_number'];
$document_date = date('d.m.Y', strtotime($movement['document_date']));

// Функция для преобразования числа в пропись
function num2str($num) {
    if ($num === null || $num == 0) {
        return 'ноль рублей 00 копеек';
    }
    $num = floatval($num);
    $rub = floor($num);
    $kop = round(($num - $rub) * 100);
    return number_format($rub, 0, ',', ' ') . ' рублей ' . str_pad($kop, 2, '0', STR_PAD_LEFT) . ' копеек';
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title><?= $document_type ?> №<?= htmlspecialchars($document_number) ?></title>
    <style>
        body {
            font-family: 'Times New Roman', Times, serif;
            margin: 0;
            padding: 20px;
            background: #fff;
        }
        .document-container {
            max-width: 1000px;
            margin: 0 auto;
            padding: 30px;
            border: 1px solid #ddd;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
        }
        .header {
            text-align: center;
            margin-bottom: 30px;
        }
        .header h1 {
            font-size: 18px;
            margin: 0;
        }
        .header h2 {
            font-size: 14px;
            margin: 5px 0;
            font-weight: normal;
        }
        .company-info {
            margin-bottom: 20px;
            font-size: 12px;
        }
        .company-info table {
            width: 100%;
            border-collapse: collapse;
        }
        .company-info td {
            padding: 5px;
            vertical-align: top;
        }
        .title {
            text-align: center;
            font-size: 16px;
            font-weight: bold;
            margin: 20px 0;
            text-transform: uppercase;
        }
        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
        }
        .items-table th, .items-table td {
            border: 1px solid #000;
            padding: 8px;
        }
        .items-table th {
            background-color: #f5f5f5;
            font-weight: bold;
            text-align: center;
        }
        .items-table td {
            text-align: left;
        }
        .items-table td.number {
            text-align: center;
        }
        .items-table td.right {
            text-align: right;
        }
        .total {
            text-align: right;
            margin: 20px 0;
            font-size: 14px;
        }
        .signatures {
            margin-top: 40px;
            display: flex;
            justify-content: space-between;
        }
        .signature {
            width: 45%;
        }
        .signature-line {
            margin-top: 30px;
            border-top: 1px solid #000;
            width: 200px;
        }
        .footer {
            margin-top: 30px;
            font-size: 10px;
            text-align: center;
            color: #666;
        }
        @media print {
            body { padding: 0; margin: 0; }
            .document-container { border: none; box-shadow: none; padding: 15px; }
            .no-print { display: none; }
        }
        .no-print {
            text-align: center;
            margin-bottom: 20px;
        }
        .btn-print {
            padding: 8px 16px;
            background: #2ecc71;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            margin: 0 5px;
        }
        .btn-print:hover {
            background: #27ae60;
        }
        .btn-close {
            background: #6c757d;
        }
        .btn-close:hover {
            background: #5a6268;
        }
        .text-center {
            text-align: center;
        }
        .text-bold {
            font-weight: bold;
        }
        .debug-info {
            background: #f0f0f0;
            padding: 10px;
            margin-bottom: 20px;
            border: 1px solid #ccc;
            font-size: 12px;
            font-family: monospace;
        }
    </style>
</head>
<body>
<div class="document-container">
    <div class="no-print">
        <button class="btn-print" onclick="window.print();">🖨️ Печать документа</button>
        <button class="btn-print btn-close" onclick="window.close();">✖ Закрыть</button>
    </div>
    
    <!-- Отладочная информация (только для администратора) -->
    <?php if (isset($_GET['debug'])): ?>
    <div class="debug-info">
        <strong>Отладка:</strong><br>
        movement_id: <?= $movement_id ?><br>
        box_id: <?= $movement['box_id'] ?><br>
        Найдено товаров: <?= count($items) ?><br>
        <?php if (empty($items)): ?>
        Проверьте SQL запрос: SELECT * FROM box_items WHERE box_id = <?= $movement['box_id'] ?>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    
    <div class="header">
        <h1>ОБЩЕСТВО С ОГРАНИЧЕННОЙ ОТВЕТСТВЕННОСТЬЮ «БУРАТИНО»</h1>
        <h2>ОГРН 1237800123456 | ИНН 7842123456 | КПП 784201001</h2>
        <p>Юридический адрес: 197022, г. Санкт-Петербург, ул. Лесная, д. 15, оф. 301</p>
    </div>

    <div class="title">
        <?= $document_type ?> №<?= htmlspecialchars($document_number) ?><br>
        от «<?= $document_date ?>»
    </div>

    <div class="company-info">
        <table>
            <tr>
                <td style="width: 150px;"><strong>Грузоотправитель:</strong></td>
                <td>ООО «Буратино», ИНН 7842123456, КПП 784201001</td>
            </tr>
            <tr>
                <td><strong>Грузополучатель:</strong></td>
                <td><?= htmlspecialchars($movement['customer_name'] ?? '—') ?></td>
            </tr>
            <tr>
                <td><strong>ИНН:</strong></td>
                <td><?= htmlspecialchars($movement['inn'] ?? '—') ?></td>
            </tr>
            <tr>
                <td><strong>Банк:</strong></td>
                <td><?= htmlspecialchars($movement['bank_name'] ?? '—') ?></td>
            </tr>
            <tr>
                <td><strong>БИК:</strong></td>
                <td><?= htmlspecialchars($movement['bik'] ?? '—') ?></td>
            </tr>
            <tr>
                <td><strong>Расчётный счёт:</strong></td>
                <td><?= htmlspecialchars($movement['account_number'] ?? '—') ?></td>
            </tr>
            <tr>
                <td><strong>Основание:</strong></td>
                <td>Договор №<?= htmlspecialchars($movement['contract_number'] ?? '—') ?></td>
            </tr>
        </table>
    </div>

    <table class="items-table">
        <thead>
            <tr>
                <th style="width: 40px;">№</th>
                <th>Наименование товара</th>
                <th style="width: 80px;">Ед. изм.</th>
                <th style="width: 100px;">Количество</th>
                <th style="width: 120px;">Цена (руб.)</th>
                <th style="width: 120px;">Сумма (руб.)</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!empty($items)): ?>
                <?php $counter = 1; ?>
                <?php foreach ($items as $item): 
                    $sum = $item['quantity'] * $item['price'];
                ?>
                <tr>
                    <td class="number"><?= $counter++ ?></td>
                    <td><?= htmlspecialchars($item['product_name']) ?></td>
                    <td class="number"><?= htmlspecialchars($item['unit']) ?></td>
                    <td class="number"><?= number_format($item['quantity'], 0, ',', ' ') ?></td>
                    <td class="right"><?= number_format($item['price'], 2, ',', ' ') ?></td>
                    <td class="right"><?= number_format($sum, 2, ',', ' ') ?></td>
                </tr>
                <?php endforeach; ?>
                <tr style="font-weight: bold; background-color: #f5f5f5;">
                    <td colspan="5" class="right"><strong>ИТОГО:</strong></td>
                    <td class="right"><strong><?= number_format($grand_total, 2, ',', ' ') ?></strong></td>
                </tr>
            <?php else: ?>
                <tr>
                    <td colspan="6" class="text-center" style="color: red;">
                        Нет данных о товарах. box_id = <?= $movement['box_id'] ?>
                    </td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>

    <?php if (!empty($items) && $grand_total > 0): ?>
    <div class="total">
        <p><strong>Сумма прописью:</strong> <?= num2str($grand_total) ?></p>
    </div>
    <?php endif; ?>

    <div class="signatures">
        <div class="signature">
            <p><strong>От грузоотправителя:</strong></p>
            <div class="signature-line"></div>
            <p>Директор _________________ /Иванов И.И./</p>
            <div class="signature-line"></div>
            <p>Гл. бухгалтер _________________ /Петрова А.С./</p>
        </div>
        <div class="signature">
            <p><strong>От грузополучателя:</strong></p>
            <div class="signature-line"></div>
            <p><?= htmlspecialchars($movement['director_name'] ?? '_________________') ?></p>
            <div class="signature-line"></div>
            <p><?= htmlspecialchars($movement['chief_accountant_name'] ?? '_________________') ?></p>
        </div>
    </div>

    <div class="footer">
        <p>Электронный документ, действителен при наличии электронной подписи</p>
        <p>Дата формирования: <?= date('d.m.Y H:i:s') ?></p>
    </div>
</div>
</body>
</html>