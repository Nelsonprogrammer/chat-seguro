<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header("location: ../auth/login.php");
    exit();
}

include_once "../../conf/db.php";
include_once "../../crypto/crypto.php";

$user_id = $_SESSION['user_id'];
$file_id = (int)$_GET['id'];

// Buscar ficheiro
$stmt = $conn->prepare("
    SELECT sf.*, 
           u1.privatersa as sender_private,
           u2.privatersa as receiver_private,
           u1.privatedh as sender_dh,
           u2.privatedh as receiver_dh
    FROM shared_files sf
    JOIN users u1 ON u1.id = sf.sender_id
    JOIN users u2 ON u2.id = sf.receiver_id
    WHERE sf.id = ? AND (sf.sender_id = ? OR sf.receiver_id = ?)
");
$stmt->bind_param("iii", $file_id, $user_id, $user_id);
$stmt->execute();
$file = $stmt->get_result()->fetch_assoc();

if (!$file) {
    die("Ficheiro não encontrado");
}

// Quem vai decriptar?
// Se o user atual é o receiver, ele decripta com a chave privada dele
// Se o user atual é o sender, ele também decripta com a chave privada dele
$target_user_id = $user_id;

$stmt = $conn->prepare("SELECT privatedh, privatersa FROM users WHERE id = ?");
$stmt->bind_param("i", $target_user_id);
$stmt->execute();
$target = $stmt->get_result()->fetch_assoc();

// Descobrir o outro user para gerar o DH
$other_id = ($file['sender_id'] == $user_id) ? $file['receiver_id'] : $file['sender_id'];

$stmt = $conn->prepare("SELECT privatedh FROM users WHERE id = ?");
$stmt->bind_param("i", $other_id);
$stmt->execute();
$other = $stmt->get_result()->fetch_assoc();

if ($target && $other) {
    // Regenerar chave AES via Diffie-Hellman
    $other_public_dh = generateDHPublic($other['privatedh']);
    $sharedSecret = generateSharedSecret($other_public_dh, $target['privatedh']);
    $aes_key = deriveAESKey($sharedSecret);
    
    // Decriptar ficheiro
    $decrypted_content = aesDecrypt($file['file_data_encrypted'], $aes_key);
    
    if ($decrypted_content) {
        // Forçar download
        header('Content-Type: ' . $file['mime_type']);
        header('Content-Disposition: attachment; filename="' . $file['file_name'] . '"');
        header('Content-Length: ' . strlen($decrypted_content));
        header('Cache-Control: private, max-age=0, must-revalidate');
        header('Pragma: public');
        
        echo $decrypted_content;
        exit();
    } else {
        die("Erro ao decriptar ficheiro");
    }
} else {
    die("Erro ao obter chaves");
}
?>