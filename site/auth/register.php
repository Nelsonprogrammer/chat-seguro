<?php
session_start();
include_once "../../conf/db.php";
include_once "../../crypto/crypto.php";

$step = isset($_GET['step']) ? (int)$_GET['step'] : 1;
$error_message = '';

if(isset($_POST["register_step1"])){
    $username = trim($_POST["username"]);
    $email = trim($_POST["email"]);
    $usernumber = trim($_POST["usernumber"]);
    
    $stmt = $conn->prepare("SELECT id FROM users WHERE email = ? OR user_number = ?");
    $stmt->bind_param("ss", $email, $usernumber);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if($result->num_rows > 0){
        $error_message = "Email ou número de utilizador já registado!";
    } else {
        $_SESSION['temp_reg'] = [
            'username' => $username,
            'email' => $email,
            'usernumber' => $usernumber
        ];
        header("location: register.php?step=2");
        exit();
    }
}

if(isset($_POST["register_step2"])){
    $phone = trim($_POST["phone"]);
    $birth_date = trim($_POST["birth_date"]);
    $city = trim($_POST["city"]);
    $country = trim($_POST["country"]);
    
    $_SESSION['temp_reg']['phone'] = $phone;
    $_SESSION['temp_reg']['birth_date'] = $birth_date;
    $_SESSION['temp_reg']['city'] = $city;
    $_SESSION['temp_reg']['country'] = $country;
    
    header("location: register.php?step=3");
    exit();
}

if(isset($_POST["register_step3"])){
    $password = $_POST["password"];
    $confirm_password = $_POST["confirm_password"];
    
    if($password !== $confirm_password){
        $error_message = "As palavras-passe não coincidem!";
    } elseif(strlen($password) < 6){
        $error_message = "A palavra-passe deve ter no mínimo 6 caracteres!";
    } else {
        $profile_photo = null;
        $profile_photo_type = null;
        
        if(isset($_FILES['profile_photo']) && $_FILES['profile_photo']['error'] === UPLOAD_ERR_OK){
            $photo_tmp = $_FILES['profile_photo']['tmp_name'];
            $photo_type = $_FILES['profile_photo']['type'];
            $photo_content = file_get_contents($photo_tmp);
            $profile_photo = base64_encode($photo_content);
            $profile_photo_type = $photo_type;
        }
        
        $RSAkeys = generateRSAKeys();
        $rsapublic = $RSAkeys["publicKey"];
        $rsaprivate = $RSAkeys["privateKey"];
        $dh_private = generateDHPrivate();
        
        $password_hash = password_hash($password, PASSWORD_DEFAULT);
        $created_at = date("Y-m-d H:i:s");
        $bio = "Olá! Estou usando o Cerulean Chat - Mensagens criptografadas ponta-a-ponta.";
        
        $stmt = $conn->prepare("
            INSERT INTO users 
            (password_hash, user_number, phone, name, email, bio, city, country, birth_date,
             profile_photo, profile_photo_type, public_key, privatersa, privatedh, created_at, is_online, last_seen) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, NULL)
        ");
        
        $stmt->bind_param(
            "sssssssssssssss",
            $password_hash,
            $_SESSION['temp_reg']['usernumber'],
            $_SESSION['temp_reg']['phone'],
            $_SESSION['temp_reg']['username'],
            $_SESSION['temp_reg']['email'],
            $bio,
            $_SESSION['temp_reg']['city'],
            $_SESSION['temp_reg']['country'],
            $_SESSION['temp_reg']['birth_date'],
            $profile_photo,
            $profile_photo_type,
            $rsapublic,
            $rsaprivate,
            $dh_private,
            $created_at
        );
        
        if($stmt->execute()){
            unset($_SESSION['temp_reg']);
            $_SESSION['register_success'] = true;
            header("location: register.php?step=success");
            exit();
        } else {
            $error_message = "Erro ao criar utilizador: " . $stmt->error;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="pt">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Criar conta • Cerulean Chat</title>
<script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&amp;family=Inter:wght@400;500;600&amp;display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&amp;display=swap" rel="stylesheet"/>
<style>
    .material-symbols-outlined {
        font-variation-settings: 'FILL' 0, 'wght' 400, 'GRAD' 0, 'opsz' 24;
    }
    body {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    }
</style>
</head>
<body class="font-['Inter'] antialiased min-h-screen flex items-center justify-center p-4">

<div class="w-full max-w-md mx-auto">
    <div class="bg-white rounded-2xl shadow-xl overflow-hidden">
        <!-- Header -->
        <div class="bg-gradient-to-r from-blue-600 to-purple-600 p-6 text-center text-white">
            <div class="w-16 h-16 bg-white/20 rounded-full flex items-center justify-center mx-auto mb-4">
                <span class="material-symbols-outlined text-3xl">lock</span>
            </div>
            <h2 class="text-2xl font-bold">Criar conta</h2>
            <p class="text-sm opacity-90 mt-1">Cerulean Chat - Comunicação segura</p>
        </div>
        
        <div class="p-6">
            
            <?php if($step == 1): ?>
            <!-- Step 1 -->
            <div class="flex justify-between mb-8">
                <div class="text-center flex-1"><div class="w-8 h-8 mx-auto rounded-full bg-blue-600 text-white flex items-center justify-center text-sm font-bold">1</div><span class="text-xs text-gray-500 mt-1 block">Básico</span></div>
                <div class="text-center flex-1"><div class="w-8 h-8 mx-auto rounded-full bg-gray-200 text-gray-500 flex items-center justify-center text-sm font-bold">2</div><span class="text-xs text-gray-400 mt-1 block">Perfil</span></div>
                <div class="text-center flex-1"><div class="w-8 h-8 mx-auto rounded-full bg-gray-200 text-gray-500 flex items-center justify-center text-sm font-bold">3</div><span class="text-xs text-gray-400 mt-1 block">Segurança</span></div>
            </div>
            
            <?php if($error_message): ?>
            <div class="mb-4 p-3 rounded-xl bg-red-50 text-red-600 text-sm flex items-center gap-2">
                <span class="material-symbols-outlined text-lg">error</span> <?php echo $error_message; ?>
            </div>
            <?php endif; ?>
            
            <form method="POST">
                <div class="mb-4">
                    <div class="relative">
                        <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-lg">person</span>
                        <input type="text" name="username" placeholder="Nome completo" required value="<?php echo isset($_SESSION['temp_reg']['username']) ? htmlspecialchars($_SESSION['temp_reg']['username']) : ''; ?>" class="w-full pl-10 pr-4 py-3 border border-gray-200 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none transition">
                    </div>
                </div>
                
                <div class="mb-4">
                    <div class="relative">
                        <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-lg">mail</span>
                        <input type="email" name="email" placeholder="Email" required value="<?php echo isset($_SESSION['temp_reg']['email']) ? htmlspecialchars($_SESSION['temp_reg']['email']) : ''; ?>" class="w-full pl-10 pr-4 py-3 border border-gray-200 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none transition">
                    </div>
                </div>
                
                <div class="mb-6">
                    <div class="relative">
                        <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-lg">badge</span>
                        <input type="text" name="usernumber" placeholder="Número de utilizador" required value="<?php echo isset($_SESSION['temp_reg']['usernumber']) ? htmlspecialchars($_SESSION['temp_reg']['usernumber']) : ''; ?>" class="w-full pl-10 pr-4 py-3 border border-gray-200 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none transition">
                    </div>
                    <p class="text-xs text-gray-400 mt-1">Seu identificador único no sistema</p>
                </div>
                
                <button type="submit" name="register_step1" class="w-full bg-gradient-to-r from-blue-600 to-purple-600 text-white py-3 rounded-xl font-semibold hover:opacity-90 transition flex items-center justify-center gap-2">
                    Continuar <span class="material-symbols-outlined text-sm">arrow_forward</span>
                </button>
            </form>
            
            <div class="text-center mt-6">
                <p class="text-sm text-gray-500">Já tem conta? <a href="login.php" class="text-blue-600 font-semibold hover:underline">Fazer login</a></p>
            </div>
            
            <?php elseif($step == 2): ?>
            <!-- Step 2 -->
            <div class="flex justify-between mb-8">
                <div class="text-center flex-1"><div class="w-8 h-8 mx-auto rounded-full bg-green-500 text-white flex items-center justify-center text-xs"><span class="material-symbols-outlined text-sm">check</span></div><span class="text-xs text-gray-500 mt-1 block">Básico</span></div>
                <div class="text-center flex-1"><div class="w-8 h-8 mx-auto rounded-full bg-blue-600 text-white flex items-center justify-center text-sm font-bold">2</div><span class="text-xs text-gray-500 mt-1 block">Perfil</span></div>
                <div class="text-center flex-1"><div class="w-8 h-8 mx-auto rounded-full bg-gray-200 text-gray-500 flex items-center justify-center text-sm font-bold">3</div><span class="text-xs text-gray-400 mt-1 block">Segurança</span></div>
            </div>
            
            <form method="POST">
                <div class="mb-4">
                    <div class="relative">
                        <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-lg">call</span>
                        <input type="tel" name="phone" placeholder="Telefone (opcional)" value="<?php echo isset($_SESSION['temp_reg']['phone']) ? htmlspecialchars($_SESSION['temp_reg']['phone']) : ''; ?>" class="w-full pl-10 pr-4 py-3 border border-gray-200 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none transition">
                    </div>
                </div>
                
                <div class="grid grid-cols-2 gap-4 mb-4">
                    <div class="relative">
                        <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-lg">calendar_month</span>
                        <input type="date" name="birth_date" value="<?php echo isset($_SESSION['temp_reg']['birth_date']) ? htmlspecialchars($_SESSION['temp_reg']['birth_date']) : ''; ?>" class="w-full pl-10 pr-3 py-3 border border-gray-200 rounded-xl focus:ring-2 focus:ring-blue-500 outline-none">
                    </div>
                    <div class="relative">
                        <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-lg">location_city</span>
                        <input type="text" name="city" placeholder="Cidade" value="<?php echo isset($_SESSION['temp_reg']['city']) ? htmlspecialchars($_SESSION['temp_reg']['city']) : ''; ?>" class="w-full pl-10 pr-3 py-3 border border-gray-200 rounded-xl focus:ring-2 focus:ring-blue-500 outline-none">
                    </div>
                </div>
                
                <div class="mb-6">
                    <div class="relative">
                        <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-lg">public</span>
                        <select name="country" class="w-full pl-10 pr-4 py-3 border border-gray-200 rounded-xl focus:ring-2 focus:ring-blue-500 outline-none appearance-none bg-white">
                            <option value="">Selecione o país</option>
                            <option value="Angola" <?php echo (isset($_SESSION['temp_reg']['country']) && $_SESSION['temp_reg']['country'] == 'Angola') ? 'selected' : ''; ?>>Angola</option>
                            <option value="Brasil" <?php echo (isset($_SESSION['temp_reg']['country']) && $_SESSION['temp_reg']['country'] == 'Brasil') ? 'selected' : ''; ?>>Brasil</option>
                            <option value="Portugal" <?php echo (isset($_SESSION['temp_reg']['country']) && $_SESSION['temp_reg']['country'] == 'Portugal') ? 'selected' : ''; ?>>Portugal</option>
                            <option value="Moçambique" <?php echo (isset($_SESSION['temp_reg']['country']) && $_SESSION['temp_reg']['country'] == 'Moçambique') ? 'selected' : ''; ?>>Moçambique</option>
                            <option value="Cabo Verde" <?php echo (isset($_SESSION['temp_reg']['country']) && $_SESSION['temp_reg']['country'] == 'Cabo Verde') ? 'selected' : ''; ?>>Cabo Verde</option>
                        </select>
                        <span class="material-symbols-outlined absolute right-3 top-1/2 -translate-y-1/2 text-gray-400">expand_more</span>
                    </div>
                </div>
                
                <div class="flex gap-3">
                    <button type="button" onclick="window.location.href='register.php?step=1'" class="px-6 py-3 bg-gray-100 text-gray-700 rounded-xl font-semibold hover:bg-gray-200 transition flex items-center gap-2">
                        <span class="material-symbols-outlined text-sm">arrow_back</span> Voltar
                    </button>
                    <button type="submit" name="register_step2" class="flex-1 bg-gradient-to-r from-blue-600 to-purple-600 text-white py-3 rounded-xl font-semibold hover:opacity-90 transition flex items-center justify-center gap-2">
                        Continuar <span class="material-symbols-outlined text-sm">arrow_forward</span>
                    </button>
                </div>
            </form>
            
            <?php elseif($step == 3): ?>
            <!-- Step 3 -->
            <div class="flex justify-between mb-8">
                <div class="text-center flex-1"><div class="w-8 h-8 mx-auto rounded-full bg-green-500 text-white flex items-center justify-center text-xs"><span class="material-symbols-outlined text-sm">check</span></div><span class="text-xs text-gray-500 mt-1 block">Básico</span></div>
                <div class="text-center flex-1"><div class="w-8 h-8 mx-auto rounded-full bg-green-500 text-white flex items-center justify-center text-xs"><span class="material-symbols-outlined text-sm">check</span></div><span class="text-xs text-gray-500 mt-1 block">Perfil</span></div>
                <div class="text-center flex-1"><div class="w-8 h-8 mx-auto rounded-full bg-blue-600 text-white flex items-center justify-center text-sm font-bold">3</div><span class="text-xs text-gray-500 mt-1 block">Segurança</span></div>
            </div>
            
            <?php if($error_message): ?>
            <div class="mb-4 p-3 rounded-xl bg-red-50 text-red-600 text-sm flex items-center gap-2">
                <span class="material-symbols-outlined text-lg">error</span> <?php echo $error_message; ?>
            </div>
            <?php endif; ?>
            
            <form method="POST" enctype="multipart/form-data">
                <div class="text-center mb-6">
                    <div class="relative inline-block">
                        <img id="photoPreview" class="w-28 h-28 rounded-full object-cover border-4 border-blue-500 shadow-lg mx-auto" src="https://ui-avatars.com/api/?background=3b82f6&color=fff&size=120&name=<?php echo urlencode(substr($_SESSION['temp_reg']['username'], 0, 2)); ?>">
                        <button type="button" onclick="document.getElementById('profile_photo').click()" class="absolute bottom-0 right-0 bg-blue-600 text-white p-1.5 rounded-full shadow-md hover:bg-blue-700 transition">
                            <span class="material-symbols-outlined text-sm">camera_alt</span>
                        </button>
                    </div>
                    <input type="file" id="profile_photo" name="profile_photo" accept="image/*" style="display:none;" onchange="previewPhoto(this)">
                    <p class="text-xs text-gray-400 mt-2">Clique na câmera para adicionar foto</p>
                </div>
                
                <div class="mb-4">
                    <div class="relative">
                        <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-lg">lock</span>
                        <input type="password" name="password" id="password" placeholder="Palavra-passe" required class="w-full pl-10 pr-4 py-3 border border-gray-200 rounded-xl focus:ring-2 focus:ring-blue-500 outline-none transition">
                    </div>
                </div>
                
                <div class="mb-6">
                    <div class="relative">
                        <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-lg">lock</span>
                        <input type="password" name="confirm_password" id="confirm_password" placeholder="Confirmar palavra-passe" required class="w-full pl-10 pr-4 py-3 border border-gray-200 rounded-xl focus:ring-2 focus:ring-blue-500 outline-none transition">
                    </div>
                    <p class="text-xs text-gray-400 mt-1">Mínimo 6 caracteres</p>
                </div>
                
                <div class="mb-6 p-3 rounded-xl bg-blue-50 text-blue-700 text-xs flex items-center gap-2">
                    <span class="material-symbols-outlined text-sm">shield</span>
                    Suas chaves criptográficas RSA (2048 bits) serão geradas automaticamente!
                </div>
                
                <div class="flex gap-3">
                    <button type="button" onclick="window.location.href='register.php?step=2'" class="px-6 py-3 bg-gray-100 text-gray-700 rounded-xl font-semibold hover:bg-gray-200 transition flex items-center gap-2">
                        <span class="material-symbols-outlined text-sm">arrow_back</span> Voltar
                    </button>
                    <button type="submit" name="register_step3" class="flex-1 bg-gradient-to-r from-blue-600 to-purple-600 text-white py-3 rounded-xl font-semibold hover:opacity-90 transition flex items-center justify-center gap-2">
                        Criar conta <span class="material-symbols-outlined text-sm">check_circle</span>
                    </button>
                </div>
            </form>
            
            <?php elseif($step == 'success'): ?>
            <!-- Success -->
            <div class="text-center">
                <div class="w-20 h-20 bg-green-500 rounded-full flex items-center justify-center mx-auto mb-4">
                    <span class="material-symbols-outlined text-3xl text-white">check</span>
                </div>
                <h3 class="text-xl font-bold text-gray-800 mb-2">Conta criada com sucesso!</h3>
                <p class="text-gray-500 text-sm mb-6">Bem-vindo ao Cerulean Chat.<br>Sua comunicação agora é segura e criptografada.</p>
                <button onclick="window.location.href='login.php'" class="w-full bg-gradient-to-r from-blue-600 to-purple-600 text-white py-3 rounded-xl font-semibold hover:opacity-90 transition flex items-center justify-center gap-2">
                    <span class="material-symbols-outlined text-sm">login</span> Fazer login
                </button>
                <div class="mt-4 p-3 rounded-xl bg-green-50 text-green-700 text-xs flex items-center gap-2">
                    <span class="material-symbols-outlined text-sm">key</span>
                    Chaves RSA e Diffie-Hellman geradas com segurança!
                </div>
            </div>
            
            <?php endif; ?>
            
        </div>
    </div>
    
    <!-- Footer -->
    <div class="text-center mt-4">
        <p class="text-xs text-white/70">© 2026 MUNGUAMBE • NHANCALE • USSENE</p>
    </div>
</div>

<script>
function previewPhoto(input) {
    if (input.files && input.files[0]) {
        if (input.files[0].size > 5 * 1024 * 1024) {
            alert('Foto muito grande! Máximo 5MB');
            input.value = '';
            return;
        }
        const reader = new FileReader();
        reader.onload = function(e) {
            document.getElementById('photoPreview').src = e.target.result;
        }
        reader.readAsDataURL(input.files[0]);
    }
}

// Validação de senha
const password = document.getElementById('password');
const confirm = document.getElementById('confirm_password');

function validatePassword() {
    if (password && confirm && confirm.value.length > 0) {
        if (password.value !== confirm.value) {
            confirm.style.borderColor = '#ef4444';
        } else if (password.value.length < 6) {
            confirm.style.borderColor = '#f59e0b';
        } else {
            confirm.style.borderColor = '#10b981';
        }
    }
}

password?.addEventListener('input', validatePassword);
confirm?.addEventListener('input', validatePassword);
</script>

</body>
</html>