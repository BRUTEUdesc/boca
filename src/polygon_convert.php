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

// compila compare/check.cpp para compare/check (estatico: o AJ so executa, sem g++ por run).
// Devolve '' ou o motivo da falha com as primeiras linhas do erro do g++.
function polygon_compile_checker($dir)
{
	return polygon_compile($dir, 'g++', array('-O2', '-std=c++23', '-static'), 'check', 'check.cpp', 'o checker');
}

// compila $dir/$src para $dir/$out com $cc (g++ ou gcc) e $flags, sem shell, com PATH fixo e
// timeout de 180 s; $what nomeia o programa nas mensagens. Devolve '' ou o motivo da falha.
function polygon_compile($dir, $cc, $flags, $out, $src, $what)
{
	$gxx = null;
	foreach (array('/usr/bin', '/usr/local/bin', '/bin') as $d) {
		if (is_file("$d/$cc") && is_executable("$d/$cc")) {
			$gxx = "$d/$cc";
			break;
		}
	}
	if ($gxx === null) {
		return "$cc nao encontrado no servidor (apt install $cc): $what precisa ser compilado na importacao";
	}
	$cmd = array_merge(array($gxx), $flags, array('-o', $out, $src));
	if (is_executable('/usr/bin/timeout')) {
		// 180 s; o timeout mata o grupo todo (cc1plus, as, ld)
		$cmd = array_merge(array('/usr/bin/timeout', '-k', '10', '180'), $cmd);
	}
	// o worker herda o ambiente do PHP-FPM (sem PATH): o g++ acha as/ld pelo PATH
	$env = array('PATH' => '/usr/local/bin:/usr/bin:/bin', 'LC_ALL' => 'C');
	if (($t = getenv('TMPDIR')) !== false && $t !== '') {
		$env['TMPDIR'] = $t;
	}
	$desc = array(0 => array('file', '/dev/null', 'r'), 1 => array('file', '/dev/null', 'w'), 2 => array('pipe', 'w'));
	$p = @proc_open($cmd, $desc, $pipes, $dir, $env);
	if (!is_resource($p)) {
		return "nao foi possivel executar o $cc para compilar $what";
	}
	$err = '';
	while (!feof($pipes[2]) && ($b = fread($pipes[2], 65536)) !== false && $b !== '') {
		if (strlen($err) < 65536) {
			$err .= $b;
		}
	}
	fclose($pipes[2]);
	$rc = proc_close($p);
	if ($rc == 0 && is_file("$dir/$out") && @chmod("$dir/$out", 0755)) {
		return '';
	}
	// so as linhas de erro (sem o trecho do fonte que o g++ repete); se nao houver, as primeiras
	$all = array_values(array_filter(array_map('trim', explode("\n", $err)), 'strlen'));
	$lines = array_values(preg_grep('/error/i', $all));
	$lines = array_slice(count($lines) ? $lines : $all, 0, 5);
	$why = ($rc == 124) ? "tempo esgotado (180 s)" : "exit $rc";
	return "$what ($src) nao compilou com $cc " . implode(' ', $flags) . " ($why)" .
		(count($lines) ? ': ' . substr(implode(' | ', $lines), 0, 800) : '');
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
			// o checker compilado vai executavel: o compare/<ext> do AJ o extrai com unzip -j e testa -x
			// (idem o interactor e o launcher dos interativos, que o run/<ext> extrai do zip)
			if ($ok && in_array($r, array('compare/check', 'run/interactor', 'run/interact'), true)) {
				$ok = $z->setExternalAttributesName($r, ZipArchive::OPSYS_UNIX, (0100755 << 16));
			}
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
	// interativo: run/interactive.sh e run/interact.c deste diretorio vao junto do template; os
	// run/<ext> e compare/<ext> do template detectam o run/interactive.sh no zip
	$itpl = __DIR__ . '/private/polygontemplate-interactive';
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
	// interativo: <assets><interactor><source path="files/interactor.cpp" type="cpp.*"/>. O fonte vai
	// para run/interactor.cpp (com o testlib.h do pacote) e e compilado estatico para run/interactor,
	// como o checker, e o pacote ganha run/interactive.sh e run/interact.c. So stdin/stdout, como o normal.
	$isrc = null;
	if (count($root->xpath('//interactor') ?: array()) > 0) {
		$inters = $root->xpath('/problem/assets/interactor') ?: array();
		$is = (count($inters) == 1) ? $inters[0]->source[0] : null;
		$isrc = ($is === null) ? '' : polygon_normname((string) $is['path']);
		$ity = ($is === null) ? '' : (string) $is['type'];
		if ($isrc === '' || !isset($ents[$isrc])) {
			return polygon_convert_fail(null, "problema interativo: fonte do interactor ('$isrc') nao encontrado no pacote");
		}
		if (strncmp($ity, 'cpp.', 4) != 0) {
			return polygon_convert_fail(null, "problema interativo: interactor em '$ity'; so interactor em C++ (testlib) e suportado");
		}
		if (!is_file("$itpl/run/interact.c")) {
			return polygon_convert_fail(null, "template interativo nao encontrado em $itpl");
		}
	}
	foreach (array('input-file' => 'entrada', 'output-file' => 'saida') as $at => $what) {
		if (trim((string) $judging[$at]) !== '') {
			return polygon_convert_fail(null, "$what por arquivo ($at=\"" . trim((string) $judging[$at]) . "\" no problem.xml) nao e suportada: o BOCA so usa stdin/stdout");
		}
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

	// /var/tmp (disco): o /tmp pode ser tmpfs pequeno e ha pacotes de centenas de MB.
	// Sobras de conversao interrompida (processo morto nao roda o shutdown): uma conversao leva minutos
	foreach (glob('/var/tmp/boca_polygon_*', GLOB_ONLYDIR) ?: array() as $old) {
		if (@filemtime($old) < time() - 3600) {
			polygon_rmtree($old);
		}
	}
	$tmp = "/var/tmp/boca_polygon_{$idx}_" . bin2hex(random_bytes(8));
	if (!@mkdir($tmp, 0700)) {
		return polygon_convert_fail(null, "nao foi possivel criar $tmp");
	}
	$pd = "$tmp/Problem_$idx";
	$ok = polygon_copytree($tpl, $pd);
	if ($isrc !== null) {
		$ok = $ok && polygon_copytree($itpl, $pd); // acrescenta run/interactive.sh e run/interact.c
	}
	foreach (array('input', 'output', 'description', 'limits', 'compare', 'run') as $d) {
		$ok = $ok && (is_dir("$pd/$d") || @mkdir("$pd/$d", 0755));
	}
	if (!$ok) {
		return polygon_convert_fail($tmp, "erro copiando o template");
	}

	// testes: <testset>/X e <testset>/X.a viram input/X.<k> e output/X.<k> (k = posicao do testset)
	$k = 0;
	$ntot = 0;
	$maxans = 0; // maior .a (bytes), para o limite de saida
	foreach ($judging->testset as $t) {
		$k++;
		if (!isset($t['name'])) {
			return polygon_convert_fail($tmp, "testset sem nome no problem.xml");
		}
		$pre = polygon_normname((string) $t['name']);
		$pre = ($pre === '') ? '' : "$pre/";
		// BRUTE: entrada sem .a seria descartada em silencio (a solucao errada so nesse teste ganharia AC)
		foreach ($ents as $name => $i) {
			$f = substr($name, strlen($pre));
			if ($pre === '' || strncmp($name, $pre, strlen($pre)) != 0 || $f === '' || strpos($f, '/') !== false || substr($f, -2) === '.a') {
				continue;
			}
			if (!isset($ents[$name . '.a'])) {
				return polygon_convert_fail($tmp, "teste $pre$f sem resposta ($f.a) no pacote: gere o pacote completo de novo no Polygon");
			}
		}
		$nt = 0;
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
			$maxans = max($maxans, (int) @filesize("$pd/output/$in.$k"));
			$nt++;
		}
		if (isset($t->{'test-count'}) && (int) $t->{'test-count'} != $nt) {
			return polygon_convert_fail($tmp, "testset $pre: o problem.xml diz " . (int) $t->{'test-count'} . " testes e o pacote tem $nt");
		}
		$ntot += $nt;
	}
	// sem nenhum teste o AJ desiste de toda run do problema ("problem package has no input files")
	if ($ntot == 0) {
		return polygon_convert_fail($tmp, "o pacote nao tem nenhum teste (gere o pacote completo no Polygon)");
	}

	// limite de arquivo do safeexec (KB) = limite da saida do time: 15 MB, ou o dobro da maior resposta do juiz + 1 MB
	// (com 15 MB fixo, problema com resposta maior dava RE para toda solucao correta)
	$maxf = max(15360, (int) ceil(2 * $maxans / 1024) + 1024);
	$tls = array('c' => $clang, 'cpp' => $clang, 'java' => $clang * $javafactor, 'kt' => $clang * $javafactor, 'py3' => $clang * $pythonfactor);
	foreach ($tls as $ext => $tl) {
		$ok = $ok && @file_put_contents("$pd/limits/$ext", "echo $tl\necho $rep\necho $ml\necho $maxf\nexit 0\n") !== false;
	}
	$info = "basename=\"$idx\"\nfullname=\"$pname\"\ndescfile=\"$idx.pdf\"\n";
	$ok = $ok && @file_put_contents("$pd/description/problem.info", $info) !== false
		&& polygon_extract($zip, $ents['check.cpp'], "$pd/compare/check.cpp")
		&& polygon_extract($zip, $ents['files/testlib.h'], "$pd/compare/testlib.h")
		&& polygon_extract($zip, $ents['statements/.pdf/portuguese/problem.pdf'], "$pd/description/$idx.pdf");
	if ($ok && $isrc !== null) {
		$ok = polygon_extract($zip, $ents[$isrc], "$pd/run/interactor.cpp")
			&& polygon_extract($zip, $ents['files/testlib.h'], "$pd/run/testlib.h");
	}
	$zip->close();
	if (!$ok) {
		return polygon_convert_fail($tmp, "erro gravando limits/description/compare");
	}
	if (($cerr = polygon_compile_checker("$pd/compare")) !== '') {
		return polygon_convert_fail($tmp, $cerr);
	}
	// interativo: interactor e launcher estaticos no pacote (o AJ so executa; o run/<ext> compila
	// das fontes do pacote se o binario nao executar la)
	if ($isrc !== null) {
		$cerr = polygon_compile("$pd/run", 'g++', array('-O2', '-std=c++23', '-static'), 'interactor', 'interactor.cpp', 'o interactor');
		if ($cerr === '') {
			$cerr = polygon_compile("$pd/run", 'gcc', array('-O2', '-static'), 'interact', 'interact.c', 'o launcher dos interativos');
		}
		if ($cerr !== '') {
			return polygon_convert_fail($tmp, $cerr);
		}
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
