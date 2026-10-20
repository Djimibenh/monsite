
<?php

// Paramètres de connexion à la base de données
$host = '127.0.0.1';
$port = '3306';
$dbname = 'concours_prison';
$username = 'root';
$password = 'djamal24682468';

// Chaîne de connexion
$dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4";

// Options PDO
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];

try {
    // Connexion à MySQL
    $pdo = new PDO($dsn, $username, $password, $options);

} catch (PDOException $e) {
    // Enregistrer l'erreur dans le journal PHP
    error_log(
        'Erreur de connexion MySQL : ' . $e->getMessage()
    );

    http_response_code(500);
    exit(
        'تعذر الاتصال بقاعدة البيانات. تحقق من تشغيل MySQL وإعدادات الاتصال.'
    );
}
