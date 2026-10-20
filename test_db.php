<?php
echo "PHP: " . PHP_BINARY . "<br>";
echo "php.ini: " . (php_ini_loaded_file() ?: "غير موجود") . "<br>";
echo "extension_dir: " . ini_get('extension_dir') . "<br>";
echo "pdo_mysql: ";
var_dump(extension_loaded('pdo_mysql'));
echo "<br>Drivers: ";
print_r(PDO::getAvailableDrivers()); 