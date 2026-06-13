<?php
session_start();
include_once "../../conf/db.php";

if (!isset($_SESSION['user_id'])) {
    exit();
}

$user_id = $_SESSION['user_id'];
$contact_id = isset($_GET['contact']) ? $_GET['contact'] : 0;

if ($contact_id > 0) {
    $stmt = $conn->prepare("
        SELECT COUNT(*) as new_messages 
        FROM messages 
        WHERE sender_id = ? AND reciver_id = ? AND is_read = 0
    ");
    $stmt->bind_param("ii", $contact_id, $user_id);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    
    echo json_encode(['new_messages' => $result['new_messages'] > 0]);
    exit();
}

echo json_encode(['new_messages' => false]);