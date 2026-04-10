<?php
// feedback.php - Оценка удовлетворённости и жалобы (с отображением уже оставленных отзывов/жалоб)
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

// Получаем информацию о пользователе
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$current_user = $stmt->fetch(PDO::FETCH_ASSOC);

// Получаем все отгруженные договоры покупателя
$stmt = $pdo->prepare("
    SELECT c.*, cust.name as customer_name
    FROM contracts c
    JOIN customers cust ON c.customer_id = cust.id
    WHERE c.customer_id = ? AND c.status = 'отгружен'
    ORDER BY c.contract_date DESC
");
$stmt->execute([$current_user['customer_id']]);
$contracts = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Получаем все отзывы этого покупателя (индексируем по contract_id)
$feedbacks = [];
if (!empty($contracts)) {
    $contractIds = array_column($contracts, 'id');
    $in = str_repeat('?,', count($contractIds) - 1) . '?';
    $stmt = $pdo->prepare("SELECT * FROM customer_feedbacks WHERE contract_id IN ($in) AND customer_id = ?");
    $stmt->execute(array_merge($contractIds, [$current_user['customer_id']]));
    $feedbacksList = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($feedbacksList as $fb) {
        $feedbacks[$fb['contract_id']] = $fb;
    }
}

// Получаем все жалобы этого покупателя (индексируем по contract_id, учитывая, что может быть несколько – возьмём последнюю)
$complaints = [];
if (!empty($contracts)) {
    $in = str_repeat('?,', count($contractIds) - 1) . '?';
    $stmt = $pdo->prepare("
        SELECT comp.*, u.email as processed_by_name
        FROM complaints comp
        LEFT JOIN users u ON comp.processed_by = u.id
        WHERE comp.contract_id IN ($in)
        ORDER BY comp.created_at DESC
    ");
    $stmt->execute($contractIds);
    $complaintsList = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($complaintsList as $comp) {
        // Если на один договор несколько жалоб – покажем только последнюю (можно изменить логику)
        if (!isset($complaints[$comp['contract_id']])) {
            $complaints[$comp['contract_id']] = $comp;
        }
    }
}

// Обработка отправки отзыва / жалобы (остаётся без изменений)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');

    try {
        if ($_POST['action'] === 'add_feedback') {
            $contract_id = (int)$_POST['contract_id'];
            $rating = (int)$_POST['rating'];
            $comment = trim($_POST['comment'] ?? '');

            $check = $pdo->prepare("SELECT id FROM contracts WHERE id = ? AND customer_id = ? AND status = 'отгружен'");
            $check->execute([$contract_id, $current_user['customer_id']]);
            if (!$check->fetch()) {
                echo json_encode(['success' => false, 'message' => 'Договор не найден или не отгружен']);
                exit;
            }

            $check = $pdo->prepare("SELECT id FROM customer_feedbacks WHERE contract_id = ?");
            $check->execute([$contract_id]);
            if ($check->fetch()) {
                echo json_encode(['success' => false, 'message' => 'Отзыв для этого договора уже оставлен']);
                exit;
            }

            $stmt = $pdo->prepare("INSERT INTO customer_feedbacks (contract_id, customer_id, rating, comment) VALUES (?, ?, ?, ?)");
            $stmt->execute([$contract_id, $current_user['customer_id'], $rating, $comment]);

            echo json_encode(['success' => true, 'message' => 'Спасибо за вашу оценку!']);

        } elseif ($_POST['action'] === 'add_complaint') {
            $contract_id = (int)$_POST['contract_id'];
            $complaint_type = $_POST['complaint_type'];
            $description = trim($_POST['description']);
            $desired_resolution = trim($_POST['desired_resolution'] ?? '');

            $check = $pdo->prepare("SELECT id FROM contracts WHERE id = ? AND customer_id = ?");
            $check->execute([$contract_id, $current_user['customer_id']]);
            if (!$check->fetch()) {
                echo json_encode(['success' => false, 'message' => 'Договор не найден']);
                exit;
            }

            $stmt = $pdo->prepare("INSERT INTO complaints (contract_id, complaint_date, description, status, complaint_type, desired_resolution) 
                                   VALUES (?, NOW(), ?, 'открыта', ?, ?)");
            $stmt->execute([$contract_id, $description, $complaint_type, $desired_resolution]);

            echo json_encode(['success' => true, 'message' => 'Жалоба отправлена. Мы рассмотрим её в ближайшее время.']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// Функции
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

function renderStars($rating) {
    $html = '';
    for ($i = 1; $i <= 5; $i++) {
        if ($i <= $rating) {
            $html .= '<i class="bi bi-star-fill text-warning"></i> ';
        } else {
            $html .= '<i class="bi bi-star text-muted"></i> ';
        }
    }
    return $html;
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Оценка и жалобы | Буратино</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <style>
        body { background-color: #f8f9fa; }
        .navbar { box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1); }
        .nav-link.active { color: #2ecc71 !important; font-weight: 600; }
        .page-header { border-bottom: 2px solid #2ecc71; margin-bottom: 1.5rem; padding-bottom: 0.75rem; }

        .contract-card {
            background: white;
            border-radius: 15px;
            padding: 20px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.05);
            margin-bottom: 25px;
            transition: transform 0.2s;
            border-left: 5px solid #dee2e6;
        }
        .contract-card:hover { transform: translateY(-2px); box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1); }

        .feedback-block {
            background: #f0fdf4;
            border-left: 4px solid #2ecc71;
            padding: 12px 15px;
            border-radius: 10px;
            margin-top: 15px;
        }
        .complaint-block {
            background: #fff5f5;
            border-left: 4px solid #e74c3c;
            padding: 12px 15px;
            border-radius: 10px;
            margin-top: 15px;
        }
        .info-row {
            display: flex;
            flex-wrap: wrap;
            margin-bottom: 8px;
        }
        .info-label {
            font-weight: 600;
            min-width: 130px;
            color: #6c757d;
        }
        .info-value {
            flex: 1;
            word-break: break-word;
        }
        hr { margin: 12px 0; }
        .btn-feedback {
            background: linear-gradient(135deg, #2ecc71, #27ae60);
            border: none;
            color: white;
        }
        .btn-feedback:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(46, 204, 113, 0.3);
        }
        .btn-complaint {
            background: linear-gradient(135deg, #e74c3c, #c0392b);
            border: none;
            color: white;
        }
        .btn-complaint:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(231, 76, 60, 0.3);
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
        }
        .rating-stars {
            display: flex;
            gap: 10px;
            margin: 15px 0;
        }
        .star {
            font-size: 2rem;
            cursor: pointer;
            color: #ddd;
            transition: color 0.2s;
        }
        .star:hover, .star.active {
            color: #ffc107;
        }
        @media (max-width: 768px) {
            .info-label { min-width: 100px; }
            .text-md-end { text-align: left !important; margin-top: 15px; }
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
                <li class="nav-item"><a class="nav-link" href="catalog.php"><i class="bi bi-box-seam me-1"></i>Каталог</a></li>
                <li class="nav-item"><a class="nav-link" href="contracts.php"><i class="bi bi-file-text-fill me-1"></i>Договоры</a></li>
                <li class="nav-item"><a class="nav-link active" href="feedback.php"><i class="bi bi-chat-dots-fill me-1"></i>Оценка и жалобы</a></li>
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
        <h2 class="mb-1"><i class="bi bi-chat-dots-fill me-2" style="color: #2ecc71;"></i>Оценка качества и жалобы</h2>
        <p class="text-muted small">Оцените качество продукции или оставьте жалобу. Здесь же отображаются ваши ранее отправленные отзывы и ответы на жалобы.</p>
    </div>

    <?php if (empty($contracts)): ?>
        <div class="text-center py-5">
            <i class="bi bi-inbox fs-1 text-muted d-block mb-3"></i>
            <h5 class="text-muted">Нет отгруженных заказов</h5>
            <p class="text-muted">После отгрузки заказа вы сможете оставить отзыв или жалобу</p>
        </div>
    <?php else: ?>
        <?php foreach ($contracts as $contract):
            $feedback = $feedbacks[$contract['id']] ?? null;
            $complaint = $complaints[$contract['id']] ?? null;
        ?>
        <div class="contract-card">
            <div class="row align-items-start">
                <div class="col-md-7">
                    <h5 class="mb-2">Договор №<?= htmlspecialchars($contract['contract_number']) ?></h5>
                    <div class="info-row">
                        <div class="info-label">Дата отгрузки:</div>
                        <div class="info-value"><?= date('d.m.Y', strtotime($contract['contract_date'])) ?></div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Сумма договора:</div>
                        <div class="info-value"><?= number_format($contract['total_amount'], 2, ',', ' ') ?> ₽</div>
                    </div>
                </div>
                <div class="col-md-5 text-md-end">
                    <?php if (!$feedback && !$complaint): ?>
                        <button class="btn btn-feedback me-2 mb-2" onclick="openFeedbackModal(<?= $contract['id'] ?>, '<?= htmlspecialchars($contract['contract_number']) ?>')">
                            <i class="bi bi-star-fill me-1"></i>Оценить
                        </button>
                        <button class="btn btn-complaint mb-2" onclick="openComplaintModal(<?= $contract['id'] ?>, '<?= htmlspecialchars($contract['contract_number']) ?>')">
                            <i class="bi bi-exclamation-triangle-fill me-1"></i>Пожаловаться
                        </button>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Блок с уже оставленным отзывом -->
            <?php if ($feedback): ?>
                <div class="feedback-block">
                    <div class="d-flex justify-content-between align-items-center flex-wrap mb-2">
                        <strong><i class="bi bi-chat-heart-fill text-success me-1"></i>Ваш отзыв</strong>
                        <small class="text-muted"><?= date('d.m.Y H:i', strtotime($feedback['created_at'])) ?></small>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Оценка:</div>
                        <div class="info-value"><?= renderStars($feedback['rating']) ?> (<?= $feedback['rating'] ?> из 5)</div>
                    </div>
                    <?php if (!empty($feedback['comment'])): ?>
                        <div class="info-row">
                            <div class="info-label">Комментарий:</div>
                            <div class="info-value"><?= nl2br(htmlspecialchars($feedback['comment'])) ?></div>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <!-- Блок с жалобой (если есть) -->
            <?php if ($complaint): ?>
                <div class="complaint-block">
                    <div class="d-flex justify-content-between align-items-center flex-wrap mb-2">
                        <strong><i class="bi bi-exclamation-triangle-fill text-danger me-1"></i>Ваша жалоба</strong>
                        <?= getStatusBadge($complaint['status']) ?>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Тип жалобы:</div>
                        <div class="info-value"><?= getTypeText($complaint['complaint_type']) ?></div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Описание:</div>
                        <div class="info-value"><?= nl2br(htmlspecialchars($complaint['description'])) ?></div>
                    </div>
                    <?php if (!empty($complaint['desired_resolution'])): ?>
                        <div class="info-row">
                            <div class="info-label">Желаемое решение:</div>
                            <div class="info-value"><?= nl2br(htmlspecialchars($complaint['desired_resolution'])) ?></div>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($complaint['resolution_comment'])): ?>
                        <hr>
                        <div class="bg-white p-2 rounded mt-2">
                            <strong>Ответ администратора:</strong>
                            <p class="mb-1 mt-1"><?= nl2br(htmlspecialchars($complaint['resolution_comment'])) ?></p>
                            <small class="text-muted">Обработал: <?= htmlspecialchars($complaint['processed_by_name'] ?? '—') ?> (<?= date('d.m.Y H:i', strtotime($complaint['processed_at'])) ?>)</small>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</main>

<!-- Модальное окно для оценки (без изменений) -->
<div class="modal fade" id="feedbackModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="bi bi-star-fill me-2"></i>Оценка качества</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="feedbackForm">
                    <input type="hidden" name="action" value="add_feedback">
                    <input type="hidden" name="contract_id" id="feedback_contract_id">
                    <p class="mb-2">Оцените качество продукции по договору <strong id="feedback_contract_number"></strong></p>
                    <div class="rating-stars" id="starRating">
                        <i class="bi bi-star star" data-rating="1"></i>
                        <i class="bi bi-star star" data-rating="2"></i>
                        <i class="bi bi-star star" data-rating="3"></i>
                        <i class="bi bi-star star" data-rating="4"></i>
                        <i class="bi bi-star star" data-rating="5"></i>
                    </div>
                    <input type="hidden" name="rating" id="rating_value" required>
                    <div class="mb-3 mt-3">
                        <label class="form-label">Ваш комментарий (необязательно)</label>
                        <textarea class="form-control" name="comment" rows="3" placeholder="Расскажите о вашем опыте..."></textarea>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                <button type="button" class="btn btn-success" id="submitFeedbackBtn">Отправить оценку</button>
            </div>
        </div>
    </div>
</div>

<!-- Модальное окно для жалобы (без изменений) -->
<div class="modal fade" id="complaintModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title"><i class="bi bi-exclamation-triangle-fill me-2"></i>Подача жалобы</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="complaintForm">
                    <input type="hidden" name="action" value="add_complaint">
                    <input type="hidden" name="contract_id" id="complaint_contract_id">
                    <p class="mb-3">Жалоба по договору <strong id="complaint_contract_number"></strong></p>
                    <div class="mb-3">
                        <label class="form-label">Тип жалобы <span class="text-danger">*</span></label>
                        <select class="form-select" name="complaint_type" required>
                            <option value="качество">Качество продукции</option>
                            <option value="сроки">Нарушение сроков поставки</option>
                            <option value="комплектация">Неполная комплектация</option>
                            <option value="документы">Проблемы с документами</option>
                            <option value="сервис">Низкое качество обслуживания</option>
                            <option value="другое">Другое</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Описание проблемы <span class="text-danger">*</span></label>
                        <textarea class="form-control" name="description" rows="4" required placeholder="Опишите подробно суть проблемы..."></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Желаемое решение</label>
                        <textarea class="form-control" name="desired_resolution" rows="2" placeholder="Как вы хотите решить эту проблему?"></textarea>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                <button type="button" class="btn btn-danger" id="submitComplaintBtn">Отправить жалобу</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
    let currentRating = 0;

    $('.star').on('click', function() {
        currentRating = $(this).data('rating');
        $('#rating_value').val(currentRating);
        $('.star').each(function() {
            const rating = $(this).data('rating');
            if (rating <= currentRating) {
                $(this).removeClass('bi-star').addClass('bi-star-fill active');
            } else {
                $(this).removeClass('bi-star-fill active').addClass('bi-star');
            }
        });
    });

    function openFeedbackModal(contractId, contractNumber) {
        $('#feedback_contract_id').val(contractId);
        $('#feedback_contract_number').text(contractNumber);
        currentRating = 0;
        $('#rating_value').val('');
        $('.star').removeClass('bi-star-fill active').addClass('bi-star');
        $('#feedbackForm textarea[name="comment"]').val('');
        $('#feedbackModal').modal('show');
    }

    function openComplaintModal(contractId, contractNumber) {
        $('#complaint_contract_id').val(contractId);
        $('#complaint_contract_number').text(contractNumber);
        $('#complaintForm')[0].reset();
        $('#complaintModal').modal('show');
    }

    $('#submitFeedbackBtn').on('click', function() {
        const rating = $('#rating_value').val();
        if (!rating) {
            Swal.fire({ icon: 'warning', title: 'Внимание', text: 'Пожалуйста, поставьте оценку' });
            return;
        }
        $.ajax({
            url: 'feedback.php',
            type: 'POST',
            data: $('#feedbackForm').serialize(),
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    Swal.fire({ icon: 'success', title: 'Спасибо!', text: response.message, timer: 2000 })
                        .then(() => location.reload());
                } else {
                    Swal.fire({ icon: 'error', title: 'Ошибка', text: response.message });
                }
            }
        });
    });

    $('#submitComplaintBtn').on('click', function() {
        const description = $('#complaintForm textarea[name="description"]').val();
        if (!description) {
            Swal.fire({ icon: 'warning', title: 'Внимание', text: 'Опишите проблему' });
            return;
        }
        Swal.fire({
            title: 'Подтверждение',
            text: 'Вы уверены, что хотите отправить жалобу?',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#e74c3c',
            confirmButtonText: 'Да, отправить',
            cancelButtonText: 'Отмена'
        }).then((result) => {
            if (result.isConfirmed) {
                $.ajax({
                    url: 'feedback.php',
                    type: 'POST',
                    data: $('#complaintForm').serialize(),
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            Swal.fire({ icon: 'success', title: 'Отправлено!', text: response.message, timer: 2000 })
                                .then(() => location.reload());
                        } else {
                            Swal.fire({ icon: 'error', title: 'Ошибка', text: response.message });
                        }
                    }
                });
            }
        });
    });
</script>
</body>
</html>