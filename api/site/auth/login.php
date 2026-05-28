<?php
session_start();

// Incluir a conexão com o banco de dados com verificação de erro
include_once "../../conf/db.php";

// Verificar se a conexão foi estabelecida
if (!isset($conn) || $conn->connect_error) {
    die("Erro de conexão com o banco de dados: " . ($conn->connect_error ?? "Conexão não estabelecida"));
}

$error_message = '';
$success_message = '';

// Verificar se veio de registro bem-sucedido
if(isset($_SESSION['register_success'])) {
    $success_message = "Conta criada com sucesso! Faça login para começar.";
    unset($_SESSION['register_success']);
}

if(isset($_POST["login"])){
    $usernumber = trim($_POST["usernumber"]);
    $userpassword = $_POST["password"];
    
    // Verificar se a conexão está OK antes de preparar a query
    if ($conn) {
        $stmt = $conn->prepare("SELECT * FROM users WHERE user_number = ? OR email = ?");
        
        if ($stmt) {
            $stmt->bind_param("ss", $usernumber, $usernumber);
            $stmt->execute();
            $result = $stmt->get_result();

            if($result->num_rows == 1){
                $user = $result->fetch_assoc();

                if(password_verify($userpassword, $user["password_hash"])){
                    $_SESSION["user_id"] = $user["id"];
                    $_SESSION["user_name"] = $user["name"];
                    
                    // Atualizar último login
                    $updateStmt = $conn->prepare("UPDATE users SET last_seen = NOW(), is_online = 1 WHERE id = ?");
                    if ($updateStmt) {
                        $updateStmt->bind_param("i", $user["id"]);
                        $updateStmt->execute();
                        $updateStmt->close();
                    }

                    header("location: ../dashboard/dashboard.php");
                    exit();
                } else {
                    $error_message = "Número/Email ou palavra-passe incorrectos";
                }
            } else {
                $error_message = "Número/Email ou palavra-passe incorrectos";
            }
            $stmt->close();
        } else {
            $error_message = "Erro na preparação da consulta: " . $conn->error;
        }
    } else {
        $error_message = "Erro de conexão com o banco de dados";
    }
}
?>

<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Entrar • Cerulean Chat</title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            font-family: 'Inter', sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            position: relative;
            overflow-x: hidden;
        }

        body::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 1%, transparent 1%);
            background-size: 50px 50px;
            animation: moveBackground 60s linear infinite;
            pointer-events: none;
        }

        @keyframes moveBackground {
            from { transform: translate(0, 0); }
            to { transform: translate(50px, 50px); }
        }

        .container-custom {
            max-width: 450px;
            width: 100%;
            margin: auto;
            position: relative;
            z-index: 1;
        }

        .card {
            background: rgba(255, 255, 255, 0.98);
            border-radius: 32px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
            overflow: hidden;
            animation: fadeInUp 0.6s ease;
            backdrop-filter: blur(10px);
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .card-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 40px 30px;
            text-align: center;
            color: white;
            position: relative;
        }

        .card-header .logo {
            width: 70px;
            height: 70px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            font-size: 32px;
        }

        .card-header h2 {
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 8px;
        }

        .card-header p {
            opacity: 0.9;
            font-size: 14px;
        }

        .card-body {
            padding: 40px;
        }

        .form-group {
            margin-bottom: 25px;
        }

        .input-group-custom {
            position: relative;
            transition: all 0.3s ease;
        }

        .input-group-custom i {
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: #999;
            font-size: 18px;
            transition: all 0.3s ease;
            z-index: 1;
        }

        .input-group-custom input {
            width: 100%;
            padding: 15px 15px 15px 48px;
            border: 2px solid #e5e7eb;
            border-radius: 16px;
            font-size: 15px;
            transition: all 0.3s ease;
            font-family: 'Inter', sans-serif;
            background: #f9fafb;
        }

        .input-group-custom input:focus {
            outline: none;
            border-color: #667eea;
            background: white;
            box-shadow: 0 0 0 4px rgba(102, 126, 234, 0.1);
        }

        .input-group-custom input:focus + i {
            color: #667eea;
        }

        .input-group-custom input::placeholder {
            color: #9ca3af;
        }

        .password-toggle {
            position: absolute;
            right: 16px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: #999;
            z-index: 2;
            background: transparent;
            border: none;
            font-size: 16px;
        }

        .password-toggle:hover {
            color: #667eea;
        }

        .options-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            font-size: 14px;
        }

        .checkbox-label {
            display: flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
            color: #6b7280;
        }

        .checkbox-label input[type="checkbox"] {
            width: 18px;
            height: 18px;
            cursor: pointer;
            accent-color: #667eea;
        }

        .forgot-link {
            color: #667eea;
            text-decoration: none;
            font-weight: 500;
            transition: all 0.3s ease;
        }

        .forgot-link:hover {
            color: #764ba2;
            text-decoration: underline;
        }

        .btn-login {
            width: 100%;
            padding: 15px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            border-radius: 16px;
            color: white;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-bottom: 20px;
        }

        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(102, 126, 234, 0.3);
        }

        .btn-login:active {
            transform: translateY(0);
        }

        .divider {
            text-align: center;
            margin: 25px 0;
            position: relative;
        }

        .divider::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 0;
            right: 0;
            height: 1px;
            background: #e5e7eb;
        }

        .divider span {
            background: white;
            padding: 0 15px;
            position: relative;
            color: #9ca3af;
            font-size: 13px;
        }

        .register-link {
            text-align: center;
            margin-top: 20px;
            font-size: 14px;
            color: #6b7280;
        }

        .register-link a {
            color: #667eea;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .register-link a:hover {
            color: #764ba2;
            text-decoration: underline;
        }

        .alert-custom {
            padding: 14px 16px;
            border-radius: 16px;
            margin-bottom: 25px;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 12px;
            animation: slideIn 0.3s ease;
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateX(-20px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        .alert-error {
            background: #fee2e2;
            color: #dc2626;
            border: 1px solid #fecaca;
        }

        .alert-success {
            background: #d1fae5;
            color: #065f46;
            border: 1px solid #a7f3d0;
        }

        .alert-custom i {
            font-size: 18px;
        }

        .demo-hint {
            background: #f3f4f6;
            border-radius: 16px;
            padding: 15px;
            margin-top: 25px;
            font-size: 12px;
            color: #6b7280;
            text-align: center;
        }

        .demo-hint strong {
            color: #667eea;
        }

        @media (max-width: 576px) {
            .card-body {
                padding: 30px 25px;
            }
            
            .card-header {
                padding: 30px 20px;
            }
            
            .card-header .logo {
                width: 60px;
                height: 60px;
                font-size: 28px;
            }
            
            .card-header h2 {
                font-size: 24px;
            }
        }
    </style>
</head>
<body>

<div class="container-custom">
    <div class="card">
        <div class="card-header">
            <div class="logo">
                <i class="fas fa-lock"></i>
            </div>
            <h2>Bem-vindo de volta</h2>
            <p>Entre para aceder às suas mensagens seguras</p>
        </div>
        
        <div class="card-body">
            
            <?php if($error_message): ?>
            <div class="alert-custom alert-error">
                <i class="fas fa-exclamation-circle"></i>
                <?php echo $error_message; ?>
            </div>
            <?php endif; ?>
            
            <?php if($success_message): ?>
            <div class="alert-custom alert-success">
                <i class="fas fa-check-circle"></i>
                <?php echo $success_message; ?>
            </div>
            <?php endif; ?>
            
            <form action="login.php" method="post" id="loginForm">
                <div class="form-group">
                    <div class="input-group-custom">
                        <i class="fas fa-user"></i>
                        <input type="text" name="usernumber" id="usernumber" 
                               placeholder="Número de utilizador ou Email" required autocomplete="off"
                               value="<?php echo isset($_POST['usernumber']) ? htmlspecialchars($_POST['usernumber']) : ''; ?>">
                    </div>
                </div>
                
                <div class="form-group">
                    <div class="input-group-custom">
                        <i class="fas fa-lock"></i>
                        <input type="password" name="password" id="password" 
                               placeholder="Palavra-passe" required>
                        <button type="button" class="password-toggle" onclick="togglePassword()">
                            <i class="far fa-eye-slash" id="toggleIcon"></i>
                        </button>
                    </div>
                </div>
                
                <div class="options-row">
                    <label class="checkbox-label">
                        <input type="checkbox" name="remember" id="remember">
                        <span>Lembrar-me</span>
                    </label>
                    <a href="#" class="forgot-link">Esqueceu a palavra-passe?</a>
                </div>
                
                <button type="submit" name="login" class="btn-login">
                    <i class="fas fa-sign-in-alt"></i> Entrar
                </button>
                
                <div class="divider">
                    <span>ou</span>
                </div>
                
                <div class="register-link">
                    Não tem conta? <a href="register.php">Criar conta gratuita</a>
                </div>
            </form>
            
            <div class="demo-hint">
                <i class="fas fa-info-circle"></i> <strong>Demo:</strong> Utilize "12345" ou "nelson@email.com"<br>
                <span style="font-size: 11px;">Palavra-passe: a que definiu no registo</span>
            </div>
            
        </div>
    </div>
</div>

<script>
    function togglePassword() {
        const passwordInput = document.getElementById('password');
        const toggleIcon = document.getElementById('toggleIcon');
        
        if (passwordInput.type === 'password') {
            passwordInput.type = 'text';
            toggleIcon.classList.remove('fa-eye-slash');
            toggleIcon.classList.add('fa-eye');
        } else {
            passwordInput.type = 'password';
            toggleIcon.classList.remove('fa-eye');
            toggleIcon.classList.add('fa-eye-slash');
        }
    }
    
    const rememberCheckbox = document.getElementById('remember');
    const usernumberInput = document.getElementById('usernumber');
    
    if (localStorage.getItem('rememberedUser')) {
        usernumberInput.value = localStorage.getItem('rememberedUser');
        rememberCheckbox.checked = true;
    }
    
    document.getElementById('loginForm').addEventListener('submit', function() {
        if (rememberCheckbox.checked) {
            localStorage.setItem('rememberedUser', usernumberInput.value);
        } else {
            localStorage.removeItem('rememberedUser');
        }
    });
    
    const inputs = document.querySelectorAll('.input-group-custom input');
    inputs.forEach(input => {
        input.addEventListener('focus', function() {
            this.parentElement.style.transform = 'translateY(-1px)';
        });
        input.addEventListener('blur', function() {
            this.parentElement.style.transform = 'translateY(0)';
        });
    });
    
    document.getElementById('password').addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
            document.querySelector('.btn-login').click();
        }
    });
</script>

</body>
</html>