<?php
/**
 * Aadhaar-style service portal (DEMO / learning project)
 * - Not affiliated with UIDAI. No real data is looked up.
 * - Aadhaar numbers are never stored in full (only last 4 digits).
 * Run:  php -S localhost:8000   (inside this folder)
 */
session_start();
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));

/* ---------- helpers ---------- */
function e($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Verhoeff checksum: the algorithm real Aadhaar numbers use for the last digit
function verhoeff_valid(string $num): bool {
    $d = [[0,1,2,3,4,5,6,7,8,9],[1,2,3,4,0,6,7,8,9,5],[2,3,4,0,1,7,8,9,5,6],[3,4,0,1,2,8,9,5,6,7],[4,0,1,2,3,9,5,6,7,8],
          [5,9,8,7,6,0,4,3,2,1],[6,5,9,8,7,1,0,4,3,2],[7,6,5,9,8,2,1,0,4,3],[8,7,6,5,9,3,2,1,0,4],[9,8,7,6,5,4,3,2,1,0]];
    $p = [[0,1,2,3,4,5,6,7,8,9],[1,5,7,6,2,8,3,0,9,4],[5,8,0,3,7,9,6,1,4,2],[8,9,1,6,0,4,3,5,2,7],
          [9,4,5,3,1,2,6,8,7,0],[4,2,8,6,5,7,3,9,0,1],[2,7,9,3,8,0,6,4,1,5],[7,0,4,6,9,1,3,2,5,8]];
    $c = 0; $rev = strrev($num);
    for ($i = 0; $i < strlen($rev); $i++) $c = $d[$c][$p[$i % 8][(int)$rev[$i]]];
    return $c === 0;
}
function clean_aadhaar(string $s): string { return preg_replace('/\D/', '', $s); }
function aadhaar_ok(string $n): bool { return preg_match('/^[2-9]\d{11}$/', $n) && verhoeff_valid($n); }

$centres = [
    ['Thodupuzha Aadhaar Seva Kendra', 'Idukki', 'Kerala', '685584', 'Mon–Sat, 9:30–5:00'],
    ['Kattappana Enrolment Centre',    'Idukki', 'Kerala', '685508', 'Mon–Fri, 10:00–4:30'],
    ['Kochi City Aadhaar Centre',      'Ernakulam', 'Kerala', '682016', 'Mon–Sat, 9:00–5:30'],
    ['Kottayam Taluk Office Centre',   'Kottayam', 'Kerala', '686001', 'Mon–Fri, 10:00–5:00'],
    ['Thiruvananthapuram Head Post Office', 'Thiruvananthapuram', 'Kerala', '695001', 'Mon–Sat, 9:30–4:30'],
    ['Madurai Meenakshi Centre',       'Madurai', 'Tamil Nadu', '625001', 'Mon–Sat, 10:00–5:00'],
];

/* ---------- request handling ---------- */
$result = null; $section = $_GET['s'] ?? 'home';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) { http_response_code(400); exit('Invalid session. Reload the page.'); }
    $action = $_POST['action'] ?? '';
    $section = $action;

    if ($action === 'verify') {
        $n = clean_aadhaar($_POST['aadhaar'] ?? '');
        $result = aadhaar_ok($n)
            ? ['ok', 'Number format is valid', 'XXXX XXXX ' . substr($n, -4) . ' passes the format and checksum test. This does not confirm that the number is issued to anyone.']
            : ['err', 'Number is not valid', 'An Aadhaar number has 12 digits, does not start with 0 or 1, and ends with a valid checksum digit. Check for typing errors.'];
    }
    if ($action === 'status') {
        $eid = preg_replace('/\D/', '', $_POST['eid'] ?? '');
        if (strlen($eid) !== 14) {
            $result = ['err', 'Enrolment ID not valid', 'The enrolment ID on your slip has 14 digits (4+5+5 digits for the centre, number and time).'];
        } else {
            $states = [['In process', 'Your enrolment is being checked.'], ['Generated', 'Your Aadhaar has been generated. You can download it.'], ['Update in progress', 'Your update request is being processed.']];
            $s = $states[crc32($eid) % 3];
            $result = ['ok', 'Demo status: ' . $s[0], $s[1] . ' (Sample result: this demo does not connect to any real database.)'];
        }
    }
    if ($action === 'update') {
        $n = clean_aadhaar($_POST['aadhaar'] ?? '');
        $name = trim($_POST['name'] ?? ''); $field = $_POST['field'] ?? ''; $value = trim($_POST['value'] ?? '');
        if (!aadhaar_ok($n)) $result = ['err', 'Update not submitted', 'Enter a valid 12-digit Aadhaar number.'];
        elseif ($name === '' || $value === '' || !in_array($field, ['Address', 'Mobile number', 'Email', 'Date of birth'], true))
            $result = ['err', 'Update not submitted', 'Fill in your name, what you want to change and the new value.'];
        else {
            $ref = 'URN' . date('ymd') . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
            @mkdir(__DIR__ . '/data', 0750, true);
            $row = ['ref' => $ref, 'time' => date('c'), 'last4' => substr($n, -4), 'name' => $name, 'field' => $field, 'value' => $value];
            file_put_contents(__DIR__ . '/data/requests.log', json_encode($row) . PHP_EOL, FILE_APPEND | LOCK_EX);
            $result = ['ok', 'Update request received', "Your reference number is $ref. Keep it to track this request."];
        }
    }
}
$q = trim($_GET['q'] ?? '');
$found = array_filter($centres, fn($c) => $q === '' || stripos(implode(' ', $c), $q) !== false);
if ($q !== '') $section = 'centres';
function nav($id, $label, $cur) { return '<a href="?s=' . $id . '#' . $id . '"' . ($cur === $id ? ' aria-current="page"' : '') . '>' . $label . '</a>'; }
function box($r) { if (!$r) return ''; return '<div class="result ' . $r[0] . '" role="status"><strong>' . e($r[1]) . '</strong><p>' . e($r[2]) . '</p></div>'; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Identity Services Portal (Demo)</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Public+Sans:wght@400;500;700;800&display=swap" rel="stylesheet">
<style>
:root{--navy:#17224d;--navy2:#24336f;--saffron:#f28a1c;--green:#1c8a52;--red:#c0392b;--bg:#f4f6fb;--ink:#161b33;--mute:#5b647f;--line:#dde2ef;--card:#fff}
*{box-sizing:border-box}
body{margin:0;font-family:"Public Sans",system-ui,sans-serif;color:var(--ink);background:var(--bg);line-height:1.6}
a{color:inherit}
.demo{background:var(--saffron);color:#2a1700;text-align:center;font-size:.85rem;font-weight:500;padding:.45rem 1rem}
header{background:#fff;border-bottom:1px solid var(--line);position:sticky;top:0;z-index:5}
.bar{max-width:1100px;margin:auto;display:flex;align-items:center;justify-content:space-between;gap:1rem;padding:.9rem 1.25rem;flex-wrap:wrap}
.brand{display:flex;align-items:center;gap:.7rem;font-weight:800;font-size:1.1rem;color:var(--navy);text-decoration:none}
.mark{width:38px;height:38px;border-radius:10px;background:var(--navy);display:grid;place-items:center}
nav{display:flex;gap:.25rem;flex-wrap:wrap}
nav a{text-decoration:none;font-weight:500;font-size:.95rem;padding:.45rem .8rem;border-radius:8px;color:var(--mute)}
nav a:hover,nav a[aria-current]{background:var(--bg);color:var(--navy)}
.hero{background:linear-gradient(135deg,var(--navy),var(--navy2));color:#fff;padding:4rem 1.25rem 5.5rem}
.hero-in{max-width:1100px;margin:auto;display:grid;grid-template-columns:1.2fr .8fr;gap:3rem;align-items:center}
.hero h1{font-size:clamp(2rem,4.5vw,3.2rem);line-height:1.12;margin:0 0 1rem;font-weight:800;letter-spacing:-.02em}
.hero p{color:#cfd6f3;max-width:48ch;margin:0 0 1.6rem}
.btn{display:inline-block;background:var(--saffron);color:#2a1700;font:700 1rem inherit;font-family:inherit;border:0;border-radius:10px;padding:.8rem 1.4rem;cursor:pointer;text-decoration:none}
.btn:hover{filter:brightness(1.07)}
.btn.ghost{background:transparent;color:#fff;border:1.5px solid #ffffff55;margin-left:.5rem}
.idcard{background:#fff;color:var(--ink);border-radius:18px;padding:1.4rem;box-shadow:0 20px 50px #0004;transform:rotate(2deg)}
.idcard .top{display:flex;justify-content:space-between;align-items:center;border-bottom:3px solid var(--saffron);padding-bottom:.7rem;margin-bottom:1rem;font-weight:700;color:var(--navy)}
.idcard .row{display:flex;gap:1rem}
.photo{width:76px;height:92px;border-radius:8px;background:repeating-linear-gradient(45deg,#e7ebf6,#e7ebf6 6px,#dde2ef 6px,#dde2ef 12px)}
.idcard small{color:var(--mute)}
.idcard .num{font-size:1.5rem;font-weight:800;letter-spacing:.12em;color:var(--navy);margin-top:1.1rem;text-align:center}
main{max-width:1100px;margin:-3rem auto 0;padding:0 1.25rem 3rem;position:relative}
.tiles{display:grid;grid-template-columns:repeat(4,1fr);gap:1rem}
.tile{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:1.2rem;text-decoration:none;box-shadow:0 8px 24px #17224d14}
.tile b{display:block;color:var(--navy);margin:.5rem 0 .15rem}
.tile span{color:var(--mute);font-size:.9rem}
.tile .ic{width:40px;height:40px;border-radius:10px;background:#fff2e2;display:grid;place-items:center;font-size:1.2rem}
section.panel{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:2rem;margin-top:2rem}
section.panel h2{margin:0 0 .3rem;color:var(--navy);font-size:1.5rem}
section.panel>p.lead{color:var(--mute);margin:0 0 1.4rem}
form{display:grid;gap:1rem;max-width:520px}
label{font-weight:500;font-size:.92rem;display:grid;gap:.35rem}
input,select{font:inherit;padding:.75rem .9rem;border:1.5px solid var(--line);border-radius:10px;background:#fff}
input:focus,select:focus,.btn:focus-visible,a:focus-visible{outline:3px solid #f28a1c88;outline-offset:2px;border-color:var(--saffron)}
form .btn{background:var(--navy);color:#fff;justify-self:start}
.result{margin-top:1.2rem;padding:1rem 1.2rem;border-radius:12px;border-left:5px solid;max-width:520px}
.result p{margin:.2rem 0 0;font-size:.93rem}
.result.ok{background:#e9f7ef;border-color:var(--green)}.result.err{background:#fdecea;border-color:var(--red)}
table{width:100%;border-collapse:collapse;margin-top:1rem;font-size:.95rem}
th,td{text-align:left;padding:.75rem .6rem;border-bottom:1px solid var(--line)}
th{color:var(--mute);font-weight:500;font-size:.85rem}
.search{display:flex;gap:.6rem;max-width:520px}.search input{flex:1}.search .btn{background:var(--navy);color:#fff}
details{border-bottom:1px solid var(--line);padding:.9rem 0}
summary{font-weight:700;cursor:pointer;color:var(--navy)}
details p{margin:.5rem 0 0;color:var(--mute)}
footer{background:var(--navy);color:#b9c2e6;text-align:center;font-size:.88rem;padding:1.6rem 1rem}
@media(max-width:820px){.hero-in{grid-template-columns:1fr}.idcard{display:none}.tiles{grid-template-columns:1fr 1fr}}
@media(max-width:480px){.tiles{grid-template-columns:1fr}.btn.ghost{margin:.6rem 0 0}}
</style>
</head>
<body>
<div class="demo">Demo project for learning. Not an official government website and not connected to any real database.</div>
<header><div class="bar">
  <a class="brand" href="./"><span class="mark"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#f28a1c" stroke-width="2"><circle cx="12" cy="12" r="4"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3M5 5l2 2M17 17l2 2M19 5l-2 2M7 17l-2 2"/></svg></span>Identity Services Portal</a>
  <nav aria-label="Main">
    <?= nav('verify', 'Verify number', $section) ?><?= nav('status', 'Enrolment status', $section) ?><?= nav('update', 'Update details', $section) ?><?= nav('centres', 'Find a centre', $section) ?><?= nav('faq', 'Help', $section) ?>
  </nav>
</div></header>

<div class="hero"><div class="hero-in">
  <div>
    <h1>Your identity services, in one clear place.</h1>
    <p>Check a number, follow an enrolment, request a correction or find the nearest centre, without the confusion.</p>
    <a class="btn" href="?s=verify#verify">Verify a number</a><a class="btn ghost" href="?s=centres#centres">Find a centre</a>
  </div>
  <div class="idcard" aria-hidden="true">
    <div class="top"><span>Sample ID</span><span style="color:var(--saffron)">DEMO</span></div>
    <div class="row"><div class="photo"></div><div><b>Asha Nair</b><br><small>DOB: 01/01/1995</small><br><small>Female</small></div></div>
    <div class="num">XXXX XXXX 0000</div>
  </div>
</div></div>

<main>
  <div class="tiles">
    <a class="tile" href="?s=verify#verify"><div class="ic">✔</div><b>Verify number</b><span>Check format and checksum</span></a>
    <a class="tile" href="?s=status#status"><div class="ic">⏱</div><b>Enrolment status</b><span>Track with your 14-digit ID</span></a>
    <a class="tile" href="?s=update#update"><div class="ic">✎</div><b>Update details</b><span>Address, mobile, email, DOB</span></a>
    <a class="tile" href="?s=centres#centres"><div class="ic">⌖</div><b>Find a centre</b><span>Search by district or PIN</span></a>
  </div>

<?php if ($section === 'verify' || $section === 'home'): ?>
  <section class="panel" id="verify">
    <h2>Verify an Aadhaar number</h2>
    <p class="lead">Checks that the number is correctly formed. Nothing is saved.</p>
    <form method="post" action="?s=verify#verify">
      <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>"><input type="hidden" name="action" value="verify">
      <label>12-digit number<input name="aadhaar" inputmode="numeric" maxlength="14" placeholder="0000 0000 0000" required autocomplete="off"></label>
      <button class="btn">Check number</button>
    </form>
    <?= $section === 'verify' ? box($result) : '' ?>
  </section>
<?php endif; ?>

<?php if ($section === 'status'): ?>
  <section class="panel" id="status">
    <h2>Check enrolment status</h2>
    <p class="lead">Enter the 14-digit enrolment ID printed on your acknowledgement slip.</p>
    <form method="post" action="?s=status#status">
      <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>"><input type="hidden" name="action" value="status">
      <label>Enrolment ID<input name="eid" inputmode="numeric" maxlength="20" placeholder="1234 56789 01234" required></label>
      <button class="btn">Check status</button>
    </form>
    <?= box($result) ?>
  </section>
<?php endif; ?>

<?php if ($section === 'update'): ?>
  <section class="panel" id="update">
    <h2>Request a detail update</h2>
    <p class="lead">Only the last 4 digits of your number are kept with the request.</p>
    <form method="post" action="?s=update#update">
      <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>"><input type="hidden" name="action" value="update">
      <label>Aadhaar number<input name="aadhaar" inputmode="numeric" maxlength="14" required autocomplete="off"></label>
      <label>Full name<input name="name" required></label>
      <label>What do you want to change?
        <select name="field"><option>Address</option><option>Mobile number</option><option>Email</option><option>Date of birth</option></select></label>
      <label>New value<input name="value" required></label>
      <button class="btn">Submit request</button>
    </form>
    <?= box($result) ?>
  </section>
<?php endif; ?>

<?php if ($section === 'centres'): ?>
  <section class="panel" id="centres">
    <h2>Find an enrolment centre</h2>
    <p class="lead">Search by centre name, district, state or PIN code.</p>
    <form class="search" method="get" action="#centres"><input type="hidden" name="s" value="centres">
      <input name="q" value="<?= e($q) ?>" placeholder="e.g. Idukki or 685584" aria-label="Search centres"><button class="btn">Search</button></form>
    <?php if ($found): ?>
    <div style="overflow-x:auto"><table>
      <tr><th>Centre</th><th>District</th><th>PIN</th><th>Hours</th></tr>
      <?php foreach ($found as $c): ?><tr><td><b><?= e($c[0]) ?></b><br><small style="color:var(--mute)"><?= e($c[2]) ?></small></td><td><?= e($c[1]) ?></td><td><?= e($c[3]) ?></td><td><?= e($c[4]) ?></td></tr><?php endforeach; ?>
    </table></div>
    <?php else: ?><div class="result err"><strong>No centres found</strong><p>Try a district name or a 6-digit PIN code.</p></div><?php endif; ?>
  </section>
<?php endif; ?>

<?php if ($section === 'faq'): ?>
  <section class="panel" id="faq">
    <h2>Help</h2><p class="lead">Quick answers to common questions.</p>
    <details open><summary>How do I know if a number is valid?</summary><p>It has 12 digits, does not begin with 0 or 1, and its last digit is a checksum. Use “Verify number” to test it.</p></details>
    <details><summary>Is my number saved?</summary><p>No. Verification is not stored. Update requests keep only the last 4 digits and your request details.</p></details>
    <details><summary>Is this the official portal?</summary><p>No. This is a demo built for learning. For real services use the official UIDAI website (uidai.gov.in).</p></details>
    <details><summary>Should I share my number or OTP with others?</summary><p>Share it only with trusted, verified services. Never share an OTP with anyone who calls or messages you.</p></details>
  </section>
<?php endif; ?>
</main>
<footer>© <?= date('Y') ?> Identity Services Portal (demo). Built with PHP.</footer>
</body>
</html>