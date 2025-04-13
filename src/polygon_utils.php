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
    $inputArg = escapeshellarg($inputfilepath);
    $letterArg = escapeshellarg($letter);

    $baseDir = "/var/www/boca-problem-builder";
    $logFile = $baseDir . "/zip_packages/Problem_" . trim($letter) . ".log";

    $tempZip = $inputArg . ".zip";
    $command_copy_zip = "cp $inputArg $tempZip";
    shell_exec($command_copy_zip);

	$JAVA_TL_FACTOR = 3;
	$PYTHON_TL_FACTOR = 3;

    $command = "cd $baseDir && python3 make_from_full_package.py $letterArg $tempZip $JAVA_TL_FACTOR $PYTHON_TL_FACTOR > " . $logFile . " 2>&1";
    shell_exec($command);

    $outputPath = "$baseDir/zip_packages/Problem_" . trim($letter) . ".zip";
    $outputFilename = "Problem_" . trim($letter) . ".zip";

    return file_exists($outputPath) ? array($outputPath, $outputFilename) : false;
}

// eof
