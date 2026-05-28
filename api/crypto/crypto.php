<?php
// ============================================
// CONFIGURAÇÕES GLOBAIS
// ============================================
define("p", gmp_init(65521));
define("g", gmp_init(3));

// ============================================
// FUNÇÕES BÁSICAS DE SEGURANÇA
// ============================================

function secureRandomBytes($length = 32)
{
    return openssl_random_pseudo_bytes($length);
}

// ============================================
// FUNÇÕES RSA
// ============================================

function generateRSAKeys()
{
    $config = [];
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        $config['config'] = 'C:/xampp/apache/conf/openssl.cnf';
    }
    
    $res = openssl_pkey_new([
        "private_key_bits" => 2048,
        "private_key_type" => OPENSSL_KEYTYPE_RSA,
    ] + $config);

    openssl_pkey_export($res, $privatekey, null, $config);
    $publicKey = openssl_pkey_get_details($res)["key"];

    return [
        "privateKey" => $privatekey,
        "publicKey"  => $publicKey
    ];
}

function rsaEncrypt($data, $publicKey)
{
    openssl_public_encrypt($data, $encrypted, $publicKey);
    return base64_encode($encrypted);
}

function rsaDecrypt($encryptedData, $privateKey)
{
    openssl_private_decrypt(base64_decode($encryptedData), $decrypted, $privateKey);
    return $decrypted;
}



// ============================================
// FUNÇÕES DIFFIE-HELLMAN (Par a Par)
// ============================================

function generateDHPrivate()
{
    $bytes = openssl_random_pseudo_bytes(32);
    return bin2hex($bytes);
}

function generateDHPublic($private)
{
    $private_gmp = gmp_init($private, 16);
    return gmp_strval(gmp_powm(g, $private_gmp, p));
}

function generateSharedSecret($public, $private)
{
    global $p;
    $public_gmp = gmp_init($public);
    $private_gmp = gmp_init($private, 16);
    return gmp_strval(gmp_powm($public_gmp, $private_gmp, p));
}

function deriveAESKey($sharedSecret)
{
    return hash('sha256', $sharedSecret, true);
}

// ============================================
// FUNÇÕES AES (Cifra Simétrica)
// ============================================

function aesEncrypt($data, $key)
{
    $iv = secureRandomBytes(16);
    
    if (strlen($key) !== 32) {
        $key = hash('sha256', $key, true);
    }

    $encrypted = openssl_encrypt(
        $data,
        "AES-256-CBC",
        $key,
        OPENSSL_RAW_DATA,
        $iv
    );

    return base64_encode($iv . $encrypted);
}

function aesDecrypt($encryptedData, $key)
{
    $data = base64_decode($encryptedData);
    
    if (strlen($key) !== 32) {
        $key = hash('sha256', $key, true);
    }

    $iv = substr($data, 0, 16);
    $ciphertext = substr($data, 16);

    $decrypted = openssl_decrypt(
        $ciphertext,
        "AES-256-CBC",
        $key,
        OPENSSL_RAW_DATA,
        $iv
    );
    
    return $decrypted;
}

// ============================================
// FUNÇÕES DE ASSINATURA DIGITAL
// ============================================

function signData($data, $privateKey)
{
    openssl_sign($data, $signature, $privateKey, OPENSSL_ALGO_SHA256);
    return base64_encode($signature);
}

function verifySignature($data, $signature, $publicKey)
{
    try {
        // Limpar e validar a chave pública
        $publicKey = trim($publicKey);
        
        // Verificar se a chave está vazia
        if (empty($publicKey)) {
            error_log("verifySignature: Chave pública vazia");
            return false;
        }
        
        // Tentar carregar a chave pública
        $pubKey = openssl_pkey_get_public($publicKey);
        
        if ($pubKey === false) {
            // Tentar corrigir o formato da chave
            if (strpos($publicKey, '-----BEGIN PUBLIC KEY-----') === false) {
                $publicKey = "-----BEGIN PUBLIC KEY-----\n" . $publicKey . "\n-----END PUBLIC KEY-----";
            }
            $pubKey = openssl_pkey_get_public($publicKey);
        }
        
        if ($pubKey === false) {
            error_log("verifySignature: Falha ao carregar chave pública - " . openssl_error_string());
            return false;
        }
        
        // Decodificar a assinatura
        $signature_decoded = base64_decode($signature);
        if ($signature_decoded === false) {
            error_log("verifySignature: Falha ao decodificar assinatura base64");
            openssl_free_key($pubKey);
            return false;
        }
        
        // Verificar a assinatura
        $result = openssl_verify($data, $signature_decoded, $pubKey, OPENSSL_ALGO_SHA256);
        openssl_free_key($pubKey);
        
        return $result === 1;
        
    } catch (Exception $e) {
        error_log("verifySignature: Exceção - " . $e->getMessage());
        return false;
    }
}

// ============================================
// FUNÇÕES PARA GRUPO (Diffie-Hellman Multipartidário)
// ============================================

/**
 * Gera chave DH específica para um membro no grupo
 */
function generateGroupDHKeys()
{
    $private = generateDHPrivate();
    $public = generateDHPublic($private);
    return [
        'private' => $private,
        'public' => $public
    ];
}

/**
 * Calcula segredo compartilhado entre dois membros específicos do grupo
 */
function calculatePairSharedSecret($member1_dh_private, $member2_dh_public)
{
    return generateSharedSecret($member2_dh_public, $member1_dh_private);
}

/**
 * Gera chave mestra do grupo (AES-256)
 */
function generateGroupMasterKey()
{
    return secureRandomBytes(32);
}

/**
 * Cifra a chave mestra do grupo para um membro específico usando DH
 * 
 * Funcionamento:
 * 1. Criador e membro calculam segredo compartilhado via DH
 * 2. Derivam chave de sessão AES do segredo
 * 3. Cifram a chave mestra do grupo com a chave de sessão
 */
function encryptGroupKeyForMember($group_master_key, $member_dh_private, $creator_dh_public)
{
    $sharedSecret = generateSharedSecret($creator_dh_public, $member_dh_private);
    $session_key = deriveAESKey($sharedSecret);
    return aesEncrypt($group_master_key, $session_key);
}

/**
 * Decifra a chave mestra do grupo usando DH
 */
function decryptGroupKeyForMember($encrypted_key, $member_dh_private, $creator_dh_public)
{
    $sharedSecret = generateSharedSecret($creator_dh_public, $member_dh_private);
    $session_key = deriveAESKey($sharedSecret);
    return aesDecrypt($encrypted_key, $session_key);
}

/**
 * Método alternativo: cifra chave do grupo com RSA (fallback)
 */
function encryptGroupKeyWithRSA($group_master_key, $member_public_rsa)
{
    return rsaEncrypt($group_master_key, $member_public_rsa);
}

function decryptGroupKeyWithRSA($encrypted_key, $member_private_rsa)
{
    return rsaDecrypt($encrypted_key, $member_private_rsa);
}

/**
 * Calcula o produto das chaves públicas DH de vários membros
 * Usado no protocolo DH multipartidário
 */
function calculateProductOfPublicKeys($public_keys)
{
    global $p;
    $product = gmp_init(1);
    
    foreach ($public_keys as $public_key) {
        $product = gmp_mul($product, gmp_init($public_key));
        $product = gmp_mod($product, $p);
    }
    
    return gmp_strval($product);
}

/**
 * Calcula segredo parcial para um membro no DH multipartidário
 * 
 * Para um grupo com membros {A, B, C, D}:
 * - A calcula: g^(b*c*d)^a = g^(a*b*c*d)
 * - B calcula: g^(a*c*d)^b = g^(a*b*c*d)
 * Todos chegam ao mesmo segredo!
 */
function calculatePartialSecret($my_private, $others_public_keys)
{
    global $p;
    
    $product = calculateProductOfPublicKeys($others_public_keys);
    $product_gmp = gmp_init($product);
    $private_gmp = gmp_init($my_private, 16);
    $secret = gmp_powm($product_gmp, $private_gmp, $p);
    
    return gmp_strval($secret);
}

/**
 * Gera chave do grupo usando DH multipartidário
 * (Todos os membros contribuem para a chave)
 */
function generateMultiPartyGroupKey($my_private, $all_public_keys)
{
    // Remover minha chave pública da lista
    $others_public_keys = array_values($all_public_keys);
    $partial_secret = calculatePartialSecret($my_private, $others_public_keys);
    return deriveAESKey($partial_secret);
}

/**
 * Verifica se todos os membros chegaram ao mesmo segredo
 * (Função de debug/validação)
 */
function verifyMultiPartySecret($all_private_keys, $all_public_keys)
{
    $secrets = [];
    $member_ids = array_keys($all_public_keys);
    
    foreach ($member_ids as $my_id) {
        $others = [];
        foreach ($member_ids as $other_id) {
            if ($other_id != $my_id) {
                $others[] = $all_public_keys[$other_id];
            }
        }
        $secrets[$my_id] = calculatePartialSecret($all_private_keys[$my_id], $others);
    }
    
    // Verificar se todos os segredos são iguais
    $first_secret = reset($secrets);
    foreach ($secrets as $secret) {
        if ($secret !== $first_secret) {
            return false;
        }
    }
    
    return $first_secret;
}

// ============================================
// FUNÇÕES PARA FICHEIROS
// ============================================

function encryptFile($filePath, $aesKey)
{
    $data = file_get_contents($filePath);
    return aesEncrypt($data, $aesKey);
}

function decryptFile($encryptedData, $aesKey, $outputPath)
{
    $data = aesDecrypt($encryptedData, $aesKey);
    file_put_contents($outputPath, $data);
}

// ============================================
// FUNÇÕES DE PACOTE DE MENSAGEM
// ============================================

function encryptMessagePackage($message, $receiverPublicKey, $aesKey)
{
    $encryptedMessage = aesEncrypt($message, $aesKey);
    $encryptedAESKey  = rsaEncrypt($aesKey, $receiverPublicKey);

    return json_encode([
        "key" => $encryptedAESKey,
        "msg" => $encryptedMessage
    ]);
}

function decryptMessagePackage($package, $privateKey)
{
    $data = json_decode($package, true);

    $aesKey = rsaDecrypt($data["key"], $privateKey);
    return aesDecrypt($data["msg"], $aesKey);
}

// ============================================
// FUNÇÕES DE UTILIDADE
// ============================================

function toBase64($data)
{
    return base64_encode($data);
}

function fromBase64($data)
{
    return base64_decode($data);
}

/**
 * Converte hex para binário
 */
function hex2bin_custom($hex)
{
    return hex2bin($hex);
}

/**
 * Converte binário para hex
 */
function bin2hex_custom($bin)
{
    return bin2hex($bin);
}
?>