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

    $inputArg = escapeshellarg($inputfilepath);
    $letterArg = escapeshellarg($letter);

    $baseDir = "/var/www/boca-problem-builder";
    $logFile = $baseDir . "/zip_packages/Problem_" . trim($letter) . ".log";

    $tempZip = $inputArg . ".zip";
    $command_copy_zip = "cp $inputArg $tempZip";
    shell_exec($command_copy_zip);

    $JAVA_TL_FACTOR = 3;
    $PYTHON_TL_FACTOR = 3;

    $outputPath = "$baseDir/zip_packages/Problem_" . trim($letter) . ".zip";
    $outputFilename = "Problem_" . trim($letter) . ".zip";
    // apaga o zip de uma conversao anterior, para nunca importar um pacote velho se esta falhar
    @unlink($outputPath);

    $command = "cd $baseDir && python3 make_from_full_package.py $letterArg $tempZip $JAVA_TL_FACTOR $PYTHON_TL_FACTOR --no-backup --zip-only > " . escapeshellarg($logFile) . " 2>&1";
    $rc = -1;
    exec($command, $cmdOutput, $rc);
    @unlink($inputfilepath . ".zip");

    if ($rc !== 0) {
        return false;
    }

    return file_exists($outputPath) ? array($outputPath, $outputFilename) : false;
}

// eof
