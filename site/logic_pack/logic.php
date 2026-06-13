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
   FUNÇÃO AUXILIAR PARA DECIFRAR MENSAGEM
===================================================== */
function decrypt_message($encrypted_message, $session_key_encrypted, $from_user, $to_user, $current_user) {
    global $conn;
    
    if (empty($encrypted_message) || empty($session_key_encrypted)) {
        return "[Mensagem vazia]";
    }
    
    // Determinar qual chave privada usar
    if ($from_user == $current_user) {
        // Mensagem enviada por mim - preciso da chave privada do destinatário
        $stmt = $conn->prepare("SELECT privatersa FROM users WHERE id = ?");
        $stmt->bind_param("i", $to_user);
        $stmt->execute();
        $target = $stmt->get_result()->fetch_assoc();
        if ($target && !empty($target['privatersa'])) {
            $aes_key = rsaDecrypt($session_key_encrypted, $target['privatersa']);
            if ($aes_key && $aes_key !== false) {
                $decrypted = aesDecrypt($encrypted_message, $aes_key);
                if ($decrypted && $decrypted !== false) {
                    return $decrypted;
                }
                return "[Erro AES]";
            }
            return "[Erro RSA - Destinatário]";
        }
        return "[Chave destinatário não encontrada]";
    } else {
        // Mensagem recebida - uso minha chave privada
        $stmt = $conn->prepare("SELECT privatersa FROM users WHERE id = ?");
        $stmt->bind_param("i", $current_user);
        $stmt->execute();
        $me = $stmt->get_result()->fetch_assoc();
        if ($me && !empty($me['privatersa'])) {
            $aes_key = rsaDecrypt($session_key_encrypted, $me['privatersa']);
            if ($aes_key && $aes_key !== false) {
                $decrypted = aesDecrypt($encrypted_message, $aes_key);
                if ($decrypted && $decrypted !== false) {
                    return $decrypted;
                }
                return "[Erro AES]";
            }
            return "[Erro RSA - Chave privada inválida]";
        }
        return "[Minha chave privada não encontrada]";
    }
}

/* =====================================================
   FUNÇÕES DE GRUPO
===================================================== */

// Buscar grupos do usuário
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
    if ($result) {
        return $result->fetch_all(MYSQLI_ASSOC);
    }
    return [];
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
            $signature_binary = $msg['signature'];
            if (base64_encode(base64_decode($msg['signature'], true)) === $msg['signature']) {
                $signature_binary = base64_decode($msg['signature']);
            }
            $msg['signature_valid'] = verifySignature($encrypted_binary, $signature_binary, $msg['sender_public_key']);
        } else {
            $msg['signature_valid'] = false;
        }
    }
    
    return $messages;
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
    $result = $stmt->get_result();
    if ($result) {
        return $result->fetch_all(MYSQLI_ASSOC);
    }
    return [];
}

// Verificar se usuário é membro do grupo
function isGroupMember($group_id, $user_id) {
    global $conn;
    $stmt = $conn->prepare("SELECT * FROM group_members WHERE group_id = ? AND user_id = ?");
    $stmt->bind_param("ii", $group_id, $user_id);
    $stmt->execute();
    return $stmt->get_result()->num_rows > 0;
}

// Buscar usuários que não estão no grupo
function getUsersNotInGroup($group_id, $current_user_id) {
    global $conn;
    $stmt = $conn->prepare("
        SELECT u.id, u.name, u.user_number, u.profile_photo, u.profile_photo_type, u.is_online
        FROM users u
        WHERE u.id != ? 
        AND u.id NOT IN (
            SELECT user_id FROM group_members WHERE group_id = ?
        )
        ORDER BY u.name ASC
    ");
    $stmt->bind_param("ii", $current_user_id, $group_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result) {
        return $result->fetch_all(MYSQLI_ASSOC);
    }
    return [];
}

/* =====================================================
   FUNÇÕES PARA CONTACTOS (COM MENSAGEM DECIFRADA)
===================================================== */

/**
 * Buscar apenas usuários com quem o usuário atual já conversou
 */
function getUsersWithConversation($user_id) {
    global $conn;
    
    // Primeiro, limpar usuários inativos
    $stmt = $conn->prepare("UPDATE users SET is_online = 0 WHERE last_seen < DATE_SUB(NOW(), INTERVAL 2 MINUTE)");
    $stmt->execute();
    
    $stmt = $conn->prepare("
        SELECT DISTINCT 
            u.id, u.name, u.user_number, u.email, u.profile_photo, 
            u.profile_photo_type, u.is_online, u.last_seen, u.bio,
            (SELECT message FROM private_messages 
             WHERE (from_user = ? AND to_user = u.id) 
                OR (from_user = u.id AND to_user = ?) 
             ORDER BY created_at DESC LIMIT 1) as last_message,
            (SELECT session_key_encrypted FROM private_messages 
             WHERE (from_user = ? AND to_user = u.id) 
                OR (from_user = u.id AND to_user = ?) 
             ORDER BY created_at DESC LIMIT 1) as last_session_key,
            (SELECT from_user FROM private_messages 
             WHERE (from_user = ? AND to_user = u.id) 
                OR (from_user = u.id AND to_user = ?) 
             ORDER BY created_at DESC LIMIT 1) as last_from_user,
            (SELECT to_user FROM private_messages 
             WHERE (from_user = ? AND to_user = u.id) 
                OR (from_user = u.id AND to_user = ?) 
             ORDER BY created_at DESC LIMIT 1) as last_to_user,
            (SELECT created_at FROM private_messages 
             WHERE (from_user = ? AND to_user = u.id) 
                OR (from_user = u.id AND to_user = ?) 
             ORDER BY created_at DESC LIMIT 1) as last_message_time,
            (SELECT COUNT(*) FROM private_messages 
             WHERE to_user = ? AND from_user = u.id AND is_read = 0) as unread_count
        FROM users u
        WHERE u.id != ? 
        AND EXISTS (
            SELECT 1 FROM private_messages 
            WHERE (from_user = ? AND to_user = u.id) 
               OR (from_user = u.id AND to_user = ?)
        )
        ORDER BY last_message_time DESC
    ");
    
    $stmt->bind_param("iiiiiiiiiiiiii", 
        $user_id, $user_id,
        $user_id, $user_id,
        $user_id, $user_id,
        $user_id, $user_id,
        $user_id, $user_id,
        $user_id,
        $user_id,
        $user_id, $user_id
    );
    
    $stmt->execute();
    $result = $stmt->get_result();
    $users = [];
    
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            if (!empty($row['last_message']) && !empty($row['last_session_key'])) {
                $decrypted = decrypt_message(
                    $row['last_message'],
                    $row['last_session_key'],
                    $row['last_from_user'],
                    $row['last_to_user'],
                    $user_id
                );
                $row['last_message'] = $decrypted;
            } else {
                $row['last_message'] = "Nenhuma mensagem";
            }
            $users[] = $row;
        }
    }
    return $users;
}

/**
 * Buscar usuários que o usuário atual NUNCA conversou
 */
function getNewUsers($user_id) {
    global $conn;
    $stmt = $conn->prepare("
        SELECT u.id, u.name, u.user_number, u.email, u.profile_photo, 
               u.profile_photo_type, u.is_online, u.last_seen, u.bio,
               u.created_at as member_since
        FROM users u
        WHERE u.id != ? 
        AND NOT EXISTS (
            SELECT 1 FROM private_messages 
            WHERE (from_user = ? AND to_user = u.id) 
               OR (from_user = u.id AND to_user = ?)
        )
        AND NOT EXISTS (
            SELECT 1 FROM group_members gm
            WHERE gm.user_id = u.id 
            AND gm.group_id IN (
                SELECT group_id FROM group_members WHERE user_id = ?
            )
        )
        ORDER BY u.name ASC
    ");
    $stmt->bind_param("iiii", $user_id, $user_id, $user_id, $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $users = [];
    if ($result) {
        while($row = $result->fetch_assoc()) {
            $users[] = $row;
        }
    }
    return $users;
}

/**
 * Buscar contatos recentes
 */
function getRecentContacts($user_id, $days = 30) {
    global $conn;
    $stmt = $conn->prepare("
        SELECT DISTINCT 
            u.id, u.name, u.user_number, u.email, u.profile_photo, 
            u.profile_photo_type, u.is_online, u.bio,
            COUNT(pm.id) as message_count
        FROM users u
        JOIN private_messages pm ON (pm.from_user = u.id AND pm.to_user = ?) 
                                 OR (pm.from_user = ? AND pm.to_user = u.id)
        WHERE u.id != ?
        AND pm.created_at > DATE_SUB(NOW(), INTERVAL ? DAY)
        GROUP BY u.id
        ORDER BY message_count DESC
        LIMIT 10
    ");
    $stmt->bind_param("iiii", $user_id, $user_id, $user_id, $days);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result) {
        return $result->fetch_all(MYSQLI_ASSOC);
    }
    return [];
}

/**
 * Buscar contactos por pesquisa
 */
function searchUsers($user_id, $search_term) {
    global $conn;
    $search = "%{$search_term}%";
    $stmt = $conn->prepare("
        SELECT u.id, u.name, u.user_number, u.email, u.profile_photo, 
               u.profile_photo_type, u.is_online, u.bio,
               CASE 
                   WHEN EXISTS (
                       SELECT 1 FROM private_messages 
                       WHERE (from_user = ? AND to_user = u.id) 
                          OR (from_user = u.id AND to_user = ?)
                   ) THEN 'contact'
                   ELSE 'new'
               END as relationship
        FROM users u
        WHERE u.id != ? 
        AND (u.name LIKE ? OR u.user_number LIKE ? OR u.email LIKE ?)
        ORDER BY 
            CASE WHEN u.name LIKE ? THEN 1 ELSE 2 END,
            u.name ASC
        LIMIT 20
    ");
    $stmt->bind_param("iiissss", $user_id, $user_id, $user_id, $search, $search, $search, $search);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result) {
        return $result->fetch_all(MYSQLI_ASSOC);
    }
    return [];
}

// Buscar todos os usuários exceto o atual
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
    $result = $stmt->get_result();
    if ($result) {
        return $result->fetch_all(MYSQLI_ASSOC);
    }
    return [];
}

/* =====================================================
   FUNÇÕES DE MENSAGENS PRIVADAS
===================================================== */

// Buscar conversa privada
// Buscar conversa privada - VERSÃO CORRIGIDA
function get_conversation($other_id) {
    global $conn, $user_id;
    
    $stmt = $conn->prepare("
        SELECT pm.*, sender.public_key as sender_public_key
        FROM private_messages pm
        JOIN users sender ON sender.id = pm.from_user
        WHERE (pm.from_user = ? AND pm.to_user = ?) 
           OR (pm.from_user = ? AND pm.to_user = ?)
        ORDER BY pm.created_at ASC
    ");
    $stmt->bind_param("iiii", $user_id, $other_id, $other_id, $user_id);
    $stmt->execute();
    $messages = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    foreach ($messages as &$msg) {
        $msg['direction'] = ($msg['from_user'] == $user_id) ? 'sent' : 'received';
        
        // 🔧 VERIFICAÇÃO CORRIGIDA DE ASSINATURA
        $signature_valid = false;
        
        // Mensagens enviadas por mim mesmo são sempre confiáveis
        if ($msg['from_user'] == $user_id) {
            $signature_valid = true;
        } 
        // Verificar assinatura de mensagens recebidas
        else if (!empty($msg['signature']) && !empty($msg['message'])) {
            // A assinatura foi gerada sobre o binário da mensagem cifrada
            $encrypted_binary = base64_decode($msg['message']);
            
            // A assinatura pode estar em binário ou base64 no banco
            $signature_binary = $msg['signature'];
            
            // Se a assinatura parece estar em base64, decodificar para binário
            if (base64_encode(base64_decode($msg['signature'], true)) === $msg['signature']) {
                $signature_binary = base64_decode($msg['signature']);
            }
            
            // Verificar a assinatura
            $signature_valid = verifySignature($encrypted_binary, $signature_binary, $msg['sender_public_key']);
            
            // Se falhou, tentar verificar sobre a string original (fallback)
            if (!$signature_valid) {
                $signature_valid = verifySignature($msg['message'], $msg['signature'], $msg['sender_public_key']);
            }
        }
        
        // Decifrar a mensagem
        if ($signature_valid || $msg['from_user'] == $user_id) {
            $decrypted = decrypt_message(
                $msg['message'],
                $msg['session_key_encrypted'],
                $msg['from_user'],
                $msg['to_user'],
                $user_id
            );
            $msg['decrypted_message'] = $decrypted;
            $msg['signature_valid'] = $signature_valid;
        } else {
            $msg['decrypted_message'] = "[⚠️ MENSAGEM NÃO CONFIÁVEL - Assinatura inválida ⚠️]";
            $msg['signature_valid'] = false;
            $msg['tampered'] = true;
        }
    }
    
    return $messages;
}

// Buscar última mensagem entre dois usuários
function get_last_message($user_id, $contact_id) {
    global $conn;
    
    $stmt = $conn->prepare("
        SELECT pm.message, pm.session_key_encrypted, pm.from_user, pm.to_user, pm.created_at
        FROM private_messages pm
        WHERE (pm.from_user = ? AND pm.to_user = ?) 
           OR (pm.from_user = ? AND pm.to_user = ?)
        ORDER BY pm.created_at DESC 
        LIMIT 1
    ");
    $stmt->bind_param("iiii", $user_id, $contact_id, $contact_id, $user_id);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    
    if (!$result) {
        return null;
    }
    
    $decrypted = decrypt_message(
        $result['message'],
        $result['session_key_encrypted'],
        $result['from_user'],
        $result['to_user'],
        $user_id
    );
    
    return [
        'message' => $decrypted,
        'created_at' => $result['created_at'],
        'from_user' => $result['from_user']
    ];
}

// Buscar mensagens não lidas
function get_unread_count($other_id = null) {
    global $conn, $user_id;
    if ($other_id) {
        $stmt = $conn->prepare("SELECT COUNT(*) as c FROM private_messages WHERE to_user = ? AND from_user = ? AND is_read = 0");
        $stmt->bind_param("ii", $user_id, $other_id);
    } else {
        $stmt = $conn->prepare("SELECT COUNT(*) as c FROM private_messages WHERE to_user = ? AND is_read = 0");
        $stmt->bind_param("i", $user_id);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result) {
        return $result->fetch_assoc()['c'];
    }
    return 0;
}

// Marcar mensagens como lidas
function mark_messages_as_read($other_id) {
    global $conn, $user_id;
    $stmt = $conn->prepare("UPDATE private_messages SET is_read = 1, read_at = NOW() WHERE to_user = ? AND from_user = ? AND is_read = 0");
    $stmt->bind_param("ii", $user_id, $other_id);
    $stmt->execute();
}

// Atualizar status online
function updateOnlineStatus($user_id, $is_online = true) {
    global $conn;
    $stmt = $conn->prepare("UPDATE users SET is_online = ?, last_seen = NOW() WHERE id = ?");
    $stmt->bind_param("ii", $is_online, $user_id);
    $stmt->execute();
}

// Atualizar status online do usuário atual
updateOnlineStatus($user_id, true);

/* =====================================================
   PROCESSAR REQUISIÇÕES POST - ENVIAR MENSAGEM PRIVADA
===================================================== */

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'send_message') {
    
    $recipient_id = (int)$_POST['recipient_id'];
    $message = trim($_POST['message']);
    
    if (empty($message) && (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK)) {
        $_SESSION['error'] = "Mensagem vazia!";
        header("location: ../dashboard/dashboard.php?contact=" . $recipient_id);
        exit();
    }
    
    if (!empty($message) && (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK)) {
        
        $stmt = $conn->prepare("SELECT public_key, privatedh FROM users WHERE id = ?");
        $stmt->bind_param("i", $recipient_id);
        $stmt->execute();
        $destinatario = $stmt->get_result()->fetch_assoc();
        
        $stmt = $conn->prepare("SELECT privatedh, privatersa FROM users WHERE id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $me = $stmt->get_result()->fetch_assoc();
        
        if ($destinatario && $me) {
            $recipient_public_dh = generateDHPublic($destinatario['privatedh']);
            $sharedSecret = generateSharedSecret($recipient_public_dh, $me['privatedh']);
            $aes_key = deriveAESKey($sharedSecret);
            
            $encrypted = aesEncrypt($message, $aes_key);
            $encrypted_key = rsaEncrypt($aes_key, $destinatario['public_key']);
            $encrypted_binary = base64_decode($encrypted);
            $signature = signData($encrypted_binary, $me['privatersa']);
            
            $stmt = $conn->prepare("INSERT INTO private_messages (from_user, to_user, message, signature, session_key_encrypted) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("iisss", $user_id, $recipient_id, $encrypted, $signature, $encrypted_key);
            $stmt->execute();
            $_SESSION['success'] = "Mensagem enviada!";
        }
    }
    
    // Processar ficheiro se existir
    if (isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
        
        $file_name = $_FILES['file']['name'];
        $file_tmp = $_FILES['file']['tmp_name'];
        $file_size = $_FILES['file']['size'];
        $mime_type = mime_content_type($file_tmp);
        
        if ($file_size <= 10 * 1024 * 1024) {
            $stmt = $conn->prepare("SELECT public_key, privatedh FROM users WHERE id = ?");
            $stmt->bind_param("i", $recipient_id);
            $stmt->execute();
            $destinatario = $stmt->get_result()->fetch_assoc();
            
            $stmt = $conn->prepare("SELECT privatedh, privatersa FROM users WHERE id = ?");
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $me = $stmt->get_result()->fetch_assoc();
            
            if ($destinatario && $me) {
                $recipient_public_dh = generateDHPublic($destinatario['privatedh']);
                $sharedSecret = generateSharedSecret($recipient_public_dh, $me['privatedh']);
                $aes_key = deriveAESKey($sharedSecret);
                
                $file_content = file_get_contents($file_tmp);
                $encrypted_content = aesEncrypt($file_content, $aes_key);
                $encrypted_key = rsaEncrypt($aes_key, $destinatario['public_key']);
                $encrypted_binary = base64_decode($encrypted_content);
                $signature = signData($encrypted_binary, $me['privatersa']);
                
                $stmt = $conn->prepare("INSERT INTO shared_files (sender_id, receiver_id, file_name, file_size, mime_type, file_data_encrypted, signature, session_key_encrypted, uploaded_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                $stmt->bind_param("iisissis", $user_id, $recipient_id, $file_name, $file_size, $mime_type, $encrypted_content, $signature, $encrypted_key);
                $stmt->execute();
                $_SESSION['success'] = "Ficheiro enviado!";
            }
        }
    }
    
    header("location: ../dashboard/dashboard.php?contact=" . $recipient_id);
    exit();
}

/* =====================================================
   PROCESSAR REQUISIÇÕES POST - ENVIAR MENSAGEM DE GRUPO
===================================================== */

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'send_group_message') {
    
    $group_id = (int)$_POST['group_id'];
    $message = trim($_POST['message']);
    
    if (empty($message)) {
        $_SESSION['error'] = "Mensagem vazia!";
        header("location: ../dashboard/dashboard.php?group=" . $group_id);
        exit();
    }
    
    $stmt = $conn->prepare("
        SELECT gsk.session_key_encrypted, u.privatersa 
        FROM group_session_keys gsk
        JOIN users u ON u.id = gsk.user_id
        WHERE gsk.group_id = ? AND gsk.user_id = ?
    ");
    $stmt->bind_param("ii", $group_id, $user_id);
    $stmt->execute();
    $key_data = $stmt->get_result()->fetch_assoc();
    
    if ($key_data) {
        $group_aes_key = rsaDecrypt($key_data['session_key_encrypted'], $key_data['privatersa']);
        $encrypted_message = aesEncrypt($message, $group_aes_key);
        
        $stmt2 = $conn->prepare("SELECT privatersa FROM users WHERE id = ?");
        $stmt2->bind_param("i", $user_id);
        $stmt2->execute();
        $me = $stmt2->get_result()->fetch_assoc();
        $encrypted_binary = base64_decode($encrypted_message);
        $signature = signData($encrypted_binary, $me['privatersa']);
        
        $stmt3 = $conn->prepare("INSERT INTO group_messages (group_id, from_user, message, signature, created_at) VALUES (?, ?, ?, ?, NOW())");
        $stmt3->bind_param("iiss", $group_id, $user_id, $encrypted_message, $signature);
        $stmt3->execute();
        $_SESSION['success'] = "Mensagem enviada para o grupo!";
    }
    
    header("location: ../dashboard/dashboard.php?group=" . $group_id);
    exit();
}

/* =====================================================
   PROCESSAR REQUISIÇÕES POST - ENVIAR FICHEIRO PRIVADO
===================================================== */

// Enviar ficheiro privado (endpoint separado)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'send_private_file') {
    
    $recipient_id = (int)$_POST['recipient_id'];
    
    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        $_SESSION['error'] = "Nenhum ficheiro enviado!";
        header("location: ../dashboard/dashboard.php?contact=" . $recipient_id);
        exit();
    }
    
    $file_name = $_FILES['file']['name'];
    $file_tmp = $_FILES['file']['tmp_name'];
    $file_size = $_FILES['file']['size'];
    $mime_type = mime_content_type($file_tmp);
    
    if ($file_size > 10 * 1024 * 1024) {
        $_SESSION['error'] = "Ficheiro muito grande! Máximo 10MB";
        header("location: ../dashboard/dashboard.php?contact=" . $recipient_id);
        exit();
    }
    
    // Buscar chave pública RSA do destinatário
    $stmt = $conn->prepare("SELECT public_key FROM users WHERE id = ?");
    $stmt->bind_param("i", $recipient_id);
    $stmt->execute();
    $destinatario = $stmt->get_result()->fetch_assoc();
    
    if (!$destinatario) {
        $_SESSION['error'] = "Destinatário não encontrado!";
        header("location: ../dashboard/dashboard.php?contact=" . $recipient_id);
        exit();
    }
    
    // Gerar chave AES aleatória (NÃO usa DH)
    $aes_key = random_bytes(32);
    
    $file_content = file_get_contents($file_tmp);
    $encrypted_content = aesEncrypt($file_content, $aes_key);
    
    // Cifrar a chave AES com RSA do destinatário APENAS
    $encrypted_key = rsaEncrypt($aes_key, $destinatario['public_key']);
    
    // Assinar (opcional)
    $stmt2 = $conn->prepare("SELECT privatersa FROM users WHERE id = ?");
    $stmt2->bind_param("i", $user_id);
    $stmt2->execute();
    $me = $stmt2->get_result()->fetch_assoc();
    
    $signature = null;
    if ($me && !empty($me['privatersa'])) {
        $encrypted_binary = base64_decode($encrypted_content);
        $signature = signData($encrypted_binary, $me['privatersa']);
    }
    
    $stmt = $conn->prepare("INSERT INTO shared_files (sender_id, receiver_id, file_name, file_size, mime_type, file_data_encrypted, signature, session_key_encrypted, uploaded_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");
    $stmt->bind_param("iisissis", $user_id, $recipient_id, $file_name, $file_size, $mime_type, $encrypted_content, $signature, $encrypted_key);
    $stmt->execute();
    
    $_SESSION['success'] = "Ficheiro enviado com segurança!";
    header("location: ../dashboard/dashboard.php?contact=" . $recipient_id);
    exit();
}

/* =====================================================
   PROCESSAR REQUISIÇÕES POST - ENVIAR FICHEIRO PARA GRUPO
===================================================== */

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'send_group_file') {
    
    $group_id = (int)$_POST['group_id'];
    
    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        $_SESSION['error'] = "Nenhum ficheiro enviado!";
        header("location: ../dashboard/dashboard.php?group=" . $group_id);
        exit();
    }
    
    // Verificar se é membro do grupo
    if (!isGroupMember($group_id, $user_id)) {
        $_SESSION['error'] = "Você não é membro deste grupo!";
        header("location: ../dashboard/dashboard.php");
        exit();
    }
    
    $file_name = $_FILES['file']['name'];
    $file_tmp = $_FILES['file']['tmp_name'];
    $file_size = $_FILES['file']['size'];
    $mime_type = mime_content_type($file_tmp);
    
    if ($file_size > 10 * 1024 * 1024) {
        $_SESSION['error'] = "Ficheiro muito grande! Máximo 10MB";
        header("location: ../dashboard/dashboard.php?group=" . $group_id);
        exit();
    }
    
    // Buscar chave AES do grupo
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
        $_SESSION['error'] = "Erro ao obter chave do grupo!";
        header("location: ../dashboard/dashboard.php?group=" . $group_id);
        exit();
    }
    
    $group_aes_key = rsaDecrypt($key_data['session_key_encrypted'], $key_data['privatersa']);
    
    $file_content = file_get_contents($file_tmp);
    $encrypted_content = aesEncrypt($file_content, $group_aes_key);
    
    $stmt = $conn->prepare("INSERT INTO shared_files (sender_id, group_id, file_name, file_size, mime_type, file_data_encrypted, uploaded_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
    $stmt->bind_param("iisiss", $user_id, $group_id, $file_name, $file_size, $mime_type, $encrypted_content);
    $stmt->execute();
    
    $_SESSION['success'] = "Ficheiro enviado para o grupo!";
    header("location: ../dashboard/dashboard.php?group=" . $group_id);
    exit();
}

/* =====================================================
   REQUISIÇÕES GET (AJAX)
===================================================== */

if (isset($_GET['action']) && $_GET['action'] == 'get_users') {
    header('Content-Type: application/json');
    $users = getAllUsersExceptCurrent($user_id);
    echo json_encode($users);
    exit();
}

if (isset($_GET['action']) && $_GET['action'] == 'get_new_users') {
    header('Content-Type: application/json');
    $users = getNewUsers($user_id);
    echo json_encode($users);
    exit();
}

if (isset($_GET['action']) && $_GET['action'] == 'get_recent_contacts' && isset($_GET['days'])) {
    header('Content-Type: application/json');
    $days = (int)$_GET['days'];
    $users = getRecentContacts($user_id, $days);
    echo json_encode($users);
    exit();
}

if (isset($_GET['action']) && $_GET['action'] == 'search_users' && isset($_GET['q'])) {
    header('Content-Type: application/json');
    $search = trim($_GET['q']);
    $users = searchUsers($user_id, $search);
    echo json_encode($users);
    exit();
}

if (isset($_GET['action']) && $_GET['action'] == 'get_group_info' && isset($_GET['group_id'])) {
    header('Content-Type: application/json');
    $group_id = (int)$_GET['group_id'];
    $group = getGroupDetails($group_id);
    if ($group) {
        $group['members'] = getGroupMembers($group_id);
        $group['member_count'] = count($group['members']);
    }
    echo json_encode($group);
    exit();
}

if (isset($_GET['action']) && $_GET['action'] == 'get_user_profile' && isset($_GET['id'])) {
    header('Content-Type: application/json');
    $profile_id = (int)$_GET['id'];
    $stmt = $conn->prepare("
        SELECT id, name, user_number, email, phone, bio, city, country, 
               profile_photo, profile_photo_type, is_online, last_seen, 
               created_at, public_key
        FROM users WHERE id = ?
    ");
    $stmt->bind_param("i", $profile_id);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    echo json_encode($user);
    exit();
}

/* =====================================================
   VARIÁVEIS PARA O DASHBOARD
===================================================== */

$active_contact_id = isset($_GET['contact']) ? (int)$_GET['contact'] : null;
$messages = [];
$current_contact = null;

if ($active_contact_id) {
    $messages = get_conversation($active_contact_id);
    mark_messages_as_read($active_contact_id);
    
    $stmt = $conn->prepare("SELECT id, name, user_number, email, is_online, last_seen, profile_photo, profile_photo_type, bio, phone, city, country FROM users WHERE id = ?");
    $stmt->bind_param("i", $active_contact_id);
    $stmt->execute();
    $current_contact = $stmt->get_result()->fetch_assoc();
}



$users = getUsersWithConversation($user_id);
$unread_total = get_unread_count();
$groups_list = getUserGroups($user_id);
$new_users = getNewUsers($user_id);
?>