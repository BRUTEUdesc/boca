<?php
session_start();
require_once("../globals.php");
require_once("../db.php");

// Mesma checagem das outras páginas do admin (sessão válida no banco + tipo admin)
if (!ValidSession() || $_SESSION["usertable"]["usertype"] !== 'admin') {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(["status" => "Unauthorized", "color" => "gray"]);
    exit;
}
session_write_close();

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
