<?php
// Conversao de pacote Polygon (full, linux) para pacote BOCA, em PHP puro.
// Porte do make_from_full_package.py do boca-problem-builder: gera o mesmo conteudo de zip.
// O template (compare/compile/limits/run) fica em private/polygontemplate.

// guarda o motivo da falha em $GLOBALS['polygon_convert_error'] e no log do BOCA
function polygon_convert_fail($tmp, $msg)
{
	$GLOBALS['polygon_convert_error'] = $msg;
	if (function_exists('LOGError')) {
		LOGError("Polygon: $msg");
	}
	if ($tmp !== null) {
		polygon_rmtree($tmp);
	}
	return false;
}

function polygon_rmtree($path)
{
	if (is_dir($path) && !is_link($path)) {
		foreach (scandir($path) ?: array() as $f) {
			if ($f !== '.' && $f !== '..') {
				polygon_rmtree("$path/$f");
			}
		}
		@rmdir($path);
	} else {
		@unlink($path);
	}
}

function polygon_copytree($src, $dst)
{
	if (!is_dir($dst) && !@mkdir($dst, 0755)) {
		return false;
	}
	foreach (scandir($src) ?: array() as $f) {
		if ($f === '.' || $f === '..') {
			continue;
		}
		$ok = is_dir("$src/$f") ? polygon_copytree("$src/$f", "$dst/$f") : @copy("$src/$f", "$dst/$f");
		if (!$ok) {
			return false;
		}
	}
	return true;
}

// nome da entrada como o extractall do python grava: sem '', '.' e '..'
function polygon_normname($name)
{
	return implode('/', array_filter(explode('/', $name), function ($p) {
		return $p !== '' && $p !== '.' && $p !== '..';
	}));
}

// int() do python sobre o texto do elemento; null se nao for inteiro
function polygon_xmlint($el)
{
	$s = ($el === null) ? '' : trim((string) $el);
	return preg_match('/\A[+-]?[0-9]+\z/', $s) ? (int) $s : null;
}

// extrai a entrada $i para $dest, conferindo tamanho e crc
function polygon_extract($zip, $i, $dest)
{
	$st = $zip->statIndex($i);
	$in = $zip->getStreamIndex($i);
	$out = @fopen($dest, 'wb');
	$ok = ($st !== false && $in !== false && $out !== false);
	if ($ok) {
		$ok = (@stream_copy_to_stream($in, $out) === $st['size']);
	}
	if ($in !== false) {
		fclose($in);
	}
	if ($out !== false) {
		$ok = fclose($out) && $ok;
	}
	return $ok && hash_file('crc32b', $dest) === sprintf('%08x', $st['crc']);
}

function polygon_zipdir($z, $base, $rel)
{
	foreach (scandir($rel === '' ? $base : "$base/$rel") ?: array() as $f) {
		if ($f === '.' || $f === '..') {
			continue;
		}
		$r = ($rel === '') ? $f : "$rel/$f";
		if (is_dir("$base/$r")) {
			$ok = $z->addEmptyDir($r) && polygon_zipdir($z, $base, $r);
		} else {
			// deflate nivel 6 como o python (o padrao do libzip, 9, e ~4x mais lento)
			$ok = $z->addFile("$base/$r", $r) && $z->setCompressionName($r, ZipArchive::CM_DEFLATE, 6);
		}
		if (!$ok) {
			return false;
		}
	}
	return true;
}

// Converte o pacote $zipfile para o pacote BOCA do problema $idx (uma letra maiuscula).
// Devolve array(caminho do zip, "Problem_<idx>.zip") ou false (motivo em $GLOBALS['polygon_convert_error']).
// O zip fica num diretorio temporario proprio, apagado no fim do request.
function polygon_convert_package($zipfile, $idx, $javafactor = 3, $pythonfactor = 3)
{
	$GLOBALS['polygon_convert_error'] = '';
	if (!is_string($idx) || !preg_match('/\A[A-Z]\z/', $idx)) {
		return polygon_convert_fail(null, "a letra do problema deve ser uma unica letra maiuscula");
	}
	$tpl = __DIR__ . '/private/polygontemplate';
	if (!is_dir($tpl)) {
		return polygon_convert_fail(null, "template nao encontrado em $tpl");
	}
	$zip = new ZipArchive();
	if (!is_string($zipfile) || !is_file($zipfile) || filesize($zipfile) == 0 || $zip->open($zipfile, ZipArchive::RDONLY) !== true) {
		return polygon_convert_fail(null, "arquivo zip invalido");
	}
	// entradas (arquivos) pelo caminho normalizado; a ultima repetida vale, como no extractall
	$ents = array();
	for ($i = 0; $i < $zip->numFiles; $i++) {
		$name = $zip->getNameIndex($i);
		if ($name !== false && substr($name, -1) !== '/' && ($n = polygon_normname($name)) !== '') {
			$ents[$n] = $i;
		}
	}
	foreach (array('check.cpp', 'files/testlib.h', 'problem.xml', 'statements/.pdf/portuguese/problem.pdf') as $f) {
		if (!isset($ents[$f])) {
			return polygon_convert_fail(null, "arquivo obrigatorio '$f' nao encontrado no pacote");
		}
	}

	$xml = $zip->getFromIndex($ents['problem.xml']);
	$prev = libxml_use_internal_errors(true);
	$root = ($xml === false || $xml === '') ? false : simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA);
	libxml_clear_errors();
	libxml_use_internal_errors($prev);
	if ($root === false) {
		return polygon_convert_fail(null, "problem.xml invalido");
	}
	$judging = $root->judging[0];
	$ts = ($judging === null) ? null : $judging->testset[0];
	if ($ts === null) {
		return polygon_convert_fail(null, "problem.xml sem judging/testset");
	}

	// limites do primeiro testset: TL em segundos inteiros, com repeticoes (lcm com 1000 ms)
	$tlms = polygon_xmlint($ts->{'time-limit'}[0]);
	$ml = polygon_xmlint($ts->{'memory-limit'}[0]);
	if ($tlms === null || $tlms <= 0 || $ml === null || $ml < 0) {
		return polygon_convert_fail(null, "time-limit/memory-limit invalido no problem.xml");
	}
	$a = 1000;
	$b = $tlms;
	while ($b != 0) {
		list($a, $b) = array($b, $a % $b);
	}
	$clang = intdiv(1000 * $tlms, $a);
	$rep = intdiv($clang, $tlms);
	$clang = intdiv($clang, 1000);
	$ml = intdiv($ml, 1024 * 1024);

	// nome: portugues, senao ingles
	$pname = null;
	foreach (array('english', 'portuguese') as $lang) {
		foreach ($root->xpath('.//names/name') ?: array() as $n) {
			if ((string) $n['language'] === $lang) {
				$pname = isset($n['value']) ? (string) $n['value'] : null;
				break;
			}
		}
	}
	if ($pname === null) {
		return polygon_convert_fail(null, "nome do problema nao encontrado no problem.xml");
	}
	$pname = str_replace('\&', '&', $pname);

	// /var/tmp (disco): o /tmp pode ser tmpfs pequeno e ha pacotes de centenas de MB
	$tmp = "/var/tmp/boca_polygon_{$idx}_" . bin2hex(random_bytes(8));
	if (!@mkdir($tmp, 0700)) {
		return polygon_convert_fail(null, "nao foi possivel criar $tmp");
	}
	$pd = "$tmp/Problem_$idx";
	$ok = polygon_copytree($tpl, $pd);
	foreach (array('input', 'output', 'description', 'limits', 'compare', 'run') as $d) {
		$ok = $ok && (is_dir("$pd/$d") || @mkdir("$pd/$d", 0755));
	}
	if (!$ok) {
		return polygon_convert_fail($tmp, "erro copiando o template");
	}

	// testes: <testset>/X e <testset>/X.a viram input/X.<k> e output/X.<k> (k = posicao do testset)
	$k = 0;
	foreach ($judging->testset as $t) {
		$k++;
		if (!isset($t['name'])) {
			return polygon_convert_fail($tmp, "testset sem nome no problem.xml");
		}
		$pre = polygon_normname((string) $t['name']);
		$pre = ($pre === '') ? '' : "$pre/";
		foreach ($ents as $name => $i) {
			$f = substr($name, strlen($pre));
			if (strncmp($name, $pre, strlen($pre)) != 0 || strpos($f, '/') !== false || substr($f, -2) !== '.a') {
				continue;
			}
			$in = substr($f, 0, -2);
			if (!isset($ents[$pre . $in])) {
				return polygon_convert_fail($tmp, "entrada '$in' nao encontrada em $pre");
			}
			if (!polygon_extract($zip, $i, "$pd/output/$in.$k") || !polygon_extract($zip, $ents[$pre . $in], "$pd/input/$in.$k")) {
				return polygon_convert_fail($tmp, "erro extraindo o teste $pre$in");
			}
		}
	}

	$tls = array('c' => $clang, 'cpp' => $clang, 'java' => $clang * $javafactor, 'kt' => $clang * $javafactor, 'py3' => $clang * $pythonfactor);
	foreach ($tls as $ext => $tl) {
		$ok = $ok && @file_put_contents("$pd/limits/$ext", "echo $tl\necho $rep\necho $ml\necho 15360\nexit 0\n") !== false;
	}
	$info = "basename=\"$idx\"\nfullname=\"$pname\"\ndescfile=\"$idx.pdf\"\n";
	$ok = $ok && @file_put_contents("$pd/description/problem.info", $info) !== false
		&& polygon_extract($zip, $ents['check.cpp'], "$pd/compare/check.cpp")
		&& polygon_extract($zip, $ents['files/testlib.h'], "$pd/compare/testlib.h")
		&& polygon_extract($zip, $ents['statements/.pdf/portuguese/problem.pdf'], "$pd/description/$idx.pdf");
	$zip->close();
	if (!$ok) {
		return polygon_convert_fail($tmp, "erro gravando limits/description/compare");
	}

	$out = "$tmp/Problem_$idx.zip";
	$z = new ZipArchive();
	if ($z->open($out, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
		return polygon_convert_fail($tmp, "nao foi possivel criar $out");
	}
	$ok = polygon_zipdir($z, $pd, '');
	if (!$z->close() || !$ok) {
		return polygon_convert_fail($tmp, "erro gerando $out");
	}
	polygon_rmtree($pd);
	// o chamador importa o zip no banco ainda neste request
	register_shutdown_function('polygon_rmtree', $tmp);
	return array($out, "Problem_$idx.zip");
}

// eof
