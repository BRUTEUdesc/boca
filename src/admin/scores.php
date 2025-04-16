<?php

require('header.php');

if (isset($_POST["confirmation"]) && $_POST["confirmation"] == "confirm") {
    // Update score.sep file
    $score_content = $_POST["score_content"];
    $score_filename = $locr . "/private/score.sep";
    $update_score = true;
    $update_webcast = true;
    if (@file_put_contents($score_filename, $score_content) === FALSE) {
        MSGError("Cannot write to file " . $score_filename);
        $update_score = false;
    } else {
        LOGInfo("Score separation file updated");
    }

    // Update webcast.sep file 
    $webcast_content = $_POST["webcast_content"];
    $webcast_filename = $locr . "/private/webcast.sep";
    if (@file_put_contents($webcast_filename, $webcast_content) === FALSE) {
        MSGError("Cannot write to file " . $webcast_filename);
        $update_webcast = false;
    } else {
        LOGInfo("Webcast separation file updated");
    }
    if ($update_score && $update_webcast) {
        MSGError("Score and webcast separation files updated successfully");
    } else {
        MSGError("One or both files were not updated");
    }

    ForceLoad("scores.php");
}

?>

<br>
<center>
<h2>Edit Score Separation Files</h2>
<form name="form1" method="post" action="scores.php">
  <input type=hidden name="confirmation" value="noconfirm" />
  <script language="javascript">
    function conf() {
      if (confirm("Confirm changes to both files?")) {
        document.form1.confirmation.value='confirm';
        document.form1.submit();
      }
    }
  </script>
  <h3>Score Separation File (score.sep)</h3>
  <p>This file defines which sites should be separated in the final score.</p>
  <textarea name="score_content" rows="15" cols="80"><?php
    $filename = $locr . "/private/score.sep";
    if (is_readable($filename)) {
        echo htmlspecialchars(file_get_contents($filename));
    } else {
        echo "Global 100/999/1";
    }
?></textarea>

  <br><br>

  <h3>Webcast Separation File (webcast.sep)</h3>
  <p>This file defines which sites should be separated in the webcast.</p>
  <textarea name="webcast_content" rows="10" cols="80"><?php
    $filename = $locr . "/private/webcast.sep";
    if (is_readable($filename)) {
        echo htmlspecialchars(file_get_contents($filename));
    } else {
        echo "global 1/100/999";
    }
?></textarea>
  <br><br>
  <input type="button" name="Submit" value="Update Both Files" onClick="conf()">
</form>
</center>

</body>
</html>