<?php
// login.php - Страница входа в систему с ролевой моделью
session_start();

require 'config.php';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Ошибка подключения к БД: " . $e->getMessage());
}

$error = '';

// Обработка отправки формы
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = trim($_POST['password'] ?? '');
    
    if (empty($email) || empty($password)) {
        $error = 'Заполните все поля';
    } else {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? AND password = ?");
        $stmt->execute([$email, $password]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_email'] = $user['email'];
            $_SESSION['is_admin'] = $user['is_admin'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['customer_id'] = $user['customer_id'];
            
            // Перенаправление в зависимости от роли
            switch ($user['role']) {
                case 'admin':
                    header('Location: contracts.php');
                    break;
                case 'manager':
                    header('Location: contracts.php');
                    break;
                case 'warehouse_keeper':
                    header('Location: warehouse.php');
                    break;
                case 'accountant':
                    header('Location: accounting.php');
                    break;
                default:
                    header('Location: catalog.php');
            }
            exit;
        } else {
            $error = 'Неверный email или пароль';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Вход | Буратино</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body {
            background: linear-gradient(135deg, #2ecc71 0%, #27ae60 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .login-card {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(39, 174, 96, 0.3);
            padding: 40px;
            width: 100%;
            max-width: 400px;
        }
        
        .login-header {
            text-align: center;
            margin-bottom: 30px;
        }
        
        .login-header i {
            font-size: 60px;
            color: #2ecc71;
            margin-bottom: 15px;
        }
        
        .login-header h2 {
            color: #27ae60;
            font-weight: 600;
        }
        
        .form-control {
            border-radius: 12px;
            padding: 12px 20px;
            border: 2px solid #e0e0e0;
        }
        
        .form-control:focus {
            border-color: #2ecc71;
            box-shadow: 0 0 0 0.2rem rgba(46, 204, 113, 0.25);
        }
        
        .btn-login {
            background: linear-gradient(135deg, #2ecc71, #27ae60);
            border: none;
            border-radius: 12px;
            padding: 12px;
            font-weight: 600;
            color: white;
            width: 100%;
        }
        
        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 30px rgba(46, 204, 113, 0.4);
        }
        
        .error-alert {
            background: #fee;
            border-left: 4px solid #f44336;
            padding: 12px 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            color: #d32f2f;
        }
        
        .demo-credentials {
            background: #f0f9f4;
            border-radius: 10px;
            padding: 15px;
            margin-top: 25px;
            font-size: 13px;
            border: 1px dashed #2ecc71;
        }
        
        .demo-credentials p {
            margin-bottom: 5px;
            color: #27ae60;
        }
    </style>
</head>
<body>

<div class="login-card">
    <div class="login-header">
        <i class="bi bi-tree-fill"></i>
        <h2>Буратино</h2>
        <p>Вход в систему</p>
    </div>
    
    <?php if ($error): ?>
        <div class="error-alert">
            <i class="bi bi-exclamation-triangle-fill"></i>
            <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>
    
    <form method="POST" action="">
        <div class="mb-4">
            <label for="email" class="form-label">Электронная почта</label>
            <input type="email" class="form-control" id="email" name="email" 
                   placeholder="example@mail.ru" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required>
        </div>
        
        <div class="mb-4">
            <label for="password" class="form-label">Пароль</label>
            <input type="password" class="form-control" id="password" name="password" 
                   placeholder="••••••••" required>
        </div>
        
        <button type="submit" class="btn-login">
            <i class="bi bi-box-arrow-in-right me-2"></i>Войти
        </button>
    </form>
    
    <div class="demo-credentials">
        <p><strong>Тестовые аккаунты:</strong></p>
        <p>Директор (админ): admin@admin.ru / admin123</p>
        <p>Менеджер: manager@forest.ru / manager123</p>
        <p>Кладовщик: warehouse@forest.ru / warehouse123</p>
        <p>Бухгалтер: accountant@forest.ru / accountant123</p>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>