<?php
// BRUTE: worker da fila "Import from Polygon" (admin/polygon.php). Disparado pela pagina em background,
// roda como www-data (o usuario do PHP-FPM). So um por vez (polygon_worker_lock): um segundo sai na hora.
// Para cada job: baixa o pacote linux do Polygon, converte (convert_polygon_to_boca, a mesma do upload
// manual) e grava com DBNewProblem. Job em "running" quando o worker pega o lock e de um worker que morreu:
// volta para a fila uma vez; na segunda vira "failed".
if (php_sapi_name() !== 'cli' || getenv('REMOTE_ADDR'))
	exit;
chdir(__DIR__ . '/..');
require_once('db.php');
require_once('polygon_utils.php');

ini_set('memory_limit', '1536M');
$GLOBALS['polygoncurrent'] = null;
$GLOBALS['polygonlock'] = null;

function polygonRunJob($job) {
	$num = (int)$job['number'];
	$contest = (int)$job['contest'];
	// DBNewProblem e LOGLevel usam a sessao: age como o admin que pediu a importacao
	$_SESSION = array('locr' => getcwd(), 'usertable' => array('contestnumber' => $contest,
		'usernumber' => (int)$job['usernumber'], 'usersitenumber' => (int)$job['usersitenumber'],
		'username' => (string)$job['username'], 'usertype' => 'admin'));
	if (!preg_match('/\A[A-Z]\z/', (string)$job['name']) ||
	    !ctype_digit((string)$job['pid']) || DBContestInfo($contest) == null)
		return 'invalid job';
	$pin = (string)($job['pin'] ?? '');
	$err = '';
	if (($pk = polygon_linux_package($job['pid'], $pin, $err)) === null)
		return $err;

	// em disco: o /tmp pode ser tmpfs pequeno e o pacote (com testes) passa de centenas de MB
	$tmp = tempnam(is_writable('/var/tmp') ? '/var/tmp' : sys_get_temp_dir(), 'polygon');
	$GLOBALS['polygontmp'] = $tmp;
	$p = array('problemId' => (string)$job['pid'], 'packageId' => (string)(int)$pk['id'], 'type' => 'linux');
	if ($pin !== '') $p['pin'] = $pin;
	if (polygon_api('problem.package', $p, $err, $tmp) !== true || !is_polygon_package($tmp)) {
		@unlink($tmp);
		return $err !== '' ? $err : 'downloaded file is not a Polygon package';
	}
	$conv = convert_polygon_to_boca($tmp, $job['name']);
	@unlink($tmp);
	if ($conv === false)
		return 'error converting the Polygon package to a BOCA package' .
			(($GLOBALS['polygon_convert_error'] ?? '') !== '' ? ': ' . $GLOBALS['polygon_convert_error'] : '');

	$colors_file = __DIR__ . '/../admin/default_problem_colors.json';
	$default_colors = is_readable($colors_file) ? json_decode(file_get_contents($colors_file), true) : null;
	// autojudge "Everything", o padrao do formulario de problem.php
	$autojudge = 0;
	$all_answers = DBGetAnswers($contest);
	for ($g = 0; $g < count($all_answers); $g++)
		if ($all_answers[$g]['fake'] != 't') $autojudge |= pow(2, $g);
	$param = array();
	$param['number'] = $num;
	$param['name'] = $job['name'];
	$param['inputfilepath'] = $conv[0];
	$param['inputfilename'] = $conv[1];
	$param['fake'] = 'f';
	$param['colorname'] = $default_colors[$num]['colorname'] ?? 'Default Color';
	$param['color'] = $default_colors[$num]['color'] ?? 'CCCCCC';
	$param['autojudge'] = $autojudge;
	ob_start(); // DBNewProblem escreve alert() em erro; aqui so interessa o retorno
	$ret = DBNewProblem($contest, $param);
	$out = ob_get_clean();
	@unlink($conv[0]); // ja esta no banco (large object); o worker processa varios jobs antes de sair
	if ($ret === false || strpos($out, 'alert(') !== false)
		return 'error saving the problem: ' . trim(strip_tags(preg_replace("/.*alert\\('([^']*)'.*/s", '$1', $out)));
	LOGLevel("Problem $num (" . $job['name'] . ") imported from Polygon problem " . (int)$job['pid'] .
		 " package " . (int)$pk['id'] . " (revision " . (int)$pk['revision'] . ") by user " . $job['username'], 2);
	$GLOBALS['polygonimported'] = 'imported package revision ' . (int)$pk['revision'];
	return '';
}

// se DBNewProblem abortar com exit no meio do job, ele vira failed e outro worker segue com a fila
register_shutdown_function(function () {
	if (!empty($GLOBALS['polygontmp'])) @unlink($GLOBALS['polygontmp']);
	if ($GLOBALS['polygoncurrent'] !== null) {
		polygon_job_finish($GLOBALS['polygoncurrent'], 'failed', 'import aborted (see the BOCA log)');
		if (!empty($GLOBALS['polygonlock'])) polygon_worker_unlock($GLOBALS['polygonlock']); // senao o novo sai na hora
		polygon_start_worker();
	}
});

do {
	if (($GLOBALS['polygonlock'] = polygon_worker_lock()) === false)
		exit(0); // outro worker esta rodando
	// com o lock na mao, nenhum outro worker esta rodando: "running" aqui e resto de worker morto
	polygon_jobs_recover();
	// downloads de worker morto (kill -9 nao roda o shutdown); 1 h de folga para um conversor ainda rodando
	foreach (glob('/var/tmp/polygon*') ?: array() as $f)
		if (is_file($f) && filemtime($f) < time() - 3600) @unlink($f);
	while (($job = polygon_job_next()) !== null) {
		$GLOBALS['polygoncurrent'] = $job;
		$err = polygonRunJob($job);
		$GLOBALS['polygoncurrent'] = null;
		$GLOBALS['polygontmp'] = null;
		polygon_job_finish($job, $err === '' ? 'done' : 'failed', $err === '' ? $GLOBALS['polygonimported'] : $err);
	}
	polygon_worker_unlock($GLOBALS['polygonlock']);
	$GLOBALS['polygonlock'] = null;
	// um job pode ter entrado entre a ultima busca e o unlock: confere de novo antes de sair
} while (polygon_jobs_pending());
// eof
