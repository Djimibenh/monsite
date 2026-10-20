
<?php
require_once __DIR__ . '/config.php';

function h($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

$message = '';
$typeMessage = 'success';

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS medecins (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            nom VARCHAR(100) NOT NULL,
            prenom VARCHAR(100) NOT NULL,
            specialite VARCHAR(150) NOT NULL,
            telephone VARCHAR(30) DEFAULT NULL,
            actif TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $nom = trim($_POST['nom'] ?? '');
        $prenom = trim($_POST['prenom'] ?? '');
        $specialite = trim($_POST['specialite'] ?? '');
        $telephone = trim($_POST['telephone'] ?? '');

        if ($nom === '' || $prenom === '' || $specialite === '') {
            $message = 'يرجى ملء اللقب والاسم والتخصص.';
            $typeMessage = 'error';
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO medecins (nom, prenom, specialite, telephone)
                VALUES (:nom, :prenom, :specialite, :telephone)
            ");

            $stmt->execute([
                'nom' => $nom,
                'prenom' => $prenom,
                'specialite' => $specialite,
                'telephone' => $telephone !== '' ? $telephone : null
            ]);

            $message = 'تم تسجيل الطبيب بنجاح.';
        }
    }

    $stmt = $pdo->query("
        SELECT id, nom, prenom, specialite, telephone
        FROM medecins
        WHERE actif = 1
        ORDER BY nom, prenom
    ");
    $medecins = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log('Erreur médecins : ' . $e->getMessage());
    $message = 'حدث خطأ أثناء حفظ بيانات الأطباء أو عرضها.';
    $typeMessage = 'error';
    $medecins = [];
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>تسجيل أطباء العيادة</title>
<style>
*{box-sizing:border-box}
body{margin:0;background:#f3f6fb;color:#1e293b;font-family:Tahoma,Arial,sans-serif}
header{background:linear-gradient(120deg,#102b4e,#1769aa);color:#fff;padding:27px 18px}
header h1{margin:0 0 8px;font-size:26px}
header p{margin:0;color:#e1ebf7;line-height:1.8}
.container{width:94%;max-width:1000px;margin:25px auto}
.panel{background:#fff;padding:24px;border-radius:15px;border:1px solid #e5eaf1;margin-bottom:22px;box-shadow:0 5px 18px #172b4d0a}
h2{color:#17365d;margin-top:0;font-size:21px}
.form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}
label{display:block;font-weight:bold;margin-bottom:8px}
input,select{width:100%;padding:12px;border:1px solid #cbd5e1;border-radius:8px;font:inherit;background:#fff}
input:focus,select:focus{outline:2px solid #93c5fd;border-color:#1769aa}
.actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:20px}
.btn{display:inline-block;border:0;border-radius:8px;background:#1769aa;color:white;padding:12px 19px;text-decoration:none;font:inherit;font-weight:bold;cursor:pointer}
.btn:hover{background:#104d80}
.notice{padding:13px;border-radius:8px;margin-bottom:18px}
.success{background:#eaf7ef;color:#18743b}
.error{background:#fff0f0;color:#b52d2d}
.table-wrap{overflow-x:auto}
table{width:100%;border-collapse:collapse;min-width:560px}
th,td{padding:13px;text-align:right;border-bottom:1px solid #e5eaf1}
th{background:#edf4fc;color:#17365d}
.empty{text-align:center;color:#64748b;padding:22px}
@media(max-width:600px){.form-grid{grid-template-columns:1fr}.panel{padding:17px}header h1{font-size:22px}}
</style>
</head>
<body>
<header>
    <h1>🩺 تسجيل أطباء العيادة</h1>
    <p>إضافة الأطباء العاملين في العيادة والاطلاع على قائمة الأطباء المسجلين.</p>
</header>

<main class="container">
    <section class="panel">
        <h2>معلومات الطبيب</h2>

        <?php if ($message !== ''): ?>
            <div class="notice <?= h($typeMessage) ?>"><?= h($message) ?></div>
        <?php endif; ?>

        <form method="post" action="medecins.php">
            <div class="form-grid">
                <div>
                    <label for="nom">اللقب *</label>
                    <input type="text" id="nom" name="nom" maxlength="100" required>
                </div>

                <div>
                    <label for="prenom">الاسم *</label>
                    <input type="text" id="prenom" name="prenom" maxlength="100" required>
                </div>

                <div>
                    <label for="specialite">التخصص أو الوظيفة *</label>
                    <select id="specialite" name="specialite" required>
                        <option value="">اختر التخصص</option>
                        <option value="طبيب عام">طبيب عام</option>
                       
                        <option value="طبيب نفساني">طبيب نفساني</option>
                     
                        
                        
                    </select>
                </div>

                <div>
                    <label for="telephone">رقم الهاتف</label>
                    <input type="tel" id="telephone" name="telephone" maxlength="30">
                </div>
            </div>

            <div class="actions">
                <button class="btn" type="submit">حفظ الطبيب</button>
                <a class="btn" href="index.php">العودة إلى الرئيسية</a>
            </div>
        </form>
    </section>

    <section class="panel">
        <h2>قائمة أطباء العيادة</h2>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>الرقم</th>
                        <th>اللقب والاسم</th>
                        <th>التخصص أو الوظيفة</th>
                        <th>رقم الهاتف</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($medecins)): ?>
                    <tr>
                        <td colspan="4" class="empty">لا يوجد أطباء مسجلون حاليًا.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($medecins as $medecin): ?>
                        <tr>
                            <td><?= h($medecin['id']) ?></td>
                            <td><?= h($medecin['nom'] . ' ' . $medecin['prenom']) ?></td>
                            <td><?= h($medecin['specialite']) ?></td>
                            <td><?= h($medecin['telephone'] ?: '—') ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>
</body>
</html>
