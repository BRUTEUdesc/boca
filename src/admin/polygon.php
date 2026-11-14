<?php
////////////////////////////////////////////////////////////////////////////////
//BOCA Online Contest Administrator
//    Copyright (C) 2003-2012 by BOCA Development Team (bocasystem@gmail.com)
//
//    This program is free software: you can redistribute it and/or modify
//    it under the terms of the GNU General Public License as published by
//    the Free Software Foundation, either version 3 of the License, or
//    (at your option) any later version.
//
//    This program is distributed in the hope that it will be useful,
//    but WITHOUT ANY WARRANTY; without even the implied warranty of
//    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
//    GNU General Public License for more details.
//    You should have received a copy of the GNU General Public License
//    along with this program.  If not, see <http://www.gnu.org/licenses/>.
////////////////////////////////////////////////////////////////////////////////
// BRUTE: importa problemas direto do Polygon pela API (conta com acesso READ aos problemas).
// Cada pacote linux baixado segue o mesmo caminho do upload manual em problem.php
// (DBNewProblem -> convert_polygon_to_boca).
require('header.php');
require_once('csrf.php');
require_once('../polygon_utils.php');

if(($ct = DBContestInfo($_SESSION["usertable"]["contestnumber"])) == null)
	ForceLoad("../index.php");
$contest = (int)$ct["contestnumber"];

// campo do POST como string ('' se faltar ou vier array); com $i, o item $i de um campo array
function polygonPost($k, $i = null) {
	$v = $_POST[$k] ?? '';
	if ($i !== null) $v = is_array($v) ? ($v[$i] ?? '') : '';
	return is_string($v) ? trim($v) : '';
}

// mensagem para alert(): sem aspas, barras nem tags
function polygonMsg($s) {
	return str_replace(array("'", '"', "\\", "<", ">", "\r", "\n"), array('', '', '', '', '', ' ', ' '), $s);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['Submit1']) || isset($_POST['Submit2']) || isset($_POST['Submit3'])) && !csrfValid()) {
	MSGError("Request refused: invalid or expired form token. Reload the page and try again.");
	ForceLoad("polygon.php");
}

$pin = polygonPost('pin');
if ($pin !== '' && !ctype_alnum($pin)) {
	MSGError('Invalid pin code');
	ForceLoad("polygon.php");
}

if (isset($_POST['Submit2']) && $_POST['Submit2'] == 'Import' && (!isset($_POST['row']) || !is_array($_POST['row']))) {
	MSGError('No problem selected');
	ForceLoad("polygon.php");
}
if (isset($_POST['Submit2']) && $_POST['Submit2'] == 'Import') {
	// valida tudo antes de importar qualquer um
	$todo = array();
	foreach ($_POST['row'] as $i => $on) {
		$pid = polygonPost('pid', $i);
		$num = polygonPost('number', $i);
		$sn = polygonPost('shortname', $i);
		// o conversor de pacote Polygon so aceita uma letra maiuscula como nome curto
		if (!ctype_digit($pid) || !ctype_digit($num) || (int)$num < 1 ||
		    !preg_match('/\A[A-Z]\z/', $sn)) {
			MSGError('Invalid number or short name in the selected problems (number: integer >= 1; short name: one uppercase letter)');
			ForceLoad("polygon.php");
		}
		if (isset($todo[(int)$num])) {
			MSGError('Two selected problems have the same number ' . (int)$num);
			ForceLoad("polygon.php");
		}
		$todo[(int)$num] = array('pid' => $pid, 'name' => $sn);
	}
	ksort($todo);
	// a importacao roda em background (private/polygonworker.php): pacotes de centenas de MB, varios minutos
	$refused = array();
	foreach ($todo as $num => $t) {
		$r = polygon_job_enqueue(array('contest' => $contest, 'number' => $num, 'name' => $t['name'], 'pid' => $t['pid'],
			'pin' => $pin, 'usernumber' => $_SESSION["usertable"]["usernumber"],
			'usersitenumber' => $_SESSION["usertable"]["usersitenumber"], 'username' => $_SESSION["usertable"]["username"]));
		if ($r !== '') $refused[] = $t['name'] . ' (' . $r . ')';
	}
	polygon_start_worker();
	if (count($refused)) MSGError(polygonMsg('Not queued: ' . implode('; ', $refused)));
	ForceLoad("polygon.php");
}

// apaga da lista os jobs ja terminados deste contest
if (isset($_POST['Submit3']) && $_POST['Submit3'] == 'Clear finished') {
	polygon_jobs_clear($contest);
	ForceLoad("polygon.php");
}

// lista os problemas de um contest (ou um problema) do Polygon
$list = null;
$mode = (isset($_POST['mode']) && $_POST['mode'] == 'problem') ? 'problem' : 'contest';
$pgid = polygonPost('pgid');
if (isset($_POST['Submit1']) && $_POST['Submit1'] == 'List') {
	if (!ctype_digit($pgid)) {
		MSGError('Polygon ' . $mode . ' ID must be a number');
		ForceLoad("polygon.php");
	}
	$err = '';
	$p = array();
	if ($pin !== '') $p['pin'] = $pin;
	if ($mode == 'contest') {
		$r = polygon_api('contest.problems', $p + array('contestId' => $pgid), $err);
	} else {
		$r = polygon_api('problems.list', array('id' => $pgid), $err);
		if (is_array($r) && count($r) == 0) $err = 'problem ' . $pgid . ' not found (is it shared with the BOCA Polygon account?)';
	}
	if (!is_array($r) || $err !== '') {
		MSGError(polygonMsg($err));
		ForceLoad("polygon.php");
	}
	if ($mode == 'contest') uksort($r, 'strnatcmp');
	$list = array();
	$i = 0;
	foreach ($r as $letter => $pr) {
		if (!is_array($pr) || !isset($pr['id']) || !empty($pr['deleted'])) continue;
		$i++;
		// o pacote linux READY e escolhido pelo worker na hora de importar (uma chamada a menos por problema:
		// o Polygon limita a taxa de chamadas)
		$list[] = array('letter' => ($mode == 'contest') ? (string)$letter : '', 'number' => $i, 'pr' => $pr);
	}
}

$existing = array();
foreach (DBGetProblems($contest, true) as $bp)
	$existing[$bp['number']] = $bp['problem'];
?>
<?php
$jobs = polygon_jobs_list($contest);
if (count($jobs) > 0) {
	$pending = false;
	echo "<br><center><b>Imports from Polygon in this contest</b></center>\n";
	echo "<table width=\"100%\" border=1>\n <tr><td><b>Number</b></td><td><b>Short name</b></td><td><b>Polygon problem</b></td>" .
		"<td><b>State</b></td><td><b>Message</b></td><td><b>Queued</b></td><td><b>Finished</b></td></tr>\n";
	foreach ($jobs as $j) {
		if ($j['state'] == 'queued' || $j['state'] == 'running') $pending = true;
		$color = ($j['state'] == 'failed') ? '#ff0000' : (($j['state'] == 'done') ? '#008000' : '#000000');
		echo " <tr><td>" . (int)$j['number'] . "</td><td>" . htmlspecialchars($j['name'] ?? '') . "</td><td>" . (int)($j['pid'] ?? 0) .
			"</td><td><font color=\"$color\">" . htmlspecialchars($j['state']) . "</font></td><td>" . htmlspecialchars($j['msg'] ?? '') .
			"&nbsp;</td><td>" . date('H:i:s', (int)($j['queued'] ?? 0)) . "</td><td>" .
			(isset($j['finished']) ? date('H:i:s', (int)$j['finished']) : '&nbsp;') . "</td></tr>\n";
	}
	echo "</table>\n";
	if ($pending) {
		// se o worker morreu (ex.: restart do php-fpm), outro assume; se esta vivo, o novo sai na hora
		polygon_start_worker();
		echo "<center>Importing in background; this page reloads every 5 seconds.</center>\n";
		echo "<script language=\"JavaScript\">setTimeout(function () { document.location='polygon.php'; }, 5000);</script>\n";
	} else {
		echo "<form method=\"post\" action=\"polygon.php\">" . csrfField() .
			"<center><input type=\"submit\" name=\"Submit3\" value=\"Clear finished\"></center></form>\n";
	}
}
?>
<br><center><b>Import problems from Polygon</b></center>
<p align=center>Problems must be shared (at least READ access) with the BOCA Polygon account, and must have a full package built in Polygon.<br>
Each imported package goes through the same conversion as the manual upload in the Problems tab, in background.<br>
A problem with the same number is replaced. It is NOT recommended to change anything while the contest is running.</p>

<form name="form1" method="post" action="polygon.php">
  <?php echo csrfField(); ?>
  <center>
    <table border="0">
      <tr>
        <td align=right>
          <select name="mode">
            <option value="contest"<?php if ($mode == 'contest') echo ' selected'; ?>>Polygon contest ID</option>
            <option value="problem"<?php if ($mode == 'problem') echo ' selected'; ?>>Polygon problem ID</option>
          </select>
        </td>
        <td><input type="text" name="pgid" value="<?php echo htmlspecialchars($pgid); ?>" size="12" maxlength="12" /></td>
      </tr>
      <tr>
        <td align=right>Pin code (if any):</td>
        <td><input type="password" name="pin" value="" size="12" maxlength="32" autocomplete="off" /></td>
      </tr>
    </table>
    <input type="submit" name="Submit1" value="List">
  </center>
</form>
<?php
if ($list !== null) {
	if (count($list) == 0) {
		echo "<br><center><b>No problems found.</b></center>\n";
	} else {
?>
<form name="form2" method="post" action="polygon.php">
  <?php echo csrfField(); ?>
  <input type="hidden" name="pin" value="<?php echo htmlspecialchars($pin); ?>" />
  <br><table width="100%" border=1>
    <tr>
      <td><b>Import</b></td><td><b>Number</b></td><td><b>Short name</b></td><td><b>Polygon problem</b></td>
      <td><b>Revision</b></td><td><b>Latest package</b></td><td><b>In this contest</b></td>
    </tr>
<?php
		foreach ($list as $i => $l) {
			$pr = $l['pr'];
			$pkrev = isset($pr['latestPackage']) ? (int)$pr['latestPackage'] : 0;
			$sn = ($l['letter'] !== '') ? $l['letter'] : chr(64 + min($l['number'], 26));
			echo "    <tr>\n";
			echo "      <td><input type=\"checkbox\" name=\"row[$i]\" value=\"1\"" . ($pkrev > 0 ? ' checked' : ' disabled') . " />";
			echo "<input type=\"hidden\" name=\"pid[$i]\" value=\"" . (int)$pr['id'] . "\" /></td>\n";
			echo "      <td><input type=\"text\" name=\"number[$i]\" value=\"" . (int)$l['number'] . "\" size=\"4\" maxlength=\"4\" /></td>\n";
			echo "      <td><input type=\"text\" name=\"shortname[$i]\" value=\"" . htmlspecialchars($sn) . "\" size=\"2\" maxlength=\"1\" /></td>\n";
			echo "      <td>" . htmlspecialchars(($l['letter'] !== '' ? $l['letter'] . ': ' : '') . ($pr['name'] ?? '')) .
				" (id " . (int)$pr['id'] . ", owner " . htmlspecialchars($pr['owner'] ?? '') . ")</td>\n";
			echo "      <td>" . (int)($pr['revision'] ?? 0) . "</td>\n";
			if ($pkrev <= 0) {
				echo "      <td><font color=\"#ff0000\">no package (build a full package in Polygon)</font></td>\n";
			} else {
				echo "      <td>revision " . $pkrev;
				if ($pkrev < (int)($pr['revision'] ?? 0))
					echo " <font color=\"#ff0000\">(older than the problem revision: build a new package in Polygon)</font>";
				echo "</td>\n";
			}
			$n = (int)$l['number'];
			echo "      <td>" . (isset($existing[$n]) ? 'number ' . $n . ' is ' . htmlspecialchars($existing[$n]) . ' (will be replaced)' : '&nbsp;') . "</td>\n";
			echo "    </tr>\n";
		}
?>
  </table>
  <br><center>
    <input type="submit" name="Submit2" value="Import" onClick="return confirm('Import the selected problems? Problems with the same number will be replaced.');">
  </center>
</form>
<?php
	}
}
?>
</body>
</html>
