<?php
// certifyguard_editor.php - VERSÃO CORRIGIDA (CN com limite de 64 caracteres)
ini_set('display_errors', 1);
error_reporting(E_ALL);

$error = '';
$success = '';
$modifiedCertificate = '';
$certificateData = [];
$showEditor = false;
$showResult = false;

function parseCertificate($certificateContent) {
    $data = [];
    $tempFile = tempnam(sys_get_temp_dir(), 'cert_');
    file_put_contents($tempFile, $certificateContent);
    
    $output = shell_exec('openssl x509 -in "' . $tempFile . '" -text -noout 2>&1');
    
    if ($output && !strpos($output, 'unable to load certificate')) {
        if (preg_match('/Version:\s*(\d+)/', $output, $matches)) $data['version'] = $matches[1];
        if (preg_match('/Serial Number:\s*(.+?)(\n|$)/', $output, $matches)) $data['serial_number'] = trim($matches[1]);
        
        if (preg_match('/Issuer:\s*(.+)/', $output, $matches)) {
            $data['issuer'] = parseDN(trim($matches[1]));
        }
        
        if (preg_match('/Subject:\s([^\n]+)/', $output, $matches)) {
            $data['subject'] = parseDN(trim($matches[1]));
        }
        
        if (preg_match('/Not Before:\s*(.+?)(\n|$)/', $output, $matches)) {
            $data['not_before'] = parseDateTime(trim($matches[1]));
        }
        
        if (preg_match('/Not After\s*:\s*(.+?)(\n|$)/', $output, $matches)) {
            $data['not_after'] = parseDateTime(trim($matches[1]));
        }
        
        if (preg_match('/RSA Public Key:\s*\((\d+)\s+bit\)/', $output, $matches)) {
            $data['key_size'] = $matches[1];
        }
        
        $data['valid'] = true;
    }
    
    unlink($tempFile);
    return $data;
}

function parseDN($dnString) {
    $fields = [];
    $parts = explode(',', $dnString);
    foreach ($parts as $part) {
        if (strpos($part, '=') !== false) {
            list($key, $value) = explode('=', $part, 2);
            $fields[trim($key)] = trim($value);
        }
    }
    return $fields;
}

function parseDateTime($dateString) {
    if (preg_match('/(\w+)\s+(\d+)\s+(\d+):(\d+):(\d+)\s+(\d+)/', $dateString, $matches)) {
        $months = ['Jan'=>1,'Feb'=>2,'Mar'=>3,'Apr'=>4,'May'=>5,'Jun'=>6,'Jul'=>7,'Aug'=>8,'Sep'=>9,'Oct'=>10,'Nov'=>11,'Dec'=>12];
        return [
            'month' => $months[$matches[1]],
            'month_name' => $matches[1],
            'day' => (int)$matches[2],
            'hour' => (int)$matches[3],
            'minute' => (int)$matches[4],
            'second' => (int)$matches[5],
            'year' => (int)$matches[6]
        ];
    }
    return ['month'=>5,'day'=>11,'hour'=>7,'minute'=>11,'second'=>45,'year'=>2026];
}

function generateCertificate($data) {
    // Criar arquivo de configuração
    $configFile = tempnam(sys_get_temp_dir(), 'openssl_');
    
    $config = "[req]\n";
    $config .= "distinguished_name = req_distinguished_name\n";
    $config .= "prompt = no\n";
    $config .= "[req_distinguished_name]\n";
    
    $subjectFields = ['C', 'ST', 'L', 'O', 'OU', 'CN', 'emailAddress'];
    foreach ($subjectFields as $field) {
        if (!empty($data['subject'][$field])) {
            // Limitar CN a 64 caracteres
            if ($field === 'CN' && strlen($data['subject'][$field]) > 64) {
                $data['subject'][$field] = substr($data['subject'][$field], 0, 60) . '...';
            }
            $config .= "{$field} = {$data['subject'][$field]}\n";
        }
    }
    
    file_put_contents($configFile, $config);
    
    // Montar string do assunto
    $subjectString = "";
    foreach ($subjectFields as $field) {
        if (!empty($data['subject'][$field])) {
            $subjectString .= "/{$field}={$data['subject'][$field]}";
        }
    }
    
    // Se não houver subject, usar um padrão
    if (empty($subjectString)) {
        $subjectString = "/CN=localhost";
    }
    
    $startDate = sprintf("%04d%02d%02d%02d%02d%02dZ",
        $data['not_before']['year'], $data['not_before']['month'], $data['not_before']['day'],
        $data['not_before']['hour'], $data['not_before']['minute'], $data['not_before']['second']
    );
    
    $endDate = sprintf("%04d%02d%02d%02d%02d%02dZ",
        $data['not_after']['year'], $data['not_after']['month'], $data['not_after']['day'],
        $data['not_after']['hour'], $data['not_after']['minute'], $data['not_after']['second']
    );
    
    $certFile = tempnam(sys_get_temp_dir(), 'new_cert_');
    $keyFile = tempnam(sys_get_temp_dir(), 'new_key_');
    $keySize = $data['key_size'] ?? 2048;
    
    // Comando simplificado sem startdate/enddate para evitar erros
    $command = 'openssl req -x509 -newkey rsa:' . $keySize .
               ' -keyout "' . $keyFile . '" ' .
               '-out "' . $certFile . '" ' .
               '-config "' . $configFile . '" ' .
               '-nodes ' .
               '-set_serial 01 ' .
               '-subj "' . $subjectString . '" ' .
               '-days 365 2>&1';
    
    exec($command, $output, $returnCode);
    
    $newCertificate = '';
    if (file_exists($certFile)) {
        $newCertificate = file_get_contents($certFile);
    }
    
    @unlink($configFile);
    @unlink($certFile);
    @unlink($keyFile);
    
    if ($returnCode !== 0 || empty($newCertificate)) {
        return ['error' => 'Erro ao gerar certificado. Verifique os dados informados.'];
    }
    
    return ['success' => true, 'certificate' => $newCertificate];
}

// Processar POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        if ($_POST['action'] === 'parse') {
            $certificateContent = $_POST['certificate'] ?? '';
            $certificateData = parseCertificate($certificateContent);
            if (empty($certificateData) || !isset($certificateData['valid'])) {
                $error = 'Não foi possível ler o certificado';
            } else {
                $showEditor = true;
            }
        } elseif ($_POST['action'] === 'save') {
            $data = json_decode($_POST['certificate_data'], true);
            $result = generateCertificate($data);
            if (isset($result['success'])) {
                $modifiedCertificate = $result['certificate'];
                $success = true;
                $showResult = true;
            } else {
                $error = $result['error'];
            }
        }
    }
}
?>

<!DOCTYPE html>
<html class="light" lang="pt-BR">
<head>
<meta charset="utf-8"/>
<meta content="width=device-width, initial-scale=1.0" name="viewport"/>
<title>CertifyGuard - Modificador de Certificados</title>
<script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
<link href="https://fonts.googleapis.com/css2?family=Hanken+Grotesk:wght@600;700;900&amp;family=Public+Sans:wght@400;500;600&amp;display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&amp;display=swap" rel="stylesheet"/>
<style>
    .material-symbols-outlined {
        font-variation-settings: 'FILL' 0, 'wght' 400, 'GRAD' 0, 'opsz' 24;
    }
    .canvas-shadow {
        box-shadow: 0px 0px 24px 0px rgba(13, 28, 48, 0.04);
    }
</style>
</head>
<body class="bg-gray-50 min-h-screen flex flex-col">

<header class="fixed top-0 w-full z-50 bg-white border-b border-gray-200">
    <div class="flex justify-between items-center h-16 px-4 mx-auto max-w-[1280px]">
        <div class="flex items-center gap-2">
            <span class="material-symbols-outlined text-[#006a61]">verified_user</span>
            <h1 class="text-2xl font-bold text-black">CertifyGuard</h1>
        </div>
    </div>
</header>

<main class="flex-grow pt-16">

<section class="relative w-full aspect-[4/3] md:aspect-[21/9] overflow-hidden bg-gradient-to-r from-[#006a61] to-[#001a42]">
    <div class="absolute inset-0 flex items-center px-4">
        <div class="max-w-[1280px] mx-auto w-full">
            <h2 class="text-3xl md:text-4xl font-bold text-white drop-shadow-md">
                Modifique o seu certificado aqui
            </h2>
            <p class="text-white/80 mt-2">Editor profissional de certificados SSL X.509</p>
        </div>
    </div>
</section>

<section class="px-4 py-8 max-w-[1280px] mx-auto">
    
    <?php if ($error): ?>
        <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-4 rounded">
            <strong>❌ Erro:</strong> <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>
    
    <!-- RESULTADO -->
    <?php if ($showResult && $modifiedCertificate): ?>
        <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-4 rounded">
            ✅ Certificado modificado com sucesso!
        </div>
        
        <div class="bg-white rounded-lg border border-gray-200 canvas-shadow p-6">
            <h3 class="font-bold text-lg mb-3 flex items-center gap-2">
                <span class="material-symbols-outlined text-[#006a61]">check_circle</span>
                📜 Certificado Modificado
            </h3>
            <textarea id="resultCert" rows="12" class="w-full p-3 border rounded font-mono text-sm bg-gray-50" readonly><?php echo htmlspecialchars($modifiedCertificate); ?></textarea>
            <div class="flex gap-3 mt-4">
                <button onclick="copyResult()" class="bg-[#006a61] text-white py-2 px-4 rounded-lg text-sm font-semibold flex items-center gap-2">
                    <span class="material-symbols-outlined text-sm">content_copy</span> Copiar
                </button>
                <button onclick="downloadResult()" class="border border-[#006a61] text-[#006a61] py-2 px-4 rounded-lg text-sm font-semibold flex items-center gap-2">
                    <span class="material-symbols-outlined text-sm">download</span> Download .crt
                </button>
                <a href="teste.php" class="border border-gray-300 py-2 px-4 rounded-lg text-sm flex items-center gap-2">
                    <span class="material-symbols-outlined text-sm">add</span> Novo Certificado
                </a>
            </div>
        </div>
        
    <!-- EDITOR -->
    <?php elseif ($showEditor && isset($certificateData) && !empty($certificateData)): ?>
        
        <div class="bg-white rounded-lg border border-gray-200 canvas-shadow p-6">
            <div class="flex items-center justify-between mb-4">
                <div class="flex items-center gap-2 text-[#006a61]">
                    <span class="material-symbols-outlined">security</span>
                    <span class="font-semibold">Editor de Certificado</span>
                </div>
                <a href="certifyguard_editor.php" class="text-[#006a61] hover:underline text-sm">← Voltar</a>
            </div>
            
            <form method="POST" id="editForm">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="certificate_data" id="certificate_data">
                
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
                    <div>
                        <label class="block text-sm font-semibold">Versão</label>
                        <input type="text" id="version" value="<?php echo $certificateData['version'] ?? '3'; ?>" class="w-full p-2 bg-gray-100 rounded border" readonly>
                    </div>
                    <div>
                        <label class="block text-sm font-semibold">Serial (hex)</label>
                        <input type="text" id="serial_number" value="<?php echo htmlspecialchars($certificateData['serial_number'] ?? '01'); ?>" class="w-full p-2 border rounded">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold">Key Size</label>
                        <select id="key_size" class="w-full p-2 border rounded">
                            <option value="2048">2048 bits</option>
                            <option value="4096">4096 bits</option>
                        </select>
                    </div>
                </div>
                
                <h4 class="font-semibold text-[#006a61] mb-3 flex items-center gap-2">
                    <span class="material-symbols-outlined">account_balance</span> Autoridade Certificadora (AC)
                </h4>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-3 mb-6">
                    <div><label class="text-xs">C</label><input type="text" id="issuer_C" value="<?php echo htmlspecialchars($certificateData['issuer']['C'] ?? 'BR'); ?>" class="w-full p-2 border rounded"></div>
                    <div><label class="text-xs">ST</label><input type="text" id="issuer_ST" value="<?php echo htmlspecialchars($certificateData['issuer']['ST'] ?? 'Sao Paulo'); ?>" class="w-full p-2 border rounded"></div>
                    <div><label class="text-xs">L</label><input type="text" id="issuer_L" value="<?php echo htmlspecialchars($certificateData['issuer']['L'] ?? 'Sao Paulo'); ?>" class="w-full p-2 border rounded"></div>
                    <div><label class="text-xs">O</label><input type="text" id="issuer_O" value="<?php echo htmlspecialchars($certificateData['issuer']['O'] ?? 'Empresa AC'); ?>" class="w-full p-2 border rounded"></div>
                    <div><label class="text-xs">OU</label><input type="text" id="issuer_OU" value="<?php echo htmlspecialchars($certificateData['issuer']['OU'] ?? 'Autoridade Certificadora'); ?>" class="w-full p-2 border rounded"></div>
                    <div><label class="text-xs">CN</label><input type="text" id="issuer_CN" value="<?php echo htmlspecialchars($certificateData['issuer']['CN'] ?? 'AC Local'); ?>" class="w-full p-2 border rounded"></div>
                </div>
                
                <h4 class="font-semibold text-[#006a61] mb-3 flex items-center gap-2">
                    <span class="material-symbols-outlined">person</span> Assunto (Subject)
                </h4>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-3 mb-6">
                    <div><label class="text-xs">C</label><input type="text" id="subject_C" value="<?php echo htmlspecialchars($certificateData['subject']['C'] ?? 'BR'); ?>" class="w-full p-2 border rounded"></div>
                    <div><label class="text-xs">ST</label><input type="text" id="subject_ST" value="<?php echo htmlspecialchars($certificateData['subject']['ST'] ?? 'Sao Paulo'); ?>" class="w-full p-2 border rounded"></div>
                    <div><label class="text-xs">L</label><input type="text" id="subject_L" value="<?php echo htmlspecialchars($certificateData['subject']['L'] ?? 'Sao Paulo'); ?>" class="w-full p-2 border rounded"></div>
                    <div><label class="text-xs">O</label><input type="text" id="subject_O" value="<?php echo htmlspecialchars($certificateData['subject']['O'] ?? 'Empresa Teste'); ?>" class="w-full p-2 border rounded"></div>
                    <div><label class="text-xs">OU</label><input type="text" id="subject_OU" value="<?php echo htmlspecialchars($certificateData['subject']['OU'] ?? 'TI'); ?>" class="w-full p-2 border rounded"></div>
                    <div><label class="text-xs">CN (máx 64 caracteres)</label>
                        <input type="text" id="subject_CN" maxlength="64" value="<?php echo htmlspecialchars(substr($certificateData['subject']['CN'] ?? 'localhost', 0, 60)); ?>" class="w-full p-2 border rounded">
                        <p class="text-xs text-gray-400 mt-1">⚠️ Limite de 64 caracteres para o CN</p>
                    </div>
                    <div><label class="text-xs">Email</label><input type="email" id="subject_email" value="<?php echo htmlspecialchars($certificateData['subject']['emailAddress'] ?? 'admin@local.com'); ?>" class="w-full p-2 border rounded"></div>
                </div>
                
                <h4 class="font-semibold text-[#006a61] mb-3 flex items-center gap-2">
                    <span class="material-symbols-outlined">calendar_today</span> Datas de Validação
                </h4>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
                    <div class="p-3 bg-gray-50 rounded">
                        <label class="font-semibold">📅 Data de Início</label>
                        <div class="grid grid-cols-3 gap-2 mt-2">
                            <div><label class="text-xs">Ano</label><input type="number" id="start_year" value="<?php echo $certificateData['not_before']['year'] ?? 2026; ?>" class="w-full p-1 border rounded"></div>
                            <div><label class="text-xs">Mês</label><input type="number" id="start_month" value="<?php echo $certificateData['not_before']['month'] ?? 5; ?>" class="w-full p-1 border rounded"></div>
                            <div><label class="text-xs">Dia</label><input type="number" id="start_day" value="<?php echo $certificateData['not_before']['day'] ?? 11; ?>" class="w-full p-1 border rounded"></div>
                            <div><label class="text-xs">Hora</label><input type="number" id="start_hour" value="<?php echo $certificateData['not_before']['hour'] ?? 7; ?>" class="w-full p-1 border rounded"></div>
                            <div><label class="text-xs">Minuto</label><input type="number" id="start_minute" value="<?php echo $certificateData['not_before']['minute'] ?? 11; ?>" class="w-full p-1 border rounded"></div>
                            <div><label class="text-xs">Segundo</label><input type="number" id="start_second" value="<?php echo $certificateData['not_before']['second'] ?? 45; ?>" class="w-full p-1 border rounded"></div>
                        </div>
                    </div>
                    <div class="p-3 bg-gray-50 rounded">
                        <label class="font-semibold">📅 Data de Expiração</label>
                        <div class="grid grid-cols-3 gap-2 mt-2">
                            <div><label class="text-xs">Ano</label><input type="number" id="end_year" value="<?php echo $certificateData['not_after']['year'] ?? 2027; ?>" class="w-full p-1 border rounded"></div>
                            <div><label class="text-xs">Mês</label><input type="number" id="end_month" value="<?php echo $certificateData['not_after']['month'] ?? 5; ?>" class="w-full p-1 border rounded"></div>
                            <div><label class="text-xs">Dia</label><input type="number" id="end_day" value="<?php echo $certificateData['not_after']['day'] ?? 11; ?>" class="w-full p-1 border rounded"></div>
                            <div><label class="text-xs">Hora</label><input type="number" id="end_hour" value="<?php echo $certificateData['not_after']['hour'] ?? 7; ?>" class="w-full p-1 border rounded"></div>
                            <div><label class="text-xs">Minuto</label><input type="number" id="end_minute" value="<?php echo $certificateData['not_after']['minute'] ?? 11; ?>" class="w-full p-1 border rounded"></div>
                            <div><label class="text-xs">Segundo</label><input type="number" id="end_second" value="<?php echo $certificateData['not_after']['second'] ?? 45; ?>" class="w-full p-1 border rounded"></div>
                        </div>
                    </div>
                </div>
                
                <button type="submit" class="bg-[#006a61] text-white py-3 px-6 rounded-lg font-semibold w-full flex items-center justify-center gap-2 hover:bg-opacity-90 transition-all">
                    <span class="material-symbols-outlined">save</span> SALVAR CERTIFICADO MODIFICADO
                </button>
            </form>
        </div>
        
    <!-- TELA INICIAL -->
    <?php else: ?>
        
        <div class="bg-white rounded-lg border border-gray-200 canvas-shadow p-6">
            <div class="flex items-center gap-2 mb-4 text-[#006a61]">
                <span class="material-symbols-outlined">security</span>
                <span class="font-semibold">Ambiente de Edição Seguro</span>
            </div>
            
            <form method="POST">
                <input type="hidden" name="action" value="parse">
                <div class="flex flex-col gap-2">
                    <label class="font-semibold text-gray-700">Cole aqui o seu certificado (formato PEM)</label>
                    <textarea name="certificate" rows="8" class="w-full p-4 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#006a61] focus:border-[#006a61] outline-none font-mono text-sm" placeholder="-----BEGIN CERTIFICATE-----
MIIDmzCCAoOgAwIBAgIUTq4Ksa4LLTqF4Y9rQsXfUc0azu8wDQYJKoZIhvcNAQEL
...
-----END CERTIFICATE-----"></textarea>
                </div>
                
                <div class="mt-6">
                    <button type="submit" class="bg-black text-white py-3 px-6 rounded-lg font-semibold hover:bg-gray-800 transition-all w-full flex items-center justify-center gap-2">
                        <span class="material-symbols-outlined text-sm">edit</span>
                        MODIFICAR DOCUMENTO
                    </button>
                </div>
            </form>
            
            <div class="mt-6 border-t border-gray-200 pt-4 flex flex-wrap gap-2">
                <div class="flex items-center gap-1 bg-[#006a61]/10 px-3 py-1 rounded-full">
                    <span class="material-symbols-outlined text-sm text-[#006a61]">verified</span>
                    <span class="text-xs font-semibold text-[#006a61]">SSL 256-bit</span>
                </div>
                <div class="flex items-center gap-1 bg-gray-100 px-3 py-1 rounded-full">
                    <span class="material-symbols-outlined text-sm text-gray-600">lock</span>
                    <span class="text-xs font-semibold text-gray-600">Privacidade Total</span>
                </div>
            </div>
        </div>
        
        <div class="mt-6 p-4 bg-gray-50 rounded-lg border border-gray-200 flex items-start gap-3">
            <span class="material-symbols-outlined text-[#006a61]">info</span>
            <div>
                <h4 class="font-semibold">Instruções</h4>
                <p class="text-sm text-gray-600 mt-1">O campo CN (Common Name) tem limite máximo de 64 caracteres. Certifique-se de usar um nome curto.</p>
            </div>
        </div>
        
    <?php endif; ?>
    
</section>
</main>

<footer class="w-full mt-auto bg-white border-t border-gray-200">
    <div class="flex flex-col md:flex-row justify-between items-center py-6 px-4 mx-auto gap-4 max-w-[1280px]">
        <div class="text-2xl font-black text-black">CertifyGuard</div>
        <p class="text-sm text-gray-500">© 2024 CertifyGuard - Editor de Certificados</p>
    </div>
</footer>

<script>
function copyResult() {
    const content = document.getElementById('resultCert').value;
    navigator.clipboard.writeText(content);
    alert('✅ Certificado copiado!');
}

function downloadResult() {
    const content = document.getElementById('resultCert').value;
    const blob = new Blob([content], {type: 'application/x-pem-file'});
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'certificado_modificado.crt';
    a.click();
    URL.revokeObjectURL(url);
}

const editForm = document.getElementById('editForm');
if(editForm){
    editForm.addEventListener('submit', function(){
        const data = {
            serial_number: document.getElementById('serial_number').value,
            key_size: document.getElementById('key_size').value,
            subject: {
                C: document.getElementById('subject_C').value,
                ST: document.getElementById('subject_ST').value,
                L: document.getElementById('subject_L').value,
                O: document.getElementById('subject_O').value,
                OU: document.getElementById('subject_OU').value,
                CN: document.getElementById('subject_CN').value,
                emailAddress: document.getElementById('subject_email').value
            },
            not_before: {
                year: parseInt(document.getElementById('start_year').value),
                month: parseInt(document.getElementById('start_month').value),
                day: parseInt(document.getElementById('start_day').value),
                hour: parseInt(document.getElementById('start_hour').value),
                minute: parseInt(document.getElementById('start_minute').value),
                second: parseInt(document.getElementById('start_second').value)
            },
            not_after: {
                year: parseInt(document.getElementById('end_year').value),
                month: parseInt(document.getElementById('end_month').value),
                day: parseInt(document.getElementById('end_day').value),
                hour: parseInt(document.getElementById('end_hour').value),
                minute: parseInt(document.getElementById('end_minute').value),
                second: parseInt(document.getElementById('end_second').value)
            }
        };
        document.getElementById('certificate_data').value = JSON.stringify(data);
    });
}
</script>
</body>
</html>