<?php
// complaints_admin.php - Управление жалобами (только для админа и менеджера)
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

// Только админ и менеджер имеют доступ
if (!in_array($current_user['role'], ['admin', 'manager'])) {
    if ($current_user['role'] == 'warehouse_keeper') {
        header('Location: warehouse.php');
    } elseif ($current_user['role'] == 'accountant') {
        header('Location: accounting.php');
    } elseif ($current_user['role'] == 'customer') {
        header('Location: catalog.php');
    } else {
        header('Location: login.php');
    }
    exit;
}

// Функция для отображения роли
function getRoleName($role) {
    $names = [
        'admin' => 'Директор',
        'manager' => 'Менеджер',
        'warehouse_keeper' => 'Кладовщик',
        'accountant' => 'Бухгалтер',
        'customer' => 'Покупатель'
    ];
    return $names[$role] ?? $role;
}

// Получаем все жалобы
$complaints = $pdo->query("
    SELECT c.*, 
           cont.contract_number, 
           cust.name as customer_name,
           u.email as processed_by_name
    FROM complaints c
    JOIN contracts cont ON c.contract_id = cont.id
    JOIN customers cust ON cont.customer_id = cust.id
    LEFT JOIN users u ON c.processed_by = u.id
    ORDER BY 
        CASE c.status 
            WHEN 'открыта' THEN 1 
            WHEN 'в работе' THEN 2 
            ELSE 3 
        END,
        c.created_at DESC
")->fetchAll(PDO::FETCH_ASSOC);

// Обработка изменения статуса жалобы
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    try {
        $complaint_id = $_POST['complaint_id'];
        $status = $_POST['status'];
        $resolution_comment = $_POST['resolution_comment'] ?? '';
        
        $stmt = $pdo->prepare("UPDATE complaints 
                               SET status = ?, 
                                   resolution_comment = ?, 
                                   resolution_date = NOW(),
                                   processed_by = ?,
                                   processed_at = NOW()
                               WHERE id = ?");
        $stmt->execute([$status, $resolution_comment, $_SESSION['user_id'], $complaint_id]);
        
        echo json_encode(['success' => true, 'message' => 'Статус жалобы обновлён']);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

function getStatusBadge($status) {
    switch ($status) {
        case 'открыта':
            return '<span class="badge bg-danger"><i class="bi bi-exclamation-circle"></i> Открыта</span>';
        case 'в работе':
            return '<span class="badge bg-warning text-dark"><i class="bi bi-clock-history"></i> В работе</span>';
        case 'решена':
            return '<span class="badge bg-success"><i class="bi bi-check-circle"></i> Решена</span>';
        case 'отклонена':
            return '<span class="badge bg-secondary"><i class="bi bi-x-circle"></i> Отклонена</span>';
        default:
            return '<span class="badge bg-secondary">' . $status . '</span>';
    }
}

function getTypeText($type) {
    $types = [
        'качество' => 'Качество продукции',
        'сроки' => 'Нарушение сроков',
        'комплектация' => 'Неполная комплектация',
        'документы' => 'Проблемы с документами',
        'сервис' => 'Низкое качество обслуживания',
        'другое' => 'Другое'
    ];
    return $types[$type] ?? $type;
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Управление жалобами | Буратино</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <style>
    body { background-color: #f8f9fa; }
    .navbar { box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1); }
    .nav-link.active { color: #2ecc71 !important; font-weight: 600; }
    .page-header { border-bottom: 2px solid #2ecc71; margin-bottom: 1.5rem; padding-bottom: 0.75rem; }
    
    .complaint-card {
        background: white;
        border-radius: 15px;
        padding: 20px;
        box-shadow: 0 5px 20px rgba(0, 0, 0, 0.05);
        margin-bottom: 20px;
        border-left: 5px solid #e74c3c;
        transition: transform 0.2s;
        overflow: visible !important;
        height: auto !important;
        min-height: 200px;
    }
    .complaint-card:hover { transform: translateY(-2px); box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1); }
    .complaint-card.resolved { border-left-color: #2ecc71; }
    .complaint-card.progress { border-left-color: #f39c12; }
    
    .complaint-card .row {
        display: flex;
        flex-wrap: wrap;
        margin: 0 -10px;
    }
    .complaint-card .col-md-8 {
        flex: 0 0 66.666%;
        max-width: 66.666%;
        padding: 0 10px;
        overflow: visible !important;
    }
    .complaint-card .col-md-4 {
        flex: 0 0 33.333%;
        max-width: 33.333%;
        padding: 0 10px;
    }
    .complaint-card .btn {
        margin-bottom: 8px;
        white-space: normal;
        word-wrap: break-word;
    }
    .complaint-card p {
        word-wrap: break-word;
        overflow-wrap: break-word;
        margin-bottom: 8px;
        white-space: normal;
    }
    .complaint-card .bg-light,
    .complaint-card .bg-info,
    .complaint-card .bg-success {
        word-wrap: break-word;
        overflow-wrap: break-word;
        overflow: visible !important;
    }
    
    .info-row {
        display: flex;
        flex-wrap: wrap;
        margin-bottom: 8px;
    }
    .info-label {
        font-weight: 600;
        min-width: 120px;
        color: #6c757d;
    }
    .info-value {
        flex: 1;
        word-break: break-word;
    }
    
    hr {
        margin: 12px 0;
        clear: both;
    }
    
    @media (max-width: 768px) {
        .complaint-card .col-md-8,
        .complaint-card .col-md-4 {
            flex: 0 0 100%;
            max-width: 100%;
        }
        .complaint-card .col-md-4 {
            margin-top: 15px;
            text-align: left !important;
        }
        .info-label {
            min-width: 100px;
        }
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
    .stats-card {
        background: white;
        border-radius: 15px;
        padding: 15px;
        text-align: center;
        box-shadow: 0 5px 20px rgba(0, 0, 0, 0.05);
        margin-bottom: 20px;
    }
    .stats-number {
        font-size: 28px;
        font-weight: bold;
    }
    
    /* Убираем все возможные ограничения высоты */
    .debt-table,
    .table-container,
    .complaint-card * {
        max-height: none !important;
        overflow: visible !important;
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
                    <li class="nav-item"><a class="nav-link" href="catalog.php"><i class="bi bi-box-seam me-1"></i>Каталог</a></li>
                <?php endif; ?>
                <?php if (!in_array($current_user['role'], ['warehouse_keeper', 'accountant'])): ?>
                    <li class="nav-item"><a class="nav-link" href="contracts.php"><i class="bi bi-file-text-fill me-1"></i>Договоры</a></li>
                <?php endif; ?>
                <?php if (in_array($current_user['role'], ['admin', 'manager'])): ?>
                    <li class="nav-item"><a class="nav-link" href="clients.php"><i class="bi bi-people-fill me-1"></i>Клиенты</a></li>
                <?php endif; ?>
                <?php if (in_array($current_user['role'], ['admin', 'warehouse_keeper'])): ?>
                    <li class="nav-item"><a class="nav-link" href="warehouse.php"><i class="bi bi-shop me-1"></i>Склад</a></li>
                <?php endif; ?>
                <?php if (in_array($current_user['role'], ['admin', 'accountant'])): ?>
                    <li class="nav-item"><a class="nav-link" href="accounting.php"><i class="bi bi-calculator-fill me-1"></i>Учёт</a></li>
                <?php endif; ?>
                <li class="nav-item"><a class="nav-link active" href="complaints_admin.php"><i class="bi bi-exclamation-triangle-fill me-1"></i>Жалобы</a></li>
            </ul>
            <div class="d-flex align-items-center">
                <div class="text-white me-3">
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
    <div class="page-header">
        <h2 class="mb-1"><i class="bi bi-exclamation-triangle-fill me-2" style="color: #e74c3c;"></i>Управление жалобами</h2>
        <p class="text-muted small">Обработка обращений и рекламаций от клиентов</p>
    </div>

    <!-- Статистика -->
    <?php 
        $open_count = count(array_filter($complaints, fn($c) => $c['status'] == 'открыта'));
        $work_count = count(array_filter($complaints, fn($c) => $c['status'] == 'в работе'));
        $resolved_count = count(array_filter($complaints, fn($c) => $c['status'] == 'решена'));
        $rejected_count = count(array_filter($complaints, fn($c) => $c['status'] == 'отклонена'));
    ?>
    <div class="row mb-4">
        <div class="col-md-3"><div class="stats-card"><div class="stats-number text-danger"><?= $open_count ?></div><div class="text-muted small">Открытых</div></div></div>
        <div class="col-md-3"><div class="stats-card"><div class="stats-number text-warning"><?= $work_count ?></div><div class="text-muted small">В работе</div></div></div>
        <div class="col-md-3"><div class="stats-card"><div class="stats-number text-success"><?= $resolved_count ?></div><div class="text-muted small">Решённых</div></div></div>
        <div class="col-md-3"><div class="stats-card"><div class="stats-number text-secondary"><?= $rejected_count ?></div><div class="text-muted small">Отклонённых</div></div></div>
    </div>

    <?php if (empty($complaints)): ?>
        <div class="text-center py-5">
            <i class="bi bi-check-circle fs-1 text-success d-block mb-3"></i>
            <h5 class="text-muted">Нет жалоб</h5>
            <p class="text-muted">Все клиенты довольны качеством продукции</p>
        </div>
    <?php else: ?>
        <?php foreach ($complaints as $complaint): 
            $card_class = '';
            if ($complaint['status'] == 'решена') $card_class = 'resolved';
            if ($complaint['status'] == 'в работе') $card_class = 'progress';
        ?>
        <div class="complaint-card <?= $card_class ?>">
            <div class="row">
                <!-- Левая колонка - информация о жалобе -->
                <div class="col-md-8">
                    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap">
                        <h5 class="mb-0">Жалоба №<?= $complaint['id'] ?></h5>
                        <?= getStatusBadge($complaint['status']) ?>
                    </div>
                    
                    <div class="info-row">
                        <div class="info-label">Договор:</div>
                        <div class="info-value">№<?= htmlspecialchars($complaint['contract_number']) ?></div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Клиент:</div>
                        <div class="info-value"><?= htmlspecialchars($complaint['customer_name']) ?></div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Тип жалобы:</div>
                        <div class="info-value"><?= getTypeText($complaint['complaint_type']) ?></div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Дата подачи:</div>
                        <div class="info-value"><?= date('d.m.Y H:i', strtotime($complaint['created_at'])) ?></div>
                    </div>
                    
                    <hr>
                    
                    <div class="bg-light p-3 rounded mb-2">
                        <strong>Описание проблемы:</strong>
                        <p class="mb-0 mt-1"><?= nl2br(htmlspecialchars($complaint['description'])) ?></p>
                    </div>
                    
                    <?php if (!empty($complaint['desired_resolution'])): ?>
                    <div class="bg-info bg-opacity-10 p-2 rounded mb-2">
                        <strong>Желаемое решение:</strong>
                        <p class="mb-0 mt-1"><?= nl2br(htmlspecialchars($complaint['desired_resolution'])) ?></p>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($complaint['resolution_comment'])): ?>
                    <div class="bg-success bg-opacity-10 p-2 rounded">
                        <strong>Ответ / Решение:</strong>
                        <p class="mb-0 mt-1"><?= nl2br(htmlspecialchars($complaint['resolution_comment'])) ?></p>
                        <small class="text-muted">Обработал: <?= htmlspecialchars($complaint['processed_by_name'] ?? '—') ?> (<?= date('d.m.Y H:i', strtotime($complaint['processed_at'])) ?>)</small>
                    </div>
                    <?php endif; ?>
                </div>
                
                <!-- Правая колонка - кнопки действий -->
                <div class="col-md-4 text-end">
                    <?php if ($complaint['status'] == 'открыта'): ?>
                        <button class="btn btn-warning w-100 mb-2" onclick="updateComplaintStatus(<?= $complaint['id'] ?>, 'в работе')">
                            <i class="bi bi-clock-history me-1"></i>Взять в работу
                        </button>
                        <button class="btn btn-danger w-100" onclick="openResolutionModal(<?= $complaint['id'] ?>, 'отклонена')">
                            <i class="bi bi-x-circle me-1"></i>Отклонить
                        </button>
                    <?php elseif ($complaint['status'] == 'в работе'): ?>
                        <button class="btn btn-success w-100 mb-2" onclick="openResolutionModal(<?= $complaint['id'] ?>, 'решена')">
                            <i class="bi bi-check-circle me-1"></i>Отметить решённой
                        </button>
                        <button class="btn btn-secondary w-100" onclick="updateComplaintStatus(<?= $complaint['id'] ?>, 'открыта')">
                            <i class="bi bi-arrow-return-left me-1"></i>Вернуть в открытые
                        </button>
                    <?php elseif ($complaint['status'] == 'решена' || $complaint['status'] == 'отклонена'): ?>
                        <button class="btn btn-outline-secondary w-100" onclick="updateComplaintStatus(<?= $complaint['id'] ?>, 'открыта')">
                            <i class="bi bi-arrow-repeat me-1"></i>Открыть заново
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</main>

<!-- Модальное окно для решения жалобы -->
<div class="modal fade" id="resolutionModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title">Решение по жалобе</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="resolutionForm">
                    <input type="hidden" name="action" value="update_status">
                    <input type="hidden" name="complaint_id" id="resolution_complaint_id">
                    <input type="hidden" name="status" id="resolution_status">
                    
                    <div class="mb-3">
                        <label class="form-label">Комментарий / Решение <span class="text-danger">*</span></label>
                        <textarea class="form-control" name="resolution_comment" rows="4" required placeholder="Опишите, как решена проблема или причина отклонения..."></textarea>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                <button type="button" class="btn btn-success" id="submitResolutionBtn">Подтвердить</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
    function updateComplaintStatus(complaintId, status) {
        let title = '', confirmColor = '#2ecc71';
        if (status === 'в работе') { title = 'Взять в работу'; confirmColor = '#f39c12'; }
        else if (status === 'открыта') { title = 'Вернуть в открытые'; confirmColor = '#e74c3c'; }
        else { title = 'Подтверждение'; }
        
        Swal.fire({
            title: title,
            text: 'Вы уверены?',
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: confirmColor,
            confirmButtonText: 'Да',
            cancelButtonText: 'Отмена'
        }).then((result) => {
            if (result.isConfirmed) {
                $.ajax({
                    url: 'complaints_admin.php',
                    type: 'POST',
                    data: { action: 'update_status', complaint_id: complaintId, status: status, resolution_comment: '' },
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            Swal.fire({ icon: 'success', title: 'Готово!', text: response.message, timer: 1500 })
                                .then(() => location.reload());
                        } else {
                            Swal.fire({ icon: 'error', title: 'Ошибка', text: response.message });
                        }
                    }
                });
            }
        });
    }
    
    function openResolutionModal(complaintId, status) {
        $('#resolution_complaint_id').val(complaintId);
        $('#resolution_status').val(status);
        $('#resolutionForm textarea').val('');
        $('#resolutionModal').modal('show');
    }
    
    $('#submitResolutionBtn').on('click', function() {
        const comment = $('#resolutionForm textarea').val();
        if (!comment.trim()) {
            Swal.fire({ icon: 'warning', title: 'Внимание', text: 'Введите комментарий' });
            return;
        }
        
        $.ajax({
            url: 'complaints_admin.php',
            type: 'POST',
            data: $('#resolutionForm').serialize(),
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    Swal.fire({ icon: 'success', title: 'Готово!', text: response.message, timer: 1500 })
                        .then(() => location.reload());
                } else {
                    Swal.fire({ icon: 'error', title: 'Ошибка', text: response.message });
                }
            }
        });
    });
</script>

</body>
</html>