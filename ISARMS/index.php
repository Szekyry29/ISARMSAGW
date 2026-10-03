<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';
startSecureSession();

if (isLoggedIn()) {
    $map = ['Admin'=>'modules/admin/dashboard.php','Teacher'=>'modules/teacher/dashboard.php',
            'Registrar'=>'modules/registrar/dashboard.php','Student'=>'modules/student/dashboard.php'];
    redirect(BASE_URL . ($map[$_SESSION['role']] ?? 'index.php'));
}

$error   = '';
$timeout = isset($_GET['timeout']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRF($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request. Please try again.';
    } else {
        $email    = sanitize($_POST['email']    ?? '');
        $password = $_POST['password'] ?? '';

        if (empty($email) || empty($password)) {
            $error = 'Please enter your email and password.';
        } else {
            $db = Database::getInstance();

            // Single form now — no user_type input from the client.
            // Look the email up in staff/faculty accounts first, then
            // fall back to student accounts. Whichever matches decides
            // the account type and, later, the correct dashboard path.
            $user = $db->fetchOne(
                "SELECT userID as id, fName, mName, lName, extName, email, password,
                        profilePic, roleName, isActive
                 FROM users WHERE email=:e AND dateDeleted IS NULL", [':e'=>$email]);
            $accountType = 'staff';

            if (!$user) {
                $user = $db->fetchOne(
                    "SELECT studentID as id, fName, mName, lName, extName, email, password,
                            profilePic, 'Student' as roleName, isActive
                     FROM students WHERE email=:e AND dateDeleted IS NULL", [':e'=>$email]);
                $accountType = 'student';
            }

            if (!$user || !verifyPassword($password, $user['password'])) {
                $error = 'Invalid email address or password.';
            } elseif (!(bool)$user['isActive']) {
                $error = 'Your account has been deactivated. Please contact the system administrator.';
            } else {
                session_regenerate_id(true);
                $_SESSION['user_id']       = $user['id'];
                $_SESSION['full_name']     = fullName($user);
                $_SESSION['role']          = $user['roleName'];
                $_SESSION['email']         = $user['email'];
                $_SESSION['profile_pic']   = $user['profilePic'];
                $_SESSION['last_activity'] = time();

                $table = ($accountType === 'student') ? 'students' : 'users';
                $idCol  = ($accountType === 'student') ? 'studentID' : 'userID';
                $db->query("UPDATE $table SET lastLogin=NOW() WHERE $idCol=:id", [':id'=>$user['id']]);

                // Same routing map as before — role decides the path,
                // so Admin/Teacher/Registrar/Student all still land on
                // their correct dashboard even though the form is merged.
                $map = ['Admin'=>'modules/admin/dashboard.php','Teacher'=>'modules/teacher/dashboard.php',
                        'Registrar'=>'modules/registrar/dashboard.php','Student'=>'modules/student/dashboard.php'];
                redirect(BASE_URL . ($map[$user['roleName']] ?? 'index.php'));
            }
        }
    }
}

$csrf = generateCSRF();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Login — ISARMS | NEUST Talavera</title>
  <link rel="icon" type="image/png" href="<?= BASE_URL ?>assets/img/neust-logo.png"/>
  <link rel="shortcut icon" type="image/png" href="<?= BASE_URL ?>assets/img/neust-logo.png"/>
  <link rel="preconnect" href="https://fonts.googleapis.com"/>
  <link href="https://fonts.googleapis.com/css2?family=Source+Sans+3:wght@300;400;600;700;800&display=swap" rel="stylesheet"/>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    :root {
      --blue:      #1a3a8c;
      --blue-dark: #0f2260;
      --blue-mid:  #2451c8;
      --gold:      #f5c518;
      --gold-deep: #c9a300;
      --white:     #ffffff;
      --border:    #dce4f0;
      --text:      #111827;
      --muted:     #6b7a94;
      --error:     #dc2626;
      --bg:        #f0f4fb;
    }

    body {
      font-family: 'Source Sans 3', sans-serif;
      min-height: 100vh;
      background: var(--bg);
      position: relative;
      overflow-x: hidden;
      overflow-y: auto;
    }

    html {
      min-height: 100%;
      overflow-x: hidden;
      overflow-y: auto;
    }

    .page-layout {
      position: relative;
      z-index: 5;
      display: flex;
      flex-direction: column;
      align-items: stretch;
      width: min(1180px, calc(100% - 40px));
      min-height: 100vh;
      margin: 0 auto;
      padding: 24px 0 40px;
    }

    .topbar {
      margin-bottom: 22px;
      max-width: none;
    }

    .topbar .brand .title {
      font-family: 'Source Sans 3', sans-serif;
      font-size: 1.08rem;
      letter-spacing: .08em;
      text-transform: uppercase;
    }

    .topbar .brand .subtitle {
      color: rgba(248,250,252,.8);
      font-size: .92rem;
    }

    .login-card {
      margin-top: 10px;
    }

    /* ── Animated background ── */
    .bg-canvas {
      position: fixed; inset: 0; z-index: 0;
      background: linear-gradient(135deg, var(--blue-dark) 0%, var(--blue) 60%, var(--blue-mid) 100%);
    }

    .bg-canvas::before {
      content: '';
      position: absolute; inset: 0;
      background-image:
        radial-gradient(ellipse at 15% 50%, rgba(245,197,24,0.12) 0%, transparent 55%),
        radial-gradient(ellipse at 85% 10%, rgba(255,255,255,0.06) 0%, transparent 40%),
        radial-gradient(ellipse at 70% 90%, rgba(245,197,24,0.08) 0%, transparent 40%);
    }

    /* Decorative circles */
    .deco-circle {
      position: absolute; border-radius: 50%;
      border: 1px solid rgba(255,255,255,0.06);
    }
    .deco-circle-1 { width:500px;height:500px; bottom:-180px; right:-100px; }
    .deco-circle-2 { width:320px;height:320px; top:-100px; left:-60px; }
    .deco-circle-3 { width:200px;height:200px; bottom:80px; left:80px; border-color:rgba(245,197,24,0.12); }

    /* Gold top stripe */
    .gold-stripe {
      position: fixed; top: 0; left: 0; right: 0;
      height: 4px;
      background: linear-gradient(90deg, var(--gold), var(--gold-deep), var(--gold));
      z-index: 10;
    }

    /* ── Login Card ── */
    .login-wrapper {
      position: relative; z-index: 5;
      display: flex; align-items: center; justify-content: center;
      width: 100%; padding: 24px 0 40px;
    }

    .portal-grid {
      display: grid;
      grid-template-columns: minmax(0, 1fr) minmax(360px, 460px);
      align-items: center;
      gap: clamp(44px, 7vw, 96px);
      width: 100%;
    }

    .landing-copy {
      color: #fff;
      max-width: 650px;
      padding: 20px 0;
      animation: copyIn .7s ease-out both;
    }

    @keyframes copyIn {
      from { opacity: 0; transform: translateX(-20px); }
      to { opacity: 1; transform: translateX(0); }
    }

    .eyebrow {
      display: inline-flex;
      align-items: center;
      gap: 9px;
      color: var(--gold);
      font-size: 12px;
      font-weight: 700;
      letter-spacing: .16em;
      text-transform: uppercase;
      margin-bottom: 20px;
    }

    .eyebrow::before {
      content: '';
      width: 30px;
      height: 2px;
      background: var(--gold);
    }

    .landing-copy h2 {
      max-width: 620px;
      font-family: 'Source Sans 3', sans-serif;
      font-size: clamp(2.6rem, 4.8vw, 4.5rem);
      line-height: 1.04;
      letter-spacing: -.04em;
      margin-bottom: 22px;
      font-weight: 800;
    }

    .landing-copy h2 span { color: var(--gold); }

    .landing-copy > p {
      max-width: 540px;
      color: rgba(248,250,252,.78);
      font-size: 1.05rem;
      line-height: 1.7;
      margin-bottom: 30px;
    }

    .feature-list {
      display: grid;
      grid-template-columns: repeat(3, minmax(0, 1fr));
      gap: 12px;
      max-width: 620px;
    }

    .feature-item {
      min-height: 112px;
      padding: 18px 16px;
      border: 1px solid rgba(255,255,255,.15);
      border-radius: 14px;
      background: linear-gradient(180deg, rgba(255,255,255,.10), rgba(255,255,255,.05));
      backdrop-filter: blur(8px);
      box-shadow: 0 12px 30px rgba(9,18,40,.08);
      transition: transform .2s ease, border-color .2s ease;
    }

    .feature-item:hover {
      transform: translateY(-2px);
      border-color: rgba(245,197,24,.42);
    }

    .feature-item i {
      color: var(--gold);
      font-size: 18px;
      margin-bottom: 12px;
    }

    .feature-item strong {
      display: block;
      font-size: 14px;
      margin-bottom: 5px;
      font-weight: 700;
    }

    .feature-item small {
      display: block;
      color: rgba(248,250,252,.68);
      font-size: 12px;
      line-height: 1.45;
    }

    .login-card {
      background: var(--white);
      border-radius: 20px;
      width: 100%; max-width: 460px;
      box-shadow: 0 24px 80px rgba(0,0,0,0.25), 0 0 0 1px rgba(255,255,255,0.1);
      overflow: hidden;
      animation: cardIn .55s cubic-bezier(.22,.68,0,1.2);
    }

    @keyframes cardIn {
      from { opacity: 0; transform: translateY(32px) scale(.97); }
      to   { opacity: 1; transform: translateY(0)    scale(1); }
    }

    /* Header section */
    .card-head {
      background: linear-gradient(160deg, var(--blue-dark) 0%, var(--blue) 100%);
      padding: 36px 40px 30px;
      text-align: center;
      position: relative; overflow: hidden;
    }

    .card-head::after {
      content: '';
      position: absolute; bottom: 0; left: 0; right: 0;
      height: 3px;
      background: linear-gradient(90deg, transparent, var(--gold), transparent);
    }

    /* Logo area */
    .school-logo {
      width: 90px; height: 90px;
      border-radius: 50%;
      background: var(--white);
      display: flex; align-items: center; justify-content: center;
      margin: 0 auto 16px;
      padding: 6px;
      box-shadow: 0 4px 20px rgba(0,0,0,.3), 0 0 0 3px rgba(245,197,24,.5);
      overflow: hidden;
    }

    .school-logo img {
      width: 100%; height: 100%;
      object-fit: contain;
    }

    .school-logo .logo-fallback {
      font-size: 36px; color: var(--blue);
    }

    .card-head h1 {
      font-family: 'Source Sans 3', sans-serif;
      font-size: 17px; color: #fff; font-weight: 700;
      line-height: 1.3; margin-bottom: 4px;
    }

    .card-head .subtitle {
      font-size: 12px; color: rgba(255,255,255,.65);
      letter-spacing: .5px;
    }

    .card-head .sys-name {
      display: inline-block;
      margin-top: 12px;
      background: var(--gold);
      color: var(--blue-dark);
      font-size: 11px; font-weight: 700;
      padding: 4px 16px; border-radius: 20px;
      letter-spacing: 1px; text-transform: uppercase;
    }

    .topbar {
      position: relative;
      z-index: 5;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 18px;
      width: 100%;
      max-width: 520px;
      margin: 0 auto 18px;
      padding: 16px 18px;
      border-radius: 18px;
      background: rgba(255,255,255,.10);
      border: 1px solid rgba(255,255,255,.16);
      backdrop-filter: blur(12px);
      color: #f8fafc;
    }

    .topbar .brand {
      display: flex;
      flex-direction: column;
      gap: 4px;
    }

    .topbar .brand .title {
      font-size: 1rem;
      font-weight: 700;
      letter-spacing: .08em;
      color: #fff;
    }

    .topbar .brand .subtitle {
      font-size: .88rem;
      color: rgba(248,250,252,.78);
    }

    .public-nav {
      position: relative;
      z-index: 5;
      display: flex;
      justify-content: flex-end;
      gap: 10px;
      width: 100%;
      margin-bottom: 4px;
    }

    .public-nav a {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 10px 15px;
      border: 1px solid rgba(255,255,255,.3);
      border-radius: 8px;
      color: #fff;
      background: rgba(15,34,96,.38);
      font-weight: 700;
      text-decoration: none;
      transition: background .2s, border-color .2s;
    }

    .public-nav a:hover,
    .public-nav a[aria-current="page"] {
      color: var(--blue-dark);
      background: var(--gold);
      border-color: var(--gold);
    }

    .back-link {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 10px 14px;
      border-radius: 999px;
      background: rgba(248,250,252,.08);
      color: #f5c518;
      font-size: .92rem;
      font-weight: 700;
      border: 1px solid rgba(255,255,255,.12);
      transition: background .2s, transform .2s;
    }

    .back-link:hover {
      background: rgba(255,255,255,.16);
      transform: translateY(-1px);
    }

    /* Form body */
    .card-body {
      padding: 32px 36px 36px;
    }

    /* Alerts */
    .alert {
      padding: 11px 14px; border-radius: 9px;
      font-size: 13px; margin-bottom: 18px;
      display: flex; align-items: flex-start; gap: 9px;
    }
    .alert-error   { background: #fef2f2; color: var(--error); border-left: 3px solid var(--error); }
    .alert-timeout { background: #fffbeb; color: #92400e; border-left: 3px solid var(--gold); }

    /* Form fields */
    .form-group { margin-bottom: 18px; }

    .form-label {
      font-size: 12.5px; font-weight: 600;
      color: var(--text); display: block; margin-bottom: 7px;
    }

    .input-wrap { position: relative; }

    .input-wrap .field-icon {
      position: absolute; left: 13px; top: 50%;
      transform: translateY(-50%);
      color: var(--muted); font-size: 14px;
    }

    .form-input {
      width: 100%;
      padding: 12px 14px 12px 40px;
      border: 1.5px solid var(--border); border-radius: 10px;
      font-family: 'Source Sans 3', sans-serif;
      font-size: 14px; color: var(--text);
      background: #fafbff; outline: none;
      transition: border-color .2s, box-shadow .2s;
    }

    .form-input:focus {
      border-color: var(--blue);
      box-shadow: 0 0 0 3px rgba(26,58,140,.10);
      background: #fff;
    }

    .form-input[type="password"]::-ms-reveal,
    .form-input[type="password"]::-ms-clear,
    .form-input[type="password"]::-webkit-credentials-auto-fill-button {
      display: none;
      pointer-events: none;
      visibility: hidden;
    }

    .pw-toggle {
      position: absolute; right: 13px; top: 50%;
      transform: translateY(-50%);
      background: none; border: none; cursor: pointer;
      color: var(--muted); font-size: 14px; padding: 2px;
      z-index: 2;
    }

    .btn-login {
      width: 100%; padding: 13px;
      background: var(--blue); color: #fff;
      border: none; border-radius: 10px;
      font-family: 'Source Sans 3', sans-serif;
      font-size: 15px; font-weight: 700;
      cursor: pointer; margin-top: 6px;
      display: flex; align-items: center; justify-content: center; gap: 9px;
      transition: background .2s, box-shadow .2s, transform .1s;
      letter-spacing: .3px;
    }

    .btn-login:hover  { background: var(--blue-dark); box-shadow: 0 6px 20px rgba(26,58,140,.35); }
    .btn-login:active { transform: scale(.99); }

    .btn-login.loading {
      cursor: wait;
      pointer-events: none;
      opacity: .9;
    }

    .btn-login.loading .login-spinner {
      width: 15px;
      height: 15px;
      border: 2px solid rgba(255,255,255,.35);
      border-top-color: #fff;
      border-radius: 50%;
      animation: loginSpin .7s linear infinite;
      flex-shrink: 0;
    }

    @keyframes loginSpin {
      to { transform: rotate(360deg); }
    }

    .card-foot {
      text-align: center; padding: 14px;
      border-top: 1px solid var(--border);
      font-size: 11.5px; color: var(--muted);
      background: var(--bg);
    }

    .card-foot strong { color: var(--blue); }

    @media (max-width: 480px) {
      .page-layout { width: min(100% - 24px, 520px); padding-top: 16px; }
      .card-head { padding: 28px 24px 22px; }
      .card-body { padding: 24px 24px 28px; }
    }

    @media (max-width: 850px) {
      .portal-grid { grid-template-columns: 1fr; gap: 16px; }
      .landing-copy { max-width: 680px; padding: 24px 0 8px; }
      .landing-copy h2 { font-size: clamp(2.5rem, 8vw, 4rem); }
      .login-wrapper { padding-top: 8px; }
      .login-card { max-width: 560px; }
    }

    @media (max-width: 520px) {
      .topbar { padding: 14px; }
      .topbar .brand .subtitle { font-size: .8rem; }
      .landing-copy h2 { font-size: 2.65rem; }
      .landing-copy > p { font-size: 1rem; }
      .feature-list { grid-template-columns: 1fr; }
      .feature-item { min-height: auto; display: grid; grid-template-columns: 26px 1fr; column-gap: 8px; align-items: center; }
      .feature-item i { grid-row: span 2; margin-bottom: 0; }
    }
  </style>
</head>
<body>

<div class="gold-stripe"></div>

<div class="bg-canvas">
  <div class="deco-circle deco-circle-1"></div>
  <div class="deco-circle deco-circle-2"></div>
  <div class="deco-circle deco-circle-3"></div>
</div>

<div class="page-layout">
  <nav class="public-nav" aria-label="Public navigation">
    <a href="<?= BASE_URL ?>index.php" aria-current="page"><i class="fas fa-right-to-bracket"></i> Portal Login</a>
    <a href="<?= BASE_URL ?>deans-list.php"><i class="fas fa-award"></i> Dean's List</a>
  </nav>

  <div class="login-wrapper">
  <div class="portal-grid">
    <section class="landing-copy" aria-labelledby="welcome-title">
      <div class="eyebrow">Student portal</div>
      <h2 id="welcome-title">Everything you need for <span>student success.</span></h2>
      <p>Manage enrollment, grades, academic records, and student services in one secure portal designed for fast and easy access.</p>
      <div class="feature-list" aria-label="Portal features">
        <div class="feature-item">
          <i class="fas fa-chart-line"></i>
          <strong>Grade Monitoring</strong>
          <small>Track academic performance and standing with clear updates.</small>
        </div>
        <div class="feature-item">
          <i class="fas fa-user-graduate"></i>
          <strong>Student Records</strong>
          <small>Access profile, enrollment, and academic history in one place.</small>
        </div>
        <div class="feature-item">
          <i class="fas fa-shield-halved"></i>
          <strong>Secure Access</strong>
          <small>Reliable login and protected academic information for every user.</small>
        </div>
      </div>
    </section>

  <div class="login-card">

    <!-- Header -->
    <div class="card-head">
      <div class="school-logo">
        <img src="<?= BASE_URL ?>assets/img/neust-logo.png" alt="NEUST Logo"
             onerror="this.style.display='none';this.nextElementSibling.style.display='flex'"/>
        <span class="logo-fallback" style="display:none;"><i class="fas fa-university"></i></span>
      </div>
      <h1>Nueva Ecija University of<br>Science and Technology</h1>
      <div class="subtitle">Talavera Off-Campus</div>
      <span class="sys-name">ISARMS</span>
    </div>

    <!-- Form Body -->
    <div class="card-body">

      <?php if ($timeout): ?>
      <div class="alert alert-timeout">
        <i class="fas fa-clock"></i>
        Your session has expired. Please sign in again.
      </div>
      <?php endif; ?>

      <?php if ($error): ?>
      <div class="alert alert-error">
        <i class="fas fa-exclamation-circle"></i>
        <?= htmlspecialchars($error) ?>
      </div>
      <?php endif; ?>

      <!-- Single login form for both staff/faculty and students.
           The server figures out which account type matched the
           email and routes to the correct dashboard automatically. -->
      <form method="POST" id="loginForm" novalidate>
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>"/>

        <div class="form-group">
          <label class="form-label" for="email">Email</label>
          <div class="input-wrap">
            <i class="fas fa-envelope field-icon"></i>
            <input class="form-input" type="email" id="email" name="email"
                   placeholder="yourname@neust.edu.ph"
                   autocomplete="email"
                   value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required/>
          </div>
        </div>

        <div class="form-group">
          <label class="form-label" for="password">Password</label>
          <div class="input-wrap">
            <i class="fas fa-lock field-icon"></i>
            <input class="form-input" type="password" id="password" name="password"
                   placeholder="Enter your password"
                   autocomplete="current-password" required/>
            <button class="pw-toggle" type="button" onclick="togglePw(this)">
              <i class="fas fa-eye"></i>
            </button>
          </div>
        </div>

        <button class="btn-login" id="loginButton" type="submit">
          <i class="fas fa-sign-in-alt"></i>
          <span class="login-text">Sign In to Portal</span>
        </button>
      </form>
    </div>

    <!-- Footer -->
    <div class="card-foot">
      &copy; <?= date('Y') ?> <strong>NEUST Talavera Off-Campus</strong>
      &bull; Integrated Student Academic Records Management System
    </div>
  </div>
  </div>
  </div>
</div>

<script>
document.getElementById('loginForm').addEventListener('submit', function () {
  const button = document.getElementById('loginButton');
  if (button.classList.contains('loading')) return;

  button.classList.add('loading');
  button.disabled = true;
  button.innerHTML = '<span class="login-spinner"></span><span class="login-text">Signing In...</span>';
});

function togglePw(btn) {
  const inp  = btn.previousElementSibling;
  const icon = btn.querySelector('i');
  if (inp.type === 'password') {
    inp.type = 'text';
    icon.className = 'fas fa-eye-slash';
  } else {
    inp.type = 'password';
    icon.className = 'fas fa-eye';
  }
}
</script>
</body>
</html>