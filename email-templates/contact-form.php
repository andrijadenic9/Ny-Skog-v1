<?php
// contact-form.php

// ============== PROD ERROR HANDLING ==============
ini_set('display_errors', 0);          // NEMOJ prikazivati greške u outputu
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);                // i dalje loguj sve
// ================================================

// Ako je nešto već otišlo u output buffer, očisti
if (ob_get_length()) { ob_end_clean(); }

// Uvek vraćamo JSON
header('Content-Type: application/json; charset=UTF-8');

// Dozvoli samo POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  echo json_encode(["ok"=>false,"message"=>"Invalid request."]);
  exit;
}

/* ==================== CONFIG ==================== */

// reCAPTCHA v3
$USE_RECAPTCHA    = true; // ostavi true da bi se proveravalo i lokalno i u produkciji
$RECAPTCHA_SECRET = '6LeH7vErAAAAAN7umWsonOBHsafZS97-GnkxBVhD'; // <-- tvoj V3 SECRET

// SMTP (PHPMailer)
$SMTP_HOST = 'mail.nyskog.no';
// $SMTP_PORT = 465; // 465 = SMTPS (SSL), 587 = STARTTLS
// $SMTP_PORT = 587;      // ⬅️ koristi 587
$SMTP_USER = 'post@nyskog.no';
$SMTP_PASS = 'x@QxyGFVjwon'; // <-- lozinka

// Pošiljalac (From)
$FROM_EMAIL = 'post@nyskog.no';
$FROM_NAME  = 'Nyskog Web';

// Primaoci
$TO = [
  ['email' => 'post@nyskog.no', 'name' => 'Nyskog']
];

// Subject poruke
$SUBJECT = 'Kontakt forma - nyskog.no';

// Pragovi za reCAPTCHA score
$RECAPTCHA_MIN_PROD = 0.5;
$RECAPTCHA_MIN_DEV  = 0.2; // opušteniji prag lokalno
/* ================================================= */

/* ==================== VALIDACIJA INPUTA ==================== */
$name    = isset($_POST['name']) ? trim($_POST['name']) : '';
$email   = isset($_POST['email']) ? trim($_POST['email']) : '';
$comment = isset($_POST['comment']) ? trim($_POST['comment']) : '';
// JS šalje rc_action='contact' i execute(..., { action: 'contact' })
$expectedAction = isset($_POST['rc_action']) ? trim($_POST['rc_action']) : 'contact';

if ($name === '' || $email === '' || $comment === '') {
  echo json_encode(["ok"=>false,"message"=>"Vennligst fyll ut alle obligatoriske felt."]); // Molimo popunite sva obavezna polja.
  exit;
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
  echo json_encode(["ok"=>false,"message"=>"E-postadressen er ugyldig."]); // E-pošta nije ispravna.
  exit;
}
if (mb_strlen($name) > 200 || mb_strlen($comment) > 5000) {
  echo json_encode(["ok"=>false,"message"=>"Meldingsteksten er for lang."]); // Tekst poruke je predugačak.
  exit;
}

// zaštita od header injection
$name        = str_replace(["\r","\n"], ' ', strip_tags($name));
$subjectSafe = str_replace(["\r","\n"], ' ', $SUBJECT);
/* =========================================================== */

/* ==================== reCAPTCHA v3 PROVERA ==================== */
if ($USE_RECAPTCHA) {
  $token = $_POST['g-recaptcha-response'] ?? '';
  if ($RECAPTCHA_SECRET === '' || $token === '') {
    echo json_encode(["ok"=>false,"message"=>"reCAPTCHA-verifisering mislyktes."]); // reCAPTCHA verifikacija nije uspela.
    exit;
  }

  // prepoznaj lokal okruženje (skini port)
  $hostRaw  = $_SERVER['HTTP_HOST'] ?? '';
  $hostname = explode(':', $hostRaw)[0]; // "localhost:8000" -> "localhost"
  $isLocal  = in_array($hostname, ['localhost','127.0.0.1'], true);
  $minScore = $isLocal ? $RECAPTCHA_MIN_DEV : $RECAPTCHA_MIN_PROD;

  // cURL poziv prema Google-u + dijagnostika
  $ch = curl_init();
  $post = http_build_query([
    'secret'   => $RECAPTCHA_SECRET,
    'response' => $token,
    // 'remoteip' => $_SERVER['REMOTE_ADDR'] ?? null,
  ]);
  curl_setopt_array($ch, [
    CURLOPT_URL            => "https://www.google.com/recaptcha/api/siteverify",
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $post,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 10,
    CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,         // ← forsiraj IPv4
    CURLOPT_SSL_VERIFYPEER => $isLocal ? false : true,   // ← samo lokalno opusti SSL
  ]);

  $response = curl_exec($ch);
  $errno    = curl_errno($ch);
  $errstr   = curl_error($ch);
  $http     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  // loguj raw odgovor i cURL statuse u dev-u
  if ($isLocal) {
    @file_put_contents(
      __DIR__ . '/recaptcha.log',
      date('c') . ' http=' . $http . ' errno=' . $errno . ' err="' . $errstr . "\" resp={$response}\n",
      FILE_APPEND
    );
  }

  // ako cURL nije uspeo ili je prazan odgovor
  if ($response === false || $response === '' || $http !== 200) {
    $dbg = $isLocal ? ["http"=>$http, "errno"=>$errno, "err"=>$errstr] : null;
    echo json_encode(["ok"=>false,"message"=>"ReCAPTCHA-verifiseringsfeil.", "debug"=>$dbg]); // Greška pri verifikaciji reCAPTCHA.
    exit;
  }

  $arr = json_decode($response, true) ?: [];

  $passed =
    ($arr['success'] ?? false) === true &&
    (($arr['action']  ?? null) === ($expectedAction ?? 'contact')) &&
    (($arr['score']   ?? 0)    >= $minScore);

  if (!$passed) {
    $dbg = $isLocal ? [
      "success"=>$arr['success'] ?? null,
      "action"=>$arr['action'] ?? null,
      "score"=>$arr['score'] ?? null,
      "host"=>$arr['hostname'] ?? null,
      "min"=>$minScore
    ] : null;
    echo json_encode(["ok"=>false,"message"=>"reCAPTCHA-sjekk mislyktes.", "debug"=>$dbg]); // reCAPTCHA provera nije prošla.
    exit;
  }
}
/* =============================================================== */

/* ==================== SASTAVI PORUKU ==================== */
$rows = [
  'Name'     => htmlspecialchars($name,  ENT_QUOTES, 'UTF-8'),
  'E-mail' => htmlspecialchars($email, ENT_QUOTES, 'UTF-8'),
  'Message'  => nl2br(htmlspecialchars($comment, ENT_QUOTES, 'UTF-8')),
];

$bodyHtml = '<table cellpadding="6" cellspacing="0" border="0" style="font-family:Arial,Helvetica,sans-serif;font-size:14px;">';
foreach ($rows as $k=>$v) {
  $bodyHtml .= '<tr><td style="font-weight:600;white-space:nowrap;">'
            .  htmlspecialchars($k, ENT_QUOTES, 'UTF-8')
            .  ':</td><td>'.$v.'</td></tr>';
}
$bodyHtml .= '</table>';

$bodyText = "Name: $name\nE-mail: $email\n\nMessage:\n$comment";
/* ======================================================== */

/* ==================== PHPMailer (GitHub verzija) ==================== */
require __DIR__ . '/phpmailer/Exception.php';
require __DIR__ . '/phpmailer/PHPMailer.php';
require __DIR__ . '/phpmailer/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP;

$mail = new PHPMailer(true);

try {
  $mail->CharSet   = 'UTF-8';
  $mail->isSMTP();
  $mail->Host       = $SMTP_HOST;
  $mail->SMTPAuth   = true;
  $mail->Username   = $SMTP_USER;
  $mail->Password   = $SMTP_PASS;

  // (A) forsiraj IPv4 ako IPv6 pravi problem
  $resolvedHost = gethostbyname($SMTP_HOST); // "mail.nyskog.no" -> "x.x.x.x"
  $mail->Host = $resolvedHost ?: $SMTP_HOST;

  // (B) kratki timeout i (po želji) debug u error_log
  $mail->Timeout = 15;
  // $mail->SMTPDebug  = SMTP::DEBUG_CONNECTION; // uključi samo dok testiraš
  // $mail->Debugoutput = 'error_log';

  // (C) probaj 465 (SMTPS), pa 587 (STARTTLS)
  $endpoints = [
    ['port' => 465, 'secure' => PHPMailer::ENCRYPTION_SMTPS],
    ['port' => 587, 'secure' => PHPMailer::ENCRYPTION_STARTTLS],
  ];
  $chosen = null;
  foreach ($endpoints as $ep) {
    $fp = @fsockopen($mail->Host, $ep['port'], $errno, $errstr, 5);
    if ($fp) { fclose($fp); $chosen = $ep; break; }
  }
  if (!$chosen) {
    throw new Exception("Nije moguće uspostaviti TCP vezu ka {$SMTP_HOST} (pokušana vrata: 465 i 587).");
  }
  $mail->Port       = $chosen['port'];
  $mail->SMTPSecure = $chosen['secure'];

  // From/Reply-To/To
  $mail->setFrom($FROM_EMAIL, $FROM_NAME);
  $mail->addReplyTo($email, $name);
  foreach ($TO as $rcpt) {
    $mail->addAddress($rcpt['email'], $rcpt['name'] ?? '');
  }

  // Sadržaj
  $mail->Subject = $subjectSafe;
  $mail->isHTML(true);
  $mail->Body    = $bodyHtml;
  $mail->AltBody = $bodyText;

  $mail->send();

  // (opciono) redirect ako si slao hidden "redirect" (ne preporučujem uz AJAX)
  if (!empty($_POST['redirect'])) {
    header('Location: ' . $_POST['redirect']);
    exit;
  }

  echo json_encode(["ok"=>true,"message"=>"Meldingen er sendt. Takk!"]); // Poruka je uspešno poslata. Hvala!
  exit;

} catch (Exception $e) {
  echo json_encode(["ok"=>false,"message"=>"Sendingen mislyktes: ".$mail->ErrorInfo.' '.$e->getMessage()]); // Slanje nije uspelo
  exit;
}
/* ==================================================================== */
