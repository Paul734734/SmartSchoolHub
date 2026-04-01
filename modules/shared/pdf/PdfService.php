<?php

class PdfService
{
    public static function dompdfAvailable(): bool
    {
        if (class_exists(\Dompdf\Dompdf::class)) return true;
        $autoload = BASE_PATH . '/vendor/autoload.php';
        if (file_exists($autoload)) {
            require_once $autoload;
        }
        return class_exists(\Dompdf\Dompdf::class);
    }

    /**
     * @return array{file_path:string, sha256:string}|null
     */
    public static function generateAndStore(string $html, string $type, ?int $studentId, ?int $refId, ?int $createdBy, string $baseName): ?array
    {
        if (!self::dompdfAvailable()) return null;

        $dompdf = new \Dompdf\Dompdf([
            'isRemoteEnabled' => true,
            'isHtml5ParserEnabled' => true,
        ]);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $pdf = $dompdf->output();

        $dir = UPLOAD_PATH . '/docs';
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $fileName = $baseName . '-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.pdf';
        $absPath = $dir . '/' . $fileName;
        file_put_contents($absPath, $pdf);

        $rel = 'uploads/docs/' . $fileName;
        $sha = hash('sha256', $pdf);

        try {
            Database::insert(
                'INSERT INTO generated_documents (type, student_id, ref_id, file_path, sha256, created_by)
                 VALUES (?,?,?,?,?,?)',
                [$type, $studentId, $refId, $rel, $sha, $createdBy]
            );
        } catch (Throwable $e) {
            // archive table optional; ignore if not present
        }

        return ['file_path' => $rel, 'sha256' => $sha];
    }
}

