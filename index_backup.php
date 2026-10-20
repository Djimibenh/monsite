
<?php
$host = '127.0.0.1';
$db   = 'concours_prison';
$user = 'root';
$pass = 'djamal24682468';

$pdo = null;
$candidats = [];
$stats = [];
$grades = [];
$erreur = '';
$modifier = null;
$message = '';

function h($v) {
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
}

function nombre($v) {
    return (int)($v ?? 0);
}

function resultatFinal($medical, $psychologique) {
    if ($medical === 'rejected' || $psychologique === 'rejected') {
        return 'rejected';
    }

    if ($medical === 'accepted' && $psychologique === 'accepted') {
        return 'accepted';
    }

    return 'pending';
}

function resultatAr($v) {
    return match ($v) {
        'accepted' => 'مقبول',
        'rejected' => 'مرفوض',
        'pending'  => 'قيد الانتظار',
        default    => 'غير محدد',
    };
}

function presenceAr($v) {
    return match ($v) {
        'present' => 'حاضر',
        'absent'  => 'غائب',
        'pending' => 'قيد الانتظار',
        default   => 'غير محدد',
    };
}

function classe($v) {
    return match ($v) {
        'accepted', 'present' => 'accepted',
        'rejected', 'absent'  => 'rejected',
        default               => 'pending',
    };
}

function dateForm($v) {
    return $v ? substr((string)$v, 0, 10) : '';
}

try {
    $pdo = new PDO(
        "mysql:host=$host;dbname=$db;charset=utf8mb4",
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );

    // تسجيل مترشح جديد أو تعديل بيانات مترشح موجود
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $id = (int)($_POST['id'] ?? 0);

        $numero = trim($_POST['numero'] ?? '');
        $nom = trim($_POST['nom'] ?? '');
        $prenom = trim($_POST['prenom'] ?? '');
        $grade = trim($_POST['grade'] ?? '');
        $sexe = $_POST['sexe'] ?? '';

        $dateMedicale = $_POST['date_medicale'] ?: null;
        $presenceMedicale = $_POST['presence_medicale'] ?? 'pending';
        $resultatMedical = $_POST['resultat_medical'] ?? 'pending';
        $motifMedical = trim($_POST['motif_refus_medical'] ?? '');

        $datePsychologique = $_POST['date_psychologique'] ?: null;
        $presencePsychologique = $_POST['presence_psychologique'] ?? 'pending';
        $resultatPsychologique = $_POST['resultat_psychologique'] ?? 'pending';
        $motifPsychologique = trim($_POST['motif_refus_psychologique'] ?? '');

        if ($numero === '' || $nom === '' || $prenom === '' || $grade === '') {
            throw new RuntimeException('يرجى ملء رقم الملف واللقب والاسم والرتبة.');
        }

        if (!in_array($sexe, ['ذكر', 'أنثى'], true)) {
            throw new RuntimeException('يرجى اختيار جنس المترشح.');
        }

        $presencesValides = ['present', 'absent', 'pending'];
        $resultatsValides = ['accepted', 'rejected', 'pending'];

        if (
            !in_array($presenceMedicale, $presencesValides, true) ||
            !in_array($presencePsychologique, $presencesValides, true) ||
            !in_array($resultatMedical, $resultatsValides, true) ||
            !in_array($resultatPsychologique, $resultatsValides, true)
        ) {
            throw new RuntimeException('إحدى قيم الحضور أو النتائج غير صحيحة.');
        }

        // لا نحتفظ بسبب رفض إذا لم تكن النتيجة مرفوضة
        if ($resultatMedical !== 'rejected') {
            $motifMedical = '';
        }
        if ($resultatPsychologique !== 'rejected') {
            $motifPsychologique = '';
        }

        $data = [
            ':numero' => $numero,
            ':nom' => $nom,
            ':prenom' => $prenom,
            ':grade' => $grade,
            ':sexe' => $sexe,
            ':date_medicale' => $dateMedicale ?: null,
            ':presence_medicale' => $presenceMedicale,
            ':resultat_medical' => $resultatMedical,
            ':motif_refus_medical' => $motifMedical ?: null,
            ':date_psychologique' => $datePsychologique ?: null,
            ':presence_psychologique' => $presencePsychologique,
            ':resultat_psychologique' => $resultatPsychologique,
            ':motif_refus_psychologique' => $motifPsychologique ?: null,
        ];

        if ($id > 0) {
            $data[':id'] = $id;

            $sql = "UPDATE candidats SET
                numero = :numero,
                nom = :nom,
                prenom = :prenom,
                grade = :grade,
                sexe = :sexe,
                date_medicale = :date_medicale,
                presence_medicale = :presence_medicale,
                resultat_medical = :resultat_medical,
                motif_refus_medical = :motif_refus_medical,
                date_psychologique = :date_psychologique,
                presence_psychologique = :presence_psychologique,
                resultat_psychologique = :resultat_psychologique,
                motif_refus_psychologique = :motif_refus_psychologique
                WHERE id = :id";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($data);
            header('Location: index.php?saved=updated');
            exit;
        } else {
            $sql = "INSERT INTO candidats (
                numero, nom, prenom, grade, sexe,
                date_medicale, presence_medicale, resultat_medical,
                motif_refus_medical,
                date_psychologique, presence_psychologique,
                resultat_psychologique, motif_refus_psychologique
            ) VALUES (
                :numero, :nom, :prenom, :grade, :sexe,
                :date_medicale, :presence_medicale, :resultat_medical,
                :motif_refus_medical,
                :date_psychologique, :presence_psychologique,
                :resultat_psychologique, :motif_refus_psychologique
            )";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($data);
            header('Location: index.php?saved=created');
            exit;
        }
    }

    if (($_GET['saved'] ?? '') === 'created') {
        $message = 'تم تسجيل المترشح بنجاح.';
    } elseif (($_GET['saved'] ?? '') === 'updated') {
        $message = 'تم تعديل بيانات المترشح بنجاح.';
    }

    // جلب بيانات المترشح عند الضغط على تعديل
    if (isset($_GET['modifier'])) {
        $stmt = $pdo->prepare('SELECT * FROM candidats WHERE id = ?');
        $stmt->execute([(int)$_GET['modifier']]);
        $modifier = $stmt->fetch();

        if (!$modifier) {
            $erreur = 'المترشح المطلوب غير موجود.';
        }
    }

    // البحث والتصفية
    $recherche = trim($_GET['recherche'] ?? '');
    $gradeFiltre = trim($_GET['grade'] ?? '');
    $sexeFiltre = trim($_GET['sexe'] ?? '');

    $sql = 'SELECT * FROM candidats WHERE 1=1';
    $params = [];

    if ($recherche !== '') {
        $sql .= ' AND (numero LIKE :numero OR nom LIKE :nom OR prenom LIKE :prenom)';
        $mot = '%' . $recherche . '%';
        $params[':numero'] = $mot;
        $params[':nom'] = $mot;
        $params[':prenom'] = $mot;
    }

    if ($gradeFiltre !== '') {
        $sql .= ' AND grade = :grade';
        $params[':grade'] = $gradeFiltre;
    }

    if (in_array($sexeFiltre, ['ذكر', 'أنثى'], true)) {
        $sql .= ' AND sexe = :sexe';
        $params[':sexe'] = $sexeFiltre;
    }

    $sql .= ' ORDER BY numero ASC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $candidats = $stmt->fetchAll();

    // الإحصائيات العامة تتحدث من قاعدة البيانات تلقائياً
    $stats = $pdo->query("
        SELECT
            COUNT(*) AS total,
            SUM(sexe = 'ذكر') AS hommes,
            SUM(sexe = 'أنثى') AS femmes,

            SUM(resultat_medical = 'accepted') AS medical_accepte,
            SUM(resultat_medical = 'rejected') AS medical_refuse,
            SUM(resultat_medical = 'pending') AS medical_attente,

            SUM(resultat_psychologique = 'accepted') AS psych_accepte,
            SUM(resultat_psychologique = 'rejected') AS psych_refuse,
            SUM(resultat_psychologique = 'pending') AS psych_attente,

            SUM(
                resultat_medical = 'rejected'
                OR resultat_psychologique = 'rejected'
            ) AS final_refuse,

            SUM(
                resultat_medical = 'accepted'
                AND resultat_psychologique = 'accepted'
            ) AS final_accepte,

            SUM(
                resultat_medical <> 'rejected'
                AND resultat_psychologique <> 'rejected'
                AND NOT (
                    resultat_medical = 'accepted'
                    AND resultat_psychologique = 'accepted'
                )
            ) AS final_attente

        FROM candidats
    ")->fetch();

    $grades = $pdo->query("
        SELECT DISTINCT grade FROM candidats
        WHERE grade IS NOT NULL AND grade <> ''
        ORDER BY grade
    ")->fetchAll(PDO::FETCH_COLUMN);

} catch (Throwable $e) {
    $erreur = $e instanceof PDOException
        ? 'خطأ في قاعدة البيانات: ' . $e->getMessage()
        : $e->getMessage();
}

$ف = $modifier ?: [];
$الجنس = $ف['sexe'] ?? '';
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>تسيير المترشحين</title>
<style>
*{box-sizing:border-box}
body{margin:0;font-family:Tahoma,Arial,sans-serif;background:#f1f5f9;color:#1e293b}
header{background:#12345a;color:white;padding:24px 5%}
header h1{margin:0 0 8px}
header p{margin:0;color:#dbeafe}
main{max-width:1500px;margin:24px auto;padding:0 16px}
.section,.card{background:white;border-radius:12px;padding:20px;margin-bottom:20px;box-shadow:0 3px 12px #0000000b}
.section h2{margin-top:0;font-size:20px}
.stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px}
.card{border-top:4px solid #2563eb;margin:0}
.card h3{font-size:14px;color:#64748b;margin:0 0 10px}
.card strong{font-size:28px}
.form-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px}
label{display:block;font-weight:bold;font-size:14px;margin-bottom:6px}
input,select,textarea,button{width:100%;font:inherit;padding:11px;border:1px solid #cbd5e1;border-radius:7px}
textarea{min-height:70px;resize:vertical}
button,.btn{background:#1d4ed8;color:white;border:0;cursor:pointer;text-decoration:none;text-align:center;display:inline-block;padding:11px 15px;border-radius:7px}
.btn-gray{background:#64748b}
.actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:16px}
.actions button,.actions .btn{width:auto}
.filters{display:flex;flex-wrap:wrap;gap:10px;margin-bottom:16px}
.filters input{flex:1;min-width:190px}
.filters select{width:auto;min-width:150px}
.table-wrap{overflow-x:auto}
table{width:100%;border-collapse:collapse;min-width:1250px}
th,td{padding:10px;border-bottom:1px solid #e2e8f0;text-align:right;vertical-align:top}
th{background:#f8fafc}
.badge{display:inline-block;padding:5px 9px;border-radius:20px;font-size:12px;white-space:nowrap}
.accepted{background:#dcfce7;color:#166534}
.rejected{background:#fee2e2;color:#991b1b}
.pending{background:#fef3c7;color:#92400e}
.notice{padding:13px;border-radius:8px;margin-bottom:15px}
.ok{background:#dcfce7;color:#166534}
.err{background:#fee2e2;color:#991b1b;overflow-wrap:anywhere}
.motif{font-size:12px;color:#991b1b;margin-top:5px;max-width:180px}
.small{font-size:12px;color:#64748b}
@media(max-width:600px){.section{padding:13px}header h1{font-size:22px}}
</style>
</head>
<body>
```html
<header>
    <h1>المدرسة الوطنية لموظفي إدارة السجون</h1>
    <p>نظام تسيير المترشحين ومتابعة الفحوصات الطبية والنفسية</p>
</header>


<main>

<?php if ($message): ?>
<div class="notice ok"><?= h($message) ?></div>
<?php endif; ?>

<?php if ($erreur): ?>
<div class="notice err"><strong><?= h($erreur) ?></strong></div>
<?php endif; ?>

<?php if ($pdo): ?>

<section class="section" id="formulaire">
    <h2><?= $modifier ? 'تعديل بيانات المترشح' : 'تسجيل مترشح جديد' ?></h2>

    <form method="post" action="index.php">
        <input type="hidden" name="id" value="<?= h($ف['id'] ?? '') ?>">

        <h3>المعلومات الشخصية</h3>
        <div class="form-grid">
            <div>
                <label>رقم الملف *</label>
                <input name="numero" required value="<?= h($ف['numero'] ?? '') ?>">
            </div>
            <div>
                <label>اللقب *</label>
                <input name="nom" required value="<?= h($ف['nom'] ?? '') ?>">
            </div>
            <div>
                <label>الاسم *</label>
                <input name="prenom" required value="<?= h($ف['prenom'] ?? '') ?>">
            </div>
            <div>
                <label>الرتبة *</label>
                <input name="grade" required list="listeGrades" value="<?= h($ف['grade'] ?? '') ?>">
                <datalist id="listeGrades">
                    <?php foreach ($grades as $g): ?>
                        <option value="<?= h($g) ?>">
                    <?php endforeach; ?>
                </datalist>
            </div>
            <div>
                <label>الجنس *</label>
                <select name="sexe" required>
                    <option value="">اختر الجنس</option>
                    <option value="ذكر" <?= $الجنس === 'ذكر' ? 'selected' : '' ?>>ذكر</option>
                    <option value="أنثى" <?= $الجنس === 'أنثى' ? 'selected' : '' ?>>أنثى</option>
                </select>
            </div>
        </div>

        <h3>الفحص الطبي</h3>
        <div class="form-grid">
            <div>
                <label>تاريخ الفحص الطبي</label>
                <input type="date" name="date_medicale" value="<?= h(dateForm($ف['date_medicale'] ?? '')) ?>">
            </div>
            <div>
                <label>الحضور الطبي</label>
                <select name="presence_medicale">
                    <?php foreach (['pending'=>'قيد الانتظار','present'=>'حاضر','absent'=>'غائب'] as $v=>$t): ?>
                    <option value="<?= h($v) ?>" <?= ($ف['presence_medicale'] ?? 'pending') === $v ? 'selected' : '' ?>><?= h($t) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>نتيجة الفحص الطبي</label>
                <select name="resultat_medical">
                    <?php foreach (['pending'=>'لم تُحدّد','accepted'=>'مقبول','rejected'=>'مرفوض'] as $v=>$t): ?>
                    <option value="<?= h($v) ?>" <?= ($ف['resultat_medical'] ?? 'pending') === $v ? 'selected' : '' ?>><?= h($t) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>سبب الرفض الطبي (إذا رُفض)</label>
                <textarea name="motif_refus_medical" placeholder="اكتب سبب الرفض الطبي"><?= h($ف['motif_refus_medical'] ?? '') ?></textarea>
            </div>
        </div>

        <h3>الفحص النفسي</h3>
        <div class="form-grid">
            <div>
                <label>تاريخ الفحص النفسي</label>
                <input type="date" name="date_psychologique" value="<?= h(dateForm($ف['date_psychologique'] ?? '')) ?>">
            </div>
            <div>
                <label>الحضور النفسي</label>
                <select name="presence_psychologique">
                    <?php foreach (['pending'=>'قيد الانتظار','present'=>'حاضر','absent'=>'غائب'] as $v=>$t): ?>
                    <option value="<?= h($v) ?>" <?= ($ف['presence_psychologique'] ?? 'pending') === $v ? 'selected' : '' ?>><?= h($t) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>نتيجة الفحص النفسي</label>
                <select name="resultat_psychologique">
                    <?php foreach (['pending'=>'لم تُحدّد','accepted'=>'مقبول','rejected'=>'مرفوض'] as $v=>$t): ?>
                    <option value="<?= h($v) ?>" <?= ($ف['resultat_psychologique'] ?? 'pending') === $v ? 'selected' : '' ?>><?= h($t) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>سبب الرفض النفسي (إذا رُفض)</label>
                <textarea name="motif_refus_psychologique" placeholder="اكتب سبب الرفض النفسي"><?= h($ف['motif_refus_psychologique'] ?? '') ?></textarea>
            </div>
        </div>

        <div class="actions">
            <button type="submit"><?= $modifier ? 'حفظ التعديلات' : 'تسجيل المترشح' ?></button>
            <?php if ($modifier): ?>
                <a class="btn btn-gray" href="index.php#formulaire">إلغاء التعديل</a>
            <?php else: ?>
                <button type="reset" class="btn-gray">مسح الحقول</button>
            <?php endif; ?>
        </div>
        <p class="small">النتيجة النهائية تُحسب آليًا من نتيجتي الفحص الطبي والنفسي.</p>
    </form>
</section>

<section class="section">
    <h2>الإحصائيات العامة</h2>
    <div class="stats">
        <div class="card"><h3>إجمالي المترشحين</h3><strong><?= nombre($stats['total'] ?? 0) ?></strong></div>
        <div class="card"><h3>الذكور</h3><strong><?= nombre($stats['hommes'] ?? 0) ?></strong></div>
        <div class="card"><h3>الإناث</h3><strong><?= nombre($stats['femmes'] ?? 0) ?></strong></div>
        <div class="card"><h3>مقبولون نهائيًا</h3><strong><?= nombre($stats['final_accepte'] ?? 0) ?></strong></div>
        <div class="card"><h3>مرفوضون نهائيًا</h3><strong><?= nombre($stats['final_refuse'] ?? 0) ?></strong></div>
        <div class="card"><h3>النتيجة النهائية قيد الانتظار</h3><strong><?= nombre($stats['final_attente'] ?? 0) ?></strong></div>
        <div class="card"><h3>مقبولون طبيًا</h3><strong><?= nombre($stats['medical_accepte'] ?? 0) ?></strong></div>
        <div class="card"><h3>مرفوضون طبيًا</h3><strong><?= nombre($stats['medical_refuse'] ?? 0) ?></strong></div>
        <div class="card"><h3>مقبولون نفسيًا</h3><strong><?= nombre($stats['psych_accepte'] ?? 0) ?></strong></div>
        <div class="card"><h3>مرفوضون نفسيًا</h3><strong><?= nombre($stats['psych_refuse'] ?? 0) ?></strong></div>
    </div>
</section>

<section class="section">
    <h2>قائمة المترشحين</h2>

    <form class="filters" method="get">
        <input type="search" name="recherche" placeholder="ابحث برقم الملف أو اللقب أو الاسم" value="<?= h($_GET['recherche'] ?? '') ?>">
        <select name="grade">
            <option value="">كل الرتب</option>
            <?php foreach ($grades as $g): ?>
            <option value="<?= h($g) ?>" <?= ($_GET['grade'] ?? '') === $g ? 'selected' : '' ?>><?= h($g) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="sexe">
            <option value="">ذكر وإناث</option>
            <option value="ذكر" <?= ($_GET['sexe'] ?? '') === 'ذكر' ? 'selected' : '' ?>>ذكر</option>
            <option value="أنثى" <?= ($_GET['sexe'] ?? '') === 'أنثى' ? 'selected' : '' ?>>أنثى</option>
        </select>
        <button type="submit">بحث</button>
        <a class="btn btn-gray" href="index.php">إعادة ضبط</a>
    </form>

    <div class="table-wrap">
    <table>
        <thead>
        <tr>
            <th>رقم الملف</th><th>اللقب</th><th>الاسم</th><th>الجنس</th><th>الرتبة</th>
            <th>النتيجة الطبية</th><th>سبب الرفض الطبي</th>
            <th>النتيجة النفسية</th><th>سبب الرفض النفسي</th>
            <th>النتيجة النهائية</th><th>إجراء</th>
        </tr>
        </thead>
        <tbody>
        <?php if (!$candidats): ?>
            <tr><td colspan="11" style="text-align:center">لا توجد نتائج.</td></tr>
        <?php else: foreach ($candidats as $c):
            $final = resultatFinal($c['resultat_medical'] ?? '', $c['resultat_psychologique'] ?? '');
        ?>
            <tr>
                <td><?= h($c['numero']) ?></td>
                <td><?= h($c['nom']) ?></td>
                <td><?= h($c['prenom']) ?></td>
                <td><?= h($c['sexe'] ?: 'غير محدد') ?></td>
                <td><?= h($c['grade']) ?></td>
                <td><span class="badge <?= h(classe($c['resultat_medical'] ?? '')) ?>"><?= h(resultatAr($c['resultat_medical'] ?? '')) ?></span></td>
                <td><div class="motif"><?= h($c['motif_refus_medical'] ?? '') ?></div></td>
                <td><span class="badge <?= h(classe($c['resultat_psychologique'] ?? '')) ?>"><?= h(resultatAr($c['resultat_psychologique'] ?? '')) ?></span></td>
                <td><div class="motif"><?= h($c['motif_refus_psychologique'] ?? '') ?></div></td>
                <td><span class="badge <?= h(classe($final)) ?>"><?= h(resultatAr($final)) ?></span></td>
                <td><a class="btn" href="index.php?modifier=<?= (int)$c['id'] ?>#formulaire">تعديل</a></td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
    </div>
</section>

<?php endif; ?>
</main>
</body>
</html>
