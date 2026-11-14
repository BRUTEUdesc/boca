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

// --- API do Polygon (https://codeforces.github.io/polygon-misc/API) ---
// A chave fica no /var/www/boca/.env (BOCA_POLYGON_KEY e BOCA_POLYGON_SECRET), lida pelo phpdotenv em private/conf.php.

// teto do zip baixado (o upload manual aceita ate 400 MB)
define('POLYGON_MAX_PACKAGE', 400 * 1024 * 1024);

// Chamada assinada. Sem $outfile devolve o 'result' do JSON; com $outfile grava o zip nele e devolve true.
// Em qualquer erro devolve false e poe a mensagem em $err.
function polygon_api($method, $params, &$err, $outfile = null)
{
    // o Polygon limita a taxa de chamadas (HTTP 429): tenta de novo com espera crescente
    for ($try = 1; ; $try++) {
        $code = 0;
        $r = polygon_api_once($method, $params, $err, $outfile, $code);
        if ($r !== false || $code != 429 || $try >= 3)
            break;
        sleep(2 * $try);
    }
    if ($r === false && $code == 429)
        $err = 'Polygon is limiting the request rate (HTTP 429): wait a minute and try again';
    return $r;
}

function polygon_api_once($method, $params, &$err, $outfile, &$code)
{
    $key = $_ENV['BOCA_POLYGON_KEY'] ?? '';
    $secret = $_ENV['BOCA_POLYGON_SECRET'] ?? '';
    if ($key === '' || $secret === '') {
        $err = 'Polygon API key not configured (BOCA_POLYGON_KEY and BOCA_POLYGON_SECRET in /var/www/boca/.env)';
        return false;
    }
    if (!function_exists('curl_init')) {
        $err = 'PHP curl extension is not installed (apt install php-curl)';
        return false;
    }
    $params['apiKey'] = $key;
    $params['time'] = (string) time();
    // apiSig = rand6 + sha512("rand6/metodo?k1=v1&k2=v2...#secret"), parametros em ordem de nome
    // (os valores aqui sao so digitos e letras, entao nao ha diferenca de encoding)
    ksort($params, SORT_STRING);
    $q = array();
    foreach ($params as $k => $v)
        $q[] = $k . '=' . $v;
    $rand = bin2hex(random_bytes(3));
    $params['apiSig'] = $rand . hash('sha512', $rand . '/' . $method . '?' . implode('&', $q) . '#' . $secret);

    $ch = curl_init('https://polygon.codeforces.com/api/' . $method);
    $opts = array(CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($params),
                  CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_TIMEOUT => ($outfile === null ? 60 : 240));
    $fp = null;
    if ($outfile !== null) {
        if (($fp = @fopen($outfile, 'wb')) === false) {
            $err = 'could not write temporary file';
            return false;
        }
        $opts[CURLOPT_FILE] = $fp;
        $opts[CURLOPT_NOPROGRESS] = false;
        $opts[CURLOPT_XFERINFOFUNCTION] = function ($ch, $dltotal, $dlnow, $ultotal, $ulnow) {
            return ($dltotal > POLYGON_MAX_PACKAGE || $dlnow > POLYGON_MAX_PACKAGE) ? 1 : 0;
        };
    } else
        $opts[CURLOPT_RETURNTRANSFER] = true;
    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch);
    $errno = curl_errno($ch);
    $cerr = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    unset($ch);
    if ($fp !== null)
        fclose($fp);
    if ($body === false) {
        $err = ($errno == CURLE_ABORTED_BY_CALLBACK) ?
            'package larger than ' . (POLYGON_MAX_PACKAGE >> 20) . ' MB' : 'could not reach Polygon: ' . $cerr;
        return false;
    }
    if ($outfile !== null) {
        if ($code == 200 && @file_get_contents($outfile, false, null, 0, 2) === 'PK')
            return true;
        // erro da API vem em JSON no lugar do zip
        $body = (string) @file_get_contents($outfile, false, null, 0, 65536);
    }
    $o = json_decode($body, true);
    if (!is_array($o) || ($o['status'] ?? '') !== 'OK') {
        $err = (is_array($o) && isset($o['comment'])) ? 'Polygon: ' . $o['comment'] : "unexpected answer from Polygon (HTTP $code)";
        return false;
    }
    if ($outfile !== null) {
        $err = 'Polygon did not send a zip file';
        return false;
    }
    return $o['result'] ?? array();
}

// Pacote linux READY mais novo do problema (o zip "full", com os testes gerados), ou null se nao houver
function polygon_linux_package($problemid, $pin, &$err)
{
    $p = array('problemId' => (string) $problemid);
    if ($pin !== '')
        $p['pin'] = $pin;
    if (($r = polygon_api('problem.packages', $p, $err)) === false)
        return null;
    $best = null;
    foreach ((array) $r as $pk) {
        if (!is_array($pk) || ($pk['type'] ?? '') !== 'linux' || ($pk['state'] ?? '') !== 'READY' || !isset($pk['id']))
            continue;
        $pk['revision'] = (int) ($pk['revision'] ?? 0);
        if ($best === null || $pk['revision'] > $best['revision'] ||
            ($pk['revision'] == $best['revision'] && $pk['id'] > $best['id']))
            $best = $pk;
    }
    if ($best === null)
        $err = 'no READY linux package (build a full package in Polygon)';
    return $best;
}

// --- Fila de importacao (admin/polygon.php enfileira, private/polygonworker.php processa em background) ---
// Um JSON por (contest BOCA, numero do problema) em private/polygonjobs/; reenfileirar o mesmo numero
// substitui o pedido. Estados: queued, running, done, failed.

function polygon_jobs_dir()
{
    $d = __DIR__ . '/private/polygonjobs';
    if (!is_dir($d))
        @mkdir($d, 0770);
    return $d;
}

// executa $fn com o lock da fila (exclusivo entre a pagina e o worker)
function polygon_jobs_locked($fn)
{
    $fp = @fopen(polygon_jobs_dir() . '/.queue.lock', 'c');
    if ($fp === false || !flock($fp, LOCK_EX))
        return false;
    try {
        return $fn();
    } finally {
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}

function polygon_job_file($contest, $number)
{
    return polygon_jobs_dir() . '/c' . (int) $contest . '-p' . (int) $number . '.json';
}

// gravacao atomica (temporario + rename): quem le nunca ve um JSON pela metade
function polygon_job_save($job)
{
    $f = polygon_job_file($job['contest'], $job['number']);
    $tmp = $f . '.' . getmypid() . '.new';
    if (@file_put_contents($tmp, json_encode($job)) === false || !@rename($tmp, $f)) {
        @unlink($tmp);
        return false;
    }
    @chmod($f, 0660);
    return true;
}

// jobs de um contest (0 = todos), ordenados pela hora em que entraram na fila
function polygon_jobs_list($contest = 0)
{
    $a = array();
    foreach (glob(polygon_jobs_dir() . '/c*-p*.json') ?: array() as $f) {
        $j = json_decode((string) @file_get_contents($f), true);
        if (is_array($j) && isset($j['contest'], $j['number'], $j['state'], $j['queued']) && ($contest == 0 || $j['contest'] == $contest))
            $a[] = $j;
    }
    usort($a, function ($x, $y) { return ($x['queued'] <=> $y['queued']) ?: ($x['number'] <=> $y['number']); });
    return $a;
}

// enfileira; devolve '' ou o motivo da recusa (o mesmo numero ja esta sendo importado)
function polygon_job_enqueue($job)
{
    return polygon_jobs_locked(function () use ($job) {
        $old = json_decode((string) @file_get_contents(polygon_job_file($job['contest'], $job['number'])), true);
        if (is_array($old) && ($old['state'] ?? '') == 'running')
            return 'problem ' . (int) $job['number'] . ' is being imported right now';
        $job['state'] = 'queued';
        $job['msg'] = '';
        $job['tries'] = 0;
        $job['queued'] = time();
        return polygon_job_save($job) ? '' : 'could not write the import queue (' . polygon_jobs_dir() . ')';
    });
}

// pega o proximo job da fila e o marca running; null se nao houver
function polygon_job_next()
{
    $r = polygon_jobs_locked(function () {
        foreach (polygon_jobs_list() as $j) {
            if ($j['state'] != 'queued')
                continue;
            $j['state'] = 'running';
            $j['tries'] = (int) ($j['tries'] ?? 0) + 1;
            $j['started'] = time();
            return polygon_job_save($j) ? $j : null;
        }
        return null;
    });
    return is_array($r) ? $r : null;
}

// fecha o job (done/failed); so grava se ainda for o mesmo pedido e estiver running
function polygon_job_finish($job, $state, $msg)
{
    polygon_jobs_locked(function () use ($job, $state, $msg) {
        $cur = json_decode((string) @file_get_contents(polygon_job_file($job['contest'], $job['number'])), true);
        if (!is_array($cur) || ($cur['queued'] ?? 0) != $job['queued'] || ($cur['state'] ?? '') != 'running')
            return;
        $cur['state'] = $state;
        $cur['msg'] = $msg;
        $cur['finished'] = time();
        unset($cur['pin']);
        polygon_job_save($cur);
    });
}

// so com o lock do worker: job running e resto de worker morto; volta para a fila uma vez, depois failed
function polygon_jobs_recover()
{
    polygon_jobs_locked(function () {
        foreach (polygon_jobs_list() as $j) {
            if ($j['state'] != 'running')
                continue;
            if ((int) ($j['tries'] ?? 0) >= 2) {
                $j['state'] = 'failed';
                $j['msg'] = 'worker interrupted twice';
                $j['finished'] = time();
                unset($j['pin']);
            } else
                $j['state'] = 'queued';
            polygon_job_save($j);
        }
    });
}

function polygon_jobs_pending()
{
    foreach (polygon_jobs_list() as $j)
        if ($j['state'] == 'queued')
            return true;
    return false;
}

// apaga os jobs ja terminados do contest
function polygon_jobs_clear($contest)
{
    polygon_jobs_locked(function () use ($contest) {
        foreach (polygon_jobs_list($contest) as $j)
            if ($j['state'] == 'done' || $j['state'] == 'failed')
                @unlink(polygon_job_file($j['contest'], $j['number']));
    });
}

// lock de "um worker por vez": devolve o handle ou false se outro worker ja o tem
function polygon_worker_lock()
{
    $fp = @fopen(polygon_jobs_dir() . '/.worker.lock', 'c');
    if ($fp === false)
        return false;
    if (!flock($fp, LOCK_EX | LOCK_NB)) {
        fclose($fp);
        return false;
    }
    return $fp;
}

function polygon_worker_unlock($fp)
{
    flock($fp, LOCK_UN);
    fclose($fp);
}

// dispara o worker em background; se ja houver um rodando, o novo sai na hora e o antigo pega os jobs novos
function polygon_start_worker()
{
    shell_exec(escapeshellarg(PHP_BINDIR . '/php') . ' ' . escapeshellarg(__DIR__ . '/private/polygonworker.php') .
               ' < /dev/null > /dev/null 2>&1 &');
}

// eof
