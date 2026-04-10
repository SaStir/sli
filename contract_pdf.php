<?php
// contract_pdf.php - Просмотр и генерация PDF договора поставки
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

// Получаем ID договора
$contract_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$contract_id) {
    header('Location: contracts.php');
    exit;
}

// Получаем данные договора с проверкой прав доступа
if ($current_user['is_admin'] || $current_user['role'] == 'manager') {
    $stmt = $pdo->prepare("
        SELECT c.*, cust.name as customer_name, cust.inn, cust.bank_name, cust.bik, 
               cust.account_number, cust.director_name, cust.chief_accountant_name,
               u.email as manager_email,
               (SELECT COALESCE(SUM(amount), 0) FROM accounting_operations 
                WHERE contract_id = c.id AND operation_type = 'оплата') as total_paid
        FROM contracts c
        JOIN customers cust ON c.customer_id = cust.id
        JOIN users u ON c.created_by = u.id
        WHERE c.id = ?
    ");
    $stmt->execute([$contract_id]);
} else {
    $stmt = $pdo->prepare("
        SELECT c.*, cust.name as customer_name, cust.inn, cust.bank_name, cust.bik, 
               cust.account_number, cust.director_name, cust.chief_accountant_name,
               (SELECT COALESCE(SUM(amount), 0) FROM accounting_operations 
                WHERE contract_id = c.id AND operation_type = 'оплата') as total_paid
        FROM contracts c
        JOIN customers cust ON c.customer_id = cust.id
        WHERE c.id = ? AND (c.customer_id = ? OR c.created_by = ?)
    ");
    $stmt->execute([$contract_id, $current_user['customer_id'], $current_user['id']]);
}

$contract = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$contract) {
    header('Location: contracts.php');
    exit;
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

// Получаем причину отказа, если договор отклонён
$reject_reason = null;
if ($contract['status'] == 'отказ') {
    $stmt = $pdo->prepare("
        SELECT comment FROM contract_status_history 
        WHERE contract_id = ? AND status = 'отказ'
        ORDER BY changed_at DESC LIMIT 1
    ");
    $stmt->execute([$contract_id]);
    $reject_reason = $stmt->fetchColumn();
}

// Функция очистки комментария от автоматически добавленных реквизитов и условий
function cleanOrderComment($comment) {
    if (empty($comment)) return '';
    
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
        'юридическое лицо', 'инн', 'банк', 'бик', 'р/с', 'директор', 'гл. бухгалтер',
        'контактный телефон', 'условия оплаты', 'частичная предоплата', 'полная предоплата',
        'отсрочка', 'предоплата', 'остаток оплачивается'
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

// Очищаем комментарий, если он есть
$cleaned_comment = cleanOrderComment($contract['order_comment'] ?? '');

// Рассчитываем суммы
$total_amount = $contract['total_amount'];
$prepayment_amount = floatval($contract['prepayment_amount'] ?? 0);
$paid_amount = floatval($contract['total_paid'] ?? 0);
$remaining_amount = $total_amount - $paid_amount;

// Расшифровка статуса
function getStatusText($status) {
    $statuses = [
        'черновик' => 'Черновик',
        'на подписании' => 'На подписании',
        'подписан' => 'Подписан',
        'отгружен' => 'Отгружен',
        'отказ' => 'Отказан',
        'в боксе' => 'Скомплектован'
    ];
    return $statuses[$status] ?? $status;
}

// Расшифровка типа оплаты
function getPaymentTypeTextFull($type, $percent = null) {
    switch ($type) {
        case 'full_prepayment':
            return 'Полная предоплата (100%) до отгрузки товара';
        case 'partial_prepayment':
            return 'Частичная предоплата (' . ($percent ?: '0') . '%) до отгрузки, остаток после отгрузки';
        case 'postpayment':
            return 'Оплата после отгрузки (отсрочка платежа 10 дней)';
        default:
            return 'Не указано';
    }
}

// Форматирование суммы прописью
function numToWords($num) {
    $num = round($num, 2);
    $rub = floor($num);
    $kop = round(($num - $rub) * 100);
    
    $f = new NumberFormatter('ru', NumberFormatter::SPELLOUT);
    $rub_str = $f->format($rub);
    
    // Склонение слова "рубль"
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
    
    // Копейки
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
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Договор поставки №<?= htmlspecialchars($contract['contract_number']) ?> | Буратино</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js" integrity="sha512-GsLlZN/3F2ErC5ifS5QtgpiJtWd43JWSuIgh7mbzZ8zBps+dvLusV+eNQATqgA/HdeKFVgA5v3S/cIrLF7QnIg==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            background-color: #e9ecef;
            font-family: 'Times New Roman', 'Georgia', serif;
            padding: 30px;
        }
        
        .contract-container {
            max-width: 1100px;
            margin: 0 auto;
            background: white;
            box-shadow: 0 10px 40px rgba(0,0,0,0.1);
        }
        
        .contract-content {
            padding: 40px 50px;
            background: white;
        }
        
        .contract-header {
            text-align: center;
            margin-bottom: 30px;
        }
        
        .contract-header h1 {
            font-size: 22px;
            font-weight: bold;
            margin-bottom: 5px;
            text-transform: uppercase;
        }
        
        .contract-header h2 {
            font-size: 18px;
            font-weight: normal;
            margin-bottom: 20px;
        }
        
        .contract-number {
            text-align: right;
            font-size: 14px;
            margin-bottom: 20px;
            border-bottom: 1px solid #ddd;
            padding-bottom: 10px;
        }
        
        .contract-number strong {
            font-weight: bold;
        }
        
        .party-section {
            margin-bottom: 25px;
        }
        
        .party-title {
            font-weight: bold;
            font-size: 16px;
            margin-bottom: 10px;
            text-decoration: underline;
        }
        
        .party-details {
            margin-left: 20px;
            line-height: 1.6;
            font-size: 14px;
        }
        
        .subject-section {
            margin-bottom: 25px;
        }
        
        .subject-title {
            font-weight: bold;
            font-size: 16px;
            margin-bottom: 10px;
            text-decoration: underline;
        }
        
        .products-table {
            width: 100%;
            border-collapse: collapse;
            margin: 15px 0;
            font-size: 13px;
        }
        
        .products-table th,
        .products-table td {
            border: 1px solid #000;
            padding: 8px 10px;
            text-align: center;
            vertical-align: top;
        }
        
        .products-table th {
            background-color: #f5f5f5;
            font-weight: bold;
        }
        
        .products-table td.text-left {
            text-align: left;
        }
        
        .total-row {
            font-weight: bold;
            background-color: #f9f9f9;
        }
        
        .total-row td {
            border-top: 2px solid #000;
        }
        
        .price-info {
            margin: 15px 0;
            font-size: 14px;
            line-height: 1.5;
        }
        
        .payment-section {
            margin: 25px 0;
        }
        
        .payment-title {
            font-weight: bold;
            font-size: 16px;
            margin-bottom: 10px;
            text-decoration: underline;
        }
        
        .delivery-section {
            margin: 25px 0;
        }
        
        .delivery-title {
            font-weight: bold;
            font-size: 16px;
            margin-bottom: 10px;
            text-decoration: underline;
        }
        
        .responsibility-section {
            margin: 25px 0;
        }
        
        .responsibility-title {
            font-weight: bold;
            font-size: 16px;
            margin-bottom: 10px;
            text-decoration: underline;
        }
        
        .force-majeure-section {
            margin: 25px 0;
        }
        
        .force-majeure-title {
            font-weight: bold;
            font-size: 16px;
            margin-bottom: 10px;
            text-decoration: underline;
        }
        
        .dispute-section {
            margin: 25px 0;
        }
        
        .dispute-title {
            font-weight: bold;
            font-size: 16px;
            margin-bottom: 10px;
            text-decoration: underline;
        }
        
        .final-section {
            margin: 25px 0;
        }
        
        .final-title {
            font-weight: bold;
            font-size: 16px;
            margin-bottom: 10px;
            text-decoration: underline;
        }
        
        .signatures {
            margin-top: 40px;
            display: flex;
            justify-content: space-between;
        }
        
        .signature-block {
            width: 45%;
        }
        
        .signature-line {
            margin-top: 40px;
            border-top: 1px solid #000;
            width: 80%;
            text-align: center;
            padding-top: 5px;
            font-size: 12px;
        }
        
        .signature-title {
            font-weight: bold;
            margin-bottom: 5px;
        }
        
        .legal-info {
            font-size: 12px;
            margin-top: 30px;
            border-top: 1px solid #ddd;
            padding-top: 15px;
        }
        
        .toolbar {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 1000;
            display: flex;
            gap: 10px;
        }
        
        .toolbar .btn {
            box-shadow: 0 2px 10px rgba(0,0,0,0.2);
        }
        
        @media print {
            body {
                background: white;
                padding: 0;
                margin: 0;
            }
            .toolbar {
                display: none;
            }
            .contract-container {
                box-shadow: none;
                margin: 0;
            }
            .contract-content {
                padding: 20px;
            }
            .btn {
                display: none;
            }
        }
        
        .payment-details {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 5px;
            margin: 15px 0;
            font-size: 13px;
        }
        
        .status-badge-print {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: normal;
        }
        
        .clause {
            margin-bottom: 15px;
        }
        
        .clause-number {
            font-weight: bold;
            margin-right: 5px;
        }
        
        .reject-reason-box {
            
            border-left: 4px ;
            padding: 10px 15px;
            margin-top: 15px;
            font-size: 13px;
            
        }
    </style>
</head>
<body>

<div class="toolbar">
    <button class="btn btn-success" id="downloadPdfBtn">
        <i class="bi bi-file-pdf me-2"></i>Скачать PDF
    </button>
    <button class="btn btn-primary" onclick="window.print();">
        <i class="bi bi-printer me-2"></i>Печать
    </button>
    <a href="contracts.php" class="btn btn-secondary">
        <i class="bi bi-arrow-left me-2"></i>Назад
    </a>
</div>

<div class="contract-container" id="contractContent">
    <div class="contract-content">
        
        <!-- Шапка договора -->
        <div class="contract-header">
            <h1>ДОГОВОР ПОСТАВКИ №<?= htmlspecialchars($contract['contract_number']) ?></h1>
            <h2>г. Сыктывкар</h2>
            <div class="contract-number">
                <strong>«<?= date('d', strtotime($contract['contract_date'])) ?>»</strong> 
                <?php
                    $months = [
                        '01' => 'января', '02' => 'февраля', '03' => 'марта',
                        '04' => 'апреля', '05' => 'мая', '06' => 'июня',
                        '07' => 'июля', '08' => 'августа', '09' => 'сентября',
                        '10' => 'октября', '11' => 'ноября', '12' => 'декабря'
                    ];
                    $month = $months[date('m', strtotime($contract['contract_date']))];
                    $year = date('Y', strtotime($contract['contract_date']));
                ?>
                <?= $month ?> <?= $year ?> г.
            </div>
        </div>
        
        <!-- Преамбула -->
        <div class="party-section">
            <div class="party-title">1. СТОРОНЫ ДОГОВОРА</div>
            <div class="party-details">
                <p><strong>Поставщик:</strong> ООО «Буратино», в лице Директора Иванова Ивана Ивановича, действующего на основании Устава, именуемое в дальнейшем «Поставщик».</p>
                <p><strong>Покупатель:</strong> <?= htmlspecialchars($contract['customer_name']) ?>, 
                в лице <?= !empty($contract['director_name']) ? htmlspecialchars($contract['director_name']) : 'руководителя' ?>, 
                действующего на основании Устава, именуемое в дальнейшем «Покупатель», с другой стороны, 
                заключили настоящий Договор о нижеследующем.</p>
            </div>
        </div>
        
        <!-- Предмет договора -->
        <div class="subject-section">
            <div class="subject-title">2. ПРЕДМЕТ ДОГОВОРА</div>
            <div class="party-details">
                <p>2.1. Поставщик обязуется передать в собственность Покупателя, а Покупатель обязуется принять и оплатить товар 
                (пиломатериалы) в ассортименте, количестве и по ценам, указанным в Спецификации (Приложение №1), 
                которая является неотъемлемой частью настоящего Договора.</p>
                <p>2.2. Наименование, количество, цена товара, а также общая стоимость партии товара определяются 
                в Спецификации (Приложение №1) к настоящему Договору.</p>
            </div>
        </div>
        
        <!-- Спецификация (таблица с товарами) -->
        <div class="subject-section">
            <div class="subject-title">Приложение №1 к Договору поставки №<?= htmlspecialchars($contract['contract_number']) ?></div>
            <div class="party-details">
                <p><strong>Спецификация на поставку товара</strong></p>
            </div>
            
            <table class="products-table">
                <thead>
                    <tr>
                        <th>№ п/п</th>
                        <th style="width: 40%">Наименование товара</th>
                        <th>Ед. изм.</th>
                        <th>Количество</th>
                        <th>Цена за ед.,<br>руб.</th>
                        <th>Сумма,<br>руб.</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $counter = 1; foreach ($items as $item): ?>
                    <tr>
                        <td><?= $counter++ ?></td>
                        <td class="text-left"><?= htmlspecialchars($item['product_name']) ?></td>
                        <td><?= htmlspecialchars($item['unit']) ?></td>
                        <td><?= number_format($item['quantity'], 0, ',', ' ') ?></td>
                        <td><?= number_format($item['price'], 2, ',', ' ') ?></td>
                        <td><?= number_format($item['quantity'] * $item['price'], 2, ',', ' ') ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <tr class="total-row">
                        <td colspan="5" style="text-align: right; font-weight: bold;">ИТОГО:</td>
                        <td style="font-weight: bold;"><?= number_format($total_amount, 2, ',', ' ') ?> ₽</td>
                    </tr>
                </tbody>
            </table>
            
            <div class="price-info">
                <p><strong>Общая стоимость Договора:</strong> <?= number_format($total_amount, 2, ',', ' ') ?> (<?= numToWords($total_amount) ?>).</p>
                <p>НДС не облагается (на основании применения УСН).</p>
            </div>
        </div>
        
        <!-- Условия оплаты -->
        <div class="payment-section">
            <div class="payment-title">3. УСЛОВИЯ ОПЛАТЫ</div>
            <div class="party-details">
                <p>3.1. Оплата товара производится в следующем порядке:</p>
                <p><?= getPaymentTypeTextFull($contract['payment_type'] ?? 'postpayment', $contract['prepayment_percent'] ?? null) ?></p>
                <?php if ($prepayment_amount > 0): ?>
                <p>3.2. Сумма предоплаты составляет: <?= number_format($prepayment_amount, 2, ',', ' ') ?> (<?= numToWords($prepayment_amount) ?>).</p>
                <p>3.3. Оставшаяся сумма в размере <?= number_format($total_amount - $prepayment_amount, 2, ',', ' ') ?> (<?= numToWords($total_amount - $prepayment_amount) ?>) оплачивается Покупателем в течение 10 (десяти) календарных дней после получения товара.</p>
                <?php endif; ?>
                <p>3.4. Датой оплаты считается дата поступления денежных средств на расчетный счет Поставщика.</p>
                <p>3.5. Обязательства Покупателя по оплате считаются исполненными с момента списания денежных средств с его расчетного счета.</p>
            </div>
        </div>
        
        <!-- Условия поставки -->
        <div class="delivery-section">
            <div class="delivery-title">4. УСЛОВИЯ ПОСТАВКИ</div>
            <div class="party-details">
                <p>4.1. Поставка товара осуществляется Поставщиком на условиях: самовывоз со склада Поставщика</p>
                <p>4.2. Желаемая дата отгрузки: <?= date('d.m.Y', strtotime($contract['desired_shipment_date'])) ?>.</p>
                <p>4.3. Право собственности на товар переходит от Поставщика к Покупателю с момента подписания сторонами 
                товарной накладной (УПД) и акта приема-передачи товара.</p>
                <p>4.4. Приемка товара по количеству и качеству производится в соответствии с Инструкцией о порядке приемки 
                продукции производственно-технического назначения и товаров народного потребления по количеству и качеству.</p>
                <p>4.5. В случае выявления недостачи или несоответствия качества товара, Покупатель обязан составить 
                акт о выявленных недостатках в течение 3 (трех) рабочих дней с момента получения товара.</p>
            </div>
        </div>
        
        <!-- Ответственность сторон -->
        <div class="responsibility-section">
            <div class="responsibility-title">5. ОТВЕТСТВЕННОСТЬ СТОРОН</div>
            <div class="party-details">
                <p>5.1. За нарушение сроков оплаты товара Покупатель уплачивает Поставщику пеню в размере 0,1% от суммы 
                просроченного платежа за каждый день просрочки, но не более 10% от суммы просроченного платежа.</p>
                <p>5.2. За нарушение сроков поставки товара Поставщик уплачивает Покупателю пеню в размере 0,1% от стоимости 
                не поставленного в срок товара за каждый день просрочки, но не более 10% от стоимости не поставленного товара.</p>
                <p>5.3. Уплата неустойки не освобождает стороны от исполнения обязательств по Договору.</p>
                <p>5.4. Во всем остальном, не предусмотренном настоящим Договором, стороны руководствуются действующим 
                законодательством Российской Федерации.</p>
            </div>
        </div>
        
        <!-- Форс-мажор -->
        <div class="force-majeure-section">
            <div class="force-majeure-title">6. ФОРС-МАЖОР</div>
            <div class="party-details">
                <p>6.1. Стороны освобождаются от ответственности за частичное или полное неисполнение обязательств 
                по настоящему Договору, если это неисполнение явилось следствием обстоятельств непреодолимой силы 
                (форс-мажор), возникших после заключения Договора в результате событий чрезвычайного характера, 
                которые сторона не могла предвидеть или предотвратить.</p>
                <p>6.2. Сторона, для которой создалась невозможность исполнения обязательств по Договору, обязана 
                в течение 5 (пяти) рабочих дней уведомить другую сторону о наступлении и прекращении указанных обстоятельств.</p>
                <p>6.3. Надлежащим подтверждением наличия форс-мажорных обстоятельств являются документы, выданные 
                уполномоченными государственными органами.</p>
            </div>
        </div>
        
        <!-- Разрешение споров -->
        <div class="dispute-section">
            <div class="dispute-title">7. РАЗРЕШЕНИЕ СПОРОВ</div>
            <div class="party-details">
                <p>7.1. Все споры и разногласия, возникающие из настоящего Договора или в связи с ним, 
                разрешаются сторонами путем переговоров.</p>
                <p>7.2. При невозможности урегулирования спора в досудебном порядке, спор передается на рассмотрение 
                в суд с соблюдением претензионного порядка.</p>
                <p>7.3. Срок ответа на претензию составляет 10 (десять) рабочих дней с момента ее получения.</p>
            </div>
        </div>
        
        <!-- Заключительные положения -->
        <div class="final-section">
            <div class="final-title">8. ЗАКЛЮЧИТЕЛЬНЫЕ ПОЛОЖЕНИЯ</div>
            <div class="party-details">
                <p>8.1. Настоящий Договор вступает в силу с момента его подписания сторонами и действует до полного 
                исполнения сторонами своих обязательств.</p>
                <p>8.2. Любые изменения и дополнения к настоящему Договору действительны лишь при условии, 
                если они совершены в письменной форме и подписаны уполномоченными представителями сторон.</p>
                <p>8.3. Настоящий Договор составлен в двух экземплярах, имеющих одинаковую юридическую силу, 
                по одному для каждой из сторон.</p>
                
                <!-- Особые условия (очищенный комментарий клиента) -->
                <?php if (!empty($cleaned_comment)): ?>
                <p>8.4. Особые условия: <?= nl2br(htmlspecialchars($cleaned_comment)) ?></p>
                <?php endif; ?>
                
                <!-- Причина отказа, если договор отклонён -->
                
                
                <p><?= (!empty($cleaned_comment) && $contract['status'] == 'отказ') ? '8.6.' : ((!empty($cleaned_comment) || $contract['status'] == 'отказ') ? '8.5.' : '8.4.') ?> Статус договора: <strong><?= getStatusText($contract['status']) ?></strong></p>
                <?php if ($contract['status'] == 'отказ' && !empty($reject_reason)): ?>
                <p><?= (!empty($cleaned_comment) ? '8.5.' : '8.4.') ?> Договор отклонён по следующей причине:</p>
                <div class="reject-reason-box">
                    <strong><?= nl2br(htmlspecialchars($reject_reason)) ?></strong>
                </div>
                <?php endif; ?>
                <?php if ($contract['status'] == 'подписан'): ?>
                <p><?= (!empty($cleaned_comment) || $contract['status'] == 'отказ') ? '8.7.' : '8.5.' ?> Договор считается заключенным и вступает в силу с момента его подписания обеими сторонами.</p>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Реквизиты и подписи -->
        <div class="signatures">
            <div class="signature-block">
                <div class="signature-title">ПОСТАВЩИК:</div>
                <div class="legal-info">
                    <p><strong>ООО «Буратино»</strong></p>
                    <p>ИНН: 7842123456</p>
                    <p>КПП: 784201001</p>
                    <p>ОГРН: 1167847123456</p>
                    <p>Юр. адрес:г. Сыктывкар</p>
                    <p>Расчетный счет: 40702810123450009999</p>
                    <p>Банк: АО «Альфа-Банк»</p>
                    <p>БИК: 044525593</p>
                    <p>Корр. счет: 30101810200000000593</p>
                    <p>Тел.: +7 (812) 123-45-67</p>
                    <div class="signature-line">Директор _______________ И.И. Иванов</div>
                </div>
            </div>
            <div class="signature-block">
                <div class="signature-title">ПОКУПАТЕЛЬ:</div>
                <div class="legal-info">
                    <p><strong><?= htmlspecialchars($contract['customer_name']) ?></strong></p>
                    <p>ИНН: <?= htmlspecialchars($contract['inn'] ?? '—') ?></p>
                    <?php if (!empty($contract['bank_name'])): ?>
                    <p>Банк: <?= htmlspecialchars($contract['bank_name']) ?></p>
                    <?php endif; ?>
                    <?php if (!empty($contract['bik'])): ?>
                    <p>БИК: <?= htmlspecialchars($contract['bik']) ?></p>
                    <?php endif; ?>
                    <?php if (!empty($contract['account_number'])): ?>
                    <p>Расчетный счет: <?= htmlspecialchars($contract['account_number']) ?></p>
                    <?php endif; ?>
                    <?php if (!empty($contract['director_name'])): ?>
                    <p>Директор: <?= htmlspecialchars($contract['director_name']) ?></p>
                    <?php endif; ?>
                    <?php if (!empty($contract['chief_accountant_name'])): ?>
                    <p>Гл. бухгалтер: <?= htmlspecialchars($contract['chief_accountant_name']) ?></p>
                    <?php endif; ?>
                    <div class="signature-line">Руководитель _______________</div>
                    <?php if (!empty($contract['director_name'])): ?>
                    <div style="font-size: 12px; margin-top: 5px;">(<?= htmlspecialchars($contract['director_name']) ?>)</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- Дата подписания -->
        <div style="text-align: center; margin-top: 30px; font-size: 12px;">
            <p>Дата подписания: «<?= date('d', strtotime($contract['contract_date'])) ?>» <?= $month ?> <?= $year ?> г.</p>
        </div>
        
        <!-- Место для печати -->
        <div style="margin-top: 30px; display: flex; justify-content: space-between;">
            <div style="width: 150px; height: 60px; border: 1px dashed #ccc; text-align: center; padding-top: 20px; font-size: 11px; color: #999;">
                М.П.
            </div>
            <div style="width: 150px; height: 60px; border: 1px dashed #ccc; text-align: center; padding-top: 20px; font-size: 11px; color: #999;">
                М.П.
            </div>
        </div>
        
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
    document.getElementById('downloadPdfBtn').addEventListener('click', function() {
        const element = document.getElementById('contractContent');
        const opt = {
            margin: [0.5, 0.5, 0.5, 0.5],
            filename: 'Договор_поставки_<?= htmlspecialchars($contract['contract_number']) ?>.pdf',
            image: { type: 'jpeg', quality: 0.98 },
            html2canvas: { scale: 2, letterRendering: true, useCORS: true },
            jsPDF: { unit: 'in', format: 'a4', orientation: 'portrait' }
        };
        
        Swal.fire({
            title: 'Генерация PDF',
            text: 'Пожалуйста, подождите...',
            allowOutsideClick: false,
            didOpen: () => {
                Swal.showLoading();
            }
        });
        
        html2pdf().set(opt).from(element).save().then(() => {
            Swal.fire({
                icon: 'success',
                title: 'Готово!',
                text: 'PDF-файл успешно создан',
                timer: 1500,
                showConfirmButton: false
            });
        }).catch(() => {
            Swal.fire({
                icon: 'error',
                title: 'Ошибка',
                text: 'Не удалось создать PDF-файл'
            });
        });
    });
</script>

</body>
</html>