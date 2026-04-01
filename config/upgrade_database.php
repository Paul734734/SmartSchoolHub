<?php
/**
 * SmartSchool Hub — Script de mise à jour automatique de la base de données
 */

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';

echo "<h2>🚀 Mise à jour SmartSchool Hub v1.1</h2>";

try {
    // Lire le fichier de mise à jour
    $updateFile = BASE_PATH . '/config/schema_updates.sql';
    if (!file_exists($updateFile)) {
        throw new Exception("Fichier de mise à jour introuvable");
    }
    
    $sql = file_get_contents($updateFile);
    $statements = array_filter(array_map('trim', explode(';', $sql)));
    
    echo "<h3>⚡ Exécution des mises à jour...</h3>";
    
    foreach ($statements as $i => $statement) {
        if (empty($statement)) continue;
        
        try {
            Database::execute($statement);
            echo "<div style='color:green;'>✅ Étape " . ($i + 1) . ": Exécutée avec succès</div>";
        } catch (Exception $e) {
            // Ignorer les erreurs de type "already exists"
            if (strpos($e->getMessage(), 'Duplicate') !== false || 
                strpos($e->getMessage(), 'already exists') !== false) {
                echo "<div style='color:orange;'>⚠️ Étape " . ($i + 1) . ": Déjà existante</div>";
            } else {
                throw $e;
            }
        }
    }
    
    echo "<h3 style='color:green;'>✅ Mise à jour terminée avec succès!</h3>";
    echo "<p><strong>Super-Admin créé:</strong> admin@smartschoolhub.com / password</p>";
    echo "<p><a href='" . BASE_URL . "/login.php'>→ Se connecter</a></p>";
    
} catch (Exception $e) {
    echo "<h3 style='color:red;'>❌ Erreur lors de la mise à jour:</h3>";
    echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
}
?>
