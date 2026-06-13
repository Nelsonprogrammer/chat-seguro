<?php
session_start();
include_once "../../conf/db.php";

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Não autenticado']);
    exit();
}

$user_id = $_SESSION['user_id'];

// Se for requisição POST para offline
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_GET['action']) && $_GET['action'] == 'offline') {
    $stmt = $conn->prepare("UPDATE users SET is_online = 0, last_seen = NOW() WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    echo json_encode(['success' => true]);
    exit();
}

// Se for verificar um usuário específico
if (isset($_GET['check'])) {
    $check_id = (int)$_GET['check'];
    $stmt = $conn->prepare("SELECT is_online, last_seen FROM users WHERE id = ?");
    $stmt->bind_param("i", $check_id);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    
    // Se último visto foi há mais de 2 minutos, considerar offline
    $is_online = $result['is_online'];
    if ($result['last_seen'] && strtotime($result['last_seen']) < strtotime('-2 minutes')) {
        $is_online = 0;
        $stmt2 = $conn->prepare("UPDATE users SET is_online = 0 WHERE id = ?");
        $stmt2->bind_param("i", $check_id);
        $stmt2->execute();
    }
    
    echo json_encode(['success' => true, 'is_online' => $is_online == 1]);
    exit();
}

// Atualizar status online do usuário atual
$stmt = $conn->prepare("UPDATE users SET is_online = 1, last_seen = NOW() WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();

// Remover usuários inativos (último visto há mais de 2 minutos)
$stmt = $conn->prepare("UPDATE users SET is_online = 0 WHERE last_seen < DATE_SUB(NOW(), INTERVAL 2 MINUTE) AND id != ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();

// Buscar status online de todos os usuários
$stmt = $conn->prepare("SELECT id, is_online FROM users");
$stmt->execute();
$result = $stmt->get_result();
$online_status = [];
while ($row = $result->fetch_assoc()) {
    $online_status[$row['id']] = $row['is_online'] == 1;
}

echo json_encode(['success' => true, 'online_status' => $online_status, 'user_id' => $user_id]);
?>