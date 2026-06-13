<?php

$host = "junction.proxy.rlwy.net";
$port = 3306;
$dbname = "railway";
$user = "root";
$password = "JHRDpWikaPlSJcyiOikFpgKpzPkacmxJ";

// conexão com porta
$conn = new mysqli($host, $user, $password, $dbname, $port);

// verificar conexão
if ($conn->connect_error) {
    die("Falha na conexão: " . $conn->connect_error);
}

?>