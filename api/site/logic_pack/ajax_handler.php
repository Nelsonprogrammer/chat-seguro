<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Não autenticado']);
    exit();
}

include_once "../../conf/db.php";
include_once "../../crypto/crypto.php";

$user_id = $_SESSION['user_id']; // ← USAR DA SESSÃO, NÃO DO GET!
$action = isset($_POST['action']) ? $_POST['action'] : (isset($_GET['action']) ? $_GET['action'] : '');

// ============================================
// FUNÇÃO PARA DECIFRAR MENSAGEM PRIVADA
// ============================================
function decryptMessage($encrypted_message, $session_key_encrypted, $from_user, $to_user, $current_user) {
    global $conn;
    
    if (empty($encrypted_message) || empty($session_key_encrypted)) {
        return "[Mensagem não disponível]";
    }
    
    // Determinar quem deve decifrar a chave
    $target_user = ($from_user == $current_user) ? $to_user : $current_user;
    
    $stmt = $conn->prepare("SELECT privatersa FROM users WHERE id = ?");
    $stmt->bind_param("i", $target_user);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    
    if (!$user || empty($user['privatersa'])) {
        return "[Chave não encontrada]";
    }
    
    $aes_key = rsaDecrypt($session_key_encrypted, $user['privatersa']);
    if (!$aes_key) {
        return "[Erro ao decifrar chave]";
    }
    
    $decrypted = aesDecrypt($encrypted_message, $aes_key);
    return $decrypted ?: "[Erro ao decifrar mensagem]";
}

// ============================================
// FUNÇÃO PARA VERIFICAR ASSINATURA
// ============================================
function verifyMessageSignature($message, $signature, $public_key) {
    if (empty($signature) || empty($message)) {
        return false;
    }
    
    // Tentar verificar com o formato original (string)
    $result = verifySignature($message, $signature, $public_key);
    if ($result) return true;
    
    // Tentar com dados em base64 decodificados
    if (base64_encode(base64_decode($message, true)) === $message) {
        $message_binary = base64_decode($message);
        $result = verifySignature($message_binary, $signature, $public_key);
        if ($result) return true;
    }
    
    // Tentar com assinatura decodificada
    if (base64_encode(base64_decode($signature, true)) === $signature) {
        $signature_binary = base64_decode($signature);
        $result = verifySignature($message, $signature_binary, $public_key);
        if ($result) return true;
    }
    
    return false;
}

// ============================================
// ENVIAR MENSAGEM PRIVADA (AJAX)
// ============================================
if ($action == 'send_message' && $_SERVER['REQUEST_METHOD'] == 'POST') {
    $recipient_id = (int)$_POST['recipient_id'];
    $message = trim($_POST['message']);
    
    if (empty($message)) {
        echo json_encode(['success' => false, 'error' => 'Mensagem vazia']);
        exit();
    }
    
    // Buscar chaves
    $stmt = $conn->prepare("SELECT public_key, privatedh FROM users WHERE id = ?");
    $stmt->bind_param("i", $recipient_id);
    $stmt->execute();
    $destinatario = $stmt->get_result()->fetch_assoc();
    
    $stmt = $conn->prepare("SELECT privatedh, privatersa FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $me = $stmt->get_result()->fetch_assoc();
    
    if (!$destinatario || !$me) {
        echo json_encode(['success' => false, 'error' => 'Utilizador não encontrado']);
        exit();
    }
    
    // Gerar chave AES via DH
    $recipient_public_dh = generateDHPublic($destinatario['privatedh']);
    $sharedSecret = generateSharedSecret($recipient_public_dh, $me['privatedh']);
    $aes_key = deriveAESKey($sharedSecret);
    
    // Encriptar mensagem
    $encrypted = aesEncrypt($message, $aes_key);
    $encrypted_key = rsaEncrypt($aes_key, $destinatario['public_key']);
    
    // 🔧 CORREÇÃO: Assinar o texto cifrado (em base64)
    $signature = signData($encrypted, $me['privatersa']);
    
    // Guardar
    $stmt = $conn->prepare("INSERT INTO private_messages (from_user, to_user, message, signature, session_key_encrypted, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
    $stmt->bind_param("iisss", $user_id, $recipient_id, $encrypted, $signature, $encrypted_key);
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message_id' => $conn->insert_id]);
    } else {
        echo json_encode(['success' => false, 'error' => $stmt->error]);
    }
    exit();
}

// ============================================
// ENVIAR MENSAGEM DE GRUPO (AJAX)
// ============================================
if ($action == 'send_group_message' && $_SERVER['REQUEST_METHOD'] == 'POST') {
    $group_id = (int)$_POST['group_id'];
    $message = trim($_POST['message']);
    
    if (empty($message)) {
        echo json_encode(['success' => false, 'error' => 'Mensagem vazia']);
        exit();
    }
    
    // Buscar chave do grupo
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
        echo json_encode(['success' => false, 'error' => 'Chave do grupo não encontrada']);
        exit();
    }
    
    // Decifrar chave do grupo
    $group_aes_key = rsaDecrypt($key_data['session_key_encrypted'], $key_data['privatersa']);
    
    // Encriptar mensagem
    $encrypted_message = aesEncrypt($message, $group_aes_key);
    
    // Assinar
    $stmt2 = $conn->prepare("SELECT privatersa FROM users WHERE id = ?");
    $stmt2->bind_param("i", $user_id);
    $stmt2->execute();
    $me = $stmt2->get_result()->fetch_assoc();
    
    // 🔧 CORREÇÃO: Assinar o texto cifrado
    $signature = signData($encrypted_message, $me['privatersa']);
    
    // Guardar
    $stmt3 = $conn->prepare("INSERT INTO group_messages (group_id, from_user, message, signature, created_at) VALUES (?, ?, ?, ?, NOW())");
    $stmt3->bind_param("iiss", $group_id, $user_id, $encrypted_message, $signature);
    
    if ($stmt3->execute()) {
        echo json_encode(['success' => true, 'message_id' => $conn->insert_id]);
    } else {
        echo json_encode(['success' => false, 'error' => $stmt3->error]);
    }
    exit();
}

// ============================================
// BUSCAR NOVAS MENSAGENS PRIVADAS (POLLING)
// ============================================
if ($action == 'get_new_messages' && isset($_GET['contact_id'])) {
    $contact_id = (int)$_GET['contact_id'];
    $last_id = isset($_GET['last_id']) ? (int)$_GET['last_id'] : 0;
    
    $stmt = $conn->prepare("
        SELECT pm.*, sender.public_key as sender_public_key
        FROM private_messages pm
        JOIN users sender ON sender.id = pm.from_user
        WHERE ((pm.from_user = ? AND pm.to_user = ?) 
           OR (pm.from_user = ? AND pm.to_user = ?))
           AND pm.id > ?
        ORDER BY pm.created_at ASC
    ");
    $stmt->bind_param("iiiii", $user_id, $contact_id, $contact_id, $user_id, $last_id);
    $stmt->execute();
    $messages = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    $decrypted_messages = [];
    foreach ($messages as $msg) {
        // 🔧 VERIFICAR ASSINATURA
        $signature_valid = false;
        if (!empty($msg['signature']) && !empty($msg['message'])) {
            $signature_valid = verifyMessageSignature(
                $msg['message'],
                $msg['signature'],
                $msg['sender_public_key']
            );
        }
        
        // Só decifrar se assinatura válida OU mensagem própria
        if ($signature_valid || $msg['from_user'] == $user_id) {
            $decrypted = decryptMessage(
                $msg['message'],
                $msg['session_key_encrypted'],
                $msg['from_user'],
                $msg['to_user'],
                $user_id
            );
            $message_text = $decrypted;
        } else {
            $message_text = "[⚠️ MENSAGEM NÃO CONFIÁVEL - Assinatura inválida ⚠️]";
        }
        
        $decrypted_messages[] = [
            'id' => $msg['id'],
            'from_user' => $msg['from_user'],
            'to_user' => $msg['to_user'],
            'message' => $message_text,
            'created_at' => $msg['created_at'],
            'direction' => ($msg['from_user'] == $user_id) ? 'sent' : 'received',
            'signature_valid' => $signature_valid
        ];
    }
    
    echo json_encode(['success' => true, 'messages' => $decrypted_messages]);
    exit();
}

// ============================================
// BUSCAR NOVAS MENSAGENS DO GRUPO (POLLING)
// ============================================
if ($action == 'get_new_group_messages' && isset($_GET['group_id'])) {
    $group_id = (int)$_GET['group_id'];
    $last_id = isset($_GET['last_id']) ? (int)$_GET['last_id'] : 0;
    
    // Verificar se é membro do grupo
    $stmt = $conn->prepare("SELECT 1 FROM group_members WHERE group_id = ? AND user_id = ?");
    $stmt->bind_param("ii", $group_id, $user_id);
    $stmt->execute();
    if ($stmt->get_result()->num_rows === 0) {
        echo json_encode(['success' => false, 'error' => 'Acesso negado']);
        exit();
    }
    
    // Buscar chave do grupo
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
        echo json_encode(['success' => false, 'error' => 'Chave do grupo não encontrada']);
        exit();
    }
    
    $group_aes_key = rsaDecrypt($key_data['session_key_encrypted'], $key_data['privatersa']);
    
    $stmt = $conn->prepare("
        SELECT gm.*, u.name as sender_name, u.public_key as sender_public_key
        FROM group_messages gm
        JOIN users u ON u.id = gm.from_user
        WHERE gm.group_id = ? AND gm.id > ?
        ORDER BY gm.created_at ASC
    ");
    $stmt->bind_param("ii", $group_id, $last_id);
    $stmt->execute();
    $messages = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    $decrypted_messages = [];
    foreach ($messages as $msg) {
        // 🔧 VERIFICAR ASSINATURA
        $signature_valid = false;
        if (!empty($msg['signature']) && !empty($msg['message'])) {
            $signature_valid = verifyMessageSignature(
                $msg['message'],
                $msg['signature'],
                $msg['sender_public_key']
            );
        }
        
        if ($signature_valid) {
            $decrypted = aesDecrypt($msg['message'], $group_aes_key);
            $message_text = $decrypted ?: "[Erro ao decifrar]";
        } else {
            $message_text = "[⚠️ MENSAGEM NÃO CONFIÁVEL - Assinatura inválida ⚠️]";
        }
        
        $decrypted_messages[] = [
            'id' => $msg['id'],
            'from_user' => $msg['from_user'],
            'sender_name' => $msg['sender_name'],
            'message' => $message_text,
            'created_at' => $msg['created_at'],
            'direction' => ($msg['from_user'] == $user_id) ? 'sent' : 'received',
            'signature_valid' => $signature_valid
        ];
    }
    
    echo json_encode(['success' => true, 'messages' => $decrypted_messages]);
    exit();
}

// ============================================
// CARREGAR CONVERSA COMPLETA (PRIVADA)
// ============================================
if ($action === 'load_full_conversation' && isset($_GET['contact_id'])) {
    $contact_id = (int)$_GET['contact_id'];
    
    // Verificar se contato existe
    $stmt = $conn->prepare("SELECT id FROM users WHERE id = ?");
    $stmt->bind_param("i", $contact_id);
    $stmt->execute();
    if ($stmt->get_result()->num_rows === 0) {
        echo json_encode(['success' => false, 'error' => 'Contato inválido']);
        exit();
    }
    
    $stmt = $conn->prepare("
        SELECT pm.*, sender.public_key as sender_public_key
        FROM private_messages pm
        JOIN users sender ON sender.id = pm.from_user
        WHERE (pm.from_user = ? AND pm.to_user = ?) 
           OR (pm.from_user = ? AND pm.to_user = ?)
        ORDER BY pm.created_at ASC
    ");
    $stmt->bind_param("iiii", $user_id, $contact_id, $contact_id, $user_id);
    $stmt->execute();
    $messages = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    $decrypted_messages = [];
    foreach ($messages as $msg) {
        // Verificar assinatura
        $signature_valid = false;
        if (!empty($msg['signature']) && !empty($msg['message'])) {
            $signature_valid = verifyMessageSignature(
                $msg['message'],
                $msg['signature'],
                $msg['sender_public_key']
            );
        }
        
        if ($signature_valid || $msg['from_user'] == $user_id) {
            $decrypted = decryptMessage(
                $msg['message'],
                $msg['session_key_encrypted'],
                $msg['from_user'],
                $msg['to_user'],
                $user_id
            );
            $message_text = $decrypted;
        } else {
            $message_text = "[⚠️ MENSAGEM NÃO CONFIÁVEL - Assinatura inválida ⚠️]";
        }
        
        $decrypted_messages[] = [
            'id' => $msg['id'],
            'from_user' => $msg['from_user'],
            'to_user' => $msg['to_user'],
            'message' => $message_text,
            'created_at' => $msg['created_at'],
            'direction' => ($msg['from_user'] == $user_id) ? 'sent' : 'received',
            'signature_valid' => $signature_valid
        ];
    }
    
    echo json_encode(['success' => true, 'messages' => $decrypted_messages]);
    exit();
}

// ============================================
// CARREGAR CONVERSA COMPLETA (GRUPO)
// ============================================
if ($action === 'load_full_group_conversation' && isset($_GET['group_id'])) {
    $group_id = (int)$_GET['group_id'];
    
    // Verificar se é membro do grupo
    $stmt = $conn->prepare("SELECT 1 FROM group_members WHERE group_id = ? AND user_id = ?");
    $stmt->bind_param("ii", $group_id, $user_id);
    $stmt->execute();
    if ($stmt->get_result()->num_rows === 0) {
        echo json_encode(['success' => false, 'error' => 'Acesso negado ao grupo']);
        exit();
    }
    
    // Buscar chave do grupo
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
        echo json_encode(['success' => false, 'error' => 'Chave do grupo não encontrada']);
        exit();
    }
    
    $group_aes_key = rsaDecrypt($key_data['session_key_encrypted'], $key_data['privatersa']);
    
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
    
    $decrypted_messages = [];
    foreach ($messages as $msg) {
        // Verificar assinatura
        $signature_valid = false;
        if (!empty($msg['signature']) && !empty($msg['message'])) {
            $signature_valid = verifyMessageSignature(
                $msg['message'],
                $msg['signature'],
                $msg['sender_public_key']
            );
        }
        
        if ($signature_valid) {
            $decrypted = aesDecrypt($msg['message'], $group_aes_key);
            $message_text = $decrypted ?: "[Erro ao decifrar]";
        } else {
            $message_text = "[⚠️ MENSAGEM NÃO CONFIÁVEL - Assinatura inválida ⚠️]";
        }
        
        $decrypted_messages[] = [
            'id' => $msg['id'],
            'from_user' => $msg['from_user'],
            'sender_name' => $msg['sender_name'],
            'message' => $message_text,
            'created_at' => $msg['created_at'],
            'direction' => ($msg['from_user'] == $user_id) ? 'sent' : 'received',
            'signature_valid' => $signature_valid
        ];
    }
    
    echo json_encode(['success' => true, 'messages' => $decrypted_messages]);
    exit();
}

// ============================================
// MARCAR MENSAGENS COMO LIDAS
// ============================================
if ($action == 'mark_as_read' && isset($_POST['contact_id'])) {
    $contact_id = (int)$_POST['contact_id'];
    
    $stmt = $conn->prepare("UPDATE private_messages SET is_read = 1, read_at = NOW() WHERE to_user = ? AND from_user = ? AND is_read = 0");
    $stmt->bind_param("ii", $user_id, $contact_id);
    $stmt->execute();
    
    echo json_encode(['success' => true]);
    exit();
}

// ============================================
// BUSCAR USUÁRIOS PARA GRUPO (AJAX)
// ============================================
if ($action == 'get_users') {
    $stmt = $conn->prepare("SELECT id, name, user_number, profile_photo, profile_photo_type, is_online FROM users WHERE id != ? ORDER BY name ASC");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $users = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    echo json_encode($users);
    exit();
}

// ============================================
// BUSCAR USUÁRIOS DISPONÍVEIS PARA GRUPO
// ============================================
if ($action == 'get_available_users' && isset($_GET['group_id'])) {
    $group_id = (int)$_GET['group_id'];
    
    $stmt = $conn->prepare("
        SELECT u.id, u.name, u.user_number, u.profile_photo, u.profile_photo_type, u.is_online
        FROM users u
        WHERE u.id != ? 
        AND u.id NOT IN (SELECT user_id FROM group_members WHERE group_id = ?)
        ORDER BY u.name ASC
    ");
    $stmt->bind_param("ii", $user_id, $group_id);
    $stmt->execute();
    $users = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    echo json_encode($users);
    exit();
}

// ============================================
// STATUS DO USUÁRIO
// ============================================
if ($action === 'get_user_status' && isset($_GET['user_id'])) {
    $target_user_id = (int)$_GET['user_id'];
    
    $stmt = $conn->prepare("SELECT is_online FROM users WHERE id = ?");
    $stmt->bind_param("i", $target_user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    
    echo json_encode(['success' => true, 'is_online' => $user['is_online'] ?? false]);
    exit();
}

echo json_encode(['success' => false, 'error' => 'Ação não encontrada']);
?>