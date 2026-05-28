<?php

// Teste de OpenSSL Ativo
echo "=== TESTE DE OPENSSL ===\n\n";

// 1. Verificar se a extensão OpenSSL está carregada
if (extension_loaded('openssl')) {
    echo "✓ OpenSSL está ATIVO (extensão carregada)\n";
} else {
    echo "✗ OpenSSL está INATIVO (extensão não carregada)\n";
}

echo "\n";

// 2. Mostrar versão do OpenSSL
if (extension_loaded('openssl')) {
    echo "Versão OpenSSL: " . OPENSSL_VERSION_TEXT . "\n";
    
}

echo "\n";

// 3. Testar se consegue gerar certificado/chave
if (extension_loaded('openssl')) {
    echo "=== TESTE DE GERAÇÃO DE CHAVE ===\n";
    
    $config = array(
        "private_key_bits" => 2048,
        "private_key_type" => OPENSSL_KEYTYPE_RSA,
    );
    
    $res = openssl_pkey_new($config);
    
    if ($res) {
        echo "✓ Conseguiu gerar nova chave privada com sucesso\n";
        openssl_pkey_free($res);
    } else {
        echo "✗ Falha ao gerar chave privada\n";
    }
}

echo "\n";

// 4. Verificar funções disponíveis
if (extension_loaded('openssl')) {
    echo "=== FUNÇÕES OPENSSL DISPONÍVEIS ===\n";
    $functions = array(
        'openssl_random_pseudo_bytes',
        'openssl_encrypt',
        'openssl_decrypt',
        'openssl_sign',
        'openssl_verify',
        'openssl_pkey_new',
        'openssl_csr_new',
    );
    
    foreach ($functions as $func) {
        if (function_exists($func)) {
            echo "✓ $func\n";
        } else {
            echo "✗ $func (não disponível)\n";
        }
    }
}

echo "\n=== FIM DO TESTE ===\n";

?>