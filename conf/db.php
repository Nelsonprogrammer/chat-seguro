<?php

$host = "junction.proxy.rlwy.net";
$port = 39852;
$dbname = "railway";
$user = "root";
$password = "JHRDpWikaPlSJcyiOikFpgKpzPkacmxJ";

$conn = new mysqli($host, $user, $password, $dbname, $port);

if ($conn->connect_error) {
    die("Erro DB: " . $conn->connect_error);
}

$conn->set_charset("utf8mb4");

?>
