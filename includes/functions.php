<?php
/**
 * SmartSchool Hub — Fonctions utilitaires globales
 */

require_once __DIR__ . '/../config/database.php';

if (!function_exists('locale')) {
    function locale(): string
    {
        $loc = $_SESSION['app_locale'] ?? APP_DEFAULT_LOCALE;
        return in_array($loc, APP_SUPPORTED_LOCALES, true) ? $loc : APP_DEFAULT_LOCALE;
    }
}

if (!function_exists('setAppLocale')) {
    function setAppLocale(string $loc): void
    {
        if (in_array($loc, APP_SUPPORTED_LOCALES, true)) {
            $_SESSION['app_locale'] = $loc;
        }
    }
}

if (!function_exists('tr')) {
    function tr(string $fr, string $en): string
    {
        return locale() === 'en' ? $en : $fr;
    }
}

// ── Formatage ──────────────────────────────────────────────────────────

function formatDate(string $date, string $format = 'd/m/Y'): string
{
    if (!$date || $date === '0000-00-00') return '—';
    return date($format, strtotime($date));
}

function formatDateTime(string $dt): string
{
    if (!$dt) return '—';
    return date('d/m/Y à H\hi', strtotime($dt));
}

function formatMoney(float $amount, string $currency = 'FCFA'): string
{
    return number_format($amount, 0, ',', ' ') . ' ' . $currency;
}

function formatGrade(?float $score, float $max = 20): string
{
    if ($score === null) return '—';
    return number_format($score, 2, '.', '') . '/' . (int)$max;
}

function gradeColor(?float $score, float $max = 20): string
{
    if ($score === null) return 'var(--muted)';
    $pct = ($score / $max) * 100;
    if ($pct >= 70) return 'var(--mint)';
    if ($pct >= 50) return 'var(--amber)';
    return 'var(--rose)';
}

function gradeBadge(?float $score, float $max = 20): array
{
    if ($score === null) return ['class' => 'badge-blue', 'label' => 'ABS'];
    $pct = ($score / $max) * 100;
    if ($pct >= 80) return ['class' => 'badge-green',  'label' => 'Excellent'];
    if ($pct >= 70) return ['class' => 'badge-green',  'label' => 'Très bien'];
    if ($pct >= 60) return ['class' => 'badge-blue',   'label' => 'Bien'];
    if ($pct >= 50) return ['class' => 'badge-amber',  'label' => 'Passable'];
    return              ['class' => 'badge-red',    'label' => 'Insuffisant'];
}

function timeAgo(string $datetime): string
{
    $now   = new DateTime();
    $past  = new DateTime($datetime);
    $diff  = $now->diff($past);

    if ($diff->y > 0) return 'il y a ' . $diff->y . ' an' . ($diff->y > 1 ? 's' : '');
    if ($diff->m > 0) return 'il y a ' . $diff->m . ' mois';
    if ($diff->d > 0) return 'il y a ' . $diff->d . ' jour' . ($diff->d > 1 ? 's' : '');
    if ($diff->h > 0) return 'il y a ' . $diff->h . 'h';
    if ($diff->i > 0) return 'il y a ' . $diff->i . ' min';
    return 'à l\'instant';
}

// ── Sécurité ───────────────────────────────────────────────────────────

function e(mixed $val): string
{
    return htmlspecialchars((string)$val, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function sanitize(string $str): string
{
    return trim(strip_tags($str));
}

function generateMatricule(string $year = ''): string
{
    if (!$year) $year = date('Y');
    $seq = Database::scalar('SELECT COUNT(*)+1 FROM students');
    return $year . '-' . str_pad($seq, 4, '0', STR_PAD_LEFT);
}

function generateReceiptNumber(): string
{
    return 'REC-' . strtoupper(substr(md5(uniqid()), 0, 8));
}

// ── Notifications ──────────────────────────────────────────────────────

function createNotification(
    int $userId,
    string $type,
    string $title,
    string $body,
    string $link = ''
): void {
    Database::insert(
        'INSERT INTO notifications (user_id, type, title, body, link) VALUES (?,?,?,?,?)',
        [$userId, $type, $title, $body, $link]
    );
}

function getUnreadNotifCount(int $userId): int
{
    return (int) Database::scalar(
        'SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0',
        [$userId]
    );
}

function getUnreadMsgCount(int $userId): int
{
    return (int) Database::scalar(
        'SELECT COUNT(*) FROM messages WHERE receiver_id = ? AND is_read = 0',
        [$userId]
    );
}

// ── Moyennes et statistiques ───────────────────────────────────────────

function computeSubjectAverage(int $studentId, int $classSubjectId, int $trimesterId): ?float
{
    $rows = Database::fetchAll(
        'SELECT g.score, e.coefficient
         FROM grades g
         JOIN evaluations e ON e.id = g.evaluation_id
         WHERE g.student_id = ?
           AND e.class_subject_id = ?
           AND e.trimester_id = ?
           AND g.is_absent = 0
           AND g.score IS NOT NULL',
        [$studentId, $classSubjectId, $trimesterId]
    );

    if (empty($rows)) return null;

    $total = 0;
    $coeffSum = 0;
    foreach ($rows as $r) {
        $total    += $r['score'] * $r['coefficient'];
        $coeffSum += $r['coefficient'];
    }
    return $coeffSum > 0 ? round($total / $coeffSum, 2) : null;
}

function computeGeneralAverage(int $studentId, int $classId, int $trimesterId): ?float
{
    $subjects = Database::fetchAll(
        'SELECT cs.id AS cs_id, cs.coefficient
         FROM class_subjects cs
         WHERE cs.class_id = ?',
        [$classId]
    );

    if (empty($subjects)) return null;

    $total = 0;
    $coeffSum = 0;
    foreach ($subjects as $s) {
        $avg = computeSubjectAverage($studentId, $s['cs_id'], $trimesterId);
        if ($avg !== null) {
            $total    += $avg * $s['coefficient'];
            $coeffSum += $s['coefficient'];
        }
    }
    return $coeffSum > 0 ? round($total / $coeffSum, 2) : null;
}

function getClassRank(int $studentId, int $classId, int $trimesterId): ?int
{
    $students = Database::fetchAll(
        'SELECT s.id FROM students s WHERE s.class_id = ? AND s.status = "enrolled"',
        [$classId]
    );

    $averages = [];
    foreach ($students as $s) {
        $avg = computeGeneralAverage($s['id'], $classId, $trimesterId);
        $averages[$s['id']] = $avg ?? -1;
    }

    arsort($averages);
    $rank = 1;
    foreach ($averages as $sid => $avg) {
        if ($sid === $studentId) return $rank;
        $rank++;
    }
    return null;
}

// ── Absences ───────────────────────────────────────────────────────────

function getStudentAbsenceCount(int $studentId, ?int $trimesterId = null): int
{
    if ($trimesterId) {
        return (int) Database::scalar(
            'SELECT COUNT(*)
             FROM attendance a
             JOIN timetable tt ON tt.class_subject_id = a.class_subject_id
             JOIN trimesters tr ON a.date BETWEEN tr.start_date AND tr.end_date
             WHERE a.student_id = ?
               AND tr.id = ?
               AND a.status IN ("absent","late")',
            [$studentId, $trimesterId]
        );
    }
    return (int) Database::scalar(
        'SELECT COUNT(*) FROM attendance WHERE student_id = ? AND status IN ("absent","late")',
        [$studentId]
    );
}

// ── École courante ─────────────────────────────────────────────────────

function getSchool(): ?array
{
    static $school = null;
    if ($school === null) {
        $school = Database::fetchOne('SELECT * FROM school LIMIT 1');
    }
    return $school;
}

function getCurrentYear(): ?array
{
    static $year = null;
    if ($year === null) {
        $year = Database::fetchOne('SELECT * FROM academic_years WHERE is_current = 1 LIMIT 1');
    }
    return $year;
}

function getCurrentTrimester(): ?array
{
    static $trim = null;
    if ($trim === null) {
        $trim = Database::fetchOne('SELECT * FROM trimesters WHERE is_current = 1 LIMIT 1');
    }
    return $trim;
}

// ── Upload fichiers ────────────────────────────────────────────────────

function uploadFile(array $file, string $subDir = 'misc'): ?string
{
    if ($file['error'] !== UPLOAD_ERR_OK) return null;
    if ($file['size'] > MAX_UPLOAD_SIZE) return null;

    $mime = mime_content_type($file['tmp_name']);
    $allowed = array_merge(ALLOWED_IMG_TYPES, ALLOWED_DOC_TYPES);
    if (!in_array($mime, $allowed, true)) return null;

    $ext  = pathinfo($file['name'], PATHINFO_EXTENSION);
    $name = bin2hex(random_bytes(16)) . '.' . strtolower($ext);
    $dir  = UPLOAD_PATH . '/' . $subDir;

    if (!is_dir($dir)) mkdir($dir, 0755, true);

    if (move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) {
        return 'uploads/' . $subDir . '/' . $name;
    }
    return null;
}

function handleFileUpload(array $file, string $subDir = 'misc'): array
{
    $result = ['success' => false, 'error' => null, 'file_path' => null, 'file_name' => null, 'file_size' => null, 'mime_type' => null];
    
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $result['error'] = 'Erreur lors du téléchargement du fichier.';
        return $result;
    }
    
    if ($file['size'] > MAX_UPLOAD_SIZE) {
        $result['error'] = 'Fichier trop volumineux.';
        return $result;
    }
    
    $mime = mime_content_type($file['tmp_name']);
    $allowed = array_merge(ALLOWED_IMG_TYPES, ALLOWED_DOC_TYPES);
    if (!in_array($mime, $allowed, true)) {
        $result['error'] = 'Type de fichier non autorisé.';
        return $result;
    }
    
    $ext  = pathinfo($file['name'], PATHINFO_EXTENSION);
    $name = bin2hex(random_bytes(16)) . '.' . strtolower($ext);
    $dir  = UPLOAD_PATH . '/' . $subDir;
    
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    
    if (move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) {
        $result['success'] = true;
        $result['file_path'] = 'uploads/' . $subDir . '/' . $name;
        $result['file_name'] = $file['name'];
        $result['file_size'] = $file['size'];
        $result['mime_type'] = $mime;
    } else {
        $result['error'] = 'Erreur lors de la sauvegarde du fichier.';
    }
    
    return $result;
}

// ── Pagination ─────────────────────────────────────────────────────────

function paginate(int $total, int $page, int $perPage = ITEMS_PER_PAGE): array
{
    $totalPages = max(1, (int) ceil($total / $perPage));
    $page       = max(1, min($page, $totalPages));
    $offset     = ($page - 1) * $perPage;

    return [
        'total'       => $total,
        'per_page'    => $perPage,
        'page'        => $page,
        'total_pages' => $totalPages,
        'offset'      => $offset,
        'has_prev'    => $page > 1,
        'has_next'    => $page < $totalPages,
    ];
}

// ── IA : score de risque ────────────────────────────────────────────────

function computeRiskScore(int $studentId, int $classId, int $trimesterId): float
{
    $score = 0;

    // Absences (max 40 points)
    $absences = getStudentAbsenceCount($studentId, $trimesterId);
    $score += min(40, $absences * 5);

    // Moyenne générale (max 40 points)
    $avg = computeGeneralAverage($studentId, $classId, $trimesterId);
    if ($avg !== null) {
        if ($avg < 6)       $score += 40;
        elseif ($avg < 8)   $score += 30;
        elseif ($avg < 10)  $score += 20;
        elseif ($avg < 12)  $score += 10;
    }

    // Discipline (max 20 points)
    $incidents = (int) Database::scalar(
        'SELECT COUNT(*) FROM discipline
         WHERE student_id = ? AND type NOT IN ("commendation") AND created_at > DATE_SUB(NOW(), INTERVAL 3 MONTH)',
        [$studentId]
    );
    $score += min(20, $incidents * 10);

    return min(100, round($score, 2));
}

function getRiskLevel(float $score): array
{
    if ($score >= 70) return ['level' => 'critical', 'label' => '🔴 Critique', 'class' => 'badge-red'];
    if ($score >= 40) return ['level' => 'warning',  'label' => '🟡 Modéré',  'class' => 'badge-amber'];
    return                   ['level' => 'info',     'label' => '🟢 Faible',  'class' => 'badge-green'];
}

// ── Flash messages ─────────────────────────────────────────────────────

function setFlash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function getFlash(): ?array
{
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

// ── Journal d'activité (audit) ──────────────────────────────────────────

function logActivity(string $action, ?string $target = null, ?int $targetId = null, ?string $details = null): void
{
    try {
        $userId = Auth::id() ?? null;
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;
        Database::insert(
            'INSERT INTO activity_log (user_id, action, target, target_id, details, ip, user_agent)
             VALUES (?,?,?,?,?,?,?)',
            [$userId, $action, $target, $targetId, $details, $ip, $ua]
        );
    } catch (Throwable $e) {
        // Ne jamais bloquer le flux utilisateur
    }
}

// ── Jours de la semaine ────────────────────────────────────────────────

function dayName(int $n): string
{
    return ['', 'Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi'][$n] ?? '?';
}
