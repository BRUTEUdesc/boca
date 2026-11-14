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

$contest = (int)$_SESSION["usertable"]["contestnumber"];
$prob = DBGetProblems($_SESSION["usertable"]["contestnumber"]);
$is_admin = $_SESSION["usertable"]["usertype"] == "admin";

// o PDF fica no banco (contestpdftable); time so baixa depois que os problemas aparecem
ob_clean();
if ((!$is_admin && count($prob) == 0) || !DBContestPdfSend($contest, 'en', 'contest-en.pdf')) {
    // ainda não liberado ou sem PDF: não é intrusão, só avisa (sem derrubar a sessão)
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Contest PDF not available yet.";
}
exit;
