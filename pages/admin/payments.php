<?php
defined('BASE_PATH') || define('BASE_PATH', dirname(dirname(__DIR__)));
require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/includes/auth.php';
require_once BASE_PATH . '/includes/functions.php';

Auth::check('admin');

$yearId = (int)(getCurrentYear()['id'] ?? 0);
$errors = [];

$students = Database::fetchAll(
  'SELECT s.id AS student_id, s.matricule, u.first_name, u.last_name, c.name AS class_name
   FROM students s
   JOIN users u ON u.id=s.user_id
   JOIN classes c ON c.id=s.class_id
   WHERE s.academic_year_id=? AND s.status="enrolled"
   ORDER BY c.level, c.section, u.last_name, u.first_name
   LIMIT 800',
  [$yearId]
);

$installments = Database::fetchAll(
  'SELECT fi.id, fi.label, fi.amount, fi.due_date, f.level, f.label AS fee_label
   FROM fee_installments fi
   JOIN fees f ON f.id=fi.fee_id
   WHERE f.academic_year_id=?
   ORDER BY f.level, fi.number',
  [$yearId]
);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'record') {
  if (!Auth::verifyCsrf($_POST['csrf_token'] ?? '')) {
    $errors[] = 'Token invalide.';
  } else {
    $studentId = (int)($_POST['student_id'] ?? 0);
    $instId    = (int)($_POST['fee_installment_id'] ?? 0);
    $amount    = (float)($_POST['amount_paid'] ?? 0);
    $date      = sanitize($_POST['payment_date'] ?? date('Y-m-d'));
    $method    = sanitize($_POST['method'] ?? 'cash');
    $ref       = sanitize($_POST['transaction_ref'] ?? '');
    $notes     = trim($_POST['notes'] ?? '');

    $methodAllowed = ['cash','orange_money','mtn_momo','bank','other'];
    if (!in_array($method, $methodAllowed, true)) $method = 'cash';

    if (!$studentId || !$instId || $amount <= 0) $errors[] = 'Champs obligatoires.';
    if (empty($errors)) {
      $receipt = 'RC-' . date('Y') . '-' . strtoupper(bin2hex(random_bytes(3)));
      try {
        $pid = (int)Database::insert(
          'INSERT INTO payments (student_id,fee_installment_id,amount_paid,payment_date,method,transaction_ref,receipt_number,notes,recorded_by)
           VALUES (?,?,?,?,?,?,?,?,?)',
          [$studentId, $instId, $amount, $date, $method, $ref ?: null, $receipt, $notes ?: null, (int)Auth::id()]
        );
        // notifier parent(s)
        $parents = Database::fetchAll(
          'SELECT u.id AS parent_id
           FROM student_parents sp
           JOIN users u ON u.id=sp.parent_id
           WHERE sp.student_id=?',
          [$studentId]
        );
        foreach ($parents as $p) {
          createNotification((int)$p['parent_id'], 'payment', 'Paiement reçu', 'Reçu: ' . $receipt, BASE_URL . '/modules/parent/pages/fees.php');
        }

        setFlash('success', 'Paiement enregistré. Reçu: ' . $receipt);
        header('Location: ' . BASE_URL . '/modules/admin/pages/payments.php?receipt=' . $pid);
        exit;
      } catch (Throwable $e) {
        $errors[] = 'Erreur enregistrement paiement.';
      }
    }
  }
}

$receiptId = (int)($_GET['receipt'] ?? 0);
$receipt = null;
if ($receiptId) {
  $receipt = Database::fetchOne(
    'SELECT p.*, fi.label AS inst_label, fi.amount AS inst_amount, fi.due_date,
            f.label AS fee_label, f.level,
            s.matricule, CONCAT(u.first_name," ",u.last_name) AS student_name,
            c.name AS class_name,
            CONCAT(rec.first_name," ",rec.last_name) AS recorder_name
     FROM payments p
     JOIN fee_installments fi ON fi.id=p.fee_installment_id
     JOIN fees f ON f.id=fi.fee_id
     JOIN students s ON s.id=p.student_id
     JOIN users u ON u.id=s.user_id
     JOIN classes c ON c.id=s.class_id
     JOIN users rec ON rec.id=p.recorded_by
     WHERE p.id=?',
    [$receiptId]
  );
}

$recent = Database::fetchAll(
  'SELECT p.id, p.receipt_number, p.amount_paid, p.payment_date, p.method,
          CONCAT(u.first_name," ",u.last_name) AS student_name, s.matricule
   FROM payments p
   JOIN students s ON s.id=p.student_id
   JOIN users u ON u.id=s.user_id
   ORDER BY p.created_at DESC
   LIMIT 120'
);

$pageTitle = 'Encaissements';
$pageIcon  = '💰';
require_once BASE_PATH . '/includes/header.php';
?>

<?php if (!empty($errors)): ?><div class="flash flash-error"><?= e(implode(' ', $errors)) ?></div><?php endif; ?>

<?php if ($receipt): ?>
<div class="card mb-20" id="receipt">
  <div class="card-title">Reçu de paiement</div>
  <div style="display:flex;gap:8px;flex-wrap:wrap;">
    <a class="btn btn-secondary" href="#" onclick="window.print();return false;">Imprimer / Export PDF</a>
    <a class="btn btn-secondary" href="<?= BASE_URL ?>/modules/admin/pages/receipt-pdf.php?payment_id=<?= (int)$receipt['id'] ?>">PDF serveur</a>
    <a class="btn btn-secondary" href="<?= BASE_URL ?>/modules/admin/pages/payments.php">Retour</a>
  </div>
  <div style="margin-top:14px;border:1px solid var(--border);border-radius:14px;padding:18px;background:#fff;">
    <div style="display:flex;justify-content:space-between;gap:10px;">
      <div>
        <div style="font-weight:900;color:var(--navy);"><?= e(getSchool()['name'] ?? APP_NAME) ?></div>
        <div style="color:var(--muted);font-size:12px;">Reçu: <b><?= e($receipt['receipt_number']) ?></b></div>
      </div>
      <div style="text-align:right;color:var(--muted);font-size:12px;">
        Date: <b><?= e(formatDate($receipt['payment_date'])) ?></b><br>
        Méthode: <b><?= e($receipt['method']) ?></b>
      </div>
    </div>

    <div style="margin-top:12px;">
      <div style="font-weight:800;color:var(--navy);">Élève</div>
      <div style="color:var(--muted);font-size:13px;">
        <?= e($receipt['student_name']) ?> (#<?= e($receipt['matricule']) ?>) — <?= e($receipt['class_name']) ?>
      </div>
    </div>

    <div style="margin-top:12px;display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;">
      <div class="mini-stat"><div class="mini-stat-label">Échéance</div><div class="mini-stat-value"><?= e($receipt['inst_label']) ?></div></div>
      <div class="mini-stat"><div class="mini-stat-label">Montant payé</div><div class="mini-stat-value"><?= e(number_format((float)$receipt['amount_paid'],0,'',' ')) ?></div></div>
      <div class="mini-stat"><div class="mini-stat-label">Référence</div><div class="mini-stat-value"><?= e($receipt['transaction_ref'] ?? '—') ?></div></div>
    </div>

    <?php if (!empty($receipt['notes'])): ?>
      <div style="margin-top:12px;color:var(--muted);font-size:13px;">Note: <?= e($receipt['notes']) ?></div>
    <?php endif; ?>

    <div style="margin-top:18px;border-top:1px dashed var(--border);padding-top:12px;color:var(--muted);font-size:12px;">
      Enregistré par: <?= e($receipt['recorder_name']) ?>
    </div>
  </div>
</div>

<style>
@media print{
  .sidebar, .topbar, .btn, .card-title, .flash { display:none !important; }
  body { background:#fff !important; }
  #receipt { border:none !important; box-shadow:none !important; }
}
</style>
<?php endif; ?>

<div class="card mb-20">
  <div class="card-title">Enregistrer un paiement</div>
  <form method="post" style="display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;">
    <input type="hidden" name="action" value="record">
    <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
    <select class="form-select" name="student_id" required style="grid-column:span 2;">
      <option value="">Élève</option>
      <?php foreach ($students as $s): ?>
        <option value="<?= (int)$s['student_id'] ?>"><?= e($s['class_name'].' - '.$s['last_name'].' '.$s['first_name'].' (#'.$s['matricule'].')') ?></option>
      <?php endforeach; ?>
    </select>
    <select class="form-select" name="fee_installment_id" required style="grid-column:span 2;">
      <option value="">Échéance</option>
      <?php foreach ($installments as $i): ?>
        <option value="<?= (int)$i['id'] ?>">
          <?= e($i['level'].' - '.$i['fee_label'].' - '.$i['label'].' ('.number_format((float)$i['amount'],0,'',' ').')') ?>
        </option>
      <?php endforeach; ?>
    </select>
    <input class="form-input" type="number" step="1" name="amount_paid" placeholder="Montant payé" required>
    <input class="form-input" type="date" name="payment_date" value="<?= e(date('Y-m-d')) ?>" required>
    <select class="form-select" name="method">
      <option value="cash">Cash</option>
      <option value="orange_money">Orange Money</option>
      <option value="mtn_momo">MTN MoMo</option>
      <option value="bank">Banque</option>
      <option value="other">Autre</option>
    </select>
    <input class="form-input" name="transaction_ref" placeholder="Référence (optionnel)">
    <textarea class="form-input" name="notes" placeholder="Note (optionnel)" style="grid-column:span 4;min-height:70px;"></textarea>
    <button class="btn btn-primary" type="submit" style="grid-column:span 4;">Enregistrer</button>
  </form>
</div>

<div class="card">
  <div class="card-title">Paiements récents</div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Date</th><th>Élève</th><th>Montant</th><th>Méthode</th><th>Reçu</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($recent as $r): ?>
        <tr>
          <td><?= e(formatDate($r['payment_date'])) ?></td>
          <td><?= e($r['student_name'].' (#'.$r['matricule'].')') ?></td>
          <td><?= e(number_format((float)$r['amount_paid'],0,'',' ')) ?></td>
          <td><?= e($r['method']) ?></td>
          <td><?= e($r['receipt_number'] ?? '—') ?></td>
          <td style="text-align:right;">
            <a class="btn btn-secondary btn-sm" href="<?= BASE_URL ?>/modules/admin/pages/payments.php?receipt=<?= (int)$r['id'] ?>">Reçu</a>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (empty($recent)): ?><tr><td colspan="6">Aucun.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>

