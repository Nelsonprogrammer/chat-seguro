<?php
//session_start();
include_once "../../conf/db.php";
include_once "../logic_pack/group_logic.php";
if (!isset($_SESSION['user_id'])) {
    header("location: ../auth/login.php");
    exit();
}



$user_id = $_SESSION['user_id'];
$group_id = isset($_GET['group_id']) ? (int)$_GET['group_id'] : 0;

if (!$group_id) {
    header("location: dashboard.php");
    exit();
}

$group = getGroupDetails($group_id);
$members = getGroupMembers($group_id);
$messages = getGroupMessages($group_id, $user_id);
$available_users = getUsersNotInGroup($group_id, $user_id);

// Verificar se usuário é membro
$is_member = false;
$is_admin = false;
foreach ($members as $member) {
    if ($member['user_id'] == $user_id) {
        $is_member = true;
        $is_admin = ($member['role'] == 'admin');
        break;
    }
}

if (!$is_member) {
    header("location: dashboard.php");
    exit();
}

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
<title><?php echo htmlspecialchars($group['name']); ?> • SecureChat</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
*{margin:0;padding:0;box-sizing:border-box;}
body{background:#e5ddd5;font-family:'Inter',sans-serif;overflow:hidden;}
.container{display:flex;height:100vh;max-width:1400px;margin:0 auto;}
.sidebar{width:320px;background:#fff;border-right:1px solid #e0e0e0;display:flex;flex-direction:column;}
.sidebar-header{background:#075e54;color:#fff;padding:20px;text-align:center;}
.sidebar-header h4{margin:0;font-size:18px;}
.members-list{flex:1;overflow-y:auto;padding:10px;}
.member-item{display:flex;align-items:center;padding:10px;border-radius:12px;margin-bottom:5px;}
.member-avatar{width:40px;height:40px;border-radius:50%;background:#25d366;display:flex;align-items:center;justify-content:center;margin-right:12px;color:#fff;font-weight:bold;}
.member-info{flex:1;}
.member-name{font-weight:500;font-size:14px;}
.member-role{font-size:11px;color:#888;}
.online-dot{width:10px;height:10px;border-radius:50%;background:#25d366;margin-left:10px;}
.chat{flex:1;display:flex;flex-direction:column;}
.chat-header{background:#075e54;color:#fff;padding:15px 20px;display:flex;align-items:center;justify-content:space-between;}
.chat-header-info{display:flex;align-items:center;gap:12px;cursor:pointer;}
.group-avatar{width:45px;height:45px;border-radius:50%;background:#128c7e;display:flex;align-items:center;justify-content:center;font-size:20px;}
.messages-container{flex:1;overflow-y:auto;padding:20px;background:#efeae2;}
.message{margin-bottom:12px;display:flex;}
.message.sent{justify-content:flex-end;}
.message.received{justify-content:flex-start;}
.bubble{max-width:65%;padding:10px 12px;border-radius:12px;}
.sent .bubble{background:#dcf8c5;}
.received .bubble{background:#fff;}
.message-text{font-size:14px;}
.message-time{font-size:10px;color:#888;text-align:right;margin-top:4px;}
.input-area{background:#f0f0f0;padding:10px;display:flex;gap:10px;border-top:1px solid #ddd;}
.input-area input{flex:1;border:none;border-radius:20px;padding:12px;outline:none;}
.input-area button{background:#075e54;color:#fff;border:none;width:44px;height:44px;border-radius:50%;cursor:pointer;}
.modal-content{border-radius:20px;}
.btn-add{background:#075e54;color:white;border:none;padding:8px 16px;border-radius:20px;font-size:12px;}
</style>
</head>
<body>

<div class="container">
    <!-- Sidebar com membros -->
    <div class="sidebar">
        <div class="sidebar-header">
            <h4><i class="fas fa-users"></i> Membros</h4>
            <small><?php echo count($members); ?> participantes</small>
        </div>
        <div class="members-list">
            <?php foreach($members as $member): ?>
            <div class="member-item">
                <div class="member-avatar">
                    <?php if(!empty($member['profile_photo'])): ?>
                    <img src="data:<?php echo $member['profile_photo_type']; ?>;base64,<?php echo $member['profile_photo']; ?>" style="width:40px;height:40px;border-radius:50%;object-fit:cover;">
                    <?php else: ?>
                    <span><?php echo strtoupper(substr($member['name'], 0, 2)); ?></span>
                    <?php endif; ?>
                </div>
                <div class="member-info">
                    <div class="member-name">
                        <?php echo htmlspecialchars($member['name']); ?>
                        <?php if($member['role'] == 'admin'): ?>
                        <i class="fas fa-crown" style="color:#ffd700; font-size:12px;"></i>
                        <?php endif; ?>
                    </div>
                    <div class="member-role"><?php echo $member['role']; ?></div>
                </div>
                <?php if($member['is_online']): ?>
                <div class="online-dot"></div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Chat -->
    <div class="chat">
        <div class="chat-header">
            <div class="chat-header-info" onclick="showGroupInfo()">
                <div class="group-avatar">
                    <i class="fas fa-users"></i>
                </div>
                <div>
                    <h5 style="margin:0"><?php echo htmlspecialchars($group['name']); ?></h5>
                    <small><?php echo count($members); ?> membros</small>
                </div>
            </div>
            <div>
                <?php if($is_admin): ?>
                <button class="btn-add" onclick="showAddMemberModal()">
                    <i class="fas fa-user-plus"></i> Adicionar
                </button>
                <?php endif; ?>
                <i class="fas fa-arrow-left" style="margin-left:15px;cursor:pointer;" onclick="window.location.href='dashboard.php'"></i>
            </div>
        </div>

        <div class="messages-container" id="messagesContainer">
            <?php foreach($messages as $msg): ?>
            <div class="message <?php echo ($msg['from_user'] == $user_id) ? 'sent' : 'received'; ?>">
                <div class="bubble">
                    <?php if($msg['from_user'] != $user_id): ?>
                    <small><strong><?php echo htmlspecialchars($msg['sender_name']); ?></strong></small><br>
                    <?php endif; ?>
                    <div class="message-text"><?php echo nl2br(htmlspecialchars($msg['decrypted_message'])); ?></div>
                    <div class="message-time">
                        <?php echo date('H:i', strtotime($msg['created_at'])); ?>
                        <?php if($msg['signature_valid']): ?>
                        <i class="fas fa-lock" style="color:#25d366;"></i>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="input-area">
            <input type="hidden" id="groupId" value="<?php echo $group_id; ?>">
            <input type="text" id="messageInput" placeholder="Digite a mensagem..." autocomplete="off">
            <button onclick="sendGroupMessage()">
                <i class="fas fa-paper-plane"></i>
            </button>
        </div>
    </div>
</div>

<!-- Modal Adicionar Membro -->
<div class="modal fade" id="addMemberModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="fas fa-user-plus"></i> Adicionar membro</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <select id="newMemberSelect" class="form-select">
                    <option value="">Selecione um usuário</option>
                    <?php foreach($available_users as $user): ?>
                    <option value="<?php echo $user['id']; ?>">
                        <?php echo htmlspecialchars($user['name']) . ' (' . htmlspecialchars($user['user_number']) . ')'; ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-success" onclick="addMember()">Adicionar</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
const groupId = document.getElementById('groupId').value;

function scrollToBottom() {
    const container = document.getElementById('messagesContainer');
    if (container) container.scrollTop = container.scrollHeight;
}

function sendGroupMessage() {
    const messageInput = document.getElementById('messageInput');
    const message = messageInput.value.trim();
    
    if (!message) return;
    
    const formData = new FormData();
    formData.append('action', 'send_group_message');
    formData.append('group_id', groupId);
    formData.append('message', message);
    
    fetch('../logic_pack/group_logic.php', {
        method: 'POST',
        body: formData
    })
    .then(() => {
        messageInput.value = '';
        location.reload();
    })
    .catch(error => console.error('Erro:', error));
}

function showGroupInfo() {
    alert('Grupo: <?php echo addslashes($group['name']); ?>\nDescrição: <?php echo addslashes($group['description'] ?? 'Sem descrição'); ?>\nCriado por: <?php echo addslashes($group['creator_name']); ?>');
}

function showAddMemberModal() {
    new bootstrap.Modal(document.getElementById('addMemberModal')).show();
}

function addMember() {
    const userId = document.getElementById('newMemberSelect').value;
    if (!userId) {
        alert('Selecione um usuário');
        return;
    }
    
    const formData = new FormData();
    formData.append('action', 'add_group_member');
    formData.append('group_id', groupId);
    formData.append('new_member_id', userId);
    
    fetch('../logic_pack/group_logic.php', {
        method: 'POST',
        body: formData
    })
    .then(() => location.reload())
    .catch(error => console.error('Erro:', error));
}

document.getElementById('messageInput')?.addEventListener('keypress', function(e) {
    if (e.key === 'Enter') sendGroupMessage();
});

scrollToBottom();

// Auto-refresh a cada 5 segundos
setInterval(() => {
    fetch(`group_chat.php?group_id=${groupId}&ajax=1`)
        .then(response => response.text())
        .then(html => {
            const parser = new DOMParser();
            const doc = parser.parseFromString(html, 'text/html');
            const newMessages = doc.getElementById('messagesContainer')?.innerHTML;
            if (newMessages && document.getElementById('messagesContainer')?.innerHTML !== newMessages) {
                document.getElementById('messagesContainer').innerHTML = newMessages;
                scrollToBottom();
            }
        })
        .catch(error => console.error('Auto-refresh error:', error));
}, 5000);
</script>
</body>
</html>