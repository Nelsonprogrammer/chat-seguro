<?php
session_start();
include_once "../../conf/db.php";
include_once "../../crypto/crypto.php";

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Não autenticado']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'send_message') {
    $sender_id = $_SESSION['user_id'];
    $recipient_id = $_POST['recipient_id'];
    $message = $_POST['message'];
    
    if (empty($message) || empty($recipient_id)) {
        echo json_encode(['success' => false, 'error' => 'Mensagem vazia']);
        exit();
    }
    
    send_message_one_to_one($sender_id, $recipient_id, $message);
    echo json_encode(['success' => true]);
    exit();
}

echo json_encode(['success' => false, 'error' => 'Requisição inválida']);