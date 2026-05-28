<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header("location: ../auth/login.php");
    exit();
}

include_once "../../conf/db.php";
include_once "../logic_pack/group_logic.php";

$user_id = $_SESSION['user_id'];

// Buscar todos os usuários exceto o atual
$stmt = $conn->prepare("SELECT id, name, user_number, profile_photo, profile_photo_type FROM users WHERE id != ? ORDER BY name ASC");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$users = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$error_msg = isset($_SESSION['error']) ? $_SESSION['error'] : null;
$success_msg = isset($_SESSION['success']) ? $_SESSION['success'] : null;
unset($_SESSION['error']);
unset($_SESSION['success']);
?>

<!DOCTYPE html>
<html lang="pt">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Criar Grupo • Cerulean Chat</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
*{margin:0;padding:0;box-sizing:border-box;}
body{background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);font-family:'Inter',sans-serif;min-height:100vh;padding:20px;}
.container-custom{max-width:800px;margin:0 auto;}
.card{background:white;border-radius:24px;box-shadow:0 20px 60px rgba(0,0,0,0.3);overflow:hidden;animation:fadeInUp 0.5s ease;}
@keyframes fadeInUp{from{opacity:0;transform:translateY(30px);}to{opacity:1;transform:translateY(0);}}
.card-header{background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);padding:30px;text-align:center;color:white;}
.card-header .logo{width:60px;height:60px;background:rgba(255,255,255,0.2);border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 15px;font-size:28px;}
.card-header h2{font-size:24px;font-weight:600;margin-bottom:8px;}
.card-header p{opacity:0.9;font-size:14px;}
.card-body{padding:30px;}
.form-group{margin-bottom:20px;}
.form-group label{font-weight:500;margin-bottom:8px;display:block;color:#333;}
.form-control{width:100%;padding:12px 15px;border:2px solid #e5e7eb;border-radius:16px;font-size:14px;transition:all 0.3s;}
.form-control:focus{outline:none;border-color:#667eea;box-shadow:0 0 0 3px rgba(102,126,234,0.1);}
.members-list{max-height:300px;overflow-y:auto;border:2px solid #e5e7eb;border-radius:16px;padding:10px;}
.member-item{display:flex;align-items:center;padding:10px;margin-bottom:8px;border-radius:12px;cursor:pointer;transition:background 0.2s;}
.member-item:hover{background:#f3f4f6;}
.member-item.selected{background:#e8f0fe;border-left:3px solid #667eea;}
.member-avatar{width:40px;height:40px;border-radius:50%;background:#e5e7eb;display:flex;align-items:center;justify-content:center;margin-right:12px;font-weight:bold;overflow:hidden;}
.member-avatar img{width:100%;height:100%;object-fit:cover;}
.member-info{flex:1;}
.member-name{font-weight:500;font-size:14px;}
.member-number{font-size:12px;color:#6b7280;}
.member-check{color:#667eea;font-size:18px;}
.btn-custom{padding:14px;border:none;border-radius:16px;font-size:16px;font-weight:600;cursor:pointer;transition:all 0.3s;width:100%;}
.btn-primary{background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);color:white;}
.btn-primary:hover{transform:translateY(-2px);box-shadow:0 10px 25px rgba(102,126,234,0.3);}
.btn-secondary{background:#f3f4f6;color:#374151;margin-top:10px;}
.btn-secondary:hover{background:#e5e7eb;}
.selected-counter{background:#667eea;color:white;border-radius:20px;padding:5px 12px;font-size:12px;font-weight:500;}
.alert-custom{padding:12px 15px;border-radius:16px;margin-bottom:20px;display:flex;align-items:center;gap:10px;}
.alert-error{background:#fee2e2;color:#dc2626;border:1px solid #fecaca;}
.alert-success{background:#d1fae5;color:#065f46;border:1px solid #a7f3d0;}
.group-photo-preview{width:100px;height:100px;border-radius:50%;object-fit:cover;margin:0 auto 15px;border:3px solid #667eea;box-shadow:0 5px 20px rgba(0,0,0,0.1);background:#f5f5f5;display:block;}
.photo-upload{text-align:center;margin-bottom:20px;}
.photo-label{display:inline-block;padding:8px 20px;background:#f0f0f0;border-radius:20px;cursor:pointer;font-size:13px;transition:all 0.3s ease;margin-top:10px;}
.photo-label:hover{background:#e0e0e0;}
.photo-label i{margin-right:5px;}
</style>
</head>
<body>

<div class="container-custom">
    <div class="card">
        <div class="card-header">
            <div class="logo"><i class="fas fa-users"></i></div>
            <h2>Criar novo grupo</h2>
            <p>Adicione os participantes e comece a conversar</p>
        </div>
        
        <div class="card-body">
            <?php if($error_msg): ?>
            <div class="alert-custom alert-error"><i class="fas fa-exclamation-circle"></i> <?php echo $error_msg; ?></div>
            <?php endif; ?>
            
            <?php if($success_msg): ?>
            <div class="alert-custom alert-success"><i class="fas fa-check-circle"></i> <?php echo $success_msg; ?></div>
            <?php endif; ?>
            
            <form id="createGroupForm" method="POST" action="../logic_pack/group_logic.php" enctype="multipart/form-data">
                <input type="hidden" name="action" value="create_group">
                <input type="hidden" name="members" id="selectedMembers" value="">
                
                <!-- Foto do Grupo -->
                <div class="photo-upload">
                    <img id="groupPhotoPreview" class="group-photo-preview" src="https://ui-avatars.com/api/?background=667eea&color=fff&size=120&name=G">
                    <label class="photo-label" onclick="document.getElementById('group_photo').click()">
                        <i class="fas fa-camera"></i> Adicionar foto do grupo
                    </label>
                    <input type="file" id="group_photo" name="group_photo" accept="image/*" style="display:none;" onchange="previewGroupPhoto(this)">
                    <small class="text-muted d-block mt-2" style="font-size:11px; color:#888;">PNG, JPG até 5MB</small>
                </div>
                
                <div class="form-group">
                    <label><i class="fas fa-tag"></i> Nome do grupo</label>
                    <input type="text" class="form-control" name="group_name" placeholder="Ex: Amigos, Família, Trabalho..." required>
                </div>
                
                <div class="form-group">
                    <label><i class="fas fa-align-left"></i> Descrição (opcional)</label>
                    <textarea class="form-control" name="description" rows="2" placeholder="Descreva o propósito do grupo..."></textarea>
                </div>
                
                <div class="form-group">
                    <label><i class="fas fa-search"></i> Pesquisar membros</label>
                    <input type="text" id="searchMember" class="form-control" placeholder="Digite o nome ou número...">
                </div>
                
                <div class="form-group">
                    <label><i class="fas fa-user-plus"></i> Selecionar membros</label>
                    <div class="members-list" id="membersList">
                        <?php foreach($users as $user): ?>
                        <div class="member-item" data-id="<?php echo $user['id']; ?>" data-name="<?php echo strtolower(htmlspecialchars($user['name'])); ?>" data-number="<?php echo htmlspecialchars($user['user_number']); ?>" onclick="toggleMember(this)">
                            <div class="member-avatar">
                                <?php if(!empty($user['profile_photo'])): ?>
                                <img src="data:<?php echo $user['profile_photo_type']; ?>;base64,<?php echo $user['profile_photo']; ?>">
                                <?php else: ?>
                                <span><?php echo strtoupper(substr($user['name'], 0, 2)); ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="member-info">
                                <div class="member-name"><?php echo htmlspecialchars($user['name']); ?></div>
                                <div class="member-number"><?php echo htmlspecialchars($user['user_number']); ?></div>
                            </div>
                            <div class="member-check"><i class="far fa-circle"></i></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                
                <div class="text-center mb-3">
                    <span class="selected-counter" id="selectedCounter">0 membros selecionados</span>
                </div>
                
                <button type="submit" class="btn-custom btn-primary">
                    <i class="fas fa-rocket"></i> Criar grupo
                </button>
                
                <button type="button" class="btn-custom btn-secondary" onclick="window.location.href='dashboard.php'">
                    <i class="fas fa-arrow-left"></i> Cancelar
                </button>
            </form>
        </div>
    </div>
</div>

<script>
let selectedMembers = [];

// Preview da foto do grupo
function previewGroupPhoto(input) {
    if (input.files && input.files[0]) {
        if (input.files[0].size > 5 * 1024 * 1024) {
            alert('Foto muito grande! Máximo 5MB');
            input.value = '';
            return;
        }
        const reader = new FileReader();
        reader.onload = function(e) {
            document.getElementById('groupPhotoPreview').src = e.target.result;
        }
        reader.readAsDataURL(input.files[0]);
    }
}

function toggleMember(element) {
    const userId = element.dataset.id;
    const index = selectedMembers.indexOf(userId);
    const checkIcon = element.querySelector('.member-check i');
    
    if (index === -1) {
        selectedMembers.push(userId);
        element.classList.add('selected');
        checkIcon.classList.remove('fa-circle');
        checkIcon.classList.add('fa-check-circle');
    } else {
        selectedMembers.splice(index, 1);
        element.classList.remove('selected');
        checkIcon.classList.remove('fa-check-circle');
        checkIcon.classList.add('fa-circle');
    }
    
    document.getElementById('selectedCounter').innerText = selectedMembers.length + ' membros selecionados';
    document.getElementById('selectedMembers').value = JSON.stringify(selectedMembers);
}

// Pesquisar membros
document.getElementById('searchMember')?.addEventListener('input', function(e) {
    const search = e.target.value.toLowerCase();
    const items = document.querySelectorAll('.member-item');
    
    items.forEach(item => {
        const name = item.getAttribute('data-name');
        const number = item.getAttribute('data-number').toLowerCase();
        
        if (name.includes(search) || number.includes(search)) {
            item.style.display = 'flex';
        } else {
            item.style.display = 'none';
        }
    });
});

// Validar antes de enviar
document.getElementById('createGroupForm').addEventListener('submit', function(e) {
    if (selectedMembers.length === 0) {
        e.preventDefault();
        alert('Selecione pelo menos um membro para o grupo');
    }
});
</script>
</body>
</html>