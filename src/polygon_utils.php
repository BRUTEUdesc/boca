<?php

/**
 * Polygon Utility Functions
*/

function is_polygon_package($filename)
{
    $zip = new ZipArchive();
    if ($zip->open($filename) === true) {
        // Verifica se o arquivo "problem.xml" existe dentro do ZIP
        $result = ($zip->locateName("problem.xml") !== false);
        $zip->close();
        return $result;
    }
    return false;
}

function convert_polygon_to_boca($inputfilepath, $letter)
{
    // O nome curto vira parte de caminhos (log/zip) e argumento do script: so aceita
    // [A-Za-z0-9._-], sem comecar com '.' ou '-' (barra '.', '..', '/', '\', NUL e opcoes).
    if (!is_string($letter) || !preg_match('/\A[A-Za-z0-9_][A-Za-z0-9._-]*\z/', $letter)) {
        return false;
    }

    // conversao em PHP (polygon_convert.php); fatores de TL de java e python = 3 x C/C++,
    // como no boca-problem-builder. O motivo de falha vai para o log (LOGError).
    require_once __DIR__ . '/polygon_convert.php';
    return polygon_convert_package($inputfilepath, $letter, 3, 3);
}

// eof
