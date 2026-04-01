<?php
defined('BASE_PATH') || define('BASE_PATH', dirname(dirname(__DIR__)));
require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/includes/auth.php';
require_once BASE_PATH . '/includes/functions.php';

Auth::check('parent');

$parentId = Auth::id();
$yearId   = getCurrentYear()['id'] ?? 0;

$children = Database::fetchAll(
    'SELECT s.id AS student_id, u.first_name, u.last_name, c.name AS class_name, c.level
     FROM student_parents sp
     JOIN students s ON s.id = sp.student_id
     JOIN users u ON u.id = s.user_id
     JOIN classes c ON c.id = s.class_id
     WHERE sp.parent_id = ? AND s.academic_year_id = ? AND s.status="enrolled"
     ORDER BY u.last_name',
    [$parentId, $yearId]
);

$selectedChildId = (int)($_GET['child_id'] ?? ($children[0]['student_id'] ?? 0));
$child = null;
foreach ($children as $c) { if ((int)$c['student_id'] === $selectedChildId) { $child = $c; break; } }
if (!$child && !empty($children)) { $child = $children[0]; $selectedChildId = (int)$child['student_id']; }

$feeStatus = [];
if ($child) {
    $feeStatus = Database::fetchAll(
        'SELECT fi.id, fi.label, fi.amount, fi.due_date, fi.number,
                p.id AS payment_id, p.amount_paid, p.payment_date, p.method, p.receipt_number
         FROM fees f
         JOIN fee_installments fi ON fi.fee_id = f.id
         LEFT JOIN payments p ON p.fee_installment_id = fi.id AND p.student_id = ?
         WHERE f.academic_year_id = ? AND f.level = ?
         ORDER BY fi.number',
        [$selectedChildId, $yearId, $child['level']]
    );
}

$pageTitle = 'Paiements';
$pageIcon  = '💰';
require_once BASE_PATH . '/includes/header.php';
?>

<?php if (count($children) > 1): ?>
<div class="card mb-20">
  <div class="card-title">Choisir un enfant</div>
  <div style="display:flex;gap:10px;flex-wrap:wrap;">
    <?php foreach ($children as $ch): ?>
      <a class="btn btn-secondary" href="?child_id=<?= (int)$ch['student_id'] ?>">
        <?= e($ch['first_name']) ?> (<?= e($ch['class_name']) ?>)
      </a>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<div class="card">
  <div class="card-title">Echeances <?= $child ? '— ' . e($child['first_name'] . ' ' . $child['last_name']) : '' ?></div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Tranche</th><th>Montant</th><th>Echeance</th><th>Statut</th><th>Recu</th></tr></thead>
      <tbody>
      <?php foreach ($feeStatus as $fi): ?>
        <?php
          $paid = !empty($fi['payment_id']);
          $late = !$paid && strtotime($fi['due_date']) < time();
        ?>
        <tr>
          <td><?= e($fi['label']) ?></td>
          <td><?= e(formatMoney((float)$fi['amount'])) ?></td>
          <td><?= e(formatDate($fi['due_date'])) ?></td>
          <td>
            <span class="badge <?= $paid ? 'badge-green' : ($late ? 'badge-red' : 'badge-amber') ?>">
              <?= $paid ? 'Payee' : ($late ? 'En retard' : 'A venir') ?>
            </span>
          </td>
          <td><?= $paid ? e($fi['receipt_number'] ?? 'OK') : '—' ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (empty($feeStatus)): ?><tr><td colspan="5">Aucune grille tarifaire configuree.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>
