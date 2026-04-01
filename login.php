<?php
/**
 * SmartSchool Hub — Page de connexion
 */
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/config/database.php';

// Déjà connecté ?
if (Auth::isLoggedIn()) {
    header('Location: ' . Auth::getDashboardUrl(Auth::role()));
    exit;
}

$error    = '';
$success  = '';
$email    = '';

// Traitement du formulaire de connexion
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = sanitize($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($email) || empty($password)) {
        $error = 'Veuillez renseigner votre email et votre mot de passe.';
    } else {
        $result = Auth::login($email, $password);
        if ($result['success']) {
            $redirect = $_GET['redirect'] ?? $result['redirect'];
            header('Location: ' . $redirect);
            exit;
        } else {
            $error = $result['message'];
        }
    }
}

// Informations de l'école
$school = Database::fetchOne('SELECT name, short_name, logo FROM school LIMIT 1');
$schoolName = $school['name'] ?? APP_NAME;
$year = Database::fetchOne('SELECT label FROM academic_years WHERE is_current = 1 LIMIT 1');
$yearLabel = $year['label'] ?? date('Y') . '-' . (date('Y') + 1);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Connexion — <?= e($schoolName) ?></title>
  <link rel="stylesheet" href="<?= ASSETS_URL ?>/css/style.css">
  <style>
    body { background: var(--navy); }
    .login-page { min-height: 100vh; }
  </style>
</head>
<body>

<div class="login-page">

  <!-- ── Côté gauche : branding ── -->
  <div class="login-left">
    <div class="login-left-logo">🎓</div>
    <h1>La gestion scolaire<br><span>réinventée</span></h1>
    <p>
      <?= e($schoolName) ?> utilise SmartSchool Hub pour centraliser toute la vie de l'établissement —
      notes, absences, paiements, communication — sur une seule plateforme intelligente.
    </p>
    <div class="login-stats">
      <div class="login-stat">
        <span class="login-stat-num" id="js-students">—</span>
        <span class="login-stat-label">Élèves inscrits</span>
      </div>
      <div class="login-stat">
        <span class="login-stat-num" id="js-teachers">—</span>
        <span class="login-stat-label">Enseignants</span>
      </div>
      <div class="login-stat">
        <span class="login-stat-num"><?= e($yearLabel) ?></span>
        <span class="login-stat-label">Année scolaire</span>
      </div>
    </div>

    <!-- Décorations flottantes -->
    <div style="position:absolute;bottom:40px;left:60px;right:60px;z-index:1;">
      <div style="display:flex;gap:10px;flex-wrap:wrap;">
        <span style="background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.12);
          border-radius:20px;padding:6px 14px;font-size:12px;font-weight:600;color:rgba(255,255,255,.6);">
          📊 Notes en temps réel
        </span>
        <span style="background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.12);
          border-radius:20px;padding:6px 14px;font-size:12px;font-weight:600;color:rgba(255,255,255,.6);">
          🤖 IA prédictive
        </span>
        <span style="background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.12);
          border-radius:20px;padding:6px 14px;font-size:12px;font-weight:600;color:rgba(255,255,255,.6);">
          📱 Notifications SMS
        </span>
        <span style="background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.12);
          border-radius:20px;padding:6px 14px;font-size:12px;font-weight:600;color:rgba(255,255,255,.6);">
          💰 Orange Money / MoMo
        </span>
      </div>
    </div>
  </div>

  <!-- ── Côté droit : formulaire ── -->
  <div class="login-right">

    <div style="margin-bottom:32px;">
      <div style="display:flex;align-items:center;gap:12px;margin-bottom:20px;">
        <div style="width:48px;height:48px;border-radius:14px;background:var(--grad2);
          display:flex;align-items:center;justify-content:center;font-size:24px;
          box-shadow:0 4px 14px rgba(26,86,219,.3);">🎓</div>
        <div>
          <div style="font-family:'Sora',sans-serif;font-weight:800;color:var(--navy);font-size:16px;">SmartSchool Hub</div>
          <div style="font-size:11px;color:var(--muted);"><?= e($schoolName) ?></div>
        </div>
      </div>
      <h2 style="font-family:'Sora',sans-serif;font-size:26px;font-weight:700;color:var(--navy);margin-bottom:6px;">
        Bon retour 👋
      </h2>
      <p style="font-size:14px;color:var(--muted);">
        Connectez-vous à votre espace personnel
      </p>
    </div>

    <!-- Message d'erreur -->
    <?php if ($error): ?>
    <div class="flash flash-error" id="flash-error">
      <span>⚠️</span> <?= e($error) ?>
    </div>
    <?php endif; ?>

    <!-- Sélection de rôle (visuelle uniquement) -->
    <div class="role-select" id="role-select" style="margin-bottom:24px;">
      <div class="role-btn active" data-role="admin" onclick="selectRole(this)">
        <span class="role-btn-icon">🏫</span>
        <span class="role-btn-label">Admin</span>
      </div>
      <div class="role-btn" data-role="teacher" onclick="selectRole(this)">
        <span class="role-btn-icon">👩‍🏫</span>
        <span class="role-btn-label">Enseignant</span>
      </div>
      <div class="role-btn" data-role="parent" onclick="selectRole(this)">
        <span class="role-btn-icon">👨‍👧</span>
        <span class="role-btn-label">Parent</span>
      </div>
      <div class="role-btn" data-role="student" onclick="selectRole(this)">
        <span class="role-btn-icon">🎒</span>
        <span class="role-btn-label">Élève</span>
      </div>
    </div>

    <!-- Formulaire de connexion -->
    <form method="POST" action="" novalidate>
      <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">

      <div class="form-group">
        <label class="form-label" for="email">Adresse e-mail</label>
        <input
          class="form-input"
          type="email"
          id="email"
          name="email"
          value="<?= e($email) ?>"
          placeholder="directeur@monecole.cm"
          autocomplete="email"
          required
        >
      </div>

      <div class="form-group">
        <label class="form-label" for="password">Mot de passe</label>
        <div style="position:relative;">
          <input
            class="form-input"
            type="password"
            id="password"
            name="password"
            placeholder="••••••••••••"
            autocomplete="current-password"
            required
            style="padding-right:48px;"
          >
          <button type="button" onclick="togglePwd()" style="
            position:absolute;right:14px;top:50%;transform:translateY(-50%);
            background:none;border:none;cursor:pointer;font-size:18px;
            color:var(--muted);padding:0;
          " title="Afficher le mot de passe" id="pwd-toggle">👁</button>
        </div>
      </div>

      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:24px;">
        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:13px;color:var(--muted);">
          <input type="checkbox" name="remember" style="accent-color:var(--blue);">
          Se souvenir de moi
        </label>
        <a href="<?= BASE_URL ?>/reset-password.php" style="font-size:13px;font-weight:600;">
          Mot de passe oublié ?
        </a>
      </div>

      <button type="submit" class="btn btn-primary btn-full btn-lg" id="login-btn">
        <span id="btn-text">Se connecter →</span>
        <span id="btn-loader" style="display:none;">⏳ Connexion…</span>
      </button>
    </form>

    <p style="text-align:center;margin-top:24px;font-size:12px;color:var(--muted);">
      SmartSchool Hub v<?= APP_VERSION ?> · 
      <a href="<?= BASE_URL ?>/install/" style="color:var(--muted);">Support</a>
    </p>
  </div>
</div>

<script>
// Sélection du rôle (UX visuelle seulement)
function selectRole(btn) {
  document.querySelectorAll('.role-btn').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  // Suggestion placeholder email selon rôle
  const ph = {
    admin:   'directeur@monecole.cm',
    teacher: 'p.mbarga@monecole.cm',
    parent:  'parent@gmail.com',
    student: 'jean.pierre@monecole.cm',
  };
  document.getElementById('email').placeholder = ph[btn.dataset.role] || ph.admin;
}

// Afficher/cacher le mot de passe
function togglePwd() {
  const inp = document.getElementById('password');
  const btn = document.getElementById('pwd-toggle');
  if (inp.type === 'password') { inp.type = 'text'; btn.textContent = '🙈'; }
  else                         { inp.type = 'password'; btn.textContent = '👁'; }
}

// Loader sur submit
document.querySelector('form').addEventListener('submit', function() {
  document.getElementById('btn-text').style.display = 'none';
  document.getElementById('btn-loader').style.display = 'inline';
  document.getElementById('login-btn').disabled = true;
});

// Stats live via AJAX
fetch('<?= BASE_URL ?>/api/stats.php')
  .then(response => {
    if (!response.ok) {
      throw new Error("Réponse réseau non valide");
    }
    return response.json();
  })
  .then(data => {
    if (data.students !== undefined) {
      document.getElementById('js-students').textContent =
        data.students.toLocaleString('fr');
    } else {
      document.getElementById('js-students').textContent = '—';
    }

    if (data.teachers !== undefined) {
      document.getElementById('js-teachers').textContent = data.teachers;
    } else {
      document.getElementById('js-teachers').textContent = '—';
    }
  })
  .catch(error => {
    console.error("Erreur lors de la récupération des stats :", error);
    document.getElementById('js-students').textContent = '—';
    document.getElementById('js-teachers').textContent = '—';
  });


// Auto-dismiss flash après 5s
setTimeout(() => {
  const f = document.getElementById('flash-error');
  if (f) { f.style.opacity = '0'; f.style.transition = 'opacity .4s'; setTimeout(() => f.remove(), 400); }
}, 5000);
</script>
</body>
</html>
