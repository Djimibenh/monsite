<?php

require_once __DIR__ . '/config.php';



function h($value): string

{

    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');

}



function getStatValue(array $data, string $key): int

{

    return (int)($data[$key] ?? 0);

}



function validDate(string $date): bool

{

    $d = DateTime::createFromFormat('!Y-m-d', $date);

    return $d && $d->format('Y-m-d') === $date;

}



$today = date('Y-m-d');

$selectedDate = $_GET['date'] ?? $today;



if (!is_string($selectedDate) || !validDate($selectedDate)) {

    $selectedDate = $today;

}



$message = '';

$erreur = '';



/* تسجيل طبيب في التاريخ المختار */

if ($_SERVER['REQUEST_METHOD'] === 'POST'

    && ($_POST['action'] ?? '') === 'ajouter_medecin') {



    $dateMedecin = $_POST['date_jour'] ?? $today;

    $nom = trim($_POST['nom'] ?? '');

    $prenom = trim($_POST['prenom'] ?? '');

    $type = $_POST['type_medecin'] ?? '';



    if (!is_string($dateMedecin) || !validDate($dateMedecin)) {

        $erreur = 'التاريخ غير صحيح.';

    } elseif ($nom === '' || $prenom === '') {

        $erreur = 'يرجى إدخال لقب الطبيب واسمه.';

    } elseif (!in_array($type, ['general', 'psychologue'], true)) {

        $erreur = 'يرجى اختيار التخصص الصحيح.';

    } else {

        try {

            // منع تسجيل الطبيب نفسه مرتين في التخصص نفسه في اليوم نفسه

            $stmt = $pdo->prepare("

                SELECT COUNT(*)

                FROM medecins_jour

                WHERE date_jour = ?

                  AND nom = ?

                  AND prenom = ?

                  AND type_medecin = ?

            ");

            $stmt->execute([$dateMedecin, $nom, $prenom, $type]);



            if ((int)$stmt->fetchColumn() > 0) {

                $erreur = 'هذا الطبيب مسجل بالفعل في هذا اليوم.';

            } else {

                $stmt = $pdo->prepare("

                    INSERT INTO medecins_jour

                        (date_jour, nom, prenom, type_medecin)

                    VALUES (?, ?, ?, ?)

                ");

                $stmt->execute([$dateMedecin, $nom, $prenom, $type]);



                header('Location: index.php?date=' . urlencode($dateMedecin)

                    . '&medecin_ajoute=1');

                exit;

            }

        } catch (PDOException $e) {

            error_log('Erreur ajout médecin : ' . $e->getMessage());

            $erreur = 'تعذر تسجيل الطبيب. تحقق من قاعدة البيانات.';

        }

    }



    $selectedDate = is_string($dateMedecin) && validDate($dateMedecin)

        ? $dateMedecin : $today;

}



if (isset($_GET['medecin_ajoute'])) {

    $message = 'تم تسجيل الطبيب بنجاح.';

}



/* إحصائيات المترشحين */

function getStats(PDO $pdo, string $date): array

{

    $stats = [

        'inscriptions' => 0,

        'medical_jour' => 0,

        'psychologique_jour' => 0,

        'medical_accepte' => 0,

        'medical_refuse' => 0,

        'medical_attente' => 0,

        'psychologique_accepte' => 0,

        'psychologique_refuse' => 0,

        'psychologique_attente' => 0,

    ];



    $stmt = $pdo->prepare("

        SELECT COUNT(*)

        FROM candidats

        WHERE created_at >= ?

          AND created_at < DATE_ADD(?, INTERVAL 1 DAY)

    ");

    $stmt->execute([$date . ' 00:00:00', $date . ' 00:00:00']);

    $stats['inscriptions'] = (int)$stmt->fetchColumn();



    $stmt = $pdo->prepare("

        SELECT

            SUM(CASE WHEN date_medicale = ? THEN 1 ELSE 0 END) AS medical_jour,

            SUM(CASE WHEN date_psychologique = ? THEN 1 ELSE 0 END) AS psychologique_jour,



            SUM(CASE WHEN date_medicale = ? AND resultat_medical = 'accepted'

                THEN 1 ELSE 0 END) AS medical_accepte,

            SUM(CASE WHEN date_medicale = ? AND resultat_medical = 'rejected'

                THEN 1 ELSE 0 END) AS medical_refuse,

            SUM(CASE WHEN date_medicale = ? AND resultat_medical = 'pending'

                THEN 1 ELSE 0 END) AS medical_attente,



            SUM(CASE WHEN date_psychologique = ? AND resultat_psychologique = 'accepted'

                THEN 1 ELSE 0 END) AS psychologique_accepte,

            SUM(CASE WHEN date_psychologique = ? AND resultat_psychologique = 'rejected'

                THEN 1 ELSE 0 END) AS psychologique_refuse,

            SUM(CASE WHEN date_psychologique = ? AND resultat_psychologique = 'pending'

                THEN 1 ELSE 0 END) AS psychologique_attente

        FROM candidats

    ");

    $stmt->execute([

        $date, $date, $date, $date, $date,

        $date, $date, $date

    ]);



    return array_merge($stats, $stmt->fetch(PDO::FETCH_ASSOC) ?: []);

}



function statCard(string $title, int $number, string $color = 'blue'): void

{

    echo '<div class="stat ' . h($color) . '">';

    echo '<div class="stat-title">' . h($title) . '</div>';

    echo '<div class="stat-number">' . $number . '</div>';

    echo '</div>';

}



$todayStats = [];

$dateStats = [];

$medecinsGeneral = [];

$psychologues = [];



try {

    $todayStats = getStats($pdo, $today);

    $dateStats = getStats($pdo, $selectedDate);



    $stmt = $pdo->prepare("

        SELECT m.*,

            (SELECT COUNT(*)

             FROM candidats c

             WHERE c.medecin_jour_id = m.id) AS nombre_candidats

        FROM medecins_jour m

        WHERE m.date_jour = ? AND m.type_medecin = 'general'

        ORDER BY m.id

    ");

    $stmt->execute([$selectedDate]);

    $medecinsGeneral = $stmt->fetchAll(PDO::FETCH_ASSOC);



    $stmt = $pdo->prepare("

        SELECT m.*,

            (SELECT COUNT(*)

             FROM candidats c

             WHERE c.psychologue_jour_id = m.id) AS nombre_candidats

        FROM medecins_jour m

        WHERE m.date_jour = ? AND m.type_medecin = 'psychologue'

        ORDER BY m.id

    ");

    $stmt->execute([$selectedDate]);

    $psychologues = $stmt->fetchAll(PDO::FETCH_ASSOC);



} catch (PDOException $e) {

    error_log('Erreur tableau de bord : ' . $e->getMessage());

    $erreur = 'تعذر تحميل البيانات. تحقق من إنشاء جدول medecins_jour والحقول المطلوبة.';

}

?>

<!DOCTYPE html>

<html lang="ar" dir="rtl">

<head>

<meta charset="UTF-8">

<meta name="viewport" content="width=device-width, initial-scale=1">

<title>المدرسة الوطنية لموظفي إدارة السجون | تسيير المتسابقين</title>

<style>

*{box-sizing:border-box}

body{margin:0;background:linear-gradient(180deg,#eef3f8 0%,#f7f9fc 260px);color:#1e293b;font-family:Tahoma,Arial,sans-serif}
a,button,input,select{transition:all .2s ease}

header{position:relative;overflow:hidden;background:linear-gradient(125deg,#0b1f3a 0%,#123f68 55%,#1976a8 100%);color:#fff;padding:34px 18px 30px;border-bottom:5px solid #d6b56d;box-shadow:0 8px 25px #0b25451f}
header:before,header:after{content:"";position:absolute;border:1px solid #ffffff1c;border-radius:50%;pointer-events:none}
header:before{width:300px;height:300px;top:-170px;left:-70px}
header:after{width:220px;height:220px;bottom:-165px;right:7%}
.header-inner{position:relative;z-index:1;text-align:center}
.brand-mark{display:inline-flex;align-items:center;justify-content:center;width:62px;height:62px;margin-bottom:13px;border:2px solid #d6b56d;border-radius:50%;background:#ffffff0d;color:#f6d995;font-size:30px;box-shadow:0 0 0 6px #ffffff08}
header h1{margin:0 auto 10px;font-size:clamp(22px,3vw,32px);line-height:1.65;letter-spacing:.2px}
header .subtitle{display:inline-block;margin:3px 0 10px;padding:6px 16px;border:1px solid #ffffff38;border-radius:30px;background:#ffffff0d;color:#f7e5b5;font-size:14px}
header p{margin:0;color:#e1ebf7;line-height:1.9}

.header-inner,.container{width:94%;max-width:1200px;margin:auto}

header h1{margin:0 0 10px;font-size:28px}

header p{margin:0;color:#e1ebf7;line-height:1.8}

.container{padding:25px 0}

.section{background:#fff;border:1px solid #e2e9f2;border-radius:18px;padding:24px;margin-bottom:22px;box-shadow:0 8px 26px #172b4d0a}
.section h2{display:flex;align-items:center;gap:10px}
.section h2:before{content:"";display:inline-block;width:5px;height:25px;border-radius:8px;background:linear-gradient(#d6b56d,#1769aa)}

.section h2{margin:0 0 20px;color:#17365d;font-size:21px}

.menu{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px}

.menu-card{border:1px solid #e4eaf2;border-radius:16px;padding:24px 22px;text-align:center;background:linear-gradient(160deg,#fff,#f8fbff);box-shadow:0 4px 12px #172b4d08}
.menu-card:hover{transform:translateY(-3px);border-color:#b8cfe6;box-shadow:0 10px 22px #172b4d12}

.symbol{display:inline-flex;align-items:center;justify-content:center;width:60px;height:60px;border-radius:18px;background:#eaf2fb;font-size:30px;margin-bottom:12px}

.menu-card h3{margin:0 0 10px;color:#17365d}

.menu-card p{color:#64748b;line-height:1.8;min-height:48px}

.btn{display:inline-block;background:linear-gradient(135deg,#1769aa,#125486);color:white;text-decoration:none;padding:12px 19px;border-radius:10px;font-weight:bold;border:0;cursor:pointer;font-family:inherit;box-shadow:0 4px 10px #1769aa20}

.btn:hover{background:linear-gradient(135deg,#125486,#0c3b61);transform:translateY(-1px);box-shadow:0 6px 14px #1769aa30}

.green-btn{background:#18864b}

.green-btn:hover{background:#116738}

.cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:14px}

.stat{padding:20px;border-radius:14px;background:linear-gradient(145deg,#f2f7fd,#eaf2fc);border-right:5px solid #3678bd;box-shadow:0 4px 12px #172b4d08}

.stat-title{font-size:14px;color:#475569;line-height:1.8}

.stat-number{font-size:32px;font-weight:bold;color:#17365d;margin-top:8px}

.stat.green{background:#eaf7ef;border-right-color:#269653}

.stat.green .stat-number{color:#18743b}

.stat.red{background:#fff0f0;border-right-color:#d64c4c}

.stat.red .stat-number{color:#b52d2d}

.stat.orange{background:#fff5e6;border-right-color:#d68b19}

.stat.orange .stat-number{color:#a76508}

.stat.purple{background:#f2edfc;border-right-color:#8054b5}

.stat.purple .stat-number{color:#674097}

.date-form,.doctor-form{display:flex;flex-wrap:wrap;align-items:end;gap:12px;margin-bottom:20px}

label{display:block;font-weight:bold;margin-bottom:8px}

input,select{padding:12px;border:1px solid #cbd5e1;border-radius:8px;font:inherit;background:white;max-width:100%}

.doctor-form input,.doctor-form select{width:100%}

.form-field{flex:1;min-width:170px}

.date-label{background:#edf4fc;padding:12px 15px;border-radius:9px;margin-bottom:18px;color:#17365d;font-weight:bold}

.alert{padding:14px;border-radius:9px;margin-bottom:18px;line-height:1.8}

.success{background:#eaf7ef;color:#176b38}

.error{background:#fff0f0;color:#a21d1d}

.doctor-list{display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:12px}

.doctor{border:1px solid #dce6f2;background:linear-gradient(145deg,#fff,#f5f9ff);border-radius:13px;padding:17px;box-shadow:0 3px 10px #172b4d07}

.doctor strong{display:block;color:#17365d;margin-bottom:8px}

.doctor small{color:#64748b}

.empty{background:#f8fafc;border:1px dashed #cbd5e1;padding:15px;border-radius:9px;color:#64748b}

footer{text-align:center;padding:24px 15px;color:#64748b;font-size:13px;line-height:1.9;border-top:1px solid #e2e8f0;background:#fff} footer strong{color:#17365d}

@media(max-width:550px){header h1{font-size:22px}.section{padding:17px}.stat-number{font-size:28px}.form-field{min-width:100%}}

</style>

</head>

<body>

<header>

    <div class="header-inner">

        <h1>لوحة تسيير المتسابقين</h1>

        <p>تسجيل المترشحين، إدارة أطباء كل يوم، ومتابعة الفحوصات والإحصائيات.</p>

    </div>

</header>



<main class="container">



<?php if ($message !== ''): ?>

    <div class="alert success"><?= h($message) ?></div>

<?php endif; ?>



<?php if ($erreur !== ''): ?>

    <div class="alert error"><?= h($erreur) ?></div>

<?php endif; ?>



<section class="section">

    <h2>الخدمات الرئيسية</h2>

    <div class="menu">

        <div class="menu-card">

            <div class="symbol">📝</div>

            <h3>تسجيل متسابق</h3>

            <p>إضافة متسابق جديد وتعيين أطباء اليوم له.</p>

            <a class="btn" href="inscription.php">تسجيل متسابق</a>

        </div>

        <div class="menu-card">

            <div class="symbol">📋</div>

            <h3>البحث والنتائج</h3>

            <p>البحث عن المترشحين وتسجيل نتائج الفحوصات.</p>

            <a class="btn" href="resultats.php">فتح النتائج</a>

        </div>

    </div>

</section>



<section class="section">

    <h2>تسجيل أطباء اليوم</h2>

    <p>أدخل بيانات كل طبيب حاضر. يمكنك إضافة عدة أطباء من كل تخصص.</p>



    <form class="doctor-form" method="post" action="index.php">

        <input type="hidden" name="action" value="ajouter_medecin">



        <div class="form-field">

            <label for="date_jour">تاريخ العمل</label>

            <input type="date" id="date_jour" name="date_jour"

                   value="<?= h($selectedDate) ?>" required>

        </div>



        <div class="form-field">

            <label for="nom">لقب الطبيب</label>

            <input type="text" id="nom" name="nom" required maxlength="100"

                   placeholder="أدخل اللقب">

        </div>



        <div class="form-field">

            <label for="prenom">اسم الطبيب</label>

            <input type="text" id="prenom" name="prenom" required maxlength="100"

                   placeholder="أدخل الاسم">

        </div>



        <div class="form-field">

            <label for="type_medecin">التخصص</label>

            <select id="type_medecin" name="type_medecin" required>

                <option value="">اختر التخصص</option>

                <option value="general">طبيب عام</option>

                <option value="psychologue">أخصائي نفساني</option>

            </select>

        </div>



        <button class="btn green-btn" type="submit">＋ تسجيل الطبيب</button>

    </form>

</section>



<section class="section">

    <h2>أطباء التاريخ المختار</h2>



    <form class="date-form" method="get" action="index.php">

        <div>

            <label for="date">اختر التاريخ لعرض أطبائه</label>

            <input type="date" id="date" name="date"

                   value="<?= h($selectedDate) ?>" required>

        </div>

        <button class="btn" type="submit">عرض الأطباء</button>

        <a class="btn" href="index.php">أطباء اليوم</a>

    </form>



    <div class="date-label">

        قائمة الأطباء بتاريخ <?= h(date('d/m/Y', strtotime($selectedDate))) ?>

    </div>



    <h3>الأطباء العامون</h3>

    <?php if (!$medecinsGeneral): ?>

        <div class="empty">لم يتم تسجيل طبيب عام لهذا التاريخ بعد.</div>

    <?php else: ?>

        <div class="doctor-list">

            <?php foreach ($medecinsGeneral as $m): ?>

                <div class="doctor">

                    <strong>🩺 <?= h($m['nom'] . ' ' . $m['prenom']) ?></strong>

                    <small>المترشحون المسندون: <?= (int)$m['nombre_candidats'] ?></small>

                </div>

            <?php endforeach; ?>

        </div>

    <?php endif; ?>



    <h3 style="margin-top:24px">الأخصائيون النفسانيون</h3>

    <?php if (!$psychologues): ?>

        <div class="empty">لم يتم تسجيل أخصائي نفساني لهذا التاريخ بعد.</div>

    <?php else: ?>

        <div class="doctor-list">

            <?php foreach ($psychologues as $m): ?>

                <div class="doctor">

                    <strong>🧠 <?= h($m['nom'] . ' ' . $m['prenom']) ?></strong>

                    <small>المترشحون المسندون: <?= (int)$m['nombre_candidats'] ?></small>

                </div>

            <?php endforeach; ?>

        </div>

    <?php endif; ?>

</section>



<section class="section">

    <h2>إحصائيات اليوم — <?= h(date('d/m/Y')) ?></h2>

    <div class="cards">

        <?php

        statCard('المتسابقون المسجلون اليوم', getStatValue($todayStats, 'inscriptions'));

        statCard('الفحوصات الطبية اليوم', getStatValue($todayStats, 'medical_jour'), 'green');

        statCard('الفحوصات النفسية اليوم', getStatValue($todayStats, 'psychologique_jour'), 'purple');

        ?>

    </div>



    <h2 style="margin-top:25px">حصيلة الفحص الطبي اليوم</h2>

    <div class="cards">

        <?php

        statCard('مقبول طبيًا', getStatValue($todayStats, 'medical_accepte'), 'green');

        statCard('مرفوض طبيًا', getStatValue($todayStats, 'medical_refuse'), 'red');

        statCard('في انتظار النتيجة الطبية', getStatValue($todayStats, 'medical_attente'), 'orange');

        ?>

    </div>



    <h2 style="margin-top:25px">حصيلة الفحص النفسي اليوم</h2>

    <div class="cards">

        <?php

        statCard('مقبول نفسيًا', getStatValue($todayStats, 'psychologique_accepte'), 'green');

        statCard('مرفوض نفسيًا', getStatValue($todayStats, 'psychologique_refuse'), 'red');

        statCard('في انتظار النتيجة النفسية', getStatValue($todayStats, 'psychologique_attente'), 'orange');

        ?>

    </div>

</section>



<section class="section">

    <h2>إحصائيات حسب التاريخ</h2>

    <form class="date-form" method="get" action="index.php">

        <div>

            <label for="date_stats">اختر اليوم المطلوب</label>

            <input type="date" id="date_stats" name="date"

                   value="<?= h($selectedDate) ?>" required>

        </div>

        <button class="btn" type="submit">عرض الإحصائيات</button>

    </form>



    <div class="date-label">

        نتائج التاريخ المختار: <?= h(date('d/m/Y', strtotime($selectedDate))) ?>

    </div>



    <div class="cards">

        <?php

        statCard('المتسابقون المسجلون في هذا اليوم', getStatValue($dateStats, 'inscriptions'));

        statCard('الفحوصات الطبية في هذا اليوم', getStatValue($dateStats, 'medical_jour'), 'green');

        statCard('الفحوصات النفسية في هذا اليوم', getStatValue($dateStats, 'psychologique_jour'), 'purple');

        ?>

    </div>



    <h2 style="margin-top:25px">نتائج الفحص الطبي للتاريخ المختار</h2>

    <div class="cards">

        <?php

        statCard('مقبول طبيًا', getStatValue($dateStats, 'medical_accepte'), 'green');

        statCard('مرفوض طبيًا', getStatValue($dateStats, 'medical_refuse'), 'red');

        statCard('في انتظار النتيجة الطبية', getStatValue($dateStats, 'medical_attente'), 'orange');

        ?>

    </div>



    <h2 style="margin-top:25px">نتائج الفحص النفسي للتاريخ المختار</h2>

    <div class="cards">

        <?php

        statCard('مقبول نفسيًا', getStatValue($dateStats, 'psychologique_accepte'), 'green');

        statCard('مرفوض نفسيًا', getStatValue($dateStats, 'psychologique_refuse'), 'red');

        statCard('في انتظار النتيجة النفسية', getStatValue($dateStats, 'psychologique_attente'), 'orange');

        ?>

    </div>

</section>



</main>

<footer>
    <strong>المدرسة الوطنية لموظفي إدارة السجون</strong><br>
    نظام تسيير المترشحين والفحوصات الطبية والنفسية
</footer>

</body>

</html>
