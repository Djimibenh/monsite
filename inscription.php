
<?php
session_start();
require_once 'config.php';

date_default_timezone_set('Africa/Algiers');

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$message = '';
$erreur = '';
$dateAujourdhui = date('Y-m-d');

$medecinChoisi = null;
$psychologueChoisi = null;

/*
|--------------------------------------------------------------------------
| اختيار الطبيب الأقل عددًا من المتسابقين اليوم
|--------------------------------------------------------------------------
*/
function choisirMedecin(PDO $pdo, string $date, string $type): ?array
{
    // أسماء الأعمدة محددة مسبقًا ولا تأتي من المستخدم
    if ($type === 'general') {
        $colonne = 'medecin_jour_id';
    } elseif ($type === 'psychologue') {
        $colonne = 'psychologue_jour_id';
    } else {
        return null;
    }

    $sql = "
        SELECT
            m.id,
            m.nom,
            m.prenom,
            COUNT(c.id) AS nombre_candidats
        FROM medecins_jour m
        LEFT JOIN candidats c
            ON c.$colonne = m.id
            AND c.created_at >= :debut_jour
            AND c.created_at < :debut_demain
        WHERE m.date_jour = :date_medecin
          AND m.type_medecin = :type_medecin
        GROUP BY m.id, m.nom, m.prenom
        ORDER BY nombre_candidats ASC, m.id ASC
        LIMIT 1
    ";

    $debutJour = $date . ' 00:00:00';
    $debutDemain = date(
        'Y-m-d H:i:s',
        strtotime($date . ' +1 day')
    );

    $stmt = $pdo->prepare($sql);

    $stmt->execute([
        ':debut_jour' => $debutJour,
        ':debut_demain' => $debutDemain,
        ':date_medecin' => $date,
        ':type_medecin' => $type
    ]);

    $medecin = $stmt->fetch(PDO::FETCH_ASSOC);

    return $medecin ?: null;
}

/*
|--------------------------------------------------------------------------
| عرض الطبيب والأخصائي المختارين تلقائيًا
|--------------------------------------------------------------------------
*/
try {
    $medecinChoisi = choisirMedecin(
        $pdo,
        $dateAujourdhui,
        'general'
    );

    $psychologueChoisi = choisirMedecin(
        $pdo,
        $dateAujourdhui,
        'psychologue'
    );

} catch (PDOException $e) {
    die(
        'تعذر جلب أطباء اليوم. تحقق من جدول medecins_jour ' .
        'ومن وجود العمود created_at في جدول candidats.'
    );
}

/*
|--------------------------------------------------------------------------
| تسجيل المتسابق وإسناده تلقائيًا إلى الطبيب والأخصائي
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $numero = trim($_POST['numero'] ?? '');
    $nom = trim($_POST['nom'] ?? '');
    $prenom = trim($_POST['prenom'] ?? '');
    $grade = trim($_POST['grade'] ?? '');
    $sexe = trim($_POST['sexe'] ?? '');

    if (
        $numero === '' ||
        $nom === '' ||
        $prenom === '' ||
        $grade === '' ||
        !in_array($sexe, ['ذكر', 'أنثى'], true)
    ) {
        $erreur = 'يرجى ملء جميع معلومات المتسابق.';
    } else {
        try {
            /*
             * منع تكرار رقم المتسابق
             */
            $checkNumero = $pdo->prepare("
                SELECT COUNT(*)
                FROM candidats
                WHERE numero = :numero
            ");

            $checkNumero->execute([
                ':numero' => $numero
            ]);

            if ((int)$checkNumero->fetchColumn() > 0) {
                $erreur = 'رقم المتسابق مسجل مسبقًا.';
            } else {

                /*
                 * إعادة اختيار الأقل عددًا لحظة التسجيل
                 */
                $pdo->beginTransaction();

                $medecinAffecte = choisirMedecin(
                    $pdo,
                    $dateAujourdhui,
                    'general'
                );

                $psychologueAffecte = choisirMedecin(
                    $pdo,
                    $dateAujourdhui,
                    'psychologue'
                );

                if (!$medecinAffecte || !$psychologueAffecte) {
                    $pdo->rollBack();

                    $erreur =
                        'يجب تسجيل طبيب عام وأخصائي نفساني بتاريخ اليوم أولًا.';
                } else {

                    /*
                     * حفظ المتسابق مع الطبيب والأخصائي المختارين تلقائيًا
                     */
                    $sqlInsert = "
                        INSERT INTO candidats (
                            numero,
                            nom,
                            prenom,
                            grade,
                            sexe,
                            medecin_jour_id,
                            psychologue_jour_id
                        ) VALUES (
                            :numero,
                            :nom,
                            :prenom,
                            :grade,
                            :sexe,
                            :medecin_id,
                            :psychologue_id
                        )
                    ";

                    $insert = $pdo->prepare($sqlInsert);

                    $insert->execute([
                        ':numero' => $numero,
                        ':nom' => $nom,
                        ':prenom' => $prenom,
                        ':grade' => $grade,
                        ':sexe' => $sexe,
                        ':medecin_id' => $medecinAffecte['id'],
                        ':psychologue_id' => $psychologueAffecte['id']
                    ]);

                    $pdo->commit();

                    // عرض الأسماء اللذين أُسند إليهما المتسابق
                    $medecinChoisi = $medecinAffecte;
                    $psychologueChoisi = $psychologueAffecte;

                    $message =
                        'تم تسجيل المتسابق وإسناده تلقائيًا بنجاح. ' .
                        'الطبيب العام: ' .
                        $medecinAffecte['nom'] . ' ' .
                        $medecinAffecte['prenom'] .
                        ' — الأخصائي النفساني: ' .
                        $psychologueAffecte['nom'] . ' ' .
                        $psychologueAffecte['prenom'];

                    // تفريغ الحقول بعد التسجيل
                    $_POST = [];
                }
            }

        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $erreur =
                'حدث خطأ أثناء التسجيل. تحقق من بنية جدول candidats ' .
                'ومن عدم وجود قيود تمنع إضافة المتسابق.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>تسجيل متسابق جديد</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 25px 15px;
            font-family: Tahoma, Arial, sans-serif;
            background: #f1f5f9;
            color: #1e293b;
        }

        .container {
            max-width: 850px;
            margin: auto;
        }

        .header {
            background: #123b63;
            color: white;
            padding: 25px;
            border-radius: 12px;
            margin-bottom: 20px;
        }

        .header h1 {
            margin: 0 0 10px;
            font-size: 25px;
        }

        .header p {
            margin: 0;
            color: #dbeafe;
        }

        .form-card {
            background: white;
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 3px 12px #0000000d;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 18px;
        }

        .field {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        label {
            font-weight: bold;
            font-size: 14px;
        }

        input,
        select {
            width: 100%;
            padding: 12px;
            border: 1px solid #cbd5e1;
            border-radius: 7px;
            font-family: inherit;
            font-size: 15px;
            background: white;
        }

        input:focus,
        select:focus {
            outline: 2px solid #93c5fd;
            border-color: #2563eb;
        }

        .section-title {
            margin: 25px 0 15px;
            padding-bottom: 10px;
            border-bottom: 1px solid #e2e8f0;
            color: #123b63;
            font-size: 18px;
        }

        .doctor-box {
            padding: 15px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 9px;
        }

        .doctor-name {
            padding: 13px;
            border-radius: 7px;
            background: #e0f2fe;
            color: #075985;
            font-size: 16px;
            font-weight: bold;
        }

        .doctor-count {
            margin-top: 8px;
            color: #475569;
            font-size: 13px;
        }

        .submit-btn {
            display: block;
            width: 100%;
            margin-top: 25px;
            padding: 14px;
            border: 0;
            border-radius: 8px;
            background: #123b63;
            color: white;
            font-family: inherit;
            font-size: 16px;
            font-weight: bold;
            cursor: pointer;
        }

        .submit-btn:hover {
            background: #1d4f7c;
        }

        .submit-btn:disabled {
            background: #94a3b8;
            cursor: not-allowed;
        }

        .alert {
            margin-bottom: 18px;
            padding: 14px;
            border-radius: 8px;
            line-height: 1.9;
        }

        .success {
            color: #166534;
            background: #dcfce7;
        }

        .error {
            color: #991b1b;
            background: #fee2e2;
        }

        .empty {
            margin-top: 8px;
            padding: 12px;
            border-radius: 7px;
            background: #fff7ed;
            color: #9a3412;
            font-size: 14px;
        }

        .back-link {
            display: inline-block;
            margin-top: 18px;
            color: #123b63;
            text-decoration: none;
            font-weight: bold;
        }

        @media (max-width: 600px) {
            .form-grid {
                grid-template-columns: 1fr;
            }

            .form-card {
                padding: 17px;
            }
        }
    </style>
</head>

<body>
<div class="container">

    <div class="header">
        <h1>تسجيل متسابق جديد</h1>
        <p>تاريخ اليوم: <?= h(date('d/m/Y')) ?></p>
    </div>

    <div class="form-card">

        <?php if ($message !== ''): ?>
            <div class="alert success">
                <?= h($message) ?>
            </div>
        <?php endif; ?>

        <?php if ($erreur !== ''): ?>
            <div class="alert error">
                <?= h($erreur) ?>
            </div>
        <?php endif; ?>

        <?php if (!$medecinChoisi || !$psychologueChoisi): ?>
            <div class="alert error">
                <?php if (!$medecinChoisi): ?>
                    لا يوجد طبيب عام مسجل بتاريخ اليوم.
                    <br>
                <?php endif; ?>

                <?php if (!$psychologueChoisi): ?>
                    لا يوجد أخصائي نفساني مسجل بتاريخ اليوم.
                    <br>
                <?php endif; ?>

                سجّل الطبيب العام والأخصائي النفساني في صفحة إدارة الأطباء أولًا.
            </div>
        <?php endif; ?>

        <form method="POST" action="">

            <h2 class="section-title">معلومات المتسابق</h2>

            <div class="form-grid">

                <div class="field">
                    <label for="numero">رقم المتسابق</label>
                    <input
                        type="text"
                        id="numero"
                        name="numero"
                        value="<?= h($_POST['numero'] ?? '') ?>"
                        required
                    >
                </div>

                <div class="field">
                    <label for="nom">اللقب</label>
                    <input
                        type="text"
                        id="nom"
                        name="nom"
                        value="<?= h($_POST['nom'] ?? '') ?>"
                        required
                    >
                </div>

                <div class="field">
                    <label for="prenom">الاسم</label>
                    <input
                        type="text"
                        id="prenom"
                        name="prenom"
                        value="<?= h($_POST['prenom'] ?? '') ?>"
                        required
                    >
                </div>

                <div class="field">
                    <label for="grade">الرتبة</label>
                    <input
                        type="text"
                        id="grade"
                        name="grade"
                        value="<?= h($_POST['grade'] ?? '') ?>"
                        required
                    >
                </div>

                <div class="field">
                    <label for="sexe">الجنس</label>

                    <select id="sexe" name="sexe" required>
                        <option value="">اختر الجنس</option>

                        <option
                            value="ذكر"
                            <?= (($_POST['sexe'] ?? '') === 'ذكر') ? 'selected' : '' ?>
                        >ذكر</option>

                        <option
                            value="أنثى"
                            <?= (($_POST['sexe'] ?? '') === 'أنثى') ? 'selected' : '' ?>
                        >أنثى</option>
                    </select>
                </div>

            </div>

            <h2 class="section-title">
                الإسناد التلقائي لأطباء اليوم
            </h2>

            <div class="form-grid">

                <div class="doctor-box">
                    <div class="field">
                        <label>الطبيب العام المختار تلقائيًا</label>

                        <?php if ($medecinChoisi): ?>
                            <div class="doctor-name">
                                <?= h(
                                    $medecinChoisi['nom'] . ' ' .
                                    $medecinChoisi['prenom']
                                ) ?>
                            </div>

                            <div class="doctor-count">
                                عدد المتسابقين المسندين إليه اليوم:
                                <?= (int)$medecinChoisi['nombre_candidats'] ?>
                            </div>
                        <?php else: ?>
                            <div class="empty">
                                لا يوجد طبيب عام مسجل لهذا اليوم.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="doctor-box">
                    <div class="field">
                        <label>الأخصائي النفساني المختار تلقائيًا</label>

                        <?php if ($psychologueChoisi): ?>
                            <div class="doctor-name">
                                <?= h(
                                    $psychologueChoisi['nom'] . ' ' .
                                    $psychologueChoisi['prenom']
                                ) ?>
                            </div>

                            <div class="doctor-count">
                                عدد المتسابقين المسندين إليه اليوم:
                                <?= (int)$psychologueChoisi['nombre_candidats'] ?>
                            </div>
                        <?php else: ?>
                            <div class="empty">
                                لا يوجد أخصائي نفساني مسجل لهذا اليوم.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

            </div>

            <button
                type="submit"
                class="submit-btn"
                <?= (!$medecinChoisi || !$psychologueChoisi) ? 'disabled' : '' ?>
            >
                تسجيل المتسابق وإسناده تلقائيًا
            </button>

        </form>

        <a href="index.php" class="back-link">
            العودة إلى الصفحة الرئيسية
        </a>

    </div>
</div>
</body>
</html>
