<?php
// Diretório onde os arquivos serão armazenados
$directory_path = '/var/www/boca/src/autojudgeip/';

// Verifica se a requisição é do tipo POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Recebe o hostname e ip do corpo da requisição
    $hostname = $_POST['hostname'] ?? 'unknown';
    $ip = $_POST['ip'] ?? 'unknown';

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

