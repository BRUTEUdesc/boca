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
        shell_exec("sudo /usr/local/bin/animeitor-wrapper.sh $cmd > /dev/null 2>&1 &");
        LogLevel("Command dispatched: $cmd", 1);
    }
    ForceLoad("animeitor.php");
}

if (isset($_POST["command"]) && $_POST["confirmation"] == "clean_confirm") {
    shell_exec("sudo /usr/local/bin/clean-webcast-cache.sh > /dev/null 2>&1 &");
    LogLevel("Webcast cache clean dispatched", 1);
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
        <td width="35%" align=right>Active BOCA Contest&nbsp;:</td>
        <td width="65%"><?php echo htmlspecialchars($ct["contestnumber"]); ?></td>
      </tr>
      <tr>
        <td width="35%" align=right>Animeitor Contest&nbsp;:</td>
        <td width="65%">
          <?php echo getCurrentContest(); ?>
        </td>
      </tr>
      <tr>
        <td width="35%" align=right>Animeitor Status&nbsp;:</td>
        <td width="65%">
          <span id="animeitorStatus">Loading...</span>
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

<br>
<table width="100%" border=1>
<tr>
 <td><center><b>Animeitor URLs</b></center></td>
</tr>
</table>
<br>
<center>
  <div id="animeitorUrls" style="font-family: monospace; font-size: 13px; text-align: left; display: inline-block;">
    Carregando URLs...
  </div>
</center>

<script>
  function updateAnimeitorStatus() {
    fetch('animeitor-status.php')
      .then(response => response.json())
      .then(data => {
        const statusSpan = document.getElementById('animeitorStatus');
        statusSpan.innerHTML = `<span style="color: ${data.color}">●</span> ${data.status}`;
      })
      .catch(error => {
        document.getElementById('animeitorStatus').innerText = 'Error fetching status';
        console.error('Error:', error);
      });
  }

  function loadAnimeitorUrls() {
    fetch('animeitor-urls.php')
      .then(response => response.json())
      .then(entries => {
        const div = document.getElementById('animeitorUrls');
        if (!entries.length) { div.textContent = '(sem URLs disponíveis)'; return; }
        let html = '';
        let lastLabel = null;
        entries.forEach((entry, i) => {
          // blank line between Animeitor block and Reveleitor block
          if (i > 0 && entry.items[0] && lastLabel !== null && entry.items[0].label !== lastLabel) {
            html += '<br>';
          }
          html += `<b>→ ${escHtml(entry.title)}</b><br>`;
          entry.items.forEach(item => {
            lastLabel = item.label;
            html += `&nbsp;&nbsp;&nbsp;&nbsp;${escHtml(item.label)} em <a href="${escHtml(item.url)}" target="_blank">${escHtml(item.url)}</a><br>`;
          });
        });
        div.innerHTML = html;
      })
      .catch(() => {
        document.getElementById('animeitorUrls').textContent = 'Erro ao carregar URLs.';
      });
  }

  function escHtml(s) {
    return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }

  window.addEventListener('DOMContentLoaded', function () {
    setInterval(updateAnimeitorStatus, 5000);
    updateAnimeitorStatus();
    loadAnimeitorUrls();
  });
</script>

<br>
<table width="100%" border=1>
<tr>
 <td><center><b>Team Photos &amp; Sounds</b></center></td>
</tr>
</table>
<br>

<?php
$photosDir = '/var/www/maratona-animeitor-rust/server/photos';
$soundsDir = '/var/www/maratona-animeitor-rust/server/sounds';

// Handle upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['media_team'])) {
    $team = preg_replace('/[^a-zA-Z0-9_\-]/', '', $_POST['media_team']);
    $type = $_POST['media_type'] ?? '';

    if ($team !== '' && isset($_FILES['media_file']) && $_FILES['media_file']['error'] === UPLOAD_ERR_OK) {
        $tmp = $_FILES['media_file']['tmp_name'];
        $origName = $_FILES['media_file']['name'];
        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

        if ($type === 'photo' && in_array($ext, ['png', 'jpg', 'jpeg', 'webp'])) {
            $dest = "$photosDir/$team.webp";
            if ($ext === 'webp') {
                move_uploaded_file($tmp, $dest);
            } else {
                shell_exec("convert " . escapeshellarg($tmp) . " " . escapeshellarg($dest));
            }
            echo "<p style='color:green'>✔ Foto de <b>$team</b> salva.</p>";
        } elseif ($type === 'sound' && $ext === 'mp3') {
            $dest = "$soundsDir/$team.mp3";
            move_uploaded_file($tmp, $dest);
            echo "<p style='color:green'>✔ Som de <b>$team</b> salvo.</p>";
        } else {
            echo "<p style='color:red'>✘ Formato inválido. Foto: png/jpg/webp · Som: mp3</p>";
        }
    }
}

// Handle delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_team'], $_POST['delete_type'])) {
    $team = preg_replace('/[^a-zA-Z0-9_\-]/', '', $_POST['delete_team']);
    if ($team !== '') {
        if ($_POST['delete_type'] === 'photo') {
            @unlink("$photosDir/$team.webp");
            echo "<p style='color:orange'>🗑 Foto de <b>$team</b> removida.</p>";
        } elseif ($_POST['delete_type'] === 'sound') {
            @unlink("$soundsDir/$team.mp3");
            echo "<p style='color:orange'>🗑 Som de <b>$team</b> removido.</p>";
        }
    }
}

// Load teams from DB
$teams = [];
$contestnumber = (int)$ct['contestnumber'];
$sql = "SELECT username, userfullname FROM usertable
        WHERE contestnumber=$contestnumber AND usertype='team' AND userenabled='t'
        ORDER BY username";
$result = DBExec($sql);
if ($result) {
    for ($i = 0; $i < DBnlines($result); $i++) {
        $row = DBRow($result, $i);
        $teams[] = ['login' => $row[0], 'name' => $row[1]];
    }
}

$photos = [];
foreach (glob("$photosDir/*.webp") as $f) {
    $photos[basename($f, '.webp')] = $f;
}
$sounds = [];
foreach (glob("$soundsDir/*.mp3") as $f) {
    $sounds[basename($f, '.mp3')] = $f;
}
?>

<center>
<form method="post" enctype="multipart/form-data">
  <table border="0" cellpadding="6" cellspacing="2" style="font-size:13px">
    <tr style="background:#ddd; font-weight:bold">
      <td>Time</td>
      <td>Login</td>
      <td align="center">Foto atual</td>
      <td align="center">Upload foto<br><small>png/jpg/webp</small></td>
      <td align="center">Som atual</td>
      <td align="center">Upload som<br><small>mp3</small></td>
    </tr>
    <?php foreach ($teams as $team):
      $login = $team['login'];
      $hasPhoto = isset($photos[$login]);
      $hasSound = isset($sounds[$login]);
    ?>
    <tr style="border-bottom:1px solid #ccc">
      <td><?php echo htmlspecialchars($team['name']); ?></td>
      <td><code><?php echo htmlspecialchars($login); ?></code></td>
      <td align="center">
        <?php if ($hasPhoto): ?>
          <img src="/animeitor/photos/<?php echo urlencode($login); ?>.webp"
               style="max-height:48px; max-width:80px; border:1px solid #aaa">
          <br>
          <button type="submit" name="delete_team" value="<?php echo htmlspecialchars($login); ?>"
                  onclick="return confirm('Remover foto de <?php echo htmlspecialchars($login); ?>?')"
                  style="font-size:11px; color:red; border:none; background:none; cursor:pointer"
                  formnovalidate>
            <input type="hidden" name="delete_type" value="photo">✖ remover</button>
        <?php else: ?>
          <span style="color:#aaa">—</span>
        <?php endif; ?>
      </td>
      <td align="center">
        <input type="file" name="media_file" accept=".png,.jpg,.jpeg,.webp"
               onchange="this.form.media_team.value='<?php echo htmlspecialchars($login); ?>';
                         this.form.media_type.value='photo'; this.form.submit();">
      </td>
      <td align="center">
        <?php if ($hasSound): ?>
          <audio controls src="/animeitor/sounds/<?php echo urlencode($login); ?>.mp3"
                 style="height:28px; width:120px"></audio>
          <br>
          <button type="submit" name="delete_team" value="<?php echo htmlspecialchars($login); ?>"
                  onclick="return confirm('Remover som de <?php echo htmlspecialchars($login); ?>?')"
                  style="font-size:11px; color:red; border:none; background:none; cursor:pointer"
                  formnovalidate>
            <input type="hidden" name="delete_type" value="sound">✖ remover</button>
        <?php else: ?>
          <span style="color:#aaa">—</span>
        <?php endif; ?>
      </td>
      <td align="center">
        <input type="file" name="media_file" accept=".mp3"
               onchange="this.form.media_team.value='<?php echo htmlspecialchars($login); ?>';
                         this.form.media_type.value='sound'; this.form.submit();">
      </td>
    </tr>
    <?php endforeach; ?>
  </table>
  <input type="hidden" name="media_team" value="">
  <input type="hidden" name="media_type" value="">
</form>
</center>

</body>
</html>

