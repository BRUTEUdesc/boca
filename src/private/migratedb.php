#!/usr/bin/php
<?php
// BRUTE: migracoes de um banco do BOCA que ja existe (o createdb.php so serve para banco novo: apaga tudo).
// Idempotente: cria so as tabelas que faltam (as mesmas funcoes DBCreate*Table do createdb.php, com
// CREATE TABLE IF NOT EXISTS) e copia para o banco os PDFs de prova que ainda estavam em arquivo.
// Nao apaga nada. Rodado pelo deploy/setup-server.sh; a mao:
//   sudo php /var/www/boca/src/private/migratedb.php
if (php_sapi_name() !== "cli" || getenv("REMOTE_ADDR"))
	exit;
if (function_exists('posix_geteuid') && posix_geteuid() != 0) {
	echo "Must be run as root\n";
	exit(1);
}
chdir(__DIR__ . '/..');
require_once('db.php');

echo "tabelas novas (se faltarem): contestpdftable, polygonjobtable\n";
DBCreateContestPdfTable();
DBCreatePolygonJobTable();

// PDFs da prova: antes ficavam em private/secretcontest/<contest>/contest.pdf e contest-en.pdf
$dir = __DIR__ . '/secretcontest';
$n = 0;
foreach (glob($dir . '/*', GLOB_ONLYDIR) ?: array() as $d) {
	$contest = basename($d);
	if (!ctype_digit($contest)) continue;
	$contest = (int)$contest;
	if (DBGetRow("select contestnumber from contesttable where contestnumber=$contest", 0) == null) {
		echo "  $d: contest $contest nao existe no banco, ignorado\n";
		continue;
	}
	foreach (array('pt' => 'contest.pdf', 'en' => 'contest-en.pdf') as $lang => $f) {
		if (!is_file("$d/$f") || DBContestPdfExists($contest, $lang)) continue;
		if (DBContestPdfSave($contest, $lang, "$d/$f")) {
			echo "  contest $contest: $f copiado para o banco\n";
			$n++;
		} else
			echo "  contest $contest: ERRO ao copiar $f (nao e PDF ou passa de 100 MB? veja o log do BOCA)\n";
	}
}
echo "PDFs copiados: $n" . ($n ? " (os arquivos em $dir foram mantidos; podem ser apagados depois de conferir)" : "") . "\n";
// eof
