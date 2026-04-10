<?php
// generate_documents.php - Генерация документов по договору
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

$contract_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$document_type = isset($_GET['type']) ? $_GET['type'] : '';

if (!$contract_id || !$document_type) {
    header('Location: contracts.php');
    exit;
}

// Получаем данные договора
$stmt = $pdo->prepare("
    SELECT c.*, cust.name as customer_name, cust.inn, cust.bank_name, cust.bik, 
           cust.account_number, cust.director_name, cust.chief_accountant_name,
           (SELECT COALESCE(SUM(amount), 0) FROM accounting_operations 
            WHERE contract_id = c.id AND operation_type = 'оплата') as total_paid
    FROM contracts c
    JOIN customers cust ON c.customer_id = cust.id
    WHERE c.id = ?
");
$stmt->execute([$contract_id]);
$contract = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$contract) {
    die("Договор не найден");
}

// Получаем позиции договора
$stmt = $pdo->prepare("
    SELECT ci.*, p.name as product_name, p.unit
    FROM contract_items ci
    JOIN products p ON ci.product_id = p.id
    WHERE ci.contract_id = ?
");
$stmt->execute([$contract_id]);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Функция для суммы прописью
function numToWords($num) {
    $num = round($num, 2);
    $rub = floor($num);
    $kop = round(($num - $rub) * 100);
    
    $f = new NumberFormatter('ru', NumberFormatter::SPELLOUT);
    $rub_str = $f->format($rub);
    
    $rub_last = $rub % 10;
    $rub_last2 = $rub % 100;
    if ($rub_last2 >= 11 && $rub_last2 <= 19) {
        $rub_word = 'рублей';
    } else {
        switch ($rub_last) {
            case 1: $rub_word = 'рубль'; break;
            case 2: case 3: case 4: $rub_word = 'рубля'; break;
            default: $rub_word = 'рублей';
        }
    }
    
    $kop_last = $kop % 10;
    $kop_last2 = $kop % 100;
    if ($kop_last2 >= 11 && $kop_last2 <= 19) {
        $kop_word = 'копеек';
    } else {
        switch ($kop_last) {
            case 1: $kop_word = 'копейка'; break;
            case 2: case 3: case 4: $kop_word = 'копейки'; break;
            default: $kop_word = 'копеек';
        }
    }
    
    return ucfirst($rub_str) . ' ' . $rub_word . ' ' . sprintf('%02d', $kop) . ' ' . $kop_word;
}

// Генерация документа в зависимости от типа
if ($document_type === 'invoice') {
    // СЧЕТ-ФАКТУРА
    $title = "СЧЕТ-ФАКТУРА №" . ($contract['invoice_number'] ?? $contract['contract_number']);
    $filename = "Счет-фактура_{$contract['contract_number']}.pdf";
    
    $content = '
    <div style="font-family: \'Times New Roman\', serif; padding: 20px;">
        <div style="text-align: center; margin-bottom: 20px;">
            <h2>СЧЕТ-ФАКТУРА №' . ($contract['invoice_number'] ?? $contract['contract_number']) . '</h2>
            <p>от "' . date('d', strtotime($contract['contract_date'])) . '" ' . getMonthName($contract['contract_date']) . ' ' . date('Y', strtotime($contract['contract_date'])) . ' г.</p>
        </div>
        
        <div style="margin-bottom: 20px;">
            <p><strong>Продавец:</strong> ООО "Буратино"<br>
            ИНН/КПП: 7842123456 / 784201001<br>
            Адрес: г. Сыктывкар, ул. Лесная, д. 1</p>
            
            <p><strong>Покупатель:</strong> ' . htmlspecialchars($contract['customer_name']) . '<br>
            ИНН: ' . htmlspecialchars($contract['inn'] ?? '—') . '<br>
            Адрес: ' . ($contract['customer_name'] == 'ООО "Северный лес 2"' ? 'г. Санкт-Петербург, ул. Лесная, д. 10' : '—') . '</p>
        </div>
        
        <table style="width: 100%; border-collapse: collapse; margin-bottom: 20px;">
            <thead>
                <tr>
                    <th style="border: 1px solid #000; padding: 8px;">№ п/п</th>
                    <th style="border: 1px solid #000; padding: 8px;">Наименование товара</th>
                    <th style="border: 1px solid #000; padding: 8px;">Ед. изм.</th>
                    <th style="border: 1px solid #000; padding: 8px;">Количество</th>
                    <th style="border: 1px solid #000; padding: 8px;">Цена</th>
                    <th style="border: 1px solid #000; padding: 8px;">Сумма</th>
                </tr>
            </thead>
            <tbody>';
    
    $counter = 1;
    $total = 0;
    foreach ($items as $item) {
        $sum = $item['quantity'] * $item['price'];
        $total += $sum;
        $content .= '
                <tr>
                    <td style="border: 1px solid #000; padding: 8px; text-align: center;">' . $counter++ . '</td>
                    <td style="border: 1px solid #000; padding: 8px;">' . htmlspecialchars($item['product_name']) . '</td>
                    <td style="border: 1px solid #000; padding: 8px; text-align: center;">' . htmlspecialchars($item['unit']) . '</td>
                    <td style="border: 1px solid #000; padding: 8px; text-align: center;">' . number_format($item['quantity'], 0, ',', ' ') . '</td>
                    <td style="border: 1px solid #000; padding: 8px; text-align: right;">' . number_format($item['price'], 2, ',', ' ') . '</td>
                    <td style="border: 1px solid #000; padding: 8px; text-align: right;">' . number_format($sum, 2, ',', ' ') . '</td>
                </tr>';
    }
    
    $content .= '
                <tr style="font-weight: bold;">
                    <td colspan="5" style="border: 1px solid #000; padding: 8px; text-align: right;">ИТОГО:</td>
                    <td style="border: 1px solid #000; padding: 8px; text-align: right;">' . number_format($total, 2, ',', ' ') . '</td>
                 </tr>
            </tbody>
         </table>
         
         <div style="margin-bottom: 20px;">
            <p><strong>Всего наименований:</strong> ' . count($items) . '</p>
            <p><strong>Сумма прописью:</strong> ' . numToWords($total) . '</p>
            <p><strong>НДС не облагается</strong> (на основании применения УСН)</p>
         </div>
         
         <div style="margin-top: 40px;">
            <div style="display: flex; justify-content: space-between;">
                <div>
                    <p>Руководитель организации<br>_________________ И.И. Иванов</p>
                </div>
                <div>
                    <p>Главный бухгалтер<br>_________________ Е.В. Соколова</p>
                </div>
            </div>
         </div>
    </div>';
    
} elseif ($document_type === 'waybill') {
    // ТОВАРНО-ТРАНСПОРТНАЯ НАКЛАДНАЯ (ТОРГ-12)
    $title = "ТОВАРНАЯ НАКЛАДНАЯ №" . ($contract['waybill_number'] ?? $contract['contract_number']);
    $filename = "Товарная_накладная_{$contract['contract_number']}.pdf";
    
    $content = '
    <div style="font-family: \'Times New Roman\', serif; padding: 20px;">
        <div style="text-align: center; margin-bottom: 20px;">
            <h2>ТОВАРНАЯ НАКЛАДНАЯ №' . ($contract['waybill_number'] ?? $contract['contract_number']) . '</h2>
            <p>от "' . date('d', strtotime($contract['shipment_date'] ?? $contract['contract_date'])) . '" ' . getMonthName($contract['contract_date']) . ' ' . date('Y', strtotime($contract['contract_date'])) . ' г.</p>
        </div>
        
        <div style="margin-bottom: 20px;">
            <p><strong>Грузоотправитель:</strong> ООО "Буратино"<br>
            Адрес: г. Сыктывкар, ул. Лесная, д. 1</p>
            
            <p><strong>Грузополучатель:</strong> ' . htmlspecialchars($contract['customer_name']) . '<br>
            Адрес: ' . ($contract['customer_name'] == 'ООО "Северный лес 2"' ? 'г. Санкт-Петербург, ул. Лесная, д. 10' : '—') . '</p>
            
            <p><strong>Плательщик:</strong> ' . htmlspecialchars($contract['customer_name']) . '<br>
            ИНН: ' . htmlspecialchars($contract['inn'] ?? '—') . '</p>
        </div>
        
        <table style="width: 100%; border-collapse: collapse; margin-bottom: 20px;">
            <thead>
                <tr>
                    <th style="border: 1px solid #000; padding: 8px;">№</th>
                    <th style="border: 1px solid #000; padding: 8px;">Товар</th>
                    <th style="border: 1px solid #000; padding: 8px;">Ед. изм.</th>
                    <th style="border: 1px solid #000; padding: 8px;">Кол-во</th>
                    <th style="border: 1px solid #000; padding: 8px;">Цена</th>
                    <th style="border: 1px solid #000; padding: 8px;">Сумма</th>
                </tr>
            </thead>
            <tbody>';
    
    $counter = 1;
    $total = 0;
    foreach ($items as $item) {
        $sum = $item['quantity'] * $item['price'];
        $total += $sum;
        $content .= '
                <tr>
                    <td style="border: 1px solid #000; padding: 8px; text-align: center;">' . $counter++ . '</td>
                    <td style="border: 1px solid #000; padding: 8px;">' . htmlspecialchars($item['product_name']) . '</td>
                    <td style="border: 1px solid #000; padding: 8px; text-align: center;">' . htmlspecialchars($item['unit']) . '</td>
                    <td style="border: 1px solid #000; padding: 8px; text-align: center;">' . number_format($item['quantity'], 0, ',', ' ') . '</td>
                    <td style="border: 1px solid #000; padding: 8px; text-align: right;">' . number_format($item['price'], 2, ',', ' ') . '</td>
                    <td style="border: 1px solid #000; padding: 8px; text-align: right;">' . number_format($sum, 2, ',', ' ') . '</td>
                </tr>';
    }
    
    $content .= '
                <tr style="font-weight: bold;">
                    <td colspan="5" style="border: 1px solid #000; padding: 8px; text-align: right;">ИТОГО:</td>
                    <td style="border: 1px solid #000; padding: 8px; text-align: right;">' . number_format($total, 2, ',', ' ') . '</td>
                 </tr>
            </tbody>
         </table>
         
         <div style="margin-bottom: 20px;">
            <p><strong>Всего мест:</strong> ' . count($items) . '</p>
            <p><strong>Сумма прописью:</strong> ' . numToWords($total) . '</p>
            <p><strong>Отпуск разрешил:</strong> _________________ (И.И. Иванов)</p>
         </div>
         
         <div style="margin-top: 40px;">
            <div style="display: flex; justify-content: space-between;">
                <div>
                    <p><strong>Отпуск груза произвел:</strong><br>
                    _________________ (должность)<br>
                    _________________ (подпись)</p>
                </div>
                <div>
                    <p><strong>Груз принял:</strong><br>
                    _________________ (должность)<br>
                    _________________ (подпись)</p>
                </div>
            </div>
         </div>
    </div>';
    
} elseif ($document_type === 'receipt_order') {
    // ПРИХОДНЫЙ ОРДЕР
    $title = "ПРИХОДНЫЙ ОРДЕР №" . ($contract['receipt_order_number'] ?? $contract['contract_number']);
    $filename = "Приходный_ордер_{$contract['contract_number']}.pdf";
    
    $content = '
    <div style="font-family: \'Times New Roman\', serif; padding: 20px;">
        <div style="text-align: center; margin-bottom: 20px;">
            <h2>ПРИХОДНЫЙ ОРДЕР №' . ($contract['receipt_order_number'] ?? $contract['contract_number']) . '</h2>
            <p>от "' . date('d', strtotime($contract['shipment_date'] ?? $contract['contract_date'])) . '" ' . getMonthName($contract['contract_date']) . ' ' . date('Y', strtotime($contract['contract_date'])) . ' г.</p>
        </div>
        
        <div style="margin-bottom: 20px;">
            <p><strong>Поставщик:</strong> ООО "Буратино"</p>
            <p><strong>Покупатель:</strong> ' . htmlspecialchars($contract['customer_name']) . '</p>
            <p><strong>Основание:</strong> Договор поставки №' . htmlspecialchars($contract['contract_number']) . ' от ' . date('d.m.Y', strtotime($contract['contract_date'])) . '</p>
        </div>
        
        <table style="width: 100%; border-collapse: collapse; margin-bottom: 20px;">
            <thead>
                <tr>
                    <th style="border: 1px solid #000; padding: 8px;">№</th>
                    <th style="border: 1px solid #000; padding: 8px;">Наименование</th>
                    <th style="border: 1px solid #000; padding: 8px;">Ед. изм.</th>
                    <th style="border: 1px solid #000; padding: 8px;">Количество</th>
                    <th style="border: 1px solid #000; padding: 8px;">Цена</th>
                    <th style="border: 1px solid #000; padding: 8px;">Сумма</th>
                </tr>
            </thead>
            <tbody>';
    
    $counter = 1;
    $total = 0;
    foreach ($items as $item) {
        $sum = $item['quantity'] * $item['price'];
        $total += $sum;
        $content .= '
                <tr>
                    <td style="border: 1px solid #000; padding: 8px; text-align: center;">' . $counter++ . '</td>
                    <td style="border: 1px solid #000; padding: 8px;">' . htmlspecialchars($item['product_name']) . '</td>
                    <td style="border: 1px solid #000; padding: 8px; text-align: center;">' . htmlspecialchars($item['unit']) . '</td>
                    <td style="border: 1px solid #000; padding: 8px; text-align: center;">' . number_format($item['quantity'], 0, ',', ' ') . '</td>
                    <td style="border: 1px solid #000; padding: 8px; text-align: right;">' . number_format($item['price'], 2, ',', ' ') . '</td>
                    <td style="border: 1px solid #000; padding: 8px; text-align: right;">' . number_format($sum, 2, ',', ' ') . '</td>
                </tr>';
    }
    
    $content .= '
                <tr style="font-weight: bold;">
                    <td colspan="5" style="border: 1px solid #000; padding: 8px; text-align: right;">ИТОГО:</td>
                    <td style="border: 1px solid #000; padding: 8px; text-align: right;">' . number_format($total, 2, ',', ' ') . '</td>
                 </tr>
            </tbody>
         </table>
         
         <div style="margin-bottom: 20px;">
            <p><strong>Сумма прописью:</strong> ' . numToWords($total) . '</p>
            <p><strong>Принял:</strong> _________________ (должность, подпись)</p>
            <p><strong>Кладовщик:</strong> _________________ (подпись)</p>
         </div>
    </div>';
}

// Функция для получения названия месяца
function getMonthName($date) {
    $months = [
        '01' => 'января', '02' => 'февраля', '03' => 'марта',
        '04' => 'апреля', '05' => 'мая', '06' => 'июня',
        '07' => 'июля', '08' => 'августа', '09' => 'сентября',
        '10' => 'октября', '11' => 'ноября', '12' => 'декабря'
    ];
    return $months[date('m', strtotime($date))];
}

// Сохраняем номер документа в БД
if ($document_type === 'invoice' && !$contract['invoice_number']) {
    $stmt = $pdo->prepare("UPDATE contracts SET invoice_number = ? WHERE id = ?");
    $stmt->execute(['СФ-' . date('Ymd') . '-' . $contract_id, $contract_id]);
} elseif ($document_type === 'waybill' && !$contract['waybill_number']) {
    $stmt = $pdo->prepare("UPDATE contracts SET waybill_number = ? WHERE id = ?");
    $stmt->execute(['ТТН-' . date('Ymd') . '-' . $contract_id, $contract_id]);
} elseif ($document_type === 'receipt_order' && !$contract['receipt_order_number']) {
    $stmt = $pdo->prepare("UPDATE contracts SET receipt_order_number = ? WHERE id = ?");
    $stmt->execute(['ПО-' . date('Ymd') . '-' . $contract_id, $contract_id]);
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title><?= $title ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    <style>
        @media print {
            body { margin: 0; padding: 0; }
            .no-print { display: none; }
        }
        body { 
            font-family: 'Times New Roman', Times, serif; 
            background: #f0f0f0;
            padding: 20px;
        }
        .document-container {
            max-width: 1100px;
            margin: 0 auto;
            background: white;
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
        }
        .document-content {
            padding: 30px;
        }
        .toolbar {
            position: fixed;
            bottom: 20px;
            right: 20px;
            z-index: 1000;
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        th, td {
            border: 1px solid #000;
            padding: 8px;
            vertical-align: top;
        }
        th {
            background-color: #f5f5f5;
            text-align: center;
        }
    </style>
</head>
<body>

<div class="document-container" id="documentContent">
    <div class="document-content">
        <?= $content ?>
    </div>
</div>

<div class="toolbar no-print">
    <button class="btn btn-success" onclick="downloadPDF()">
        <i class="bi bi-file-pdf"></i> Скачать PDF
    </button>
    <button class="btn btn-primary" onclick="window.print()">
        <i class="bi bi-printer"></i> Печать
    </button>
    <a href="contracts.php" class="btn btn-secondary">Назад</a>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
    function downloadPDF() {
        const element = document.getElementById('documentContent');
        const opt = {
            margin: [0.5, 0.5, 0.5, 0.5],
            filename: '<?= $filename ?>',
            image: { type: 'jpeg', quality: 0.98 },
            html2canvas: { scale: 2, letterRendering: true },
            jsPDF: { unit: 'in', format: 'a4', orientation: 'portrait' }
        };
        html2pdf().set(opt).from(element).save();
    }
</script>

</body>
</html>