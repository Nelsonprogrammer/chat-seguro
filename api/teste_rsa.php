<?php
//gerar para de chaves RSA
$config =[
    "private_key_bits" => 2048,
    "private_key_type" => OPENSSL_KEYTYPE_RSA,
    "config" => "C:/xampp/apache/conf/openssl.cnf",
];
$res = openssl_pkey_new($config);
var_dump($res);

//extrair chave privada
openssl_pkey_export($res, $privatekey, null, $config);



//extrair a chave publica
$publickey = openssl_pkey_get_details($res)["key"];
$mensagem = "ola eu te odeio muito";

//cifrar com a chave publica
openssl_public_encrypt($mensagem, $mensagemcifrada, $publickey);

openssl_private_decrypt($mensagemcifrada, $mensagemdecifrada, $privatekey);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Document</title>
</head>
<body>
    <p>
        <h3>Chaves</h3>
        chave privada: <?php echo htmlspecialchars($privatekey);?><br><hr>
        chave publica: <?php echo $publickey;?><br>

</p>
<h2>Mensagens</h2>
<p>Mensagem: <?php echo $mensagem;?></p>
<p>Mensagem Cifrada: <?php echo $mensagemcifrada;?></p>
<p>Mensagem deciifrada: <?php echo $mensagemdecifrada;?></p>

</body>
</html>

