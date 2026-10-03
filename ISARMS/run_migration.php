<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/**
 * Database Migration Runner
 * Applies the additive project migrations from the CLI.
 */
require_once 'includes/config.php';

$db = Database::getInstance();

$migrationFiles = [
    'database/migration_add_critical_features.sql',
    'database/migration_deployment_readiness.sql',
    'database/migration_allow_unassigned_section_teachers.sql',
    'database/migration_normalize_grade_statuses.sql',
    'database/migration_auto_post_saved_grades.sql',
    'database/migration_dean_list_publications.sql',
    'database/migration_subject_gwa_rules.sql',
];
$success = 0;
$failed = 0;
$errors = [];

foreach ($migrationFiles as $migrationFile) {
    $migrationSQL = file_get_contents(__DIR__ . '/' . $migrationFile);
    if ($migrationSQL === false) {
        $failed++;
        $errors[] = ['statement'=>$migrationFile, 'error'=>'Migration file could not be read.'];
        continue;
    }

    $lines = array_filter(array_map('trim', explode("\n", $migrationSQL)));
    $statement = '';
    foreach ($lines as $line) {
        if (strpos($line, '--') === 0) continue;
        $statement .= ' ' . $line;

        if (substr(rtrim($line), -1) === ';') {
            $stmt = trim($statement);
            if ($stmt !== '') {
                $stmt = rtrim($stmt, ';');
                try {
                    $db->query($stmt);
                    $success++;
                    echo "✓ Success\n";
                } catch (Exception $e) {
                    $failed++;
                    $errors[] = [
                        'statement' => substr($stmt, 0, 100) . '...',
                        'error' => $e->getMessage()
                    ];
                    echo "✗ Error: " . $e->getMessage() . "\n";
                }
            }
            $statement = '';
        }
    }
}

echo "\n=== MIGRATION COMPLETE ===\n";
echo "Success: $success\n";
echo "Failed: $failed\n";
if (!empty($errors)) {
    echo "\nErrors:\n";
    foreach ($errors as $err) {
        echo "  - " . $err['error'] . "\n";
    }
}
if ($failed > 0) exit(1);
?>
