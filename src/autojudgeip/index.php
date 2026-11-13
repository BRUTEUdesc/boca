<?php
// Diretório onde os arquivos serão armazenados
$directory_path = '/var/www/boca/src/autojudgeip/';

// Verifica se a requisição é do tipo POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Só aceita AJs da rede interna da UDESC (10.20.0.0/16). Sem isso qualquer um na internet
    // podia trocar o IP dos AJs (e quem acessa via sshpass mandaria a senha root para lá).
    $remote = $_SERVER['REMOTE_ADDR'] ?? '';
    if (!filter_var($remote, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ||
        (ip2long($remote) & ip2long('255.255.0.0')) !== ip2long('10.20.0.0')) {
        http_response_code(403);
        echo "Origem não autorizada.";
        exit;
    }

    // Token obrigatório (BOCA_AJ_TOKEN no .env). Sem token configurado, recusa (falha fechada).
    $env_token = getenv('BOCA_AJ_TOKEN') ?: '';
    if ($env_token === '' && is_readable('/var/www/boca/.env')) {
        foreach (file('/var/www/boca/.env', FILE_IGNORE_NEW_LINES) as $line) {
            if (preg_match('/^\s*BOCA_AJ_TOKEN\s*=\s*"?([^"\s]*)"?\s*$/', $line, $m)) {
                $env_token = $m[1];
            }
        }
    }
    if ($env_token === '' || !hash_equals($env_token, (string) ($_POST['token'] ?? ''))) {
        http_response_code(403);
        echo "Token inválido.";
        exit;
    }

    // Recebe o hostname e ip do corpo da requisição
    $hostname = $_POST['hostname'] ?? 'unknown';
    $ip = trim($_POST['ip'] ?? '');
    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        http_response_code(400);
        echo "IP inválido.";
        exit;
    }

    // Determina o arquivo com base no hostname recebido
    if ($hostname === 'brute-AJ1') {
        $file_path = $directory_path . 'ip_aj1';
    } elseif ($hostname === 'brute-AJ2') {
        $file_path = $directory_path . 'ip_aj2';
    } else {
        http_response_code(400);
        echo "Hostname desconhecido.";
        exit; // Early return
    }

    // Verifica se o arquivo pode ser escrito
    if (!is_writable($file_path) && !is_writable(dirname($file_path))) {
        http_response_code(500);
        echo "Erro: O arquivo ou diretório não é gravável.";
        exit; // Early return
    }

    // Prepara a string para escrever no arquivo
    $data_to_write = $ip . "\n";

    // Tenta escrever no arquivo
    if (file_put_contents($file_path, $data_to_write) === false) {
        // Se falhar, retorna um erro
        http_response_code(500);
        echo "Erro ao escrever no arquivo.";
    } else {
        // Se sucesso, retorna ok
        http_response_code(200);
        echo "Informação atualizada com sucesso.";
    }
} else {
    // Método não permitido
    echo "<script language=\"JavaScript\">\n";
    echo "document.location='" . "../index.php" . "';\n";
    echo "</script></html>\n";
    http_response_code(200);
    exit;
}
?>

