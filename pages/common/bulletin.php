<?php
defined('BASE_PATH') || define('BASE_PATH', dirname(dirname(__DIR__)));
require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/includes/auth.php';
require_once BASE_PATH . '/includes/functions.php';

Auth::check('admin','teacher','parent','student');

$role = Auth::role();
$userId = (int)Auth::id();
$year = getCurrentYear();
$trim = getCurrentTrimester();
$yearId = (int)($year['id'] ?? 0);
$trimId = (int)($_GET['trimester_id'] ?? ($trim['id'] ?? 0));

// Choix de l'élève
$studentId = (int)($_GET['student_id'] ?? 0);

if ($role === 'student') {
    $studentId = (int)Database::scalar('SELECT id FROM students WHERE user_id=? AND academic_year_id=?', [$userId, $yearId]);
}

if ($role === 'parent' && !$studentId) {
    $studentId = (int)Database::scalar(
        'SELECT s.id
         FROM student_parents sp
         JOIN students s ON s.id=sp.student_id
         WHERE sp.parent_id=? AND s.academic_year_id=?
         ORDER BY sp.is_primary DESC, s.id ASC
         LIMIT 1',
        [$userId, $yearId]
    );
}

// Vérifier droits parent sur l'élève
if ($role === 'parent' && $studentId) {
    $ok = (int)Database::scalar(
        'SELECT COUNT(*) FROM student_parents sp
         JOIN students s ON s.id=sp.student_id
         WHERE sp.parent_id=? AND sp.student_id=? AND s.academic_year_id=?',
        [$userId, $studentId, $yearId]
    );
    if (!$ok) { http_response_code(403); die('Acces refuse.'); }
}

// Données élève + classe
$stu = null;
if ($studentId) {
    $stu = Database::fetchOne(
        'SELECT s.id AS student_id, s.matricule, s.class_id, u.first_name, u.last_name, c.name AS class_name
         FROM students s
         JOIN users u ON u.id = s.user_id
         JOIN classes c ON c.id = s.class_id
         WHERE s.id = ? AND s.academic_year_id = ?
         LIMIT 1',
        [$studentId, $yearId]
    );
}
if (!$stu) { setFlash('error','Eleve introuvable.'); header('Location: '.BASE_URL.'/modules/common/pages/coming-soon.php?module=Bulletin'); exit; }

$classId = (int)$stu['class_id'];

$trimRow = Database::fetchOne('SELECT * FROM trimesters WHERE id=?', [$trimId]);
if (!$trimRow) { $trimRow = getCurrentTrimester(); $trimId = (int)($trimRow['id'] ?? 0); }

$subjects = Database::fetchAll(
  'SELECT cs.id AS cs_id, cs.coefficient, sub.name AS subject_name, sub.short_name
   FROM class_subjects cs
   JOIN subjects sub ON sub.id = cs.subject_id
   WHERE cs.class_id = ?
   ORDER BY sub.name',
  [$classId]
);

$rows = [];
foreach ($subjects as $s) {
    $avg = computeSubjectAverage($studentId, (int)$s['cs_id'], $trimId);
    $rows[] = [
        'subject' => $s['subject_name'],
        'coeff' => (float)$s['coefficient'],
        'avg' => $avg,
    ];
}

$generalAvg = computeGeneralAverage($studentId, $classId, $trimId);
$rank = getClassRank($studentId, $classId, $trimId);
$absences = getStudentAbsenceCount($studentId, $trimId);
$discCount = (int)Database::scalar(
  'SELECT COUNT(*) FROM discipline
   WHERE student_id=? AND date BETWEEN ? AND ? AND type NOT IN ("commendation")',
  [$studentId, $trimRow['start_date'] ?? '1900-01-01', $trimRow['end_date'] ?? '2999-12-31']
);

$mention = '—';
if ($generalAvg !== null) {
    if ($generalAvg >= 16) $mention = 'Très Bien';
    elseif ($generalAvg >= 14) $mention = 'Bien';
    elseif ($generalAvg >= 12) $mention = 'Assez Bien';
    elseif ($generalAvg >= 10) $mention = 'Passable';
    else $mention = 'Insuffisant';
}

$school = getSchool();
$pageTitle = 'Bulletin';
$pageIcon  = '📄';
require_once BASE_PATH . '/includes/header.php';
?>

<div class="card mb-20">
  <div class="card-title">Bulletin trimestriel</div>
  <div style="display:flex;gap:8px;flex-wrap:wrap;">
    <a class="btn btn-secondary" href="#" onclick="window.print();return false;">Imprimer / Export PDF (navigateur)</a>
    <a class="btn btn-secondary" href="<?= BASE_URL ?>/modules/common/pages/bulletin-pdf.php?student_id=<?= (int)$stu['student_id'] ?>&trimester_id=<?= (int)$trimId ?>">PDF serveur</a>
    <?php if ($role === 'admin' || $role === 'teacher'): ?>
      <a class="btn btn-secondary" href="<?= BASE_URL ?>/modules/admin/pages/students.php">Retour</a>
    <?php endif; ?>
  </div>
</div>

<div class="card" id="bulletin" style="padding:22px;">
  <div style="display:flex;justify-content:space-between;gap:14px;align-items:flex-start;">
    <div>
      <div style="font-size:18px;font-weight:900;color:var(--navy);"><?= e($school['name'] ?? APP_NAME) ?></div>
      <div style="color:var(--muted);font-size:12px;margin-top:4px;">
        Bulletin — <?= e($trimRow['label'] ?? 'Trimestre') ?> · <?= e($year['label'] ?? '') ?>
      </div>
    </div>
    <div style="text-align:right;">
      <div style="font-weight:900;color:var(--navy);"><?= e($stu['last_name'].' '.$stu['first_name']) ?></div>
      <div style="color:var(--muted);font-size:12px;">Matricule: <?= e($stu['matricule']) ?> · Classe: <?= e($stu['class_name']) ?></div>
    </div>
  </div>

  <div style="display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin:16px 0;">
    <div class="mini-stat"><div class="mini-stat-label">Moyenne générale</div><div class="mini-stat-value"><?= $generalAvg!==null?e(number_format($generalAvg,2)):'—' ?></div></div>
    <div class="mini-stat"><div class="mini-stat-label">Rang</div><div class="mini-stat-value"><?= $rank?e((string)$rank):'—' ?></div></div>
    <div class="mini-stat"><div class="mini-stat-label">Absences/retards</div><div class="mini-stat-value"><?= (int)$absences ?></div></div>
    <div class="mini-stat"><div class="mini-stat-label">Mention</div><div class="mini-stat-value"><?= e($mention) ?></div></div>
  </div>

  <div class="table-wrap">
    <table>
      <thead><tr><th>Matière</th><th>Coeff.</th><th>Moyenne</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><?= e($r['subject']) ?></td>
          <td><?= e(number_format((float)$r['coeff'], 1)) ?></td>
          <td><?= $r['avg']!==null?e(number_format($r['avg'],2)):'—' ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (empty($rows)): ?><tr><td colspan="3">Aucune matière.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>

  <div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px;margin-top:14px;">
    <div class="card" style="box-shadow:none;border:1px solid var(--border);">
      <div class="card-title">Synthèse discipline</div>
      <div style="color:var(--muted);font-size:13px;">
        Incidents trimestre: <b><?= (int)$discCount ?></b>
      </div>
    </div>
    <div class="card" style="box-shadow:none;border:1px solid var(--border);">
      <div class="card-title">Appreciation</div>
      <div style="color:var(--muted);font-size:13px;">
        <?= e($generalAvg!==null && $generalAvg>=12 ? 'Bon ensemble, continue.' : 'Des efforts sont attendus, un plan de remédiation est recommandé.') ?>
      </div>
    </div>
  </div>

  <div style="margin-top:18px;display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:20px;">
    <div style="border-top:1px dashed var(--border);padding-top:14px;color:var(--muted);font-size:12px;">
      Signature Professeur principal: ____________________
    </div>
    <div style="border-top:1px dashed var(--border);padding-top:14px;color:var(--muted);font-size:12px;text-align:right;">
      Signature Direction: ____________________
    </div>
  </div>
</div>

<style>
@media print{
  .sidebar, .topbar, .btn, .card-title, .flash { display:none !important; }
  body { background:#fff !important; }
  #bulletin { border:none !important; box-shadow:none !important; }
}
</style>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>

