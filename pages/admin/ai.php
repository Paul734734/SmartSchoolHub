<?php
defined('BASE_PATH') || define('BASE_PATH', dirname(dirname(__DIR__)));
require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/includes/auth.php';
require_once BASE_PATH . '/includes/functions.php';

Auth::check('admin');

$yearId = (int)(getCurrentYear()['id'] ?? 0);
$classId = (int)($_GET['class_id'] ?? 0);
$level = sanitize($_GET['level'] ?? '');
$q = sanitize($_GET['q'] ?? '');

if (isset($_GET['resolve']) && ctype_digit((string)$_GET['resolve'])) {
    $id = (int)$_GET['resolve'];
    Database::execute('UPDATE ai_alerts SET is_resolved=1 WHERE id=?', [$id]);
    setFlash('success','Alerte résolue.');
    header('Location: ' . BASE_URL . '/modules/admin/pages/ai.php');
    exit;
}

$classes = Database::fetchAll('SELECT id, name FROM classes WHERE academic_year_id=? ORDER BY level, section', [$yearId]);

$where = 'WHERE a.is_resolved=0';
$params = [];
if ($level !== '' && in_array($level, ['info','warning','critical'], true)) {
    $where .= ' AND a.level=?';
    $params[] = $level;
}
if ($classId) {
    $where .= ' AND s.class_id=?';
    $params[] = $classId;
}
if ($q !== '') {
    $where .= ' AND (u.first_name LIKE ? OR u.last_name LIKE ? OR s.matricule LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like);
}

$rows = Database::fetchAll(
  'SELECT a.*, s.matricule, c.name AS class_name, CONCAT(u.first_name," ",u.last_name) AS student_name
   FROM ai_alerts a
   JOIN students s ON s.id=a.student_id
   JOIN users u ON u.id=s.user_id
   JOIN classes c ON c.id=s.class_id
   ' . $where . '
   ORDER BY FIELD(a.level,"critical","warning","info"), a.created_at DESC
   LIMIT 300',
  $params
);

// Matrice risque (score calculé)
$trimId = (int)(getCurrentTrimester()['id'] ?? 0);
$risk = Database::fetchAll(
  'SELECT s.id AS student_id, s.matricule, c.name AS class_name, c.id AS class_id,
          CONCAT(u.first_name," ",u.last_name) AS student_name
   FROM students s
   JOIN users u ON u.id=s.user_id
   JOIN classes c ON c.id=s.class_id
   WHERE s.academic_year_id=? AND s.status="enrolled"
   ' . ($classId ? ' AND s.class_id='.(int)$classId : '') . '
   ORDER BY c.level, c.section, u.last_name
   LIMIT 200',
  [$yearId]
);

foreach ($risk as &$r) {
    $score = computeRiskScore((int)$r['student_id'], (int)$r['class_id'], $trimId);
    $r['risk_score'] = $score;
    $r['risk_level'] = getRiskLevel($score);
}
unset($r);

$pageTitle = 'Module IA';
$pageIcon  = '🤖';
require_once BASE_PATH . '/includes/header.php';
?>

<div class="card mb-20">
  <div class="card-title">Filtres</div>
  <form method="get" style="display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;">
    <input class="form-input" name="q" value="<?= e($q) ?>" placeholder="Élève / matricule">
    <select class="form-select" name="class_id">
      <option value="0">Toutes classes</option>
      <?php foreach ($classes as $c): ?>
        <option value="<?= (int)$c['id'] ?>" <?= (int)$c['id']===$classId?'selected':'' ?>><?= e($c['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <select class="form-select" name="level">
      <option value="">Tous niveaux</option>
      <?php foreach (['critical','warning','info'] as $lv): ?>
        <option value="<?= e($lv) ?>" <?= $level===$lv?'selected':'' ?>><?= e($lv) ?></option>
      <?php endforeach; ?>
    </select>
    <button class="btn btn-secondary" type="submit">Appliquer</button>
  </form>
</div>

<div class="card mb-20">
  <div class="card-title">Alertes (non résolues)</div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Niveau</th><th>Élève</th><th>Classe</th><th>Catégorie</th><th>Message</th><th>Date</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><span class="badge <?= $r['level']==='critical'?'badge-red':($r['level']==='warning'?'badge-amber':'badge-green') ?>"><?= e($r['level']) ?></span></td>
          <td><?= e($r['student_name'].' (#'.$r['matricule'].')') ?></td>
          <td><?= e($r['class_name']) ?></td>
          <td><?= e($r['category']) ?></td>
          <td><?= e(substr($r['message'],0,80)) ?><?= strlen($r['message'])>80?'…':'' ?></td>
          <td><?= e(timeAgo($r['created_at'])) ?></td>
          <td style="text-align:right;"><a class="btn btn-secondary btn-sm" href="?resolve=<?= (int)$r['id'] ?>" onclick="return confirm('Marquer résolue ?')">Résolu</a></td>
        </tr>
      <?php endforeach; ?>
      <?php if (empty($rows)): ?><tr><td colspan="7">Aucune alerte.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="card">
  <div class="card-title">Matrice de risque (calcul)</div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Élève</th><th>Classe</th><th>Score</th><th>Niveau</th></tr></thead>
      <tbody>
      <?php foreach ($risk as $r): ?>
        <tr>
          <td><?= e($r['student_name'].' (#'.$r['matricule'].')') ?></td>
          <td><?= e($r['class_name']) ?></td>
          <td><?= e(number_format((float)$r['risk_score'], 2)) ?></td>
          <td><span class="badge <?= e($r['risk_level']['class']) ?>"><?= e($r['risk_level']['label']) ?></span></td>
        </tr>
      <?php endforeach; ?>
      <?php if (empty($risk)): ?><tr><td colspan="4">Aucun élève.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>

