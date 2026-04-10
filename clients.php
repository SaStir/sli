<?php
// clients.php - Управление покупателями (только для админов и менеджеров)
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

// Проверка роли: только админ и менеджер имеют доступ к клиентам
// НЕ ПУТАЕМ: менеджер должен иметь доступ!
if (!in_array($current_user['role'], ['admin', 'manager'])) {
    // Если не админ и не менеджер - редирект
    if ($current_user['role'] == 'accountant') {
        header('Location: accounting.php');
    } elseif ($current_user['role'] == 'warehouse_keeper') {
        header('Location: warehouse.php');
    } elseif ($current_user['role'] == 'customer') {
        header('Location: catalog.php');
    } else {
        header('Location: login.php');
    }
    exit;
}

// Функции для отображения роли (если нужны)
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

// Получаем список всех покупателей
$customers = $pdo->query("SELECT * FROM customers ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
$totalCustomers = count($customers);
// Обработка добавления нового покупателя
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create') {
    header('Content-Type: application/json');

    try {
        $stmt = $pdo->prepare("INSERT INTO customers (name, inn, bank_name, bik, account_number, director_name, chief_accountant_name, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");

        $result = $stmt->execute([
            $_POST['name'],
            $_POST['inn'] ?: null,
            $_POST['bank_name'] ?: null,
            $_POST['bik'] ?: null,
            $_POST['account_number'] ?: null,
            $_POST['director_name'] ?: null,
            $_POST['chief_accountant_name'] ?: null
        ]);

        if ($result) {
            echo json_encode(['success' => true, 'message' => 'Покупатель успешно добавлен']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Ошибка при добавлении покупателя']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// Обработка удаления покупателя
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    header('Content-Type: application/json');

    try {
        // Проверяем, есть ли у покупателя договоры
        $check = $pdo->prepare("SELECT COUNT(*) FROM contracts WHERE customer_id = ?");
        $check->execute([$_POST['id']]);
        $count = $check->fetchColumn();

        if ($count > 0) {
            echo json_encode(['success' => false, 'message' => 'Нельзя удалить покупателя, у которого есть договоры']);
            exit;
        }

        $stmt = $pdo->prepare("DELETE FROM customers WHERE id = ?");
        $result = $stmt->execute([$_POST['id']]);

        if ($result) {
            echo json_encode(['success' => true, 'message' => 'Покупатель удален']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Ошибка при удалении']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// Обработка получения данных покупателя для редактирования
if (isset($_GET['action']) && $_GET['action'] === 'get' && isset($_GET['id'])) {
    header('Content-Type: application/json');

    $stmt = $pdo->prepare("SELECT * FROM customers WHERE id = ?");
    $stmt->execute([$_GET['id']]);
    $customer = $stmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode($customer);
    exit;
}

// Обработка обновления покупателя
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update') {
    header('Content-Type: application/json');

    try {
        $stmt = $pdo->prepare("UPDATE customers SET name = ?, inn = ?, bank_name = ?, bik = ?, account_number = ?, director_name = ?, chief_accountant_name = ? WHERE id = ?");

        $result = $stmt->execute([
            $_POST['name'],
            $_POST['inn'] ?: null,
            $_POST['bank_name'] ?: null,
            $_POST['bik'] ?: null,
            $_POST['account_number'] ?: null,
            $_POST['director_name'] ?: null,
            $_POST['chief_accountant_name'] ?: null,
            $_POST['id']
        ]);

        if ($result) {
            echo json_encode(['success' => true, 'message' => 'Данные покупателя обновлены']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Ошибка при обновлении']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// Функции форматирования
function formatInn($inn)
{
    return $inn ? htmlspecialchars($inn) : '—';
}

function formatBankInfo($bank_name, $bik)
{
    if (!$bank_name && !$bik)
        return '—';
    $result = '';
    if ($bank_name)
        $result .= htmlspecialchars($bank_name);
    if ($bik)
        $result .= ($result ? '<br>' : '') . '<small class="text-secondary">БИК ' . htmlspecialchars($bik) . '</small>';
    return $result;
}

function formatPersonName($name)
{
    return $name ? htmlspecialchars($name) : '—';
}

function formatDate($date)
{
    return $date ? date('d.m.Y', strtotime($date)) : '—';
}
?>
<!DOCTYPE html>
<html lang="ru">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Клиенты | Буратино</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
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

        .btn-primary {
            background: linear-gradient(135deg, #2ecc71, #27ae60);
            border: none;
        }

        .btn-primary:hover {
            background: linear-gradient(135deg, #27ae60, #2ecc71);
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(46, 204, 113, 0.3);
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

        .badge-inn {
            font-family: monospace;
            background-color: #e9ecef;
            color: #1e293b;
            padding: 0.35em 0.65em;
            border-radius: 6px;
        }

        .table-container {
            background: white;
            border-radius: 15px;
            padding: 20px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.05);
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
                            <a class="nav-link" href="contracts.php">
                                <i class="bi bi-file-text-fill me-1"></i>Договоры
                            </a>
                        </li>
                    <?php endif; ?>

                    <!-- Клиенты только для админа и менеджера -->
                    <?php if (in_array($current_user['role'], ['admin', 'manager'])): ?>
                        <li class="nav-item">
                            <a class="nav-link active" href="clients.php">
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
                        <!-- Единое оформление должности -->
                        <span class="role-badge-custom">
                            <?= getRoleName($current_user['role']) ?>
                        </span>
                    </div>
                    <a href="logout.php" class="btn btn-outline-light btn-sm">
                        <i class="bi bi-box-arrow-right"></i>
                    </a>
                </div>
            </div>
        </div>
    </nav>

    <!-- Основной контейнер -->
    <main class="container-fluid px-4">

        <!-- Заголовок + кнопка добавления -->
        <div class="page-header d-flex justify-content-between align-items-center">
            <div>
                <h2 class="mb-1"><i class="bi bi-person-vcard me-2" style="color: #2ecc71;"></i>Список покупателей</h2>
                <p class="text-muted small">Юридические лица и ИП, с которыми заключены договоры</p>
            </div>
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addCustomerModal">
                <i class="bi bi-plus-circle me-2"></i>Новый покупатель
            </button>
        </div>

        <!-- Таблица клиентов -->
        <div class="table-container">
            <div class="table-responsive">
                <table id="customersTable" class="table table-hover table-striped align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>ID</th>
                            <th>Наименование</th>
                            <th>ИНН</th>
                            <th>Банк / БИК</th>
                            <th>Счёт</th>
                            <th>Руководитель</th>
                            <th>Гл. бухгалтер</th>
                            <th>Создан</th>
                            <th style="width: 120px;">Действия</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($customers)): ?>
                            <tr>
                                <td colspan="9" class="text-center py-5">
                                    <i class="bi bi-inbox fs-1 text-muted d-block mb-3"></i>
                                    <p class="text-muted">Нет данных о покупателях</p>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($customers as $customer): ?>
                                <tr>
                                    <td><?= $customer['id'] ?></td>
                                    <td><span class="fw-semibold"><?= htmlspecialchars($customer['name']) ?></span></td>
                                    <td><span class="badge badge-inn"><?= formatInn($customer['inn']) ?></span></td>
                                    <td><?= formatBankInfo($customer['bank_name'] ?? '', $customer['bik'] ?? '') ?></td>
                                    <td><span
                                            class="font-monospace small"><?= $customer['account_number'] ? htmlspecialchars($customer['account_number']) : '—' ?></span>
                                    </td>
                                    <td><?= formatPersonName($customer['director_name'] ?? '') ?></td>
                                    <td><?= formatPersonName($customer['chief_accountant_name'] ?? '') ?></td>
                                    <td><?= formatDate($customer['created_at']) ?></td>
                                    <td>
                                        <button class="btn btn-sm btn-outline-primary edit-btn" data-id="<?= $customer['id'] ?>"
                                            title="Редактировать">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        <button class="btn btn-sm btn-outline-danger delete-btn"
                                            data-id="<?= $customer['id'] ?>"
                                            data-name="<?= htmlspecialchars($customer['name']) ?>" title="Удалить">
                                            <i class="bi bi-trash3"></i>
                                        </button>

                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div class="text-muted small mt-3">
                Всего записей: <strong><?= $totalCustomers ?></strong>
            </div>
        </div>

    </main>

    <!-- Модальное окно добавления -->
    <div class="modal fade" id="addCustomerModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="bi bi-person-plus-fill me-2"></i>Новый покупатель</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="customerForm">
                        <input type="hidden" name="action" value="create">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="name" class="form-label">Наименование <span
                                        class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="name" name="name" required>
                            </div>
                            <div class="col-md-6">
                                <label for="inn" class="form-label">ИНН</label>
                                <input type="text" class="form-control" id="inn" name="inn" maxlength="12">
                            </div>
                            <div class="col-md-6">
                                <label for="bank_name" class="form-label">Наименование банка</label>
                                <input type="text" class="form-control" id="bank_name" name="bank_name">
                            </div>
                            <div class="col-md-3">
                                <label for="bik" class="form-label">БИК</label>
                                <input type="text" class="form-control" id="bik" name="bik" maxlength="9">
                            </div>
                            <div class="col-md-3">
                                <label for="account_number" class="form-label">Расчётный счёт</label>
                                <input type="text" class="form-control" id="account_number" name="account_number">
                            </div>
                            <div class="col-md-6">
                                <label for="director_name" class="form-label">ФИО руководителя</label>
                                <input type="text" class="form-control" id="director_name" name="director_name">
                            </div>
                            <div class="col-md-6">
                                <label for="chief_accountant_name" class="form-label">Главный бухгалтер</label>
                                <input type="text" class="form-control" id="chief_accountant_name"
                                    name="chief_accountant_name">
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                    <button type="button" class="btn btn-primary" id="saveCustomerBtn">
                        <i class="bi bi-check2-circle me-2"></i>Сохранить
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Модальное окно редактирования -->
    <div class="modal fade" id="editCustomerModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title"><i class="bi bi-pencil-square me-2"></i>Редактировать покупателя</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="editCustomerForm">
                        <input type="hidden" id="edit_id" name="id">
                        <input type="hidden" name="action" value="update">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="edit_name" class="form-label">Наименование <span
                                        class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="edit_name" name="name" required>
                            </div>
                            <div class="col-md-6">
                                <label for="edit_inn" class="form-label">ИНН</label>
                                <input type="text" class="form-control" id="edit_inn" name="inn" maxlength="12">
                            </div>
                            <div class="col-md-6">
                                <label for="edit_bank_name" class="form-label">Наименование банка</label>
                                <input type="text" class="form-control" id="edit_bank_name" name="bank_name">
                            </div>
                            <div class="col-md-3">
                                <label for="edit_bik" class="form-label">БИК</label>
                                <input type="text" class="form-control" id="edit_bik" name="bik" maxlength="9">
                            </div>
                            <div class="col-md-3">
                                <label for="edit_account_number" class="form-label">Расчётный счёт</label>
                                <input type="text" class="form-control" id="edit_account_number" name="account_number">
                            </div>
                            <div class="col-md-6">
                                <label for="edit_director_name" class="form-label">ФИО руководителя</label>
                                <input type="text" class="form-control" id="edit_director_name" name="director_name">
                            </div>
                            <div class="col-md-6">
                                <label for="edit_chief_accountant_name" class="form-label">Главный бухгалтер</label>
                                <input type="text" class="form-control" id="edit_chief_accountant_name"
                                    name="chief_accountant_name">
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                    <button type="button" class="btn btn-success" id="updateCustomerBtn">
                        <i class="bi bi-check2-circle me-2"></i>Обновить
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Скрипты -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <script>
        $(document).ready(function () {
            // DataTables
            $('#customersTable').DataTable({
                language: {
                    url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/ru.json'
                },
                pageLength: 10,
                columnDefs: [{ orderable: false, targets: 8 }]
            });

            // Сохранение нового покупателя
            $('#saveCustomerBtn').click(function () {
                $.ajax({
                    url: 'clients.php',
                    type: 'POST',
                    data: $('#customerForm').serialize(),
                    dataType: 'json',
                    success: function (response) {
                        if (response.success) {
                            Swal.fire({
                                icon: 'success',
                                title: 'Успешно!',
                                text: response.message,
                                timer: 1500
                            }).then(() => location.reload());
                        } else {
                            Swal.fire({ icon: 'error', title: 'Ошибка', text: response.message });
                        }
                    }
                });
            });

            // Загрузка данных для редактирования
            $('.edit-btn').click(function () {
                const id = $(this).data('id');
                $.getJSON('clients.php?action=get&id=' + id, function (data) {
                    $('#edit_id').val(data.id);
                    $('#edit_name').val(data.name);
                    $('#edit_inn').val(data.inn);
                    $('#edit_bank_name').val(data.bank_name);
                    $('#edit_bik').val(data.bik);
                    $('#edit_account_number').val(data.account_number);
                    $('#edit_director_name').val(data.director_name);
                    $('#edit_chief_accountant_name').val(data.chief_accountant_name);
                    $('#editCustomerModal').modal('show');
                });
            });

            // Обновление покупателя
            $('#updateCustomerBtn').click(function () {
                $.ajax({
                    url: 'clients.php',
                    type: 'POST',
                    data: $('#editCustomerForm').serialize(),
                    dataType: 'json',
                    success: function (response) {
                        if (response.success) {
                            Swal.fire({
                                icon: 'success',
                                title: 'Успешно!',
                                text: response.message,
                                timer: 1500
                            }).then(() => location.reload());
                        } else {
                            Swal.fire({ icon: 'error', title: 'Ошибка', text: response.message });
                        }
                    }
                });
            });

            // Удаление
            $('.delete-btn').click(function () {
                const id = $(this).data('id');
                const name = $(this).data('name');

                Swal.fire({
                    title: 'Удаление',
                    html: `Удалить покупателя <strong>${name}</strong>?`,
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#d33',
                    confirmButtonText: 'Да, удалить',
                    cancelButtonText: 'Отмена'
                }).then((result) => {
                    if (result.isConfirmed) {
                        $.ajax({
                            url: 'clients.php',
                            type: 'POST',
                            data: { action: 'delete', id: id },
                            dataType: 'json',
                            success: function (response) {
                                if (response.success) {
                                    Swal.fire({
                                        icon: 'success',
                                        title: 'Удалено!',
                                        timer: 1500
                                    }).then(() => location.reload());
                                } else {
                                    Swal.fire({ icon: 'error', title: 'Ошибка', text: response.message });
                                }
                            }
                        });
                    }
                });
            });

            // Создание договора
            $('.contract-btn').click(function () {
                const id = $(this).data('id');
                const name = $(this).data('name');

                Swal.fire({
                    icon: 'info',
                    title: 'Создание договора',
                    html: `Создать договор для <strong>${name}</strong>?`,
                    showCancelButton: true,
                    confirmButtonText: 'Перейти',
                    cancelButtonText: 'Отмена'
                }).then((result) => {
                    if (result.isConfirmed) {
                        window.location.href = 'contract_create.php?customer_id=' + id;
                    }
                });
            });
        });
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