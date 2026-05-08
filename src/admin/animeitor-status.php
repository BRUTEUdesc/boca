<?php
session_start();

// Validação leve de autenticação (opcional)
if (!isset($_SESSION["usertable"]) || $_SESSION["usertable"]["usertype"] !== 'admin') {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(["status" => "Unauthorized", "color" => "gray"]);
    exit;
}

function getAnimeitorStatus()
{
    $output = shell_exec("sudo /usr/local/bin/check-animeitor-status 2>&1");
    if (trim($output) === 'true') {
        return ['status' => 'Running', 'color' => 'green'];
    } else {
        return ['status' => 'Stopped', 'color' => 'red'];
    }
}

header('Content-Type: application/json');
echo json_encode(getAnimeitorStatus());
