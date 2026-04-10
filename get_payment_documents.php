<?php
// get_payment_documents.php - Получение списка платежных документов по договору
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

require_once 'config.php';

$contract_id = isset($_GET['contract_id']) ? (int)$_GET['contract_id'] : 0;

if (!$contract_id) {
    echo json_encode(['error' => 'No contract ID']);
    exit;
}

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}

// Получаем ВСЕ оплаты по договору (и предоплату, и последующие платежи)
$payments = $pdo->prepare("
    SELECT amount, document_number, operation_date, comment 
    FROM accounting_operations 
    WHERE contract_id = ? AND operation_type = 'оплата'
    ORDER BY operation_date ASC
");
$payments->execute([$contract_id]);
$payments_data = $payments->fetchAll(PDO::FETCH_ASSOC);

$result = [
    'payments' => [],
    'prepayment' => null
];

foreach ($payments_data as $payment) {
    $payment_item = [
        'amount' => number_format($payment['amount'], 2, ',', ' ') . ' ₽',
        'document_number' => $payment['document_number'],
        'date' => date('d.m.Y', strtotime($payment['operation_date'])),
        'comment' => $payment['comment']
    ];
    
    // Если это предоплата (содержит ПРЕДОПЛАТА в номере документа)
    if (strpos($payment['document_number'], 'ПРЕДОПЛАТА-') !== false) {
        $result['prepayment'] = $payment_item;
    } else {
        $result['payments'][] = $payment_item;
    }
}

header('Content-Type: application/json');
echo json_encode($result);
?>