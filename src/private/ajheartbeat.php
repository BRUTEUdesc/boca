<?php
// BRUTE: sinal de vida de uma run em correcao no autojudge (ver DBGetRunToAutojudging em frun.php). Lancado pelo
// autojudging.php (ajbeat_start) como processo proprio:
//   php ajheartbeat.php <contest> <site> <run> <autojudge ip> <pid do autojudging.php> <arquivo de progresso>
// Atualiza runtable.autoheartbeat (relogio do banco) a cada 30 s ate: o pai encerrar este processo, o pai morrer,
// a run deixar de ser deste autojudge (respondida ou retomada por outro) ou o pai ficar 15 min sem marcar progresso
// no arquivo (travado fora de uma etapa com timeout: a run volta para a fila 2 min depois). Falha de rede/banco:
// tenta de novo na volta seguinte; se nao voltar em 2 min, a run e retomada por outro autojudge, como deve ser.
if (php_sapi_name() !== 'cli' || getenv('REMOTE_ADDR') || $argc != 7)
	exit(1);
foreach (array(1, 2, 3, 5) as $i)
	if (!ctype_digit($argv[$i])) exit(1);
if (!preg_match('/\A[A-Za-z0-9._:-]{1,20}\z/', $argv[4]))
	exit(1);
chdir(__DIR__ . '/..');
require_once('db.php');

$ppid = (int)$argv[5];
$progress = $argv[6];
$conf = globalconf();
$cs = "connect_timeout=10 application_name=bocaheartbeat " .
	($conf["dblocal"] == "true" ? "" : "host=" . $conf["dbhost"] . " port=" . $conf["dbport"] . " ") .
	"dbname=" . $conf["dbname"] . " user=" . $conf["dbuser"] . " password=" . $conf["dbpass"];
$sql = "update runtable set autoheartbeat=extract(epoch from now())::int where contestnumber=" . (int)$argv[1] .
	" and runsitenumber=" . (int)$argv[2] . " and runnumber=" . (int)$argv[3] . " and autoip='" . $argv[4] .
	"' and autoenddate is null";
$c = false;
while (!function_exists('posix_getppid') || posix_getppid() == $ppid) {
	clearstatcache();
	if (($m = @filemtime($progress)) === false || $m < time() - 900)
		break;
	if ($c === false)
		$c = @pg_connect($cs, PGSQL_CONNECT_FORCE_NEW);
	if ($c !== false) {
		$r = @pg_query($c, $sql);
		if ($r === false) {
			@pg_close($c);
			$c = false;
		} else if (pg_affected_rows($r) == 0)
			break;
	}
	sleep(30);
}
@unlink($progress);
exit(0);
// eof
