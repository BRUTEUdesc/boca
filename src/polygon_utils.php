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
// Tabela polygonjobtable, uma linha por (contest BOCA, numero do problema): reenfileirar o mesmo numero
// substitui o pedido. Estados: queued, running, done, failed. Criada por createdb.php (banco novo) ou
// private/migratedb.php (banco existente). Os jobs tem o formato de array usado pela pagina e pelo worker.

function DBCreatePolygonJobTable()
{
    $c = DBConnect();
    $conf = globalconf();
    if ($conf["dbuser"] == "")
        $conf["dbuser"] = "bocauser";
    DBExec($c, "
CREATE TABLE IF NOT EXISTS \"polygonjobtable\" (
\"contestnumber\" int4 NOT NULL,		-- (id do concurso)
\"problemnumber\" int4 NOT NULL,		-- (numero do problema no BOCA)
\"problemname\" varchar(20) NOT NULL,		-- (nome curto: a letra)
\"polygonproblem\" int4 NOT NULL,		-- (id do problema no Polygon)
\"polygonpin\" varchar(32) DEFAULT '' NOT NULL,	-- (pin do Polygon; apagado ao terminar)
\"usernumber\" int4 NOT NULL,			-- (admin que pediu)
\"usersitenumber\" int4 NOT NULL,
\"username\" varchar(20) NOT NULL,
\"jobstate\" varchar(10) NOT NULL,		-- (queued, running, done, failed)
\"jobmsg\" text DEFAULT '' NOT NULL,
\"jobtries\" int4 DEFAULT 0 NOT NULL,
\"queuedtime\" int4 NOT NULL,
\"startedtime\" int4 DEFAULT 0 NOT NULL,
\"finishedtime\" int4 DEFAULT 0 NOT NULL,
\"updatetime\" int4 DEFAULT EXTRACT(EPOCH FROM now()) NOT NULL, -- (indica a ultima atualizacao no registro)
CONSTRAINT \"polygonjob_pkey\" PRIMARY KEY (\"contestnumber\", \"problemnumber\"),
CONSTRAINT \"contest_fk\" FOREIGN KEY (\"contestnumber\")
	REFERENCES \"contesttable\" (\"contestnumber\")
	ON DELETE CASCADE ON UPDATE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)", "DBCreatePolygonJobTable(create table)");
    DBExec($c, "REVOKE ALL ON \"polygonjobtable\" FROM PUBLIC", "DBCreatePolygonJobTable(revoke public)");
    DBExec($c, "GRANT ALL ON \"polygonjobtable\" TO \"" . $conf["dbuser"] . "\"", "DBCreatePolygonJobTable(grant bocauser)");
}

// consulta parametrizada na conexao do BOCA; false (e log) em erro, sem abortar a pagina/o worker
function polygon_db($sql, $params = array(), $c = null)
{
    if ($c === null)
        $c = DBConnect();
    $r = @pg_query_params($c, $sql, $params);
    if ($r === false)
        LOGError("Polygon import queue: SQL error (" . pg_last_error($c) . ")");
    return $r;
}

function polygon_job_row($a)
{
    return array('contest' => (int) $a['contestnumber'], 'number' => (int) $a['problemnumber'],
                 'name' => $a['problemname'], 'pid' => $a['polygonproblem'], 'pin' => $a['polygonpin'],
                 'usernumber' => (int) $a['usernumber'], 'usersitenumber' => (int) $a['usersitenumber'],
                 'username' => $a['username'], 'state' => $a['jobstate'], 'msg' => $a['jobmsg'],
                 'tries' => (int) $a['jobtries'], 'queued' => (int) $a['queuedtime'],
                 'started' => (int) $a['startedtime'], 'finished' => ((int) $a['finishedtime'] > 0) ? (int) $a['finishedtime'] : null);
}

// jobs de um contest (0 = todos), ordenados pela hora em que entraram na fila
function polygon_jobs_list($contest = 0)
{
    $r = ($contest == 0) ? polygon_db("select * from polygonjobtable order by queuedtime, problemnumber") :
        polygon_db("select * from polygonjobtable where contestnumber=\$1 order by queuedtime, problemnumber", array((int) $contest));
    $a = array();
    if ($r !== false)
        while (($row = pg_fetch_assoc($r)) !== false)
            $a[] = polygon_job_row($row);
    return $a;
}

// enfileira; devolve '' ou o motivo da recusa (o mesmo numero ja esta sendo importado)
function polygon_job_enqueue($job)
{
    $r = polygon_db("insert into polygonjobtable (contestnumber, problemnumber, problemname, polygonproblem, polygonpin, " .
        "usernumber, usersitenumber, username, jobstate, jobmsg, jobtries, queuedtime, startedtime, finishedtime, updatetime) " .
        "values (\$1, \$2, \$3, \$4, \$5, \$6, \$7, \$8, 'queued', '', 0, \$9, 0, 0, \$9) " .
        "on conflict (contestnumber, problemnumber) do update set problemname=excluded.problemname, " .
        "polygonproblem=excluded.polygonproblem, polygonpin=excluded.polygonpin, usernumber=excluded.usernumber, " .
        "usersitenumber=excluded.usersitenumber, username=excluded.username, jobstate='queued', jobmsg='', jobtries=0, " .
        "queuedtime=excluded.queuedtime, startedtime=0, finishedtime=0, updatetime=excluded.updatetime " .
        "where polygonjobtable.jobstate <> 'running' returning problemnumber",
        array((int) $job['contest'], (int) $job['number'], (string) $job['name'], (int) $job['pid'], (string) ($job['pin'] ?? ''),
              (int) $job['usernumber'], (int) $job['usersitenumber'], (string) $job['username'], time()));
    if ($r === false)
        return 'could not write the import queue (see the BOCA log)';
    return (pg_num_rows($r) == 1) ? '' : 'problem ' . (int) $job['number'] . ' is being imported right now';
}

// pega o proximo job da fila e o marca running; null se nao houver (SKIP LOCKED: dois nunca pegam o mesmo)
function polygon_job_next()
{
    $r = polygon_db("update polygonjobtable set jobstate='running', jobtries=jobtries+1, startedtime=\$1, updatetime=\$1 " .
        "where (contestnumber, problemnumber) = (select contestnumber, problemnumber from polygonjobtable " .
        "where jobstate='queued' order by queuedtime, problemnumber limit 1 for update skip locked) returning *", array(time()));
    if ($r === false || pg_num_rows($r) != 1)
        return null;
    return polygon_job_row(pg_fetch_assoc($r));
}

// fecha o job (done/failed); so grava se ainda for o mesmo pedido e estiver running
function polygon_job_finish($job, $state, $msg)
{
    polygon_db("update polygonjobtable set jobstate=\$1, jobmsg=\$2, finishedtime=\$3, updatetime=\$3, polygonpin='' " .
        "where contestnumber=\$4 and problemnumber=\$5 and queuedtime=\$6 and jobstate='running'",
        array($state, (string) $msg, time(), (int) $job['contest'], (int) $job['number'], (int) $job['queued']));
}

// so com o lock do worker: job running e resto de worker morto; volta para a fila uma vez, depois failed
function polygon_jobs_recover()
{
    polygon_db("update polygonjobtable set jobstate='failed', jobmsg='worker interrupted twice', finishedtime=\$1, " .
        "updatetime=\$1, polygonpin='' where jobstate='running' and jobtries >= 2", array(time()));
    polygon_db("update polygonjobtable set jobstate='queued', updatetime=\$1 where jobstate='running'", array(time()));
}

function polygon_jobs_pending()
{
    $r = polygon_db("select 1 from polygonjobtable where jobstate='queued' limit 1");
    return $r !== false && pg_num_rows($r) > 0;
}

// apaga os jobs ja terminados do contest
function polygon_jobs_clear($contest)
{
    polygon_db("delete from polygonjobtable where contestnumber=\$1 and jobstate in ('done', 'failed')", array((int) $contest));
}

// lock de "um worker por vez": advisory lock de sessao na conexao do BOCA (o worker e um processo so; o
// PostgreSQL solta o lock sozinho se o worker morrer). Devolve a conexao ou false se outro worker ja o tem.
define('POLYGON_WORKER_LOCK', 727001);
function polygon_worker_lock()
{
    $c = DBConnect();
    $r = polygon_db("select pg_try_advisory_lock(\$1) as ok", array(POLYGON_WORKER_LOCK), $c);
    return ($r !== false && pg_fetch_result($r, 0, 'ok') === 't') ? $c : false;
}

function polygon_worker_unlock($c)
{
    polygon_db("select pg_advisory_unlock(\$1)", array(POLYGON_WORKER_LOCK), $c);
}

// dispara o worker em background; se ja houver um rodando, o novo sai na hora e o antigo pega os jobs novos
function polygon_start_worker()
{
    shell_exec(escapeshellarg(PHP_BINDIR . '/php') . ' ' . escapeshellarg(__DIR__ . '/private/polygonworker.php') .
               ' < /dev/null > /dev/null 2>&1 &');
}

// eof
