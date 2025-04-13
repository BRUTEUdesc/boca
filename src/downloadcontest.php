<?php

ob_start();
session_start();
require_once("globals.php");
require_once("db.php");

if (!ValidSession()) {
    echo "<html><head><title>Download Page</title>";
    InvalidSession("downloadcontest.php");
    ForceLoad("index.php");
}

$prob = DBGetProblems($_SESSION["usertable"]["contestnumber"]);
$contest_path = "/var/www/boca/src/private/secretcontest/contest.pdf";
$is_admin = $_SESSION["usertable"]["usertype"] == "admin";

if ((!$is_admin && count($prob) == 0) || !file_exists($contest_path) || !is_readable($contest_path)) {
    IntrusionNotify("Contest not ready yet.");
    ForceLoad("index.php");
}

$filePath = '/var/www/boca/src/private/secretcontest/contest.pdf';

// Verificar se o arquivo existe
if (file_exists($filePath)) {
    // Configurar os cabeçalhos apropriados
    header('Content-Description: File Transfer');
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . basename($filePath) . '"');
    header('Expires: 0');
    header('Cache-Control: must-revalidate');
    header('Pragma: public');
    header('Content-Length: ' . filesize($filePath));

    // Limpar buffer de saída
    ob_clean();
    flush();

    // Ler o arquivo e enviar para o navegador
    readfile($filePath);

    // Finalizar script para evitar qualquer saída adicional
    exit;
} else {
    // Retornar erro se o arquivo não existir
    http_response_code(404);
    echo 'File not found';
}
