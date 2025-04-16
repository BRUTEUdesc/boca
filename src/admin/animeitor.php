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
require('header.php');

if (($ct = DBContestInfo($_SESSION["usertable"]["contestnumber"])) == null) {
    ForceLoad("../index.php");
}

$webcastPhpPath = __DIR__ . '/report/webcast.php';

function getCurrentContest()
{
    global $webcastPhpPath;
    $content = @file_get_contents($webcastPhpPath);
    if ($content !== false) {
        if (preg_match('/\$ANIMEITOR_CONTEST\s*=\s*(\d+);/', $content, $matches)) {
            return intval($matches[1]);
        }
    }
    return 1;
}

function getAnimeitorStatus()
{
    $output = shell_exec("sudo /usr/local/bin/check-animeitor-status 2>&1");
    if (trim($output) === 'true') {
        return array('status' => 'Running', 'color' => 'green');
    } else {
        return array('status' => 'Stopped', 'color' => 'red');
    }
}

if (isset($_POST["Submit3"]) && isset($_POST["setContest"]) && $_POST["confirmation"] == "contest_confirm") {
    $content = @file_get_contents($webcastPhpPath);
    if ($content !== false) {
        $content = preg_replace(
            '/\$ANIMEITOR_CONTEST\s*=\s*\d+;/',
            '$ANIMEITOR_CONTEST = ' . intval($_POST["setContest"]) . ';',
            $content
        );
        if (@file_put_contents($webcastPhpPath, $content, LOCK_EX) !== false) {
            LogLevel("Contest changed to " . $_POST["setContest"], 1);
        }
    }
    ForceLoad("animeitor.php");
}

if (isset($_POST["command"]) && $_POST["confirmation"] == "animeitor_confirm") {
    $cmd = $_POST["command"];
    if ($cmd == "start" || $cmd == "stop" || $cmd == "restart") {
        $output = shell_exec("sudo /usr/local/bin/animeitor-wrapper.sh $cmd 2>&1");

        if ($output !== null) {
            LogLevel("Command executed successfully: $cmd", 1);
        } else {
            LogLevel("Command failed: $cmd (Output: " . ($output ?? "null") . ")", 1);
        }
    }
    ForceLoad("animeitor.php");
}

if (isset($_POST["command"]) && $_POST["confirmation"] == "clean_confirm") {
    $output = shell_exec("sudo /usr/local/bin/clean-webcast-cache.sh 2>&1");
    if ($output !== null) {
        LogLevel("Webcast cache cleaned successfully", 1);
    } else {
        LogLevel("Webcast cache cleaning failed", 1);
    }
    ForceLoad("animeitor.php");
}

?>

<br>
<table width="100%" border=1>
<tr>
 <td><center><b>Animeitor Control Panel</b></center></td>
</tr>
</table>
<br>

<form name="form1" enctype="multipart/form-data" method="post" action="animeitor.php">
  <input type=hidden name="confirmation" value="noconfirm" />
  <script language="javascript">
    function conf1() {
      if (confirm("Confirm?")) {
        document.form1.confirmation.value='contest_confirm';
      }
    }
    function conf2() {
      if (confirm("Confirm?")) {
        document.form1.confirmation.value='animeitor_confirm';
      }
    }
    function conf3() {
      if (confirm("Confirm?")) {
        document.form1.confirmation.value='clean_confirm';
      }
    }
  </script>

  <br><br>
  <center>
    <table border="0">
      <tr>
        <td width="35%" align=right>Current Contest&nbsp;:</td>
        <td width="65%">
          <?php echo getCurrentContest(); ?>
        </td>
      </tr>
      <tr>
        <td width="35%" align=right>Animeitor Status&nbsp;:</td>
        <td width="65%">
          <?php
          $status = getAnimeitorStatus();
echo '<span style="color: ' . $status['color'] . '">●</span> ' . $status['status'];
?>
        </td>
      </tr>
      <tr>
        <td width="35%" align=right>Set Contest Number&nbsp;:</td>
        <td width="65%">
          <input type="text" name="setContest" value="" size="20" maxlength="20" />
          <input type="submit" name="Submit3" value="Set Contest" onClick="conf1()">
        </td>
      </tr>
      <tr>
        <td width="35%" align=right>Animeitor Controls&nbsp;:</td>
        <td width="65%">
          <input type="submit" name="command" value="start" onClick="conf2()">
          <input type="submit" name="command" value="stop" onClick="conf2()">
          <input type="submit" name="command" value="restart" onClick="conf2()">
        </td>
      </tr>
      <tr>
        <td width="35%" align=right>Clean Webcast Cache&nbsp;:</td>
        <td width="65%">
          <input type="submit" name="command" value="clean" onClick="conf3()">
        </td>
      </tr>
    </table>
  </center>
</form>

</body>
</html>

