<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    header("location: ../auth/login.php");
    exit();
}

include_once "../../conf/db.php";
include_once "../../crypto/crypto.php";

$user_id = $_SESSION['user_id'];


/* =====================================================
   FUNÇÃO PARA BUSCAR TODOS OS USUÁRIOS (AJAX)
===================================================== */
function getAllUsersForGroup($current_user_id) {
    global $conn;
    $stmt = $conn->prepare("
        SELECT id, name, user_number, profile_photo, profile_photo_type, is_online
        FROM users 
        WHERE id != ? 
        ORDER BY name ASC
    ");
    $stmt->bind_param("i", $current_user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $users = [];
    while($row = $result->fetch_assoc()) {
        $users[] = $row;
    }
    return $users;
}
// Buscar usuários disponíveis para adicionar ao grupo (que não são membros)
if (isset($_GET['action']) && $_GET['action'] == 'get_available_users' && isset($_GET['group_id'])) {
    header('Content-Type: application/json');
    $group_id = (int)$_GET['group_id'];
    
    // Buscar usuários que não estão no grupo
    $stmt = $conn->prepare("
        SELECT u.id, u.name, u.user_number, u.email, u.profile_photo, u.profile_photo_type, u.is_online
        FROM users u
        WHERE u.id != ? 
        AND u.id NOT IN (
            SELECT user_id FROM group_members WHERE group_id = ?
        )
        ORDER BY u.name ASC
    ");
    $stmt->bind_param("ii", $user_id, $group_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $users = [];
    while($row = $result->fetch_assoc()) {
        $users[] = $row;
    }
    echo json_encode($users);
    exit();
}
/* =====================================================
   ROTAS GET (AJAX)
===================================================== */

// Buscar usuários para criar grupo
if (isset($_GET['action']) && $_GET['action'] == 'get_users') {
    header('Content-Type: application/json');
    $users = getAllUsersForGroup($user_id);
    echo json_encode($users);
    exit();
}

// Buscar informações do grupo
if (isset($_GET['action']) && $_GET['action'] == 'get_group_info' && isset($_GET['group_id'])) {
    header('Content-Type: application/json');
    $group_id = (int)$_GET['group_id'];
    
    // Buscar dados do grupo
    $stmt = $conn->prepare("
        SELECT g.*, u.name as creator_name 
        FROM groups g 
        JOIN users u ON u.id = g.created_by 
        WHERE g.id = ?
    ");
    $stmt->bind_param("i", $group_id);
    $stmt->execute();
    $group = $stmt->get_result()->fetch_assoc();
    
    if ($group) {
        // Buscar membros
        $stmt = $conn->prepare("
            SELECT gm.*, u.name, u.user_number, u.email
            FROM group_members gm
            JOIN users u ON u.id = gm.user_id
            WHERE gm.group_id = ?
            ORDER BY gm.role DESC, u.name ASC
        ");
        $stmt->bind_param("i", $group_id);
        $stmt->execute();
        $group['members'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $group['member_count'] = count($group['members']);
    }
    
    echo json_encode($group);
    exit();
}

/* =====================================================
   CRIAR GRUPO (COM SUPORTE A FOTO)
===================================================== */
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'create_group') {
    
    $group_name = trim($_POST['group_name']);
    $description = trim($_POST['description']);
    $members = isset($_POST['members']) ? json_decode($_POST['members'], true) : [];
    
    if (empty($group_name) || empty($members)) {
        $_SESSION['error'] = "Nome do grupo e membros são obrigatórios";
        header("location: ../dashboard/dashboard.php");
        exit();
    }
    
    // Adicionar o criador como membro
    $members[] = $user_id;
    $members = array_unique($members);
    
    // PROCESSAR FOTO DO GRUPO (NOVO)
    $group_photo = null;
    $group_photo_type = null;
    if (isset($_FILES['group_photo']) && $_FILES['group_photo']['error'] === UPLOAD_ERR_OK) {
        $photo_tmp = $_FILES['group_photo']['tmp_name'];
        $photo_type = $_FILES['group_photo']['type'];
        $photo_content = file_get_contents($photo_tmp);
        $group_photo = base64_encode($photo_content);
        $group_photo_type = $photo_type;
    }
    
    // Gerar chave AES mestra do grupo
    $group_aes_key = openssl_random_pseudo_bytes(32);
    $group_aes_key_base64 = base64_encode($group_aes_key);
    
    // Criar o grupo no banco (COM FOTO)
    if ($group_photo) {
        $stmt = $conn->prepare("INSERT INTO groups (name, description, created_by, group_photo, group_photo_type, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
        $stmt->bind_param("ssiss", $group_name, $description, $user_id, $group_photo, $group_photo_type);
    } else {
        $stmt = $conn->prepare("INSERT INTO groups (name, description, created_by, created_at) VALUES (?, ?, ?, NOW())");
        $stmt->bind_param("ssi", $group_name, $description, $user_id);
    }
    $stmt->execute();
    $group_id = $conn->insert_id;
    
    // Para cada membro, cifrar a chave do grupo com sua chave pública RSA
    foreach ($members as $member_id) {
        // Buscar chave pública RSA do membro
        $stmt = $conn->prepare("SELECT public_key FROM users WHERE id = ?");
        $stmt->bind_param("i", $member_id);
        $stmt->execute();
        $member = $stmt->get_result()->fetch_assoc();
        
        if ($member) {
            // Cifrar chave AES do grupo com RSA do membro
            $encrypted_key = rsaEncrypt($group_aes_key, $member['public_key']);
            
            // Guardar membro
            $role = ($member_id == $user_id) ? 'admin' : 'member';
            $stmt2 = $conn->prepare("
                INSERT INTO group_members (group_id, user_id, role, joined_at) 
                VALUES (?, ?, ?, NOW())
            ");
            $stmt2->bind_param("iis", $group_id, $member_id, $role);
            $stmt2->execute();
            
            // Guardar chave cifrada
            $stmt3 = $conn->prepare("
                INSERT INTO group_session_keys (group_id, user_id, session_key_encrypted, created_at) 
                VALUES (?, ?, ?, NOW())
            ");
            $stmt3->bind_param("iis", $group_id, $member_id, $encrypted_key);
            $stmt3->execute();
        }
    }
    
    $_SESSION['success'] = "Grupo criado com sucesso!";
    header("location: ../dashboard/dashboard.php?group=" . $group_id);
    exit();
}

/* =====================================================
   ENVIAR MENSAGEM NO GRUPO
===================================================== */
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'send_group_message') {
    
    $group_id = (int)$_POST['group_id'];
    $message = trim($_POST['message']);
    
    if (empty($message)) {
        $_SESSION['error'] = "Mensagem vazia!";
        header("location: ../dashboard/dashboard.php?group=" . $group_id);
        exit();
    }
    
    // Verificar se usuário é membro do grupo
    $stmt = $conn->prepare("SELECT * FROM group_members WHERE group_id = ? AND user_id = ?");
    $stmt->bind_param("ii", $group_id, $user_id);
    $stmt->execute();
    if ($stmt->get_result()->num_rows == 0) {
        $_SESSION['error'] = "Você não é membro deste grupo";
        header("location: ../dashboard/dashboard.php");
        exit();
    }
    
    // Buscar chave AES do grupo para este usuário
    $stmt = $conn->prepare("
        SELECT gsk.session_key_encrypted, u.privatersa 
        FROM group_session_keys gsk
        JOIN users u ON u.id = gsk.user_id
        WHERE gsk.group_id = ? AND gsk.user_id = ?
    ");
    $stmt->bind_param("ii", $group_id, $user_id);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    
    if (!$result) {
        $_SESSION['error'] = "Erro ao obter chave do grupo";
        header("location: ../dashboard/dashboard.php?group=" . $group_id);
        exit();
    }
    
    // Decifrar chave AES do grupo com minha chave privada RSA
    $group_aes_key = rsaDecrypt($result['session_key_encrypted'], $result['privatersa']);
    
    // Encriptar mensagem com AES
    $encrypted_message = aesEncrypt($message, $group_aes_key);
    
    // Assinar mensagem
    $encrypted_binary = base64_decode($encrypted_message);
    $stmt2 = $conn->prepare("SELECT privatersa FROM users WHERE id = ?");
    $stmt2->bind_param("i", $user_id);
    $stmt2->execute();
    $me = $stmt2->get_result()->fetch_assoc();
    $signature = signData($encrypted_binary, $me['privatersa']);
    
    // Guardar mensagem
    $stmt3 = $conn->prepare("
        INSERT INTO group_messages (group_id, from_user, message, signature, created_at) 
        VALUES (?, ?, ?, ?, NOW())
    ");
    $stmt3->bind_param("iiss", $group_id, $user_id, $encrypted_message, $signature);
    $stmt3->execute();
    
    $_SESSION['success'] = "Mensagem enviada para o grupo!";
    header("location: ../dashboard/dashboard.php?group=" . $group_id);
    exit();
}

/* =====================================================
   ADICIONAR MEMBRO AO GRUPO
===================================================== */
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'add_group_member') {
    
    $group_id = (int)$_POST['group_id'];
    $new_member_id = (int)$_POST['new_member_id'];
    
    // Verificar se usuário é admin do grupo
    $stmt = $conn->prepare("SELECT role FROM group_members WHERE group_id = ? AND user_id = ?");
    $stmt->bind_param("ii", $group_id, $user_id);
    $stmt->execute();
    $current_member = $stmt->get_result()->fetch_assoc();
    
    if (!$current_member || $current_member['role'] != 'admin') {
        $_SESSION['error'] = "Apenas administradores podem adicionar membros";
        header("location: ../dashboard/dashboard.php?group=" . $group_id);
        exit();
    }
    
    // Verificar se o novo membro já está no grupo
    $stmt = $conn->prepare("SELECT * FROM group_members WHERE group_id = ? AND user_id = ?");
    $stmt->bind_param("ii", $group_id, $new_member_id);
    $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) {
        $_SESSION['error'] = "Usuário já é membro do grupo";
        header("location: ../dashboard/dashboard.php?group=" . $group_id);
        exit();
    }
    
    // Buscar chave AES atual do grupo
    $stmt = $conn->prepare("
        SELECT gsk.session_key_encrypted, u.privatersa 
        FROM group_session_keys gsk
        JOIN users u ON u.id = gsk.user_id
        WHERE gsk.group_id = ? AND gsk.user_id = ?
    ");
    $stmt->bind_param("ii", $group_id, $user_id);
    $stmt->execute();
    $key_data = $stmt->get_result()->fetch_assoc();
    
    if (!$key_data) {
        $_SESSION['error'] = "Erro ao obter chave do grupo";
        header("location: ../dashboard/dashboard.php?group=" . $group_id);
        exit();
    }
    
    // Decifrar chave AES do grupo
    $group_aes_key = rsaDecrypt($key_data['session_key_encrypted'], $key_data['privatersa']);
    
    // Cifrar a chave para o novo membro
    $stmt = $conn->prepare("SELECT public_key FROM users WHERE id = ?");
    $stmt->bind_param("i", $new_member_id);
    $stmt->execute();
    $new_member = $stmt->get_result()->fetch_assoc();
    
    if (!$new_member) {
        $_SESSION['error'] = "Usuário não encontrado";
        header("location: ../dashboard/dashboard.php?group=" . $group_id);
        exit();
    }
    
    $encrypted_key_for_new = rsaEncrypt($group_aes_key, $new_member['public_key']);
    
    // Adicionar novo membro
    $stmt = $conn->prepare("
        INSERT INTO group_members (group_id, user_id, role, joined_at) 
        VALUES (?, ?, 'member', NOW())
    ");
    $stmt->bind_param("ii", $group_id, $new_member_id);
    $stmt->execute();
    
    // Adicionar chave cifrada
    $stmt = $conn->prepare("
        INSERT INTO group_session_keys (group_id, user_id, session_key_encrypted, created_at) 
        VALUES (?, ?, ?, NOW())
    ");
    $stmt->bind_param("iis", $group_id, $new_member_id, $encrypted_key_for_new);
    $stmt->execute();
    
    $_SESSION['success'] = "Membro adicionado ao grupo!";
    header("location: ../dashboard/dashboard.php?group=" . $group_id);
    exit();
}

/* =====================================================
   FUNÇÕES PARA BUSCAR DADOS DO GRUPO
===================================================== */

// Buscar todos os grupos do usuário
function getUserGroups($user_id) {
    global $conn;
    $stmt = $conn->prepare("
        SELECT g.*, 
               COUNT(DISTINCT gm.user_id) as member_count,
               (SELECT COUNT(*) FROM group_messages WHERE group_id = g.id) as message_count,
               (SELECT message FROM group_messages WHERE group_id = g.id ORDER BY created_at DESC LIMIT 1) as last_message,
               (SELECT created_at FROM group_messages WHERE group_id = g.id ORDER BY created_at DESC LIMIT 1) as last_activity
        FROM groups g
        JOIN group_members gm ON gm.group_id = g.id
        WHERE gm.user_id = ?
        GROUP BY g.id
        ORDER BY last_activity DESC
    ");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_all(MYSQLI_ASSOC);
}

// Buscar detalhes do grupo
function getGroupDetails($group_id) {
    global $conn;
    $stmt = $conn->prepare("
        SELECT g.*, u.name as creator_name
        FROM groups g
        JOIN users u ON u.id = g.created_by
        WHERE g.id = ?
    ");
    $stmt->bind_param("i", $group_id);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc();
}

// Buscar membros do grupo
function getGroupMembers($group_id) {
    global $conn;
    $stmt = $conn->prepare("
        SELECT gm.*, u.name, u.user_number, u.email, u.profile_photo, u.profile_photo_type, u.is_online
        FROM group_members gm
        JOIN users u ON u.id = gm.user_id
        WHERE gm.group_id = ?
        ORDER BY gm.role DESC, u.name ASC
    ");
    $stmt->bind_param("i", $group_id);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

// Buscar mensagens do grupo
function getGroupMessages($group_id, $user_id) {
    global $conn;
    
    // Buscar chave AES do grupo para este usuário
    $stmt = $conn->prepare("
        SELECT gsk.session_key_encrypted, u.privatersa 
        FROM group_session_keys gsk
        JOIN users u ON u.id = gsk.user_id
        WHERE gsk.group_id = ? AND gsk.user_id = ?
    ");
    $stmt->bind_param("ii", $group_id, $user_id);
    $stmt->execute();
    $key_data = $stmt->get_result()->fetch_assoc();
    
    if (!$key_data) {
        return [];
    }
    
    // Decifrar chave AES do grupo
    $group_aes_key = rsaDecrypt($key_data['session_key_encrypted'], $key_data['privatersa']);
    
    // Buscar mensagens
    $stmt = $conn->prepare("
        SELECT gm.*, u.name as sender_name, u.public_key as sender_public_key
        FROM group_messages gm
        JOIN users u ON u.id = gm.from_user
        WHERE gm.group_id = ?
        ORDER BY gm.created_at ASC
    ");
    $stmt->bind_param("i", $group_id);
    $stmt->execute();
    $messages = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    // Decifrar cada mensagem
    foreach ($messages as &$msg) {
        $msg['decrypted_message'] = aesDecrypt($msg['message'], $group_aes_key);
        if (!$msg['decrypted_message']) {
            $msg['decrypted_message'] = "[Mensagem não disponível]";
        }
        
        // Verificar assinatura
        if (!empty($msg['signature']) && !empty($msg['message'])) {
            $encrypted_binary = base64_decode($msg['message']);
            $msg['signature_valid'] = verifySignature($encrypted_binary, $msg['signature'], $msg['sender_public_key']);
        } else {
            $msg['signature_valid'] = false;
        }
    }
    
    return $messages;
}

// Buscar usuários que não estão no grupo
function getUsersNotInGroup($group_id, $current_user_id) {
    global $conn;
    $stmt = $conn->prepare("
        SELECT u.id, u.name, u.user_number, u.email, u.profile_photo, u.profile_photo_type
        FROM users u
        WHERE u.id != ? 
        AND u.id NOT IN (
            SELECT user_id FROM group_members WHERE group_id = ?
        )
        ORDER BY u.name ASC
    ");
    $stmt->bind_param("ii", $current_user_id, $group_id);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

// Verificar se usuário é membro do grupo
function isGroupMember($group_id, $user_id) {
    global $conn;
    $stmt = $conn->prepare("SELECT * FROM group_members WHERE group_id = ? AND user_id = ?");
    $stmt->bind_param("ii", $group_id, $user_id);
    $stmt->execute();
    return $stmt->get_result()->num_rows > 0;
}

// Buscar todos os usuários exceto o atual (para criar grupo)
function getAllUsersExceptCurrent($user_id) {
    global $conn;
    $stmt = $conn->prepare("
        SELECT id, name, user_number, profile_photo, profile_photo_type, is_online
        FROM users 
        WHERE id != ? 
        ORDER BY name ASC
    ");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}
?>