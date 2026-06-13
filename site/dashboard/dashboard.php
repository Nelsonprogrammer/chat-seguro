<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    header("location: ../auth/login.php");
    exit();
}

include_once "../../conf/db.php";
include_once "../logic_pack/logic.php";

$user_id = $_SESSION["user_id"];

// User logado
$stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user_logado = $stmt->get_result()->fetch_assoc();

// Contactos (apenas com quem já conversou)
$users = getUsersWithConversation($user_id);
$groups = getUserGroups($user_id);
$new_users = getNewUsers($user_id);

// Contacto atual
$current_contact = null;
$current_contact_id = isset($_GET['contact']) ? (int)$_GET['contact'] : null;
if ($current_contact_id) {
    $stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->bind_param("i", $current_contact_id);
    $stmt->execute();
    $current_contact = $stmt->get_result()->fetch_assoc();
}

// Grupo atual
$current_group = null;
$group_messages = [];
$group_members = [];
$current_group_id = isset($_GET['group']) ? (int)$_GET['group'] : null;
if ($current_group_id) {
    $stmt = $conn->prepare("SELECT * FROM group_members WHERE group_id = ? AND user_id = ?");
    $stmt->bind_param("ii", $current_group_id, $user_id);
    $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) {
        $stmt = $conn->prepare("SELECT * FROM groups WHERE id = ?");
        $stmt->bind_param("i", $current_group_id);
        $stmt->execute();
        $current_group = $stmt->get_result()->fetch_assoc();
        
        $group_messages = getGroupMessages($current_group_id, $user_id);
        
        $stmt = $conn->prepare("
            SELECT gm.*, u.name, u.user_number, u.profile_photo, u.profile_photo_type, u.is_online, u.bio, u.email, u.phone, u.city, u.country, u.created_at
            FROM group_members gm
            JOIN users u ON u.id = gm.user_id
            WHERE gm.group_id = ?
            ORDER BY gm.role DESC, u.name ASC
        ");
        $stmt->bind_param("i", $current_group_id);
        $stmt->execute();
        $group_members = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }
}

// Buscar ficheiros partilhados
$shared_files = [];
if ($current_contact_id) {
    $stmt = $conn->prepare("
        SELECT * FROM shared_files 
        WHERE (sender_id = ? AND receiver_id = ?)
        OR (sender_id = ? AND receiver_id = ?)
        ORDER BY uploaded_at ASC
    ");
    $stmt->bind_param("iiii", $user_id, $current_contact_id, $current_contact_id, $user_id);
    $stmt->execute();
    $shared_files = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

$group_files = [];
if ($current_group_id) {
    $stmt = $conn->prepare("SELECT * FROM shared_files WHERE group_id = ? ORDER BY uploaded_at ASC");
    if ($stmt) {
        $stmt->bind_param("i", $current_group_id);
        $stmt->execute();
        $group_files = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }
}

$success_msg = isset($_SESSION['success']) ? $_SESSION['success'] : null;
$error_msg = isset($_SESSION['error']) ? $_SESSION['error'] : null;
unset($_SESSION['success']);
unset($_SESSION['error']);

$show_groups = isset($_GET['groups']) || isset($_GET['group']);
$is_in_chat = ($current_contact || $current_group);
?>
<!DOCTYPE html>
<html lang="pt">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover, user-scalable=no">
<title>Cerulean Chat</title>
<script src="https://cdn.tailwindcss.com"></script>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<style>
    * { -webkit-tap-highlight-color: transparent; margin: 0; padding: 0; box-sizing: border-box; }
    .material-symbols-outlined { font-variation-settings: 'FILL' 0, 'wght' 400, 'GRAD' 0, 'opsz' 24; }
    .message-bubble-shadow { box-shadow: 0 1px 2px rgba(0,0,0,0.05); }
    .emoji-picker { font-family: 'Segoe UI Emoji', 'Apple Color Emoji', 'Noto Color Emoji', sans-serif; }
    
    @media (max-width: 768px) {
        .desktop-only { display: none !important; }
        .mobile-only { display: block; }
        .mobile-flex { display: flex; }
        .bubble-max { max-width: 85% !important; }
    }
    @media (min-width: 769px) {
        .desktop-only { display: block; }
        .mobile-only, .mobile-flex, .mobile-list-area, .mobile-chat-area { display: none !important; }
        .sidebar-desktop, .right-sidebar { display: flex !important; }
    }
    .sidebar-desktop, .right-sidebar { display: none; }
    
    .message-text { word-break: break-word; white-space: pre-wrap; }
    .new-chat-btn { transition: all 0.3s ease; }
    .new-chat-btn:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3); }
    
    .group-photo-preview {
        width: 80px;
        height: 80px;
        border-radius: 50%;
        object-fit: cover;
        margin: 0 auto;
        border: 3px solid #667eea;
        background: #f5f5f5;
    }
    
    .member-list-item {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 10px;
        border-radius: 12px;
        cursor: pointer;
        transition: all 0.2s;
    }
    .member-list-item:hover { background: #f3f4f6; }
    .member-list-item.selected { background: #e8f0fe; border-left: 3px solid #3b82f6; }
    .member-avatar-small {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        background: linear-gradient(135deg, #3b82f6, #8b5cf6);
        display: flex;
        align-items: center;
        justify-content: center;
        overflow: hidden;
        flex-shrink: 0;
    }
    .member-avatar-small img { width: 100%; height: 100%; object-fit: cover; }
    .member-avatar-small span { color: white; font-weight: bold; font-size: 14px; }
    .online-indicator-mobile {
        position: absolute;
        bottom: 0;
        right: 0;
        width: 12px;
        height: 12px;
        background-color: #22c55e;
        border-radius: 50%;
        border: 2px solid white;
    }
    .file-preview-img {
        max-width: 200px;
        max-height: 150px;
        border-radius: 8px;
        margin-top: 8px;
        cursor: pointer;
    }
    .file-preview-video {
        max-width: 200px;
        max-height: 150px;
        border-radius: 8px;
        margin-top: 8px;
    }
    .file-attachment {
        background: rgba(0,0,0,0.05);
        border-radius: 8px;
        padding: 8px;
        margin-top: 5px;
    }
    @keyframes spin {
        from { transform: rotate(0deg); }
        to { transform: rotate(360deg); }
    }
    .animate-spin {
        animation: spin 1s linear infinite;
    }
    
    /* Estilos para loading */
    .message-loading {
        opacity: 0.7;
    }
    .sending-spinner {
        display: inline-block;
        width: 12px;
        height: 12px;
        border: 2px solid rgba(255,255,255,0.3);
        border-radius: 50%;
        border-top-color: white;
        animation: spin 0.8s linear infinite;
        margin-left: 8px;
    }
</style>
</head>
<body class="bg-[#f8f9ff] font-['Inter'] text-[#0b1c30]">

<!-- ==================== MOBILE - LISTA DE CONVERSAS ==================== -->
<div id="mobileListArea" class="mobile-flex flex-col h-screen bg-white" style="<?php echo $is_in_chat ? 'display: none;' : 'display: flex;'; ?>">
    <div class="bg-gradient-to-r from-blue-600 to-purple-600 text-white px-4 py-4 shadow-lg">
        <div class="flex justify-between items-center">
            <div><h1 class="text-xl font-bold">Cerulean Chat</h1><p class="text-xs opacity-80">Criptografado</p></div>
            <div class="flex gap-4">
                <span class="material-symbols-outlined cursor-pointer" onclick="openProfileModal()">person</span>
                <span class="material-symbols-outlined cursor-pointer" onclick="logout()">logout</span>
            </div>
        </div>
    </div>
    
    <div class="p-3">
        <button onclick="openNewChatModal()" class="w-full bg-blue-600 text-white py-3 rounded-xl font-semibold flex items-center justify-center gap-2 shadow-md new-chat-btn">
            <span class="material-symbols-outlined">chat</span> Novo Chat
        </button>
    </div>
    
    <div class="p-3 bg-white border-b"><div class="relative"><span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-slate-400">search</span><input type="text" id="mobileSearch" placeholder="Pesquisar conversas..." class="w-full bg-[#eff4ff] rounded-xl py-2 pl-10 pr-4 text-sm outline-none"></div></div>
    
    <div class="flex border-b bg-white">
        <button id="mobileTabContacts" class="flex-1 py-3 text-center font-medium text-blue-600 border-b-2 border-blue-600" onclick="mobileSwitchTab('contacts')">Contactos</button>
        <button id="mobileTabGroups" class="flex-1 py-3 text-center font-medium text-slate-500" onclick="mobileSwitchTab('groups')">Grupos</button>
    </div>
    
    <div id="mobileContactsDiv" class="flex-1 overflow-y-auto px-2 py-2 space-y-1">
        <?php foreach($users as $u): $last_msg = get_last_message($user_id, $u['id']); $unread = get_unread_count($u['id']); 
            $preview_text = $last_msg ? ($last_msg['from_user'] == $user_id ? 'Você: ' : '') . htmlspecialchars(substr($last_msg['message'], 0, 35)) : 'Nenhuma mensagem';
        ?>
        <div class="flex items-center gap-3 p-3 rounded-xl active:bg-slate-100 cursor-pointer" data-user-id="<?php echo $u['id']; ?>" onclick="openMobileChat('contact', <?php echo $u['id']; ?>)">
            <div class="relative">
                <div class="w-12 h-12 rounded-full bg-gradient-to-br from-blue-500 to-blue-400 flex items-center justify-center text-white font-bold text-lg shadow-sm overflow-hidden">
                    <?php if(!empty($u['profile_photo'])): ?>
                    <img src="data:<?php echo $u['profile_photo_type']; ?>;base64,<?php echo $u['profile_photo']; ?>" class="w-full h-full object-cover">
                    <?php else: ?>
                    <?php echo strtoupper(substr($u['name'], 0, 2)); ?>
                    <?php endif; ?>
                </div>
                <?php if($u['is_online']): ?>
                <div class="absolute bottom-0 right-0 w-3 h-3 bg-green-500 rounded-full border-2 border-white"></div>
                <?php endif; ?>
            </div>
            <div class="flex-1">
                <div class="flex justify-between">
                    <span class="font-semibold"><?php echo htmlspecialchars($u['name']); ?></span>
                    <span class="text-xs text-slate-400"><?php echo $last_msg ? date('H:i', strtotime($last_msg['created_at'])) : ''; ?></span>
                </div>
                <div class="flex justify-between">
                    <p class="text-sm text-slate-500 truncate message-text"><?php echo $preview_text; ?></p>
                    <?php if($unread > 0): ?>
                    <span class="bg-green-500 text-white text-xs rounded-full px-2"><?php echo $unread; ?></span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    
    <div id="mobileGroupsDiv" class="flex-1 overflow-y-auto px-2 py-2 space-y-1" style="display: none;">
        <div class="flex items-center gap-3 p-3 rounded-xl bg-blue-50 active:opacity-80 cursor-pointer" onclick="openCreateGroupModal()">
            <div class="w-12 h-12 rounded-full bg-green-500 flex items-center justify-center text-white"><span class="material-symbols-outlined">add</span></div>
            <div><span class="font-semibold">Criar novo grupo</span><p class="text-sm text-slate-500">Adicione membros</p></div>
        </div>
        <?php foreach($groups as $g): ?>
        <div class="flex items-center gap-3 p-3 rounded-xl active:bg-slate-100 cursor-pointer" data-group-id="<?php echo $g['id']; ?>" onclick="openMobileChat('group', <?php echo $g['id']; ?>)">
            <div class="w-12 h-12 rounded-full bg-gradient-to-br from-purple-500 to-purple-400 flex items-center justify-center text-white shadow-sm overflow-hidden">
                <?php if(!empty($g['group_photo'])): ?>
                <img src="data:<?php echo $g['group_photo_type']; ?>;base64,<?php echo $g['group_photo']; ?>" class="w-full h-full object-cover">
                <?php else: ?>
                <span class="material-symbols-outlined">groups</span>
                <?php endif; ?>
            </div>
            <div class="flex-1">
                <div class="flex justify-between">
                    <span class="font-semibold"><?php echo htmlspecialchars($g['name']); ?></span>
                    <span class="text-xs text-slate-400"><?php echo $g['member_count']; ?> membros</span>
                </div>
                <p class="text-sm text-slate-500 truncate"><?php echo $g['last_message'] ? htmlspecialchars(substr($g['last_message'], 0, 35)) : 'Nenhuma mensagem'; ?></p>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    
    <div class="bg-white border-t py-2 px-4 shadow-lg">
        <div class="flex justify-around">
            <div class="flex flex-col items-center text-blue-600 cursor-pointer" onclick="mobileSwitchTab('contacts')">
                <span class="material-symbols-outlined">chat_bubble</span>
                <span class="text-[10px]">Chats</span>
            </div>
            <div class="flex flex-col items-center text-slate-400 cursor-pointer" onclick="mobileSwitchTab('groups')">
                <span class="material-symbols-outlined">group</span>
                <span class="text-[10px]">Grupos</span>
            </div>
            <div class="flex flex-col items-center text-slate-400 cursor-pointer" onclick="openCreateGroupModal()">
                <span class="material-symbols-outlined">group_add</span>
                <span class="text-[10px]">Criar</span>
            </div>
            <div class="flex flex-col items-center text-slate-400 cursor-pointer" onclick="openProfileModal()">
                <span class="material-symbols-outlined">person</span>
                <span class="text-[10px]">Perfil</span>
            </div>
        </div>
    </div>
</div>

<!-- ==================== MOBILE - ÁREA DE CHAT ==================== -->
<div id="mobileChatArea" class="mobile-chat-area flex flex-col h-screen bg-[#f8f9ff]" style="<?php echo $is_in_chat ? 'display: flex;' : 'display: none;'; ?>">
    <div class="bg-gradient-to-r from-blue-600 to-purple-600 text-white px-4 py-3 flex items-center gap-3 shadow-lg">
        <button onclick="closeMobileChat()" class="p-1"><span class="material-symbols-outlined">arrow_back</span></button>
        <div class="flex items-center gap-3 flex-1 cursor-pointer" onclick="<?php echo $current_contact ? 'viewContactProfile('.$current_contact['id'].')' : 'showGroupInfoWithAddMember()'; ?>">
            <div class="w-10 h-10 rounded-full bg-white/20 flex items-center justify-center overflow-hidden">
                <?php if($current_contact): ?>
                    <?php if(!empty($current_contact['profile_photo'])): ?>
                    <img src="data:<?php echo $current_contact['profile_photo_type']; ?>;base64,<?php echo $current_contact['profile_photo']; ?>" class="w-full h-full object-cover">
                    <?php else: ?>
                    <span class="font-bold"><?php echo strtoupper(substr($current_contact['name'], 0, 2)); ?></span>
                    <?php endif; ?>
                <?php else: ?>
                    <?php if(!empty($current_group['group_photo'])): ?>
                    <img src="data:<?php echo $current_group['group_photo_type']; ?>;base64,<?php echo $current_group['group_photo']; ?>" class="w-full h-full object-cover">
                    <?php else: ?>
                    <span class="material-symbols-outlined">groups</span>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
            <div>
                <h3 class="font-bold text-sm" id="mobileChatTitle"><?php echo $current_contact ? htmlspecialchars($current_contact['name']) : htmlspecialchars($current_group['name']); ?></h3>
                <p class="text-xs opacity-80" id="chatStatusMobile"><?php echo ($current_contact && $current_contact['is_online']) ? '● Online' : (($current_contact) ? 'Offline' : count($group_members).' membros'); ?></p>
            </div>
        </div>
        <button onclick="<?php echo $current_contact ? 'viewContactProfile('.$current_contact['id'].')' : 'showGroupInfoWithAddMember();'; ?>"><span class="material-symbols-outlined">more_vert</span></button>
    </div>
    
    <div class="flex-1 overflow-y-auto p-3 space-y-3" id="mobileMessages">
        <?php 
        $all_items = [];
        
        if($current_contact): 
            $msgs = get_conversation($current_contact['id']);
            foreach($msgs as $m) {
                $all_items[] = ['type' => 'message', 'data' => $m, 'created_at' => $m['created_at']];
            }
            foreach($shared_files as $f) {
                $all_items[] = ['type' => 'file', 'data' => $f, 'created_at' => $f['uploaded_at']];
            }
        elseif($current_group):
            foreach($group_messages as $msg) {
                $all_items[] = ['type' => 'group_message', 'data' => $msg, 'created_at' => $msg['created_at']];
            }
            foreach($group_files as $f) {
                $all_items[] = ['type' => 'group_file', 'data' => $f, 'created_at' => $f['uploaded_at']];
            }
        endif;
        
        usort($all_items, function($a, $b) {
            return strtotime($a['created_at']) - strtotime($b['created_at']);
        });
        
        $last_date = '';
        foreach($all_items as $item):
            $item_date = date('Y-m-d', strtotime($item['created_at']));
            if($last_date != $item_date): $last_date = $item_date;
        ?>
        <div class="flex justify-center"><span class="text-xs text-slate-400 bg-slate-100 px-3 py-1 rounded-full"><?php echo date('d/m/Y', strtotime($item['created_at'])); ?></span></div>
        <?php endif; ?>
        
        <?php if($item['type'] == 'message'): 
            $m = $item['data'];
        ?>
        <div class="flex <?php echo $m['direction'] == 'sent' ? 'justify-end' : 'justify-start'; ?> message-item" data-message-id="<?php echo $m['id']; ?>">
            <div class="max-w-[85%] <?php echo $m['direction'] == 'sent' ? 'bg-blue-600 text-white rounded-2xl rounded-br-none' : 'bg-white rounded-2xl rounded-bl-none'; ?> p-3 message-bubble-shadow">
                <p class="text-sm emoji-picker message-text"><?php echo nl2br(htmlspecialchars($m['decrypted_message'])); ?></p>
                <p class="text-[10px] <?php echo $m['direction'] == 'sent' ? 'text-blue-200' : 'text-slate-400'; ?> text-right mt-1"><?php echo date('H:i', strtotime($m['created_at'])); ?></p>
            </div>
        </div>
        
        <?php elseif($item['type'] == 'group_message'): 
            $msg = $item['data'];
        ?>
        <div class="flex <?php echo ($msg['from_user'] == $user_id) ? 'justify-end' : 'justify-start'; ?> group-message-item" data-message-id="<?php echo $msg['id']; ?>">
            <div class="max-w-[85%] <?php echo ($msg['from_user'] == $user_id) ? 'bg-blue-600 text-white rounded-2xl rounded-br-none' : 'bg-white rounded-2xl rounded-bl-none'; ?> p-3 message-bubble-shadow">
                <?php if($msg['from_user'] != $user_id): ?>
                <p class="text-xs font-bold text-blue-600 mb-1"><?php echo htmlspecialchars($msg['sender_name']); ?></p>
                <?php endif; ?>
                <p class="text-sm emoji-picker message-text"><?php echo nl2br(htmlspecialchars($msg['decrypted_message'])); ?></p>
                <p class="text-[10px] <?php echo ($msg['from_user'] == $user_id) ? 'text-blue-200' : 'text-slate-400'; ?> text-right mt-1"><?php echo date('H:i', strtotime($msg['created_at'])); ?></p>
            </div>
        </div>
        
        <?php elseif($item['type'] == 'file'): 
            $f = $item['data'];
            $is_image = strpos($f['mime_type'], 'image') !== false;
            $is_video = strpos($f['mime_type'], 'video') !== false;
            $is_pdf = strpos($f['mime_type'], 'pdf') !== false;
        ?>
        <div class="flex <?php echo ($f['sender_id'] == $user_id) ? 'justify-end' : 'justify-start'; ?>">
            <div class="max-w-[85%] <?php echo ($f['sender_id'] == $user_id) ? 'bg-blue-600 text-white rounded-2xl rounded-br-none' : 'bg-white rounded-2xl rounded-bl-none'; ?> p-2 message-bubble-shadow">
                <div class="cursor-pointer" onclick="downloadFile(<?php echo $f['id']; ?>)">
                    <div class="flex items-center gap-2">
                        <?php if($is_image): ?>
                        <span class="material-symbols-outlined">image</span>
                        <?php elseif($is_video): ?>
                        <span class="material-symbols-outlined">videocam</span>
                        <?php elseif($is_pdf): ?>
                        <span class="material-symbols-outlined">picture_as_pdf</span>
                        <?php else: ?>
                        <span class="material-symbols-outlined">attach_file</span>
                        <?php endif; ?>
                        <div>
                            <p class="text-sm font-medium"><?php echo htmlspecialchars($f['file_name']); ?></p>
                            <p class="text-[10px] opacity-70"><?php echo round($f['file_size']/1024, 2); ?> KB</p>
                        </div>
                    </div>
                    <?php if($is_image): ?>
                    <img src="preview.php?id=<?php echo $f['id']; ?>" class="file-preview-img mt-2" onclick="event.stopPropagation(); window.open('download.php?id=<?php echo $f['id']; ?>', '_blank')">
                    <?php elseif($is_video): ?>
                    <video class="file-preview-video mt-2" controls onclick="event.stopPropagation()">
                        <source src="download.php?id=<?php echo $f['id']; ?>" type="<?php echo $f['mime_type']; ?>">
                    </video>
                    <?php endif; ?>
                </div>
                <p class="text-[10px] opacity-50 text-right mt-1"><?php echo date('H:i', strtotime($f['uploaded_at'])); ?></p>
            </div>
        </div>
        <?php endif; ?>
        <?php endforeach; ?>
    </div>
    
    <div class="p-3 bg-white border-t">
        <div class="flex gap-2 bg-[#eff4ff] rounded-2xl p-2 items-center">
            <input type="hidden" id="mobileChatType" value="<?php echo $current_contact ? 'private' : 'group'; ?>">
            <input type="hidden" id="mobileRecipientId" value="<?php echo $current_contact ? $current_contact['id'] : ($current_group ? $current_group['id'] : ''); ?>">
            <input type="text" id="mobileMsgInput" placeholder="Digite uma mensagem..." class="flex-1 bg-transparent outline-none text-sm py-1 emoji-picker">
            <input type="file" id="mobileFileInput" style="display:none">
            <button type="button" class="p-2 text-slate-400" onclick="document.getElementById('mobileFileInput').click()"><span class="material-symbols-outlined">attach_file</span></button>
            <button type="button" class="p-2 text-slate-400 emoji-toggle" onclick="toggleMobileEmoji()"><span class="material-symbols-outlined">sentiment_satisfied</span></button>
            <button type="button" class="bg-blue-600 text-white rounded-xl px-4 py-2" onclick="sendMessageAsync()"><span class="material-symbols-outlined text-sm">send</span></button>
        </div>
        <div id="mobileFilePreview" class="hidden mt-2 bg-slate-100 rounded-xl p-2 flex items-center justify-between">
            <div class="flex items-center gap-2"><span class="material-symbols-outlined text-blue-600">insert_drive_file</span><div><p class="text-xs font-medium" id="mobilePreviewFileName"></p><p class="text-[10px] text-slate-500" id="mobilePreviewFileSize"></p></div></div>
            <button type="button" class="text-red-500" onclick="removeMobileSelectedFile()"><span class="material-symbols-outlined text-sm">close</span></button>
        </div>
        <div id="mobileEmojiPanel" class="hidden bg-white rounded-2xl shadow-lg p-2 mt-2 grid grid-cols-8 gap-1 text-2xl z-50 relative">
            <span class="cursor-pointer p-1 hover:bg-slate-100 rounded transition" onclick="addEmojiToMobile('😊')">😊</span>
            <span class="cursor-pointer p-1 hover:bg-slate-100 rounded transition" onclick="addEmojiToMobile('😂')">😂</span>
            <span class="cursor-pointer p-1 hover:bg-slate-100 rounded transition" onclick="addEmojiToMobile('❤️')">❤️</span>
            <span class="cursor-pointer p-1 hover:bg-slate-100 rounded transition" onclick="addEmojiToMobile('😍')">😍</span>
            <span class="cursor-pointer p-1 hover:bg-slate-100 rounded transition" onclick="addEmojiToMobile('👍')">👍</span>
            <span class="cursor-pointer p-1 hover:bg-slate-100 rounded transition" onclick="addEmojiToMobile('🙏')">🙏</span>
            <span class="cursor-pointer p-1 hover:bg-slate-100 rounded transition" onclick="addEmojiToMobile('🔥')">🔥</span>
            <span class="cursor-pointer p-1 hover:bg-slate-100 rounded transition" onclick="addEmojiToMobile('😢')">😢</span>
            <span class="cursor-pointer p-1 hover:bg-slate-100 rounded transition" onclick="addEmojiToMobile('😎')">😎</span>
            <span class="cursor-pointer p-1 hover:bg-slate-100 rounded transition" onclick="addEmojiToMobile('🎉')">🎉</span>
            <span class="cursor-pointer p-1 hover:bg-slate-100 rounded transition" onclick="addEmojiToMobile('🤔')">🤔</span>
            <span class="cursor-pointer p-1 hover:bg-slate-100 rounded transition" onclick="addEmojiToMobile('😡')">😡</span>
            <span class="cursor-pointer p-1 hover:bg-slate-100 rounded transition" onclick="addEmojiToMobile('😱')">😱</span>
            <span class="cursor-pointer p-1 hover:bg-slate-100 rounded transition" onclick="addEmojiToMobile('🤗')">🤗</span>
            <span class="cursor-pointer p-1 hover:bg-slate-100 rounded transition" onclick="addEmojiToMobile('😴')">😴</span>
            <span class="cursor-pointer p-1 hover:bg-slate-100 rounded transition" onclick="addEmojiToMobile('😉')">😉</span>
            <span class="cursor-pointer p-1 hover:bg-slate-100 rounded transition" onclick="addEmojiToMobile('😘')">😘</span>
            <span class="cursor-pointer p-1 hover:bg-slate-100 rounded transition" onclick="addEmojiToMobile('🥰')">🥰</span>
            <span class="cursor-pointer p-1 hover:bg-slate-100 rounded transition" onclick="addEmojiToMobile('😁')">😁</span>
            <span class="cursor-pointer p-1 hover:bg-slate-100 rounded transition" onclick="addEmojiToMobile('🤣')">🤣</span>
            <span class="cursor-pointer p-1 hover:bg-slate-100 rounded transition" onclick="addEmojiToMobile('🥺')">🥺</span>
            <span class="cursor-pointer p-1 hover:bg-slate-100 rounded transition" onclick="addEmojiToMobile('😅')">😅</span>
            <span class="cursor-pointer p-1 hover:bg-slate-100 rounded transition" onclick="addEmojiToMobile('🤪')">🤪</span>
            <span class="cursor-pointer p-1 hover:bg-slate-100 rounded transition" onclick="addEmojiToMobile('🥳')">🥳</span>
        </div>
    </div>
</div>

<!-- ==================== DESKTOP VERSION ==================== -->
<div class="desktop-only flex h-screen">
    <div class="w-16 bg-white border-r flex flex-col items-center py-4 fixed left-0 top-0 h-screen z-50">
        <div class="w-10 h-10 rounded-xl bg-gradient-to-r from-blue-600 to-purple-600 flex items-center justify-center text-white font-bold shadow-lg mb-8">C</div>
        <div class="flex flex-col items-center gap-6 flex-1">
            <span class="material-symbols-outlined text-blue-600 cursor-pointer" onclick="location.href='dashboard.php'">chat_bubble</span>
            <span class="material-symbols-outlined text-slate-400 hover:text-blue-500 cursor-pointer" onclick="location.href='dashboard.php?groups=1'">group</span>
            <span class="material-symbols-outlined text-slate-400">call</span>
            <span class="material-symbols-outlined text-slate-400">archive</span>
        </div>
        <div class="mt-auto pb-4"><span class="material-symbols-outlined text-slate-400 cursor-pointer" onclick="openProfileModal()">settings</span></div>
    </div>
    
    <div class="w-80 bg-white border-r flex flex-col ml-16">
        <div class="p-4 border-b">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-full bg-gradient-to-r from-blue-600 to-purple-600 flex items-center justify-center text-white font-bold shadow-sm overflow-hidden">
                    <?php if(!empty($user_logado['profile_photo'])): ?>
                    <img src="data:<?php echo $user_logado['profile_photo_type']; ?>;base64,<?php echo $user_logado['profile_photo']; ?>" class="w-full h-full object-cover">
                    <?php else: ?>
                    <?php echo strtoupper(substr($user_logado['name'], 0, 2)); ?>
                    <?php endif; ?>
                </div>
                <div class="flex-1"><h3 class="font-semibold"><?php echo htmlspecialchars($user_logado['name']); ?></h3><p class="text-xs text-slate-400"><?php echo htmlspecialchars($user_logado['user_number']); ?></p></div>
                <span class="material-symbols-outlined text-blue-600 cursor-pointer" onclick="openCreateGroupModal()">group_add</span>
            </div>
            <div class="mt-3">
                <button onclick="openNewChatModal()" class="w-full bg-blue-600 text-white py-2 rounded-xl font-semibold flex items-center justify-center gap-2 shadow-md new-chat-btn">
                    <span class="material-symbols-outlined text-lg">chat</span> Novo Chat
                </button>
            </div>
        </div>
        <div class="p-3"><div class="flex gap-2 bg-[#eff4ff] rounded-xl p-1"><button class="flex-1 py-2 rounded-lg text-sm font-medium <?php echo (!$show_groups) ? 'bg-white shadow-sm text-blue-600' : 'text-slate-500'; ?>" onclick="location.href='dashboard.php'">Contactos</button><button class="flex-1 py-2 rounded-lg text-sm font-medium <?php echo ($show_groups) ? 'bg-white shadow-sm text-blue-600' : 'text-slate-500'; ?>" onclick="location.href='dashboard.php?groups=1'">Grupos</button></div></div>
        <div class="flex-1 overflow-y-auto px-2 space-y-1" id="contactsList">
            <?php if(!$show_groups): foreach($users as $u): $last_msg = get_last_message($user_id, $u['id']); $unread = get_unread_count($u['id']); ?>
            <div class="flex items-center gap-3 p-3 rounded-xl hover:bg-slate-50 cursor-pointer <?php echo ($current_contact && $current_contact['id'] == $u['id']) ? 'bg-blue-50' : ''; ?>" data-user-id="<?php echo $u['id']; ?>" onclick="switchToChat('contact', <?php echo $u['id']; ?>, '<?php echo htmlspecialchars($u['name']); ?>')">
                <div class="relative">
                    <div class="w-12 h-12 rounded-full bg-gradient-to-br from-blue-500 to-blue-400 flex items-center justify-center text-white font-bold overflow-hidden">
                        <?php if(!empty($u['profile_photo'])): ?>
                        <img src="data:<?php echo $u['profile_photo_type']; ?>;base64,<?php echo $u['profile_photo']; ?>" class="w-full h-full object-cover">
                        <?php else: ?>
                        <?php echo strtoupper(substr($u['name'], 0, 2)); ?>
                        <?php endif; ?>
                    </div>
                    <?php if($u['is_online']): ?>
                    <div class="absolute bottom-0 right-0 w-3 h-3 bg-green-500 rounded-full border-2 border-white"></div>
                    <?php endif; ?>
                </div>
                <div class="flex-1">
                    <div class="flex justify-between">
                        <span class="font-semibold"><?php echo htmlspecialchars($u['name']); ?></span>
                        <span class="text-xs text-slate-400"><?php echo $last_msg ? date('H:i', strtotime($last_msg['created_at'])) : ''; ?></span>
                    </div>
                    <div class="flex justify-between">
                        <p class="text-sm text-slate-500 truncate last-preview"><?php echo $last_msg ? ($last_msg['from_user'] == $user_id ? 'Você: ' : '') . htmlspecialchars(substr($last_msg['message'], 0, 35)) : 'Nenhuma mensagem'; ?></p>
                        <?php if($unread > 0): ?>
                        <span class="bg-green-500 text-white text-xs rounded-full px-2 unread-badge"><?php echo $unread; ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; else: ?>
            <div class="flex items-center gap-3 p-3 rounded-xl bg-blue-50 cursor-pointer mb-2" onclick="openCreateGroupModal()"><div class="w-12 h-12 rounded-full bg-green-500 flex items-center justify-center text-white"><span class="material-symbols-outlined">add</span></div><div><span class="font-semibold">Criar novo grupo</span><p class="text-sm text-slate-500">Adicione membros</p></div></div>
            <?php foreach($groups as $g): ?>
            <div class="flex items-center gap-3 p-3 rounded-xl hover:bg-slate-50 cursor-pointer <?php echo ($current_group && $current_group['id'] == $g['id']) ? 'bg-purple-50' : ''; ?>" data-group-id="<?php echo $g['id']; ?>" onclick="switchToChat('group', <?php echo $g['id']; ?>, '<?php echo htmlspecialchars($g['name']); ?>')">
                <div class="w-12 h-12 rounded-full bg-gradient-to-br from-purple-500 to-purple-400 flex items-center justify-center text-white shadow-sm overflow-hidden">
                    <?php if(!empty($g['group_photo'])): ?>
                    <img src="data:<?php echo $g['group_photo_type']; ?>;base64,<?php echo $g['group_photo']; ?>" class="w-full h-full object-cover">
                    <?php else: ?>
                    <span class="material-symbols-outlined">groups</span>
                    <?php endif; ?>
                </div>
                <div class="flex-1">
                    <div class="flex justify-between">
                        <span class="font-semibold"><?php echo htmlspecialchars($g['name']); ?></span>
                        <span class="text-xs text-slate-400"><?php echo $g['member_count']; ?> membros</span>
                    </div>
                    <p class="text-sm text-slate-500 truncate last-preview"><?php echo $g['last_message'] ? htmlspecialchars(substr($g['last_message'], 0, 35)) : 'Nenhuma mensagem'; ?></p>
                </div>
            </div>
            <?php endforeach; endif; ?>
        </div>
    </div>
    
    <div class="flex-1 flex flex-col bg-[#f8f9ff]">
        <?php if($current_contact): ?>
        <div class="px-4 py-3 bg-white border-b flex items-center justify-between shadow-sm">
            <div class="flex items-center gap-3 cursor-pointer" onclick="viewContactProfile(<?php echo $current_contact['id']; ?>)">
                <div class="relative">
                    <div class="w-10 h-10 rounded-full bg-gradient-to-br from-blue-500 to-blue-400 flex items-center justify-center text-white font-bold overflow-hidden">
                        <?php if(!empty($current_contact['profile_photo'])): ?>
                        <img src="data:<?php echo $current_contact['profile_photo_type']; ?>;base64,<?php echo $current_contact['profile_photo']; ?>" class="w-full h-full object-cover">
                        <?php else: ?>
                        <?php echo strtoupper(substr($current_contact['name'], 0, 2)); ?>
                        <?php endif; ?>
                    </div>
                    <?php if($current_contact['is_online']): ?>
                    <div class="absolute bottom-0 right-0 w-2.5 h-2.5 bg-green-500 rounded-full border-2 border-white"></div>
                    <?php endif; ?>
                </div>
                <div>
                    <h3 class="font-bold"><?php echo htmlspecialchars($current_contact['name']); ?></h3>
                    <p class="text-xs text-slate-500" id="chatStatusDesktop"><?php echo $current_contact['is_online'] ? 'Online' : 'Offline'; ?></p>
                </div>
            </div>
            <button class="p-2 text-slate-400 hover:bg-slate-100 rounded-full" onclick="viewContactProfile(<?php echo $current_contact['id']; ?>)"><span class="material-symbols-outlined">info</span></button>
        </div>
        
        <div class="flex-1 overflow-y-auto p-4 space-y-3" id="desktopMessages">
            <?php 
            $all_items_desktop = [];
            $msgs = get_conversation($current_contact['id']);
            foreach($msgs as $m) {
                $all_items_desktop[] = ['type' => 'message', 'data' => $m, 'created_at' => $m['created_at']];
            }
            foreach($shared_files as $f) {
                $all_items_desktop[] = ['type' => 'file', 'data' => $f, 'created_at' => $f['uploaded_at']];
            }
            usort($all_items_desktop, function($a, $b) {
                return strtotime($a['created_at']) - strtotime($b['created_at']);
            });
            
            $last_date = '';
            foreach($all_items_desktop as $item):
                $item_date = date('Y-m-d', strtotime($item['created_at']));
                if($last_date != $item_date): $last_date = $item_date;
            ?>
            <div class="text-center"><span class="text-xs text-slate-400 bg-slate-100 px-3 py-1 rounded-full"><?php echo date('d/m/Y', strtotime($item['created_at'])); ?></span></div>
            <?php endif; ?>
            
            <?php if($item['type'] == 'message'): 
                $m = $item['data'];
            ?>
            <div class="flex <?php echo $m['direction'] == 'sent' ? 'justify-end' : 'justify-start'; ?> message-item" data-message-id="<?php echo $m['id']; ?>">
                <div class="max-w-[65%] <?php echo $m['direction'] == 'sent' ? 'bg-blue-600 text-white rounded-2xl rounded-br-none' : 'bg-white rounded-2xl rounded-bl-none'; ?> p-3 message-bubble-shadow">
                    <p class="text-sm emoji-picker message-text"><?php echo nl2br(htmlspecialchars($m['decrypted_message'])); ?></p>
                    <p class="text-[10px] <?php echo $m['direction'] == 'sent' ? 'text-blue-200' : 'text-slate-400'; ?> text-right mt-1"><?php echo date('H:i', strtotime($m['created_at'])); ?></p>
                </div>
            </div>
            <?php elseif($item['type'] == 'file'): 
                $f = $item['data'];
                $is_image = strpos($f['mime_type'], 'image') !== false;
                $is_video = strpos($f['mime_type'], 'video') !== false;
                $is_pdf = strpos($f['mime_type'], 'pdf') !== false;
            ?>
            <div class="flex <?php echo ($f['sender_id'] == $user_id) ? 'justify-end' : 'justify-start'; ?>">
                <div class="max-w-[65%] <?php echo ($f['sender_id'] == $user_id) ? 'bg-blue-600 text-white rounded-2xl rounded-br-none' : 'bg-white rounded-2xl rounded-bl-none'; ?> p-2 message-bubble-shadow cursor-pointer" onclick="downloadFile(<?php echo $f['id']; ?>)">
                    <div class="flex items-center gap-2">
                        <?php if($is_image): ?>
                        <span class="material-symbols-outlined">image</span>
                        <?php elseif($is_video): ?>
                        <span class="material-symbols-outlined">videocam</span>
                        <?php elseif($is_pdf): ?>
                        <span class="material-symbols-outlined">picture_as_pdf</span>
                        <?php else: ?>
                        <span class="material-symbols-outlined">attach_file</span>
                        <?php endif; ?>
                        <div>
                            <p class="text-sm font-medium"><?php echo htmlspecialchars($f['file_name']); ?></p>
                            <p class="text-[10px] opacity-70"><?php echo round($f['file_size']/1024, 2); ?> KB</p>
                        </div>
                    </div>
                    <?php if($is_image): ?>
                    <img src="preview.php?id=<?php echo $f['id']; ?>" class="file-preview-img mt-2" onclick="event.stopPropagation(); window.open('download.php?id=<?php echo $f['id']; ?>', '_blank')">
                    <?php elseif($is_video): ?>
                    <video class="file-preview-video mt-2" controls onclick="event.stopPropagation()">
                        <source src="download.php?id=<?php echo $f['id']; ?>" type="<?php echo $f['mime_type']; ?>">
                    </video>
                    <?php endif; ?>
                    <p class="text-[10px] opacity-50 text-right mt-1"><?php echo date('H:i', strtotime($f['uploaded_at'])); ?></p>
                </div>
            </div>
            <?php endif; ?>
            <?php endforeach; ?>
        </div>
        
        <div class="p-3 bg-white border-t">
            <div class="flex gap-2 bg-[#eff4ff] rounded-2xl p-2">
                <input type="hidden" id="desktopChatType" value="private">
                <input type="hidden" id="desktopRecipientId" value="<?php echo $current_contact['id']; ?>">
                <input type="text" id="desktopMsgInput" placeholder="Digite uma mensagem..." class="flex-1 bg-transparent outline-none text-sm py-1 emoji-picker">
                <input type="file" id="desktopFileInput" style="display:none">
                <button type="button" class="p-2 text-slate-400" onclick="document.getElementById('desktopFileInput').click()"><span class="material-symbols-outlined">attach_file</span></button>
                <button type="button" class="p-2 text-slate-400 emoji-toggle" onclick="toggleDesktopEmoji()"><span class="material-symbols-outlined">sentiment_satisfied</span></button>
                <button type="button" class="bg-blue-600 text-white rounded-xl px-4 py-2" onclick="sendMessageAsync()"><span class="material-symbols-outlined text-sm">send</span></button>
            </div>
            <div id="desktopFilePreview" class="hidden mt-2 bg-slate-100 rounded-xl p-2 flex items-center justify-between">
                <div class="flex items-center gap-2"><span class="material-symbols-outlined text-blue-600">insert_drive_file</span><div><p class="text-xs font-medium" id="desktopPreviewFileName"></p><p class="text-[10px] text-slate-500" id="desktopPreviewFileSize"></p></div></div>
                <button type="button" class="text-red-500" onclick="removeDesktopSelectedFile()"><span class="material-symbols-outlined text-sm">close</span></button>
            </div>
            <div id="desktopEmojiPanel" class="hidden bg-white rounded-2xl shadow-lg p-2 mt-2 grid grid-cols-8 gap-1 text-2xl z-50 absolute bottom-20 right-4">
                <span class="cursor-pointer p-1 hover:bg-slate-100 rounded transition" onclick="addEmojiToDesktop('😊')">😊</span>
                <span class="cursor-pointer p-1 hover:bg-slate-100 rounded transition" onclick="addEmojiToDesktop('😂')">😂</span>
                <span class="cursor-pointer p-1 hover:bg-slate-100 rounded transition" onclick="addEmojiToDesktop('❤️')">❤️</span>
                <span class="cursor-pointer p-1 hover:bg-slate-100 rounded transition" onclick="addEmojiToDesktop('😍')">😍</span>
                <span class="cursor-pointer p-1 hover:bg-slate-100 rounded transition" onclick="addEmojiToDesktop('👍')">👍</span>
                <span class="cursor-pointer p-1 hover:bg-slate-100 rounded transition" onclick="addEmojiToDesktop('🙏')">🙏</span>
                <span class="cursor-pointer p-1 hover:bg-slate-100 rounded transition" onclick="addEmojiToDesktop('🔥')">🔥</span>
                <span class="cursor-pointer p-1 hover:bg-slate-100 rounded transition" onclick="addEmojiToDesktop('😢')">😢</span>
                <span class="cursor-pointer p-1 hover:bg-slate-100 rounded transition" onclick="addEmojiToDesktop('😎')">😎</span>
                <span class="cursor-pointer p-1 hover:bg-slate-100 rounded transition" onclick="addEmojiToDesktop('🎉')">🎉</span>
                <span class="cursor-pointer p-1 hover:bg-slate-100 rounded transition" onclick="addEmojiToDesktop('🤔')">🤔</span>
                <span class="cursor-pointer p-1 hover:bg-slate-100 rounded transition" onclick="addEmojiToDesktop('😡')">😡</span>
                <span class="cursor-pointer p-1 hover:bg-slate-100 rounded transition" onclick="addEmojiToDesktop('😱')">😱</span>
                <span class="cursor-pointer p-1 hover:bg-slate-100 rounded transition" onclick="addEmojiToDesktop('🤗')">🤗</span>
                <span class="cursor-pointer p-1 hover:bg-slate-100 rounded transition" onclick="addEmojiToDesktop('😴')">😴</span>
                <span class="cursor-pointer p-1 hover:bg-slate-100 rounded transition" onclick="addEmojiToDesktop('😉')">😉</span>
                <span class="cursor-pointer p-1 hover:bg-slate-100 rounded transition" onclick="addEmojiToDesktop('😘')">😘</span>
                <span class="cursor-pointer p-1 hover:bg-slate-100 rounded transition" onclick="addEmojiToDesktop('🥰')">🥰</span>
                <span class="cursor-pointer p-1 hover:bg-slate-100 rounded transition" onclick="addEmojiToDesktop('😁')">😁</span>
                <span class="cursor-pointer p-1 hover:bg-slate-100 rounded transition" onclick="addEmojiToDesktop('🤣')">🤣</span>
                <span class="cursor-pointer p-1 hover:bg-slate-100 rounded transition" onclick="addEmojiToDesktop('🥺')">🥺</span>
                <span class="cursor-pointer p-1 hover:bg-slate-100 rounded transition" onclick="addEmojiToDesktop('😅')">😅</span>
                <span class="cursor-pointer p-1 hover:bg-slate-100 rounded transition" onclick="addEmojiToDesktop('🤪')">🤪</span>
                <span class="cursor-pointer p-1 hover:bg-slate-100 rounded transition" onclick="addEmojiToDesktop('🥳')">🥳</span>
            </div>
        </div>
        
        <?php elseif($current_group): ?>
        <div class="px-4 py-3 bg-white border-b flex items-center justify-between">
            <div class="flex items-center gap-3 cursor-pointer" onclick="showGroupInfoWithAddMember()">
                <div class="w-10 h-10 rounded-full bg-gradient-to-br from-purple-500 to-purple-400 flex items-center justify-center text-white shadow-sm overflow-hidden">
                    <?php if(!empty($current_group['group_photo'])): ?>
                    <img src="data:<?php echo $current_group['group_photo_type']; ?>;base64,<?php echo $current_group['group_photo']; ?>" class="w-full h-full object-cover">
                    <?php else: ?>
                    <span class="material-symbols-outlined">groups</span>
                    <?php endif; ?>
                </div>
                <div>
                    <h3 class="font-bold"><?php echo htmlspecialchars($current_group['name']); ?></h3>
                    <p class="text-xs text-slate-500"><?php echo count($group_members); ?> membros</p>
                </div>
            </div>
            <button class="p-2 text-slate-400 hover:bg-slate-100 rounded-full" onclick="showGroupInfoWithAddMember()"><span class="material-symbols-outlined">info</span></button>
        </div>
        
        <div class="flex-1 overflow-y-auto p-4 space-y-3" id="desktopMessages">
            <?php 
            $all_items_group = [];
            foreach($group_messages as $msg) {
                $all_items_group[] = ['type' => 'group_message', 'data' => $msg, 'created_at' => $msg['created_at']];
            }
            foreach($group_files as $f) {
                $all_items_group[] = ['type' => 'group_file', 'data' => $f, 'created_at' => $f['uploaded_at']];
            }
            usort($all_items_group, function($a, $b) {
                return strtotime($a['created_at']) - strtotime($b['created_at']);
            });
            
            $last_date = '';
            foreach($all_items_group as $item):
                $item_date = date('Y-m-d', strtotime($item['created_at']));
                if($last_date != $item_date): $last_date = $item_date;
            ?>
            <div class="text-center"><span class="text-xs text-slate-400 bg-slate-100 px-3 py-1 rounded-full"><?php echo date('d/m/Y', strtotime($item['created_at'])); ?></span></div>
            <?php endif; ?>
            
            <?php if($item['type'] == 'group_message'): 
                $msg = $item['data'];
            ?>
            <div class="flex <?php echo ($msg['from_user'] == $user_id) ? 'justify-end' : 'justify-start'; ?> group-message-item" data-message-id="<?php echo $msg['id']; ?>">
                <div class="max-w-[65%] <?php echo ($msg['from_user'] == $user_id) ? 'bg-blue-600 text-white rounded-2xl rounded-br-none' : 'bg-white rounded-2xl rounded-bl-none'; ?> p-3 message-bubble-shadow">
                    <?php if($msg['from_user'] != $user_id): ?>
                    <p class="text-xs font-bold text-blue-600 mb-1"><?php echo htmlspecialchars($msg['sender_name']); ?></p>
                    <?php endif; ?>
                    <p class="text-sm emoji-picker message-text"><?php echo nl2br(htmlspecialchars($msg['decrypted_message'])); ?></p>
                    <p class="text-[10px] <?php echo ($msg['from_user'] == $user_id) ? 'text-blue-200' : 'text-slate-400'; ?> text-right mt-1"><?php echo date('H:i', strtotime($msg['created_at'])); ?></p>
                </div>
            </div>
            <?php elseif($item['type'] == 'group_file'): 
                $f = $item['data'];
                $is_image = strpos($f['mime_type'], 'image') !== false;
                $is_video = strpos($f['mime_type'], 'video') !== false;
            ?>
            <div class="flex <?php echo ($f['sender_id'] == $user_id) ? 'justify-end' : 'justify-start'; ?>">
                <div class="max-w-[65%] <?php echo ($f['sender_id'] == $user_id) ? 'bg-blue-600 text-white rounded-2xl rounded-br-none' : 'bg-white rounded-2xl rounded-bl-none'; ?> p-2 message-bubble-shadow cursor-pointer" onclick="downloadFile(<?php echo $f['id']; ?>)">
                    <div class="flex items-center gap-2">
                        <span class="material-symbols-outlined">attach_file</span>
                        <div>
                            <p class="text-sm font-medium"><?php echo htmlspecialchars($f['file_name']); ?></p>
                            <p class="text-[10px] opacity-70"><?php echo round($f['file_size']/1024, 2); ?> KB</p>
                        </div>
                    </div>
                    <?php if($is_image): ?>
                    <img src="preview.php?id=<?php echo $f['id']; ?>" class="file-preview-img mt-2">
                    <?php elseif($is_video): ?>
                    <video class="file-preview-video mt-2" controls>
                        <source src="download.php?id=<?php echo $f['id']; ?>" type="<?php echo $f['mime_type']; ?>">
                    </video>
                    <?php endif; ?>
                    <p class="text-[10px] opacity-50 text-right mt-1"><?php echo date('H:i', strtotime($f['uploaded_at'])); ?></p>
                </div>
            </div>
            <?php endif; ?>
            <?php endforeach; ?>
        </div>
        
        <div class="p-3 bg-white border-t">
            <div class="flex gap-2 bg-[#eff4ff] rounded-2xl p-2">
                <input type="hidden" id="desktopChatType" value="group">
                <input type="hidden" id="desktopRecipientId" value="<?php echo $current_group['id']; ?>">
                <input type="text" id="desktopMsgInput" placeholder="Digite uma mensagem..." class="flex-1 bg-transparent outline-none text-sm py-1 emoji-picker">
                <input type="file" id="desktopGroupFileInput" style="display:none">
                <button type="button" class="p-2 text-slate-400" onclick="document.getElementById('desktopGroupFileInput').click()"><span class="material-symbols-outlined">attach_file</span></button>
                <button type="button" class="p-2 text-slate-400 emoji-toggle" onclick="toggleDesktopEmoji()"><span class="material-symbols-outlined">sentiment_satisfied</span></button>
                <button type="button" class="bg-blue-600 text-white rounded-xl px-4 py-2" onclick="sendMessageAsync()"><span class="material-symbols-outlined text-sm">send</span></button>
            </div>
            <div id="desktopGroupFilePreview" class="hidden mt-2 bg-slate-100 rounded-xl p-2 flex items-center justify-between">
                <div class="flex items-center gap-2"><span class="material-symbols-outlined text-blue-600">insert_drive_file</span><div><p class="text-xs font-medium" id="desktopGroupPreviewFileName"></p><p class="text-[10px] text-slate-500" id="desktopGroupPreviewFileSize"></p></div></div>
                <button type="button" class="text-red-500" onclick="removeDesktopGroupSelectedFile()"><span class="material-symbols-outlined text-sm">close</span></button>
            </div>
            <div id="desktopEmojiPanel" class="hidden bg-white rounded-2xl shadow-lg p-2 mt-2 grid grid-cols-8 gap-1 text-2xl z-50 absolute bottom-20 right-4">
                <span class="cursor-pointer p-1 hover:bg-slate-100 rounded transition" onclick="addEmojiToDesktop('😊')">😊</span>
                <span class="cursor-pointer p-1 hover:bg-slate-100 rounded transition" onclick="addEmojiToDesktop('😂')">😂</span>
                <span class="cursor-pointer p-1 hover:bg-slate-100 rounded transition" onclick="addEmojiToDesktop('❤️')">❤️</span>
                <span class="cursor-pointer p-1 hover:bg-slate-100 rounded transition" onclick="addEmojiToDesktop('😍')">😍</span>
                <span class="cursor-pointer p-1 hover:bg-slate-100 rounded transition" onclick="addEmojiToDesktop('👍')">👍</span>
                <span class="cursor-pointer p-1 hover:bg-slate-100 rounded transition" onclick="addEmojiToDesktop('🙏')">🙏</span>
                <span class="cursor-pointer p-1 hover:bg-slate-100 rounded transition" onclick="addEmojiToDesktop('🔥')">🔥</span>
                <span class="cursor-pointer p-1 hover:bg-slate-100 rounded transition" onclick="addEmojiToDesktop('😢')">😢</span>
                <span class="cursor-pointer p-1 hover:bg-slate-100 rounded transition" onclick="addEmojiToDesktop('😎')">😎</span>
                <span class="cursor-pointer p-1 hover:bg-slate-100 rounded transition" onclick="addEmojiToDesktop('🎉')">🎉</span>
                <span class="cursor-pointer p-1 hover:bg-slate-100 rounded transition" onclick="addEmojiToDesktop('🤔')">🤔</span>
                <span class="cursor-pointer p-1 hover:bg-slate-100 rounded transition" onclick="addEmojiToDesktop('😡')">😡</span>
                <span class="cursor-pointer p-1 hover:bg-slate-100 rounded transition" onclick="addEmojiToDesktop('😱')">😱</span>
                <span class="cursor-pointer p-1 hover:bg-slate-100 rounded transition" onclick="addEmojiToDesktop('🤗')">🤗</span>
                <span class="cursor-pointer p-1 hover:bg-slate-100 rounded transition" onclick="addEmojiToDesktop('😴')">😴</span>
                <span class="cursor-pointer p-1 hover:bg-slate-100 rounded transition" onclick="addEmojiToDesktop('😉')">😉</span>
                <span class="cursor-pointer p-1 hover:bg-slate-100 rounded transition" onclick="addEmojiToDesktop('😘')">😘</span>
                <span class="cursor-pointer p-1 hover:bg-slate-100 rounded transition" onclick="addEmojiToDesktop('🥰')">🥰</span>
                <span class="cursor-pointer p-1 hover:bg-slate-100 rounded transition" onclick="addEmojiToDesktop('😁')">😁</span>
                <span class="cursor-pointer p-1 hover:bg-slate-100 rounded transition" onclick="addEmojiToDesktop('🤣')">🤣</span>
                <span class="cursor-pointer p-1 hover:bg-slate-100 rounded transition" onclick="addEmojiToDesktop('🥺')">🥺</span>
                <span class="cursor-pointer p-1 hover:bg-slate-100 rounded transition" onclick="addEmojiToDesktop('😅')">😅</span>
                <span class="cursor-pointer p-1 hover:bg-slate-100 rounded transition" onclick="addEmojiToDesktop('🤪')">🤪</span>
                <span class="cursor-pointer p-1 hover:bg-slate-100 rounded transition" onclick="addEmojiToDesktop('🥳')">🥳</span>
            </div>
        </div>
        
        <?php else: ?>
        <div class="flex-1 flex items-center justify-center"><div class="text-center"><div class="w-20 h-20 bg-slate-100 rounded-full flex items-center justify-center mx-auto mb-4"><span class="material-symbols-outlined text-4xl text-slate-400">chat</span></div><h3 class="text-xl font-bold">Cerulean Chat</h3><p class="text-slate-500">Selecione um contacto ou grupo</p></div></div>
        <?php endif; ?>
    </div>
    
    <?php if($current_contact): ?>
    <div class="w-80 bg-white border-l overflow-y-auto hidden lg:block">
        <div class="p-6 text-center border-b">
            <div class="w-28 h-28 rounded-full bg-gradient-to-br from-blue-500 to-blue-400 mx-auto flex items-center justify-center text-white text-4xl font-bold shadow-lg mb-4 cursor-pointer relative overflow-hidden">
                <?php if(!empty($current_contact['profile_photo'])): ?>
                <img src="data:<?php echo $current_contact['profile_photo_type']; ?>;base64,<?php echo $current_contact['profile_photo']; ?>" class="w-full h-full object-cover">
                <?php else: ?>
                <?php echo strtoupper(substr($current_contact['name'], 0, 2)); ?>
                <?php endif; ?>
                <?php if($current_contact['is_online']): ?>
                <div class="absolute bottom-2 right-2 w-4 h-4 bg-green-500 rounded-full border-2 border-white"></div>
                <?php endif; ?>
            </div>
            <h2 class="text-xl font-bold"><?php echo htmlspecialchars($current_contact['name']); ?></h2>
            <p class="text-slate-500">@<?php echo htmlspecialchars($current_contact['user_number']); ?></p>
        </div>
        <div class="p-5">
            <div class="mb-5"><h4 class="text-xs text-slate-400">Sobre</h4><p class="text-sm"><?php echo !empty($current_contact['bio']) ? htmlspecialchars($current_contact['bio']) : 'Sem descrição'; ?></p></div>
            <div><h4 class="text-xs text-slate-400">Detalhes</h4><div class="space-y-2 text-sm"><div class="flex items-center gap-2"><span class="material-symbols-outlined text-slate-400 text-sm">email</span><?php echo htmlspecialchars($current_contact['email']); ?></div><?php if(!empty($current_contact['phone'])): ?><div class="flex items-center gap-2"><span class="material-symbols-outlined text-slate-400 text-sm">call</span><?php echo htmlspecialchars($current_contact['phone']); ?></div><?php endif; ?></div></div>
        </div>
    </div>
    <?php elseif($current_group): ?>
    <div class="w-80 bg-white border-l overflow-y-auto hidden lg:block">
        <div class="p-6 text-center border-b">
            <div class="w-28 h-28 rounded-full bg-gradient-to-br from-purple-500 to-purple-400 mx-auto flex items-center justify-center text-white shadow-lg overflow-hidden">
                <?php if(!empty($current_group['group_photo'])): ?>
                <img src="data:<?php echo $current_group['group_photo_type']; ?>;base64,<?php echo $current_group['group_photo']; ?>" class="w-full h-full object-cover">
                <?php else: ?>
                <span class="material-symbols-outlined text-5xl">groups</span>
                <?php endif; ?>
            </div>
            <h2 class="text-xl font-bold"><?php echo htmlspecialchars($current_group['name']); ?></h2>
            <p class="text-slate-500"><?php echo count($group_members); ?> membros</p>
            <?php 
            $is_admin = false;
            foreach($group_members as $m) {
                if($m['user_id'] == $user_id && $m['role'] == 'admin') {
                    $is_admin = true;
                    break;
                }
            }
            if($is_admin): ?>
            <button onclick="openAddMemberModal()" class="mt-3 bg-blue-600 text-white px-4 py-2 rounded-xl text-sm font-semibold hover:bg-blue-700 transition w-full">
                <span class="material-symbols-outlined text-sm">person_add</span> Adicionar membro
            </button>
            <?php endif; ?>
        </div>
        <div class="p-5">
            <div class="mb-5"><h4 class="text-xs text-slate-400">Descrição</h4><p class="text-sm"><?php echo !empty($current_group['description']) ? htmlspecialchars($current_group['description']) : 'Sem descrição'; ?></p></div>
            <h4 class="text-xs text-slate-400 mb-3">Membros</h4>
            <div class="space-y-2 max-h-64 overflow-y-auto"><?php foreach($group_members as $m): ?><div class="flex items-center gap-3 p-2 rounded-lg hover:bg-slate-50 cursor-pointer" onclick="viewContactProfile(<?php echo $m['user_id']; ?>)"><div class="w-8 h-8 rounded-full bg-gradient-to-br from-purple-500 to-purple-400 flex items-center justify-center text-white text-xs font-bold overflow-hidden"><?php if(!empty($m['profile_photo'])): ?><img src="data:<?php echo $m['profile_photo_type']; ?>;base64,<?php echo $m['profile_photo']; ?>" class="w-full h-full object-cover"><?php else: ?><?php echo strtoupper(substr($m['name'], 0, 2)); ?><?php endif; ?></div><div class="flex-1"><p class="text-sm font-medium"><?php echo htmlspecialchars($m['name']); ?></p><p class="text-xs text-slate-400"><?php echo $m['role'] == 'admin' ? 'Admin' : 'Membro'; ?></p></div><?php if($m['is_online']): ?><div class="w-2 h-2 bg-green-500 rounded-full"></div><?php endif; ?></div><?php endforeach; ?></div>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- MODAIS -->
<div id="createGroupModal" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 hidden">
    <div class="bg-white rounded-2xl w-full max-w-md mx-4 max-h-[90vh] overflow-hidden shadow-2xl">
        <div class="bg-gradient-to-r from-blue-600 to-purple-600 text-white p-4 flex justify-between items-center">
            <h3 class="font-bold flex items-center gap-2"><span class="material-symbols-outlined">group_add</span> Criar grupo</h3>
            <button onclick="closeCreateGroupModal()" class="text-white hover:text-slate-200 transition"><span class="material-symbols-outlined">close</span></button>
        </div>
        <div class="p-4 overflow-y-auto max-h-[calc(90vh-120px)]">
            <div class="text-center mb-4">
                <div class="relative inline-block">
                    <img id="groupPhotoPreview" class="group-photo-preview" src="https://ui-avatars.com/api/?background=667eea&color=fff&size=80&name=G">
                    <button type="button" onclick="document.getElementById('group_photo_input').click()" class="absolute bottom-0 right-0 bg-blue-600 text-white p-1 rounded-full shadow-md hover:bg-blue-700 transition"><span class="material-symbols-outlined text-sm">camera_alt</span></button>
                </div>
                <input type="file" id="group_photo_input" name="group_photo" accept="image/*" style="display:none;" onchange="previewGroupPhoto(this)">
                <p class="text-xs text-slate-400 mt-2">Clique na câmera para adicionar foto</p>
            </div>
            <div class="mb-4"><label class="block text-sm font-medium mb-1 text-slate-700">Nome do grupo</label><input type="text" id="groupName" class="w-full border border-slate-200 rounded-xl p-3 focus:ring-2 focus:ring-blue-600 outline-none transition" placeholder="Ex: Amigos, Família, Trabalho..."></div>
            <div class="mb-4"><label class="block text-sm font-medium mb-1 text-slate-700">Descrição</label><textarea id="groupDescription" class="w-full border border-slate-200 rounded-xl p-3 focus:ring-2 focus:ring-blue-600 outline-none transition" rows="2" placeholder="Descreva o propósito do grupo..."></textarea></div>
            <label class="block text-sm font-medium mb-2 text-slate-700">Selecionar membros</label>
            <div class="relative mb-3"><span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-lg">search</span><input type="text" id="searchUser" placeholder="Pesquisar usuários..." class="w-full pl-10 pr-4 py-2 border border-slate-200 rounded-xl text-sm focus:ring-2 focus:ring-blue-600 outline-none"></div>
            <div id="membersList" class="max-h-52 overflow-y-auto border border-slate-200 rounded-xl p-2 space-y-1 bg-slate-50"></div>
            <div class="mt-3 text-center"><span id="selectedCount" class="bg-blue-600 text-white text-xs rounded-full px-3 py-1">0 membros selecionados</span></div>
        </div>
        <div class="p-4 border-t border-slate-100 flex justify-end gap-2">
            <button class="px-4 py-2 bg-slate-100 rounded-xl hover:bg-slate-200 transition" onclick="closeCreateGroupModal()">Cancelar</button>
            <button class="px-4 py-2 bg-gradient-to-r from-blue-600 to-purple-600 text-white rounded-xl hover:opacity-90 transition shadow-md" onclick="createGroup()">Criar grupo</button>
        </div>
    </div>
</div>

<div id="newChatModal" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 hidden">
    <div class="bg-white rounded-2xl w-full max-w-md mx-4 max-h-[80vh] overflow-hidden shadow-2xl">
        <div class="bg-gradient-to-r from-blue-600 to-purple-600 text-white p-4 flex justify-between items-center">
            <h3 class="font-bold flex items-center gap-2"><span class="material-symbols-outlined">chat</span> Novo Chat</h3>
            <button onclick="closeNewChatModal()" class="text-white hover:text-slate-200 transition"><span class="material-symbols-outlined">close</span></button>
        </div>
        <div class="p-4">
            <div class="relative mb-4"><span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-lg">search</span><input type="text" id="searchNewUser" placeholder="Pesquisar por nome, número ou email..." class="w-full pl-10 pr-4 py-3 border border-slate-200 rounded-xl focus:ring-2 focus:ring-blue-600 outline-none"></div>
            <div class="text-sm text-slate-500 mb-3 flex items-center gap-2"><span class="material-symbols-outlined text-sm">group_add</span> Pessoas que você ainda não conversa</div>
            <div id="newUsersList" class="max-h-96 overflow-y-auto space-y-2">
                <?php foreach($new_users as $u): ?>
                <div class="flex items-center gap-3 p-3 rounded-xl hover:bg-slate-50 cursor-pointer transition new-user-item" data-name="<?php echo htmlspecialchars($u['name']); ?>" data-number="<?php echo htmlspecialchars($u['user_number']); ?>" onclick="startNewChat(<?php echo $u['id']; ?>)">
                    <div class="w-12 h-12 rounded-full bg-gradient-to-br from-green-500 to-green-400 flex items-center justify-center text-white font-bold text-lg shadow-sm overflow-hidden">
                        <?php if(!empty($u['profile_photo'])): ?>
                        <img src="data:<?php echo $u['profile_photo_type']; ?>;base64,<?php echo $u['profile_photo']; ?>" class="w-full h-full object-cover">
                        <?php else: ?>
                        <?php echo strtoupper(substr($u['name'], 0, 2)); ?>
                        <?php endif; ?>
                    </div>
                    <div class="flex-1"><p class="font-semibold text-[#0b1c30]"><?php echo htmlspecialchars($u['name']); ?></p><p class="text-sm text-slate-500">@<?php echo htmlspecialchars($u['user_number']); ?></p></div>
                    <button class="bg-blue-600 text-white px-4 py-2 rounded-xl text-sm font-semibold hover:bg-blue-700 transition">Conversar</button>
                </div>
                <?php endforeach; ?>
                <?php if(empty($new_users)): ?>
                <div class="text-center py-8 text-slate-400"><span class="material-symbols-outlined text-4xl mb-2">people</span><p>Nenhum novo usuário encontrado</p></div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div id="profileModal" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 hidden"><div class="bg-white rounded-2xl w-full max-w-md mx-4 max-h-[90vh] overflow-y-auto"><div class="bg-gradient-to-r from-blue-600 to-purple-600 text-white p-4 flex justify-between sticky top-0"><h3 class="font-bold">Meu Perfil</h3><button onclick="closeProfileModal()"><span class="material-symbols-outlined">close</span></button></div><div class="p-6 text-center"><div class="w-24 h-24 rounded-full bg-gradient-to-r from-blue-600 to-purple-600 mx-auto flex items-center justify-center text-white text-3xl font-bold shadow-lg mb-4 overflow-hidden"><?php if(!empty($user_logado['profile_photo'])): ?><img src="data:<?php echo $user_logado['profile_photo_type']; ?>;base64,<?php echo $user_logado['profile_photo']; ?>" class="w-full h-full object-cover"><?php else: ?><?php echo strtoupper(substr($user_logado['name'], 0, 2)); ?><?php endif; ?></div><h2 class="text-xl font-bold"><?php echo htmlspecialchars($user_logado['name']); ?></h2><p class="text-slate-500">@<?php echo htmlspecialchars($user_logado['user_number']); ?></p><div class="text-left mt-6 space-y-3 border-t pt-4"><p class="flex gap-2"><span class="material-symbols-outlined text-slate-400">email</span> <?php echo htmlspecialchars($user_logado['email']); ?></p><?php if(!empty($user_logado['phone'])): ?><p class="flex gap-2"><span class="material-symbols-outlined text-slate-400">call</span> <?php echo htmlspecialchars($user_logado['phone']); ?></p><?php endif; ?><?php if(!empty($user_logado['bio'])): ?><p class="flex gap-2"><span class="material-symbols-outlined text-slate-400">description</span> <?php echo htmlspecialchars($user_logado['bio']); ?></p><?php endif; ?><?php if(!empty($user_logado['city']) || !empty($user_logado['country'])): ?><p class="flex gap-2"><span class="material-symbols-outlined text-slate-400">location_on</span> <?php echo htmlspecialchars($user_logado['city'] ?? ''); ?><?php echo (!empty($user_logado['city']) && !empty($user_logado['country'])) ? ', ' : ''; ?><?php echo htmlspecialchars($user_logado['country'] ?? ''); ?></p><?php endif; ?><p class="flex gap-2"><span class="material-symbols-outlined text-slate-400">calendar_month</span> Membro desde <?php echo date('d/m/Y', strtotime($user_logado['created_at'])); ?></p></div><hr class="my-4"><details><summary class="text-sm text-blue-600">Chave Pública RSA</summary><div class="bg-slate-100 p-3 rounded-xl mt-2 text-xs font-mono break-all max-h-40 overflow-y-auto"><?php echo nl2br(htmlspecialchars($user_logado['public_key'])); ?></div></details><button onclick="logout()" class="w-full mt-6 py-3 bg-red-500 text-white rounded-xl font-semibold">Sair da conta</button></div></div></div>

<div id="contactProfileModal" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 hidden"><div id="contactProfileContent" class="bg-white rounded-2xl w-full max-w-md mx-4 max-h-[90vh] overflow-y-auto"></div></div>
<div id="groupInfoModal" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 hidden"><div id="groupInfoContent" class="bg-white rounded-2xl w-full max-w-md mx-4 max-h-[90vh] overflow-y-auto"></div></div>

<div id="addMemberModal" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 hidden">
    <div class="bg-white rounded-2xl w-full max-w-md mx-4 max-h-[80vh] overflow-hidden shadow-2xl">
        <div class="bg-gradient-to-r from-blue-600 to-purple-600 text-white p-4 flex justify-between items-center">
            <h3 class="font-bold flex items-center gap-2"><span class="material-symbols-outlined">person_add</span> Adicionar membro</h3>
            <button onclick="closeAddMemberModal()" class="text-white hover:text-slate-200 transition"><span class="material-symbols-outlined">close</span></button>
        </div>
        <div class="p-4">
            <div class="relative mb-4">
                <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-lg">search</span>
                <input type="text" id="searchNewMember" placeholder="Pesquisar usuários..." class="w-full pl-10 pr-4 py-3 border border-slate-200 rounded-xl focus:ring-2 focus:ring-blue-600 outline-none">
            </div>
            <div class="text-sm text-slate-500 mb-3 flex items-center gap-2">
                <span class="material-symbols-outlined text-sm">group_add</span>
                Pessoas que não estão no grupo
            </div>
            <div id="addMemberList" class="max-h-96 overflow-y-auto space-y-2">
                <div class="text-center py-8 text-slate-400">Carregando...</div>
            </div>
        </div>
    </div>
</div>

<!-- Footer Fixo -->

<script>
// ============================================
// VARIÁVEIS GLOBAIS
// ============================================
let lastMessageId = 0;
let lastGroupMessageId = 0;
let pollingInterval = null;
let currentChatType = '<?php echo $current_contact ? "private" : ($current_group ? "group" : ""); ?>';
let currentChatId = '<?php echo $current_contact ? $current_contact["id"] : ($current_group ? $current_group["id"] : ""); ?>';
let desktopSelectedFile = null;
let desktopGroupSelectedFile = null;
let selectedMembers = [];
let allUsers = [];
let availableUsers = [];

// ============================================
// INICIALIZAÇÃO
// ============================================
$(document).ready(function() {
    scrollToBottom();
    
    // Inicializar handlers de ficheiro
    $('#mobileFileInput').on('change', handleMobileFileSelect);
    $('#desktopFileInput').on('change', handleDesktopFileSelect);
    $('#desktopGroupFileInput').on('change', handleDesktopGroupFileSelect);
    
    // Se já estiver num chat ao carregar a página
    if (currentChatType && currentChatId) {
        if (currentChatType === 'private') {
            lastMessageId = <?php echo $current_contact ? (max(array_column($msgs ?? [], 'id') ?: [0])) : 0; ?>;
            startPolling();
        } else if (currentChatType === 'group') {
            lastGroupMessageId = <?php echo $current_group ? (max(array_column($group_messages, 'id') ?: [0])) : 0; ?>;
            startGroupPolling();
        }
    }

    setupInputResize();
    startOnlineStatusCheck();

    // Click handlers para mobile
    $('#mobileContactsDiv').on('click', 'div[data-user-id]', function() {
        const userId = $(this).data('user-id');
        switchToChat('contact', userId, $(this).find('.font-semibold').text());
    });

    $('#mobileGroupsDiv').on('click', 'div[data-group-id]', function() {
        const groupId = $(this).data('group-id');
        switchToChat('group', groupId, $(this).find('.font-semibold').text());
    });
});

// ============================================
// HANDLERS DE FICHEIROS
// ============================================
function handleMobileFileSelect() {
    const input = document.getElementById('mobileFileInput');
    if (input.files && input.files[0]) {
        const file = input.files[0];
        if (file.size > 10 * 1024 * 1024) {
            alert('Máximo 10MB!');
            input.value = '';
            return;
        }
        document.getElementById('mobilePreviewFileName').innerText = file.name;
        document.getElementById('mobilePreviewFileSize').innerText = (file.size / 1024).toFixed(2) + ' KB';
        document.getElementById('mobileFilePreview').classList.remove('hidden');
    }
}

function removeMobileSelectedFile() {
    document.getElementById('mobileFileInput').value = '';
    document.getElementById('mobileFilePreview').classList.add('hidden');
}

function handleDesktopFileSelect() {
    const input = document.getElementById('desktopFileInput');
    if (input.files && input.files[0]) {
        const file = input.files[0];
        if (file.size > 10 * 1024 * 1024) {
            alert('Máximo 10MB!');
            input.value = '';
            return;
        }
        desktopSelectedFile = file;
        document.getElementById('desktopPreviewFileName').innerText = file.name;
        document.getElementById('desktopPreviewFileSize').innerText = (file.size / 1024).toFixed(2) + ' KB';
        document.getElementById('desktopFilePreview').classList.remove('hidden');
    }
}

function removeDesktopSelectedFile() {
    desktopSelectedFile = null;
    document.getElementById('desktopFileInput').value = '';
    document.getElementById('desktopFilePreview').classList.add('hidden');
}

function handleDesktopGroupFileSelect() {
    const input = document.getElementById('desktopGroupFileInput');
    if (input.files && input.files[0]) {
        const file = input.files[0];
        if (file.size > 10 * 1024 * 1024) {
            alert('Máximo 10MB!');
            input.value = '';
            return;
        }
        desktopGroupSelectedFile = file;
        document.getElementById('desktopGroupPreviewFileName').innerText = file.name;
        document.getElementById('desktopGroupPreviewFileSize').innerText = (file.size / 1024).toFixed(2) + ' KB';
        document.getElementById('desktopGroupFilePreview').classList.remove('hidden');
    }
}

function removeDesktopGroupSelectedFile() {
    desktopGroupSelectedFile = null;
    document.getElementById('desktopGroupFileInput').value = '';
    document.getElementById('desktopGroupFilePreview').classList.add('hidden');
}

// ============================================
// FUNÇÕES DE EMOJI
// ============================================
function toggleMobileEmoji() {
    const panel = document.getElementById('mobileEmojiPanel');
    panel.classList.toggle('hidden');
}

function toggleDesktopEmoji() {
    const panel = document.getElementById('desktopEmojiPanel');
    panel.classList.toggle('hidden');
}

function addEmojiToMobile(emoji) {
    const input = document.getElementById('mobileMsgInput');
    input.value += emoji;
    input.focus();
    document.getElementById('mobileEmojiPanel').classList.add('hidden');
}

function addEmojiToDesktop(emoji) {
    const input = document.getElementById('desktopMsgInput');
    input.value += emoji;
    input.focus();
    document.getElementById('desktopEmojiPanel').classList.add('hidden');
}

// ============================================
// TROCA DE CHAT (PRINCIPAL - SEM REFRESH)
// ============================================
function switchToChat(type, id, name = '') {
    if (pollingInterval) clearInterval(pollingInterval);

    currentChatType = type;
    currentChatId = id;

    const url = `dashboard.php?${type === 'contact' ? 'contact' : 'group'}=${id}`;
    history.pushState({type, id}, '', url);

    // Interface Mobile
    $('#mobileListArea').hide();
    $('#mobileChatArea').show();

    // Limpar mensagens atuais
    $('#mobileMessages').empty();
    if (document.getElementById('desktopMessages')) {
        $('#desktopMessages').empty();
    }

    // Atualizar título
    if (name) $('#mobileChatTitle').text(name);

    // Atualizar campos ocultos
    $('#mobileChatType').val(type === 'contact' ? 'private' : 'group');
    $('#mobileRecipientId').val(id);

    // Carregar histórico completo + iniciar polling
    loadFullConversation(type, id);
}

function mobileSwitchTab(tab) {
    if (tab === 'contacts') {
        $('#mobileContactsDiv').show();
        $('#mobileGroupsDiv').hide();
        $('#mobileTabContacts').addClass('text-blue-600 border-blue-600').removeClass('text-slate-500 border-transparent');
        $('#mobileTabGroups').addClass('text-slate-500 border-transparent').removeClass('text-blue-600 border-blue-600');
    } else {
        $('#mobileContactsDiv').hide();
        $('#mobileGroupsDiv').show();
        $('#mobileTabGroups').addClass('text-blue-600 border-blue-600').removeClass('text-slate-500 border-transparent');
        $('#mobileTabContacts').addClass('text-slate-500 border-transparent').removeClass('text-blue-600 border-blue-600');
    }
}

// Carregar conversa completa
function loadFullConversation(type, id) {
    const action = type === 'contact' ? 'load_full_conversation' : 'load_full_group_conversation';
    
    $.ajax({
        url: '../logic_pack/ajax_handler.php',
        method: 'GET',
        data: {
            action: action,
            contact_id: type === 'contact' ? id : null,
            group_id: type === 'group' ? id : null,
            user_id: <?php echo $user_id; ?>
        },
        dataType: 'json',
        success: function(response) {
            if (response.success && response.messages) {
                $('#mobileMessages').empty();
                if ($('#desktopMessages').length) $('#desktopMessages').empty();
                
                response.messages.forEach(msg => {
                    addMessageToChat(msg, type);
                    if (type === 'private' && msg.id > lastMessageId) lastMessageId = msg.id;
                    if (type === 'group' && msg.id > lastGroupMessageId) lastGroupMessageId = msg.id;
                });
                
                scrollToBottom();
                startPollingAfterLoad(type, id);
            }
        },
        error: function() {
            location.reload();
        }
    });
}

function startPollingAfterLoad(type, id) {
    if (type === 'private') {
        startPolling();
    } else {
        startGroupPolling();
    }
}

// ============================================
// POLLING
// ============================================
function startPolling() {
    if (pollingInterval) clearInterval(pollingInterval);
    pollingInterval = setInterval(() => pollNewMessages('private'), 2500);
}

function startGroupPolling() {
    if (pollingInterval) clearInterval(pollingInterval);
    pollingInterval = setInterval(() => pollNewMessages('group'), 2500);
}

function pollNewMessages(type) {
    const lastId = type === 'private' ? lastMessageId : lastGroupMessageId;
    
    $.ajax({
        url: '../logic_pack/ajax_handler.php',
        method: 'GET',
        data: {
            action: type === 'private' ? 'get_new_messages' : 'get_new_group_messages',
            contact_id: type === 'private' ? currentChatId : null,
            group_id: type === 'group' ? currentChatId : null,
            last_id: lastId
        },
        dataType: 'json',
        success: function(response) {
            if (response.success && response.messages?.length > 0) {
                response.messages.forEach(msg => {
                    addMessageToChat(msg, type);
                    if (type === 'private') lastMessageId = Math.max(lastMessageId, msg.id);
                    else lastGroupMessageId = Math.max(lastGroupMessageId, msg.id);
                });
                scrollToBottom();
            }
        }
    });
}

function startOnlineStatusCheck() {
    setInterval(() => {
        $.ajax({
            url: '../logic_pack/ajax_handler.php',
            method: 'GET',
            data: { action: 'check_online_status' },
            dataType: 'json',
            success: function(response) {
                if (response.success && response.online_users) {
                    $('.online-indicator, #chatStatusDesktop, #chatStatusMobile').each(function() {
                        // Atualizar status online dos contactos
                    });
                }
            }
        });
    }, 5000);
}

// ============================================
// ENVIAR MENSAGEM + FICHEIRO
// ============================================
function sendMessageAsync() {
    const isMobile = $('#mobileChatArea').is(':visible');
    let message = isMobile ? $('#mobileMsgInput').val().trim() : $('#desktopMsgInput').val().trim();
    const chatType = isMobile ? $('#mobileChatType').val() : $('#desktopChatType').val();
    const recipientId = isMobile ? $('#mobileRecipientId').val() : $('#desktopRecipientId').val();

    const formData = new FormData();
    formData.append('action', chatType === 'private' ? 'send_message' : 'send_group_message');
    formData.append(chatType === 'private' ? 'recipient_id' : 'group_id', recipientId);
    if (message) formData.append('message', message);

    // Adicionar ficheiro se existir
    if (isMobile) {
        const fileInput = document.getElementById('mobileFileInput');
        if (fileInput && fileInput.files && fileInput.files[0]) {
            formData.append('file', fileInput.files[0]);
        }
    } else {
        if (chatType === 'private' && desktopSelectedFile) {
            formData.append('file', desktopSelectedFile);
        } else if (chatType === 'group' && desktopGroupSelectedFile) {
            formData.append('file', desktopGroupSelectedFile);
        }
    }

    if (!message && !formData.has('file')) {
        alert('Digite uma mensagem ou selecione um ficheiro');
        return;
    }

    $.ajax({
        url: '../logic_pack/ajax_handler.php',
        method: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                // Limpar
                if (isMobile) {
                    $('#mobileMsgInput').val('');
                    removeMobileSelectedFile();
                } else {
                    $('#desktopMsgInput').val('');
                    if (chatType === 'private') {
                        removeDesktopSelectedFile();
                    } else {
                        removeDesktopGroupSelectedFile();
                    }
                }
                // Recarregar mensagens
                loadFullConversation(currentChatType, currentChatId);
            } else {
                alert(response.error || 'Erro ao enviar');
            }
        },
        error: function() {
            alert('Erro de conexão ao enviar mensagem');
        }
    });
}

// ============================================
// FUNÇÕES DE UI
// ============================================
function addMessageToChat(msg, type) {
    const isSent = msg.from_user == <?php echo $user_id; ?> || msg.direction === 'sent';
    const time = msg.created_at ? msg.created_at.substring(11, 16) : '';
    const messageText = msg.message || msg.decrypted_message || '';

    let html = `
        <div class="flex ${isSent ? 'justify-end' : 'justify-start'} message-item" data-message-id="${msg.id}">
            <div class="max-w-[85%] ${isSent ? 'bg-blue-600 text-white rounded-2xl rounded-br-none' : 'bg-white rounded-2xl rounded-bl-none'} p-3 message-bubble-shadow">
    `;

    if (type === 'group' && !isSent && msg.sender_name) {
        html += `<p class="text-xs font-bold text-blue-600 mb-1">${escapeHtml(msg.sender_name)}</p>`;
    }

    html += `
                <p class="text-sm emoji-picker message-text">${escapeHtml(messageText)}</p>
                <p class="text-[10px] ${isSent ? 'text-blue-200' : 'text-slate-400'} text-right mt-1">${time}</p>
            </div>
        </div>
    `;

    $('#mobileMessages').append(html);
    if ($('#desktopMessages').length && $('#desktopMessages').is(':visible')) {
        $('#desktopMessages').append(html);
    }
}

function scrollToBottom() {
    const mobileMsgs = document.getElementById('mobileMessages');
    const desktopMsgs = document.getElementById('desktopMessages');
    if (mobileMsgs) mobileMsgs.scrollTop = mobileMsgs.scrollHeight;
    if (desktopMsgs) desktopMsgs.scrollTop = desktopMsgs.scrollHeight;
}

function escapeHtml(text) {
    if (!text) return '';
    const map = {
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
    };
    return text.replace(/[&<>"']/g, m => map[m]);
}

function setupInputResize() {
    const inputs = ['#desktopMsgInput', '#mobileMsgInput'];
    inputs.forEach(selector => {
        const input = document.querySelector(selector);
        if (input) {
            input.addEventListener('input', function() {
                this.style.height = 'auto';
                this.style.height = Math.min(this.scrollHeight, 120) + 'px';
            });
        }
    });
}

// ============================================
// FUNÇÕES DE CHAT MOBILE
// ============================================
function openMobileChat(type, id) {
    switchToChat(type, id, '');
}

function closeMobileChat() {
    $('#mobileListArea').show();
    $('#mobileChatArea').hide();
    if (pollingInterval) clearInterval(pollingInterval);
}

// ============================================
// FUNÇÕES DE FICHEIROS E DOWNLOAD
// ============================================
function downloadFile(fileId) {
    window.open('download.php?id=' + fileId, '_blank');
}

// ============================================
// MODAIS E OUTRAS FUNÇÕES
// ============================================
function openNewChatModal() {
    document.getElementById('newChatModal').style.display = 'flex';
}

function closeNewChatModal() {
    document.getElementById('newChatModal').style.display = 'none';
    document.getElementById('searchNewUser').value = '';
}

function filterNewUsers() {
    const search = document.getElementById('searchNewUser').value.toLowerCase();
    const items = document.querySelectorAll('#newUsersList .new-user-item');
    items.forEach(item => {
        const name = item.getAttribute('data-name')?.toLowerCase();
        const number = item.getAttribute('data-number')?.toLowerCase();
        if ((name && name.includes(search)) || (number && number.includes(search))) {
            item.style.display = 'flex';
        } else {
            item.style.display = 'none';
        }
    });
}

document.getElementById('searchNewUser')?.addEventListener('input', filterNewUsers);

function startNewChat(userId) {
    closeNewChatModal();
    switchToChat('contact', userId, '');
}

function logout() {
    if (confirm('Sair do Cerulean Chat?')) {
        window.location.href = '../auth/logout.php';
    }
}

function openProfileModal() {
    document.getElementById('profileModal').style.display = 'flex';
}

function closeProfileModal() {
    document.getElementById('profileModal').style.display = 'none';
}

function closeContactProfileModal() {
    document.getElementById('contactProfileModal').style.display = 'none';
}

function closeGroupInfoModal() {
    document.getElementById('groupInfoModal').style.display = 'none';
}

function viewContactProfile(userId) {
    fetch(`../logic_pack/logic.php?action=get_user_profile&id=${userId}`)
        .then(r => r.json())
        .then(user => {
            document.getElementById('contactProfileContent').innerHTML = `
                <div class="bg-gradient-to-r from-blue-600 to-purple-600 text-white p-4 flex justify-between sticky top-0">
                    <h3 class="font-bold">Perfil</h3>
                    <button onclick="closeContactProfileModal()"><span class="material-symbols-outlined">close</span></button>
                </div>
                <div class="p-6 text-center">
                    <div class="w-24 h-24 rounded-full bg-gradient-to-r from-blue-600 to-purple-600 mx-auto flex items-center justify-center text-white text-3xl font-bold mb-4 overflow-hidden">
                        ${user.profile_photo ? `<img src="data:${user.profile_photo_type};base64,${user.profile_photo}" class="w-full h-full object-cover">` : (user.name ? user.name.substring(0,2).toUpperCase() : '')}
                    </div>
                    <h2 class="text-xl font-bold">${escapeHtml(user.name || '')}</h2>
                    <p class="text-slate-500">@${escapeHtml(user.user_number || '')}</p>
                    <div class="mt-2 inline-block px-3 py-1 rounded-full text-xs ${user.is_online ? 'bg-green-100 text-green-700' : 'bg-slate-100 text-slate-500'}">
                        ${user.is_online ? 'Online' : 'Offline'}
                    </div>
                    <div class="text-left mt-6 space-y-3 border-t pt-4">
                        <p class="flex gap-2"><span class="material-symbols-outlined text-slate-400">email</span> ${escapeHtml(user.email || '')}</p>
                        ${user.phone ? `<p class="flex gap-2"><span class="material-symbols-outlined text-slate-400">call</span> ${escapeHtml(user.phone)}</p>` : ''}
                        ${user.bio ? `<p class="flex gap-2"><span class="material-symbols-outlined text-slate-400">description</span> ${escapeHtml(user.bio)}</p>` : ''}
                        ${user.city || user.country ? `<p class="flex gap-2"><span class="material-symbols-outlined text-slate-400">location_on</span> ${escapeHtml(user.city || '')}${user.city && user.country ? ', ' : ''}${escapeHtml(user.country || '')}</p>` : ''}
                        <p class="flex gap-2"><span class="material-symbols-outlined text-slate-400">calendar_month</span> Membro desde ${user.created_at ? new Date(user.created_at).toLocaleDateString() : ''}</p>
                    </div>
                    <hr class="my-4">
                    <details>
                        <summary class="text-sm text-blue-600">Chave Pública RSA</summary>
                        <div class="bg-slate-100 p-3 rounded-xl mt-2 text-xs font-mono break-all">${escapeHtml(user.public_key || 'Não disponível')}</div>
                    </details>
                </div>
            `;
            document.getElementById('contactProfileModal').style.display = 'flex';
        })
        .catch(error => console.error('Erro:', error));
}

// ============================================
// FUNÇÕES DE GRUPO (CREATE, ADD MEMBER, ETC)
// ============================================
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
        };
        reader.readAsDataURL(input.files[0]);
    }
}

function openCreateGroupModal() {
    selectedMembers = [];
    document.getElementById('groupName').value = '';
    document.getElementById('groupDescription').value = '';
    document.getElementById('groupPhotoPreview').src = 'https://ui-avatars.com/api/?background=667eea&color=fff&size=80&name=G';
    document.getElementById('group_photo_input').value = '';
    document.getElementById('selectedCount').innerText = '0 membros selecionados';
    document.getElementById('searchUser').value = '';
    
    fetch('../logic_pack/group_logic.php?action=get_users')
        .then(response => response.json())
        .then(users => {
            allUsers = users;
            renderMemberList(users);
            document.getElementById('createGroupModal').style.display = 'flex';
        })
        .catch(error => console.error('Erro:', error));
}

function renderMemberList(users) {
    const container = document.getElementById('membersList');
    if (!container) return;
    container.innerHTML = '';
    users.forEach(user => {
        if (user.id != <?php echo $user_id; ?>) {
            const isSelected = selectedMembers.includes(user.id.toString());
            const div = document.createElement('div');
            div.className = `member-list-item ${isSelected ? 'selected' : ''}`;
            div.setAttribute('data-id', user.id);
            div.onclick = () => toggleMemberSelect(div, user.id);
            div.innerHTML = `
                <div class="member-avatar-small">
                    ${user.profile_photo ? `<img src="data:${user.profile_photo_type};base64,${user.profile_photo}">` : `<span>${user.name ? user.name.substring(0,2).toUpperCase() : '??'}</span>`}
                </div>
                <div class="flex-1">
                    <strong class="text-sm">${escapeHtml(user.name || '')}</strong>
                    <br>
                    <small class="text-xs text-slate-500">@${escapeHtml(user.user_number || '')}</small>
                </div>
                <div class="selected-check">
                    <span class="material-symbols-outlined text-slate-400">${isSelected ? 'check_circle' : 'radio_button_unchecked'}</span>
                </div>
            `;
            container.appendChild(div);
        }
    });
}

document.getElementById('searchUser')?.addEventListener('input', function(e) {
    const search = e.target.value.toLowerCase();
    const filtered = allUsers.filter(user => 
        (user.name && user.name.toLowerCase().includes(search)) || 
        (user.user_number && user.user_number.toLowerCase().includes(search))
    );
    renderMemberList(filtered);
});

function toggleMemberSelect(element, userId) {
    const index = selectedMembers.indexOf(userId.toString());
    const checkIcon = element.querySelector('.selected-check span');
    if (index === -1) {
        selectedMembers.push(userId.toString());
        element.classList.add('selected');
        if (checkIcon) {
            checkIcon.innerText = 'check_circle';
            checkIcon.classList.add('text-blue-600');
        }
    } else {
        selectedMembers.splice(index, 1);
        element.classList.remove('selected');
        if (checkIcon) {
            checkIcon.innerText = 'radio_button_unchecked';
            checkIcon.classList.remove('text-blue-600');
        }
    }
    document.getElementById('selectedCount').innerText = selectedMembers.length + ' membros selecionados';
}

function closeCreateGroupModal() {
    document.getElementById('createGroupModal').style.display = 'none';
}

function createGroup() {
    const name = document.getElementById('groupName').value.trim();
    const description = document.getElementById('groupDescription').value.trim();
    if (!name) {
        alert('Digite o nome do grupo');
        return;
    }
    if (selectedMembers.length === 0) {
        alert('Selecione pelo menos um membro');
        return;
    }
    
    const formData = new FormData();
    formData.append('action', 'create_group');
    formData.append('group_name', name);
    formData.append('description', description);
    formData.append('members', JSON.stringify(selectedMembers));
    const photoInput = document.getElementById('group_photo_input');
    if (photoInput.files && photoInput.files[0]) {
        formData.append('group_photo', photoInput.files[0]);
    }
    
    $.ajax({
        url: '../logic_pack/group_logic.php',
        method: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        success: function() {
            location.reload();
        },
        error: function() {
            alert('Erro ao criar grupo. Tente novamente.');
        }
    });
}

function showGroupInfoWithAddMember() {
    const groupId = <?php echo $current_group_id ?? 0; ?>;
    if (!groupId) return;
    
    fetch(`../logic_pack/group_logic.php?action=get_group_info&group_id=${groupId}`)
        .then(response => response.json())
        .then(data => {
            const isAdmin = <?php 
                $is_admin = false;
                foreach($group_members as $m) {
                    if($m['user_id'] == $user_id && $m['role'] == 'admin') {
                        $is_admin = true;
                        break;
                    }
                }
                echo $is_admin ? 'true' : 'false';
            ?>;
            
            let membersHtml = '';
            if (data.members) {
                membersHtml = data.members.map(m => `
                    <div class="flex items-center gap-3 p-2 rounded-lg hover:bg-slate-50 cursor-pointer" onclick="viewContactProfile(${m.user_id})">
                        <div class="w-8 h-8 rounded-full bg-purple-500 flex items-center justify-center text-white text-xs overflow-hidden">
                            ${m.profile_photo ? `<img src="data:${m.profile_photo_type};base64,${m.profile_photo}" class="w-full h-full object-cover">` : (m.name ? m.name.substring(0,2).toUpperCase() : '??')}
                        </div>
                        <div class="flex-1">
                            <p class="text-sm font-medium">${escapeHtml(m.name || '')}</p>
                            <p class="text-xs text-slate-400">${m.role === 'admin' ? 'Admin' : 'Membro'}</p>
                        </div>
                        ${m.is_online ? '<div class="w-2 h-2 bg-green-500 rounded-full"></div>' : ''}
                    </div>
                `).join('');
            }
            
            document.getElementById('groupInfoContent').innerHTML = `
                <div class="bg-gradient-to-r from-purple-600 to-pink-500 text-white p-4 flex justify-between sticky top-0">
                    <h3 class="font-bold">${escapeHtml(data.name || '')}</h3>
                    <button onclick="closeGroupInfoModal()" class="text-white"><span class="material-symbols-outlined">close</span></button>
                </div>
                <div class="p-6">
                    <div class="flex justify-center mb-4">
                        <div class="w-24 h-24 rounded-full bg-gradient-to-br from-purple-500 to-purple-400 flex items-center justify-center text-white shadow-lg overflow-hidden">
                            ${data.group_photo ? `<img src="data:${data.group_photo_type};base64,${data.group_photo}" class="w-full h-full object-cover">` : '<span class="material-symbols-outlined text-5xl">groups</span>'}
                        </div>
                    </div>
                    <p class="text-slate-600 mb-4 text-center">${escapeHtml(data.description || 'Sem descrição')}</p>
                    <div class="space-y-2 mb-4">
                        <p class="text-sm flex items-center gap-2"><span class="material-symbols-outlined text-slate-400 text-sm">person</span> Criado por: ${escapeHtml(data.creator_name || '')}</p>
                        <p class="text-sm flex items-center gap-2"><span class="material-symbols-outlined text-slate-400 text-sm">calendar_month</span> Criado em: ${data.created_at ? new Date(data.created_at).toLocaleDateString() : ''}</p>
                        <p class="text-sm flex items-center gap-2"><span class="material-symbols-outlined text-slate-400 text-sm">groups</span> ${data.member_count || 0} membros</p>
                    </div>
                    ${isAdmin ? `<button onclick="closeGroupInfoModal(); openAddMemberModal();" class="w-full mb-4 bg-blue-600 text-white py-2 rounded-xl font-semibold hover:bg-blue-700 transition flex items-center justify-center gap-2"><span class="material-symbols-outlined text-sm">person_add</span> Adicionar membro</button>` : ''}
                    <hr class="my-4">
                    <h4 class="font-bold mb-3 flex items-center gap-2"><span class="material-symbols-outlined text-sm">group</span> Membros</h4>
                    <div class="space-y-2 max-h-64 overflow-y-auto">${membersHtml}</div>
                </div>
            `;
            document.getElementById('groupInfoModal').style.display = 'flex';
        })
        .catch(error => console.error('Erro:', error));
}

function openAddMemberModal() {
    const groupId = <?php echo $current_group_id ?? 0; ?>;
    if (!groupId) {
        console.error('Group ID não encontrado');
        return;
    }
    
    fetch(`../logic_pack/group_logic.php?action=get_available_users&group_id=${groupId}`)
        .then(response => response.json())
        .then(users => {
            availableUsers = users;
            renderAddMemberList(users);
            document.getElementById('addMemberModal').style.display = 'flex';
        })
        .catch(error => {
            console.error('Erro:', error);
            document.getElementById('addMemberList').innerHTML = '<div class="text-center py-8 text-red-500">Erro ao carregar usuários</div>';
            document.getElementById('addMemberModal').style.display = 'flex';
        });
}

function closeAddMemberModal() {
    document.getElementById('addMemberModal').style.display = 'none';
    document.getElementById('searchNewMember').value = '';
}

function renderAddMemberList(users) {
    const container = document.getElementById('addMemberList');
    if (!container) return;
    container.innerHTML = '';
    
    if (!users || users.length === 0) {
        container.innerHTML = '<div class="text-center py-8 text-slate-400"><span class="material-symbols-outlined text-4xl mb-2">person_off</span><p>Nenhum usuário disponível</p></div>';
        return;
    }
    
    users.forEach(user => {
        const div = document.createElement('div');
        div.className = 'flex items-center gap-3 p-3 rounded-xl hover:bg-slate-50 cursor-pointer transition';
        div.onclick = () => addMemberToGroup(user.id);
        div.innerHTML = `
            <div class="w-12 h-12 rounded-full bg-gradient-to-br from-green-500 to-green-400 flex items-center justify-center text-white font-bold text-lg shadow-sm overflow-hidden">
                ${user.profile_photo ? `<img src="data:${user.profile_photo_type};base64,${user.profile_photo}" class="w-full h-full object-cover">` : (user.name ? user.name.substring(0,2).toUpperCase() : '??')}
            </div>
            <div class="flex-1">
                <p class="font-semibold">${escapeHtml(user.name || '')}</p>
                <p class="text-sm text-slate-500">@${escapeHtml(user.user_number || '')}</p>
                ${user.is_online ? '<span class="text-xs text-green-500">● Online</span>' : '<span class="text-xs text-slate-400">Offline</span>'}
            </div>
            <button class="bg-blue-600 text-white px-4 py-2 rounded-xl text-sm font-semibold hover:bg-blue-700 transition" onclick="event.stopPropagation(); addMemberToGroup(${user.id});">Adicionar</button>
        `;
        container.appendChild(div);
    });
}

function addMemberToGroup(userId) {
    const groupId = <?php echo $current_group_id ?? 0; ?>;
    const formData = new FormData();
    formData.append('action', 'add_group_member');
    formData.append('group_id', groupId);
    formData.append('new_member_id', userId);
    
    $.ajax({
        url: '../logic_pack/group_logic.php',
        method: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        success: function() {
            location.reload();
        },
        error: function() {
            alert('Erro ao adicionar membro. Tente novamente.');
        }
    });
}

document.getElementById('searchNewMember')?.addEventListener('input', function(e) {
    const search = e.target.value.toLowerCase();
    const filtered = availableUsers.filter(user => 
        (user.name && user.name.toLowerCase().includes(search)) || 
        (user.user_number && user.user_number.toLowerCase().includes(search))
    );
    renderAddMemberList(filtered);
});

// ENTER PARA ENVIAR
$('#mobileMsgInput, #desktopMsgInput').keypress(function(e) {
    if (e.which === 13) {
        e.preventDefault();
        sendMessageAsync();
    }
});
</script>
</body>
</html>
