<?php
// generate_stock_document.php - Генерация документов склада (приход/расход/отгрузка бокса)
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

require_once 'config.php';

$movement_id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

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
    SELECT wm.*, p.name as product_name, p.unit, p.current_price,
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

// Определяем тип документа
$is_box_shipment = ($movement['document_type'] == 'Отгрузка бокса' && $movement['box_id'] > 0);
$is_income = ($movement['movement_type'] == 'приход');

// Получаем все товары для этого документа по одному номеру документа
$items = [];

if ($is_box_shipment && $movement['box_id'] > 0) {
    // Для отгрузки бокса - получаем товары из box_items с ценой из contract_items
    $box_items = $pdo->prepare("
        SELECT bi.*, p.name as product_name, p.unit,
               ci.price
        FROM box_items bi
        JOIN products p ON bi.product_id = p.id
        JOIN warehouse_boxes wb ON bi.box_id = wb.id
        JOIN contract_items ci ON ci.contract_id = wb.contract_id AND ci.product_id = bi.product_id
        WHERE bi.box_id = ?
    ");
    $box_items->execute([$movement['box_id']]);
    $items = $box_items->fetchAll(PDO::FETCH_ASSOC);
} else {
    // Для обычного прихода/расхода - получаем из warehouse_movements
    $all_items = $pdo->prepare("
        SELECT wm.*, p.name as product_name, p.unit, p.current_price as price
        FROM warehouse_movements wm
        JOIN products p ON wm.product_id = p.id
        WHERE wm.document_number = ? AND wm.product_id != 0
        ORDER BY wm.id
    ");
    $all_items->execute([$movement['document_number']]);
    $items = $all_items->fetchAll(PDO::FETCH_ASSOC);
}

// Вычисляем общую сумму
$grand_total = 0;
foreach ($items as $item) {
    $price = isset($item['price']) ? floatval($item['price']) : (isset($item['current_price']) ? floatval($item['current_price']) : 0);
    $grand_total += $item['quantity'] * $price;
}

$document_type = ($is_income) ? 'Приходный ордер' : 'Расходная накладная';
if ($movement['document_type'] == 'Отгрузка бокса') {
    $document_type = 'Акт отгрузки';
}
$document_number = $movement['document_number'];
$document_date = date('d.m.Y', strtotime($movement['document_date']));

// Функция для преобразования числа в пропись
function num2str($num)
{
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
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Times New Roman', Times, serif;
            background: #f5f5f5;
            padding: 30px;
        }

        .document-container {
            max-width: 1100px;
            margin: 0 auto;
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 0 20px rgba(0, 0, 0, 0.1);
        }

        .header {
            text-align: center;
            margin-bottom: 30px;
            padding-bottom: 20px;

        }

        .header h1 {
            font-size: 20px;
            margin-bottom: 5px;
        }

        .header h2 {
            font-size: 16px;
            font-weight: normal;
            color: #555;
        }

        .title {
            text-align: center;
            font-size: 18px;
            font-weight: bold;
            margin: 25px 0;
            text-transform: uppercase;
        }

        .company-info {
            margin-bottom: 25px;
            font-size: 12px;
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
        }

        .company-info table {
            width: 100%;
            border-collapse: collapse;
        }

        .company-info td {
            padding: 5px;
            vertical-align: top;
        }

        .company-info td:first-child {
            width: 140px;
            font-weight: bold;
        }

        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin: 25px 0;
        }

        .items-table th,
        .items-table td {
            border: 1px solid #000;
            padding: 10px;
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
            padding: 15px;
            background: #f8f9fa;
            border-radius: 8px;
        }

        .total p {
            font-size: 14px;
            margin: 5px 0;
        }

        .signatures {
            margin-top: 40px;
            display: flex;
            justify-content: space-between;
        }

        .signature {
            width: 45%;
        }

        .signature p {
            margin: 10px 0;
        }

        .signature-line {
            margin-top: 30px;
            border-top: 1px solid #000;
            width: 220px;
        }

        .footer {
            margin-top: 30px;
            font-size: 10px;
            text-align: center;
            color: #666;
            border-top: 1px solid #ddd;
            padding-top: 15px;
        }

        @media print {
            body {
                padding: 0;
                margin: 0;
                background: white;
            }

            .document-container {
                box-shadow: none;
                padding: 15px;
            }

            .no-print {
                display: none;
            }
        }

        .no-print {
            text-align: center;
            margin-bottom: 20px;
        }

        .btn-print {
            padding: 8px 20px;
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
    </style>
</head>

<body>
    <div class="document-container">
        <div class="no-print">
            <button class="btn-print" onclick="window.print();">🖨️ Печать документа</button>
            <button class="btn-print btn-close" onclick="window.close();">✖ Закрыть</button>
        </div>

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
                    <td>Организация:</td>
                    <td>ООО «Буратино», ИНН 7842123456, КПП 784201001</td>
                </tr>
                <?php if ($movement['customer_name']): ?>
                    <tr>
                        <td>Контрагент:</td>
                        <td><?= htmlspecialchars($movement['customer_name']) ?></td>
                    </tr>
                    <tr>
                        <td>ИНН:</td>
                        <td><?= htmlspecialchars($movement['inn'] ?? '—') ?></td>
                    </tr>
                <?php endif; ?>
                <?php if ($movement['contract_number']): ?>
                    <tr>
                        <td>Основание:</td>
                        <td>Договор №<?= htmlspecialchars($movement['contract_number']) ?></td>
                    </tr>
                <?php endif; ?>
                <?php if ($movement['box_number']): ?>
                    <tr>
                        <td>Бокс:</td>
                        <td><?= htmlspecialchars($movement['box_number']) ?></td>
                    </tr>
                <?php endif; ?>
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
                    <th style="width: 130px;">Сумма (руб.)</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($items)): ?>
                    <?php $counter = 1; ?>
                    <?php foreach ($items as $item):
                        $price = isset($item['price']) ? floatval($item['price']) : (isset($item['current_price']) ? floatval($item['current_price']) : 0);
                        $sum = $item['quantity'] * $price;
                        ?>
                        <tr>
                            <td class="number"><?= $counter++ ?></td>
                            <td><?= htmlspecialchars($item['product_name']) ?></td>
                            <td class="number"><?= htmlspecialchars($item['unit']) ?></td>
                            <td class="number"><?= number_format($item['quantity'], 0, ',', ' ') ?></td>
                            <td class="right"><?= number_format($price, 2, ',', ' ') ?></td>
                            <td class="right"><?= number_format($sum, 2, ',', ' ') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <tr style="font-weight: bold; background-color: #f5f5f5;">
                        <td colspan="5" class="right"><strong>ИТОГО:</strong></td>
                        <td class="right"><strong
                                class="text-success"><?= number_format($grand_total, 2, ',', ' ') ?></strong></td>
                    </tr>
                <?php else: ?>
                    <tr>
                        <td colspan="6" class="text-center" style="color: #999; padding: 40px;">
                            Нет данных о товарах
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>

        <?php if (!empty($items) && $grand_total > 0): ?>
            <div class="total">
                <p><strong>Сумма прописью:</strong> <?= num2str($grand_total) ?></p>
                <?php if ($is_income): ?>
                    <p><strong>Основание:</strong> Приход товара на склад</p>
                <?php else: ?>
                    <p><strong>Основание:</strong> Отгрузка товара со склада</p>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="signatures">
            <div class="signature">
                <p><strong>От организации:</strong></p>
                <div class="signature-line"></div>
                <p>Директор _________________ /Иванов И.И./</p>
                <div class="signature-line"></div>
                <p>Гл. бухгалтер _________________ /Петрова А.С./</p>
            </div>
            <?php if ($movement['customer_name']): ?>
                <div class="signature">
                    <p><strong>От контрагента:</strong></p>
                    <div class="signature-line"></div>
                    <p><?= htmlspecialchars($movement['director_name'] ?? '_________________') ?></p>
                    <div class="signature-line"></div>
                    <p><?= htmlspecialchars($movement['chief_accountant_name'] ?? '_________________') ?></p>
                </div>
            <?php endif; ?>
        </div>

        <div class="footer">
            <p>Электронный документ, действителен при наличии электронной подписи</p>
            <p>Дата формирования: <?= date('d.m.Y H:i:s') ?></p>
        </div>
    </div>
</body>

</html>