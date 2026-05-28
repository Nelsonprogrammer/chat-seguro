<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header("location: ../auth/login.php");
    exit();
}

include_once "../../conf/db.php";
include_once "../logic_pack/group_logic.php";

$user_id = $_SESSION['user_id'];
$groups = getUserGroups($user_id);
?>

<!DOCTYPE html>
<html lang="pt">
<head>
<meta charset="UTF-8">
<title>Meus Grupos • SecureChat</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
body{background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);font-family:'Inter',sans-serif;min-height:100vh;padding:20px;}
.container-custom{max-width:600px;margin:0 auto;}
.card{background:white;border-radius:24px;box-shadow:0 20px 60px rgba(0,0,0,0.3);overflow:hidden;}
.card-header{background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);padding:30px;text-align:center;color:white;}
.group-item{display:flex;align-items:center;padding:15px;border-bottom:1px solid #e5e7eb;cursor:pointer;transition:background 0.2s;}
.group-item:hover{background:#f9fafb;}
.group-avatar{width:50px;height:50px;border-radius:50%;background:#25d366;display:flex;align-items:center;justify-content:center;margin-right:15px;color:white;font-size:20px;}
.group-info{flex:1;}
.group-name{font-weight:600;margin-bottom:4px;}
.group-details{font-size:12px;color:#6b7280;}
.btn-create{background:white;color:#667eea;border:none;padding:12px 24px;border-radius:30px;font-weight:600;margin-top:20px;}
</style>
</head>
<body>

<div class="container-custom">
    <div class="card">
        <div class="card-header">
            <i class="fas fa-users" style="font-size:48px;margin-bottom:15px;"></i>
            <h2>Meus Grupos</h2>
            <p>Conversas em grupo com criptografia ponta-a-ponta</p>
        </div>
        <div class="p-3">
            <?php if(empty($groups)): ?>
            <div class="text-center p-5">
                <i class="fas fa-users-slash" style="font-size:64px;color:#ccc;margin-bottom:20px;"></i>
                <p>Você não participa de nenhum grupo ainda</p>
                <button class="btn-create" onclick="location.href='create_group.php'">
                    <i class="fas fa-plus"></i> Criar primeiro grupo
                </button>
            </div>
            <?php else: ?>
                <?php foreach($groups as $group): ?>
                <div class="group-item" onclick="location.href='group_chat.php?group_id=<?php echo $group['id']; ?>'">
                    <div class="group-avatar">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="group-info">
                        <div class="group-name"><?php echo htmlspecialchars($group['name']); ?></div>
                        <div class="group-details">
                            <?php echo $group['member_count']; ?> membros • <?php echo $group['message_count']; ?> mensagens
                        </div>
                    </div>
                    <i class="fas fa-chevron-right" style="color:#ccc;"></i>
                </div>
                <?php endforeach; ?>
                <div class="text-center p-3">
                    <button class="btn-create" onclick="location.href='create_group.php'">
                        <i class="fas fa-plus"></i> Novo grupo
                    </button>
                    <button class="btn-create" onclick="location.href='dashboard.php'" style="background:#f3f4f6;color:#374151;margin-left:10px;">
                        <i class="fas fa-arrow-left"></i> Voltar
                    </button>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

</body>
</html>