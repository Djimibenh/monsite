
<?php
session_start();
date_default_timezone_set('Africa/Algiers');

require_once __DIR__ . '/config.php';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$message = '';
$messageType = 'info';
$saved = false;
$printData = null;
$selectedCandidate = null;
$candidates = [];

$q = trim($_GET['q'] ?? $_POST['q'] ?? '');
$selectedId = (int)($_GET['id'] ?? 0);

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function calculateFinalResult(
    string $presence,
    string $medical,
    string $psychological
): string {
    if ($presence === 'absent') {
        return 'غائب';
    }

    if ($medical === 'rejected' || $psychological === 'rejected') {
        return 'مرفوض';
    }

    return 'مقبول';
}

// معالجة الطلبات
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (
        empty($_POST['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'], (string)$_POST['csrf_token'])
    ) {
        $message = 'الطلب غير صالح. حدّث الصفحة وحاول مجدداً.';
        $messageType = 'error';
    } else {

        $action = $_POST['action'] ?? '';
        $id = filter_var(
            $_POST['candidate_id'] ?? null,
            FILTER_VALIDATE_INT
        );

        // حذف مترشح
        if ($action === 'delete') {
            if (!$id || $id <= 0) {
                $message = 'رقم المترشح غير صالح.';
                $messageType = 'error';
            } else {
                try {
                    $stmt = $pdo->prepare(
                        'DELETE FROM candidats WHERE id = ?'
                    );
                    $stmt->execute([$id]);

                    if ($stmt->rowCount() > 0) {
                        $message = 'تم حذف المترشح بنجاح.';
                        $messageType = 'success';
                    } else {
                        $message = 'لم يتم العثور على المترشح.';
                        $messageType = 'error';
                    }
                } catch (PDOException $e) {
                    error_log($e->getMessage());
                    $message = 'تعذر حذف المترشح. تحقق من وجود بيانات مرتبطة به.';
                    $messageType = 'error';
                }
            }
        }

        // حفظ النتائج
        elseif ($action === 'save') {
            $presence = $_POST['presence_medicale'] ?? '';
            $medical = $_POST['resultat_medical'] ?? '';
            $psychological = $_POST['resultat_psychologique'] ?? '';

            $motifMedical = trim($_POST['motif_refus_medical'] ?? '');
            $motifPsychological = trim(
                $_POST['motif_refus_psychologique'] ?? ''
            );

            if (
                !$id || $id <= 0 ||
                !in_array($presence, ['present', 'absent'], true) ||
                !in_array($medical, ['accepted', 'rejected'], true) ||
                !in_array($psychological, ['accepted', 'rejected'], true)
            ) {
                $message = 'تحقق من المعلومات المدخلة.';
                $messageType = 'error';
            } else {
                try {
                    $stmt = $pdo->prepare(
                        'SELECT id, numero, nom, prenom, grade
                         FROM candidats
                         WHERE id = ?'
                    );
                    $stmt->execute([$id]);
                    $candidate = $stmt->fetch(PDO::FETCH_ASSOC);

                    if (!$candidate) {
                        $message = 'المترشح غير موجود.';
                        $messageType = 'error';
                    } else {
                        $finalResult = calculateFinalResult(
                            $presence,
                            $medical,
                            $psychological
                        );

                        $now = date('Y-m-d H:i:s');

                        $stmt = $pdo->prepare(
                            'UPDATE candidats SET
                                presence_medicale = ?,
                                resultat_medical = ?,
                                resultat_psychologique = ?,
                                motif_refus_medical = ?,
                                motif_refus_psychologique = ?,
                                resultat_final = ?,
                                date_medicale = ?,
                                date_psychologique = ?
                             WHERE id = ?'
                        );

                        $stmt->execute([
                            $presence,
                            $medical,
                            $psychological,
                            $medical === 'rejected' ? $motifMedical : '',
                            $psychological === 'rejected'
                                ? $motifPsychological : '',
                            $finalResult,
                            $now,
                            $now,
                            $id
                        ]);

                        $message = 'تم حفظ نتائج المترشح بنجاح.';
                        $messageType = 'success';
                        $saved = true;

                        $printData = [
                            'numero' => $candidate['numero'] ?? '',
                            'nom' => $candidate['nom'] ?? '',
                            'prenom' => $candidate['prenom'] ?? '',
                            'grade' => $candidate['grade'] ?? '',
                            'presence' => $presence === 'present'
                                ? 'حاضر' : 'غائب',
                            'medical' => $medical === 'accepted'
                                ? 'مقبول' : 'مرفوض',
                            'psychological' => $psychological === 'accepted'
                                ? 'مقبول' : 'مرفوض',
                            'motif_medical' => $medical === 'rejected'
                                ? $motifMedical : '',
                            'motif_psychological' =>
                                $psychological === 'rejected'
                                    ? $motifPsychological : '',
                            'final' => $finalResult,
                            'date' => date('d/m/Y H:i')
                        ];
                    }
                } catch (PDOException $e) {
                    error_log($e->getMessage());
                    $message = 'تعذر حفظ النتائج. تحقق من أسماء الأعمدة في قاعدة البيانات.';
                    $messageType = 'error';
                }
            }
        }
    }
}

// جلب جميع المترشحين أو نتائج البحث
try {
    if ($q !== '') {
        $stmt = $pdo->prepare(
            'SELECT id, numero, nom, prenom, grade
             FROM candidats
             WHERE nom LIKE ?
                OR prenom LIKE ?
                OR numero LIKE ?
             ORDER BY nom, prenom'
        );

        $like = '%' . $q . '%';
        $stmt->execute([$like, $like, $like]);
    } else {
        $stmt = $pdo->query(
            'SELECT id, numero, nom, prenom, grade
             FROM candidats
             ORDER BY nom, prenom'
        );
    }

    $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // جلب المترشح المختار
    $selectedId = (int)($_POST['candidate_id'] ?? $_GET['id'] ?? 0);

    if ($selectedId > 0) {
        $stmt = $pdo->prepare(
            'SELECT id, numero, nom, prenom, grade,
                    presence_medicale,
                    resultat_medical,
                    resultat_psychologique,
                    motif_refus_medical,
                    motif_refus_psychologique
             FROM candidats
             WHERE id = ?'
        );

        $stmt->execute([$selectedId]);
        $selectedCandidate = $stmt->fetch(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    error_log($e->getMessage());
    $message = 'تعذر جلب البيانات. تحقق من قاعدة البيانات وأسماء الأعمدة.';
    $messageType = 'error';
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>تسيير نتائج الفحوصات</title>

    <style>
        * { box-sizing: border-box; }

        body {
            margin: 0;
            padding: 25px 15px;
            font-family: Tahoma, Arial, sans-serif;
            background: #f1f5f9;
            color: #1e293b;
        }

        .container { max-width: 1150px; margin: auto; }

        .header {
            background: linear-gradient(135deg, #0f766e, #115e59);
            color: white;
            border-radius: 16px;
            padding: 25px;
            margin-bottom: 22px;
        }

        .header h1 { margin: 0 0 10px; font-size: 25px; }
        .header p { margin: 0; }

        .card {
            background: white;
            padding: 22px;
            margin-bottom: 22px;
            border-radius: 14px;
            box-shadow: 0 4px 14px #0000000c;
        }

        h2 { margin-top: 0; color: #0f766e; font-size: 21px; }

        input[type="text"], select, textarea {
            width: 100%;
            padding: 12px;
            margin-top: 7px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            font-family: inherit;
            font-size: 15px;
            background: white;
        }

        textarea { min-height: 75px; resize: vertical; }

        label {
            display: block;
            margin-bottom: 15px;
            font-weight: bold;
        }

        .search-row { display: flex; gap: 10px; }
        .search-row input { flex: 1; min-width: 0; }

        .btn {
            display: inline-block;
            border: none;
            padding: 11px 15px;
            border-radius: 8px;
            cursor: pointer;
            font-family: inherit;
            font-size: 14px;
            text-decoration: none;
            text-align: center;
            color: white;
            white-space: nowrap;
        }

        .btn-primary { background: #0f766e; }
        .btn-danger { background: #dc2626; }
        .btn-secondary { background: #475569; }
        .btn-print { background: #2563eb; }

        .message {
            padding: 14px 16px;
            border-radius: 9px;
            margin-bottom: 20px;
        }

        .success { background: #dcfce7; color: #166534; }
        .error { background: #fee2e2; color: #991b1b; }
        .info { background: #e0f2fe; color: #075985; }

        .table-wrap { overflow-x: auto; }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 700px;
        }

        th, td {
            padding: 12px;
            border-bottom: 1px solid #e2e8f0;
            text-align: right;
        }

        th { background: #f8fafc; }

        .actions {
            display: flex;
            flex-wrap: wrap;
            gap: 7px;
            align-items: center;
        }

        .actions form { margin: 0; }

        .grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 16px;
        }

        .candidate-info {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
            background: #f8fafc;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
        }

        .buttons {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 15px;
        }

        .count {
            color: #475569;
            margin-bottom: 15px;
        }

        #printResult { display: none; }

        @media (max-width: 650px) {
            .grid, .candidate-info { grid-template-columns: 1fr; }
            .search-row { flex-direction: column; }
            .header h1 { font-size: 21px; }
            .card { padding: 16px; }
        }

        @media print {
            @page { size: A4; margin: 15mm; }

            body { background: white !important; padding: 0; }

            body * { visibility: hidden !important; }

            #printResult, #printResult * {
                visibility: visible !important;
            }

            #printResult {
                display: block !important;
                position: absolute;
                top: 0;
                right: 0;
                left: 0;
                width: 100%;
                color: black;
                background: white;
                font-family: Tahoma, Arial, sans-serif;
            }

            .print-title {
                text-align: center;
                border-bottom: 2px solid #111;
                padding-bottom: 15px;
                margin-bottom: 25px;
            }

            .print-row {
                padding: 12px 5px;
                border-bottom: 1px solid #ccc;
                font-size: 16px;
            }

            .print-footer {
                margin-top: 40px;
                text-align: left;
            }
        }
    </style>
</head>

<body>
<div class="container">

    <header class="header">
        <h1>إدارة نتائج الفحوصات الطبية والنفسية</h1>
        <p>البحث عن المترشحين وتسجيل النتائج وطباعة بطاقة النتيجة</p>
    </header>

    <?php if ($message !== ''): ?>
        <div class="message <?= h($messageType) ?>">
            <?= h($message) ?>
        </div>
    <?php endif; ?>

    <section class="card">
        <h2>🔎 البحث عن مترشح</h2>

        <form method="GET" action="resultats.php">
            <div class="search-row">
                <input
                    type="text"
                    name="q"
                    value="<?= h($q) ?>"
                    placeholder="ابحث بالاسم أو اللقب أو رقم التسجيل..."
                >
                <button class="btn btn-primary" type="submit">بحث</button>
                <a class="btn btn-secondary" href="resultats.php">عرض الجميع</a>
                <a class="btn btn-secondary" href="index.php">الرئيسية</a>
            </div>
        </form>
    </section>

    <section class="card">
        <h2>📋 قائمة المترشحين المسجلين</h2>

        <p class="count">
            عدد المترشحين الظاهرين: <?= count($candidates) ?>
        </p>

        <?php if (count($candidates) > 0): ?>
            <div class="table-wrap">
                <table>
                    <thead>
                    <tr>
                        <th>رقم التسجيل</th>
                        <th>اللقب</th>
                        <th>الاسم</th>
                        <th>الرتبة</th>
                        <th>الإجراءات</th>
                    </tr>
                    </thead>

                    <tbody>
                    <?php foreach ($candidates as $c): ?>
                        <tr>
                            <td><?= h($c['numero'] ?? '') ?></td>
                            <td><?= h($c['nom'] ?? '') ?></td>
                            <td><?= h($c['prenom'] ?? '') ?></td>
                            <td><?= h($c['grade'] ?? '') ?></td>
                            <td>
                                <div class="actions">
                                    <a
                                        class="btn btn-primary"
                                        href="resultats.php?id=<?= (int)$c['id'] ?>&q=<?= urlencode($q) ?>"
                                    >
                                        فتح النتائج
                                    </a>

                                    <form
                                        method="POST"
                                        action="resultats.php"
                                        onsubmit="return confirm('هل أنت متأكد من حذف هذا المترشح نهائياً؟');"
                                    >
                                        <input type="hidden" name="csrf_token"
                                               value="<?= h($_SESSION['csrf_token']) ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="candidate_id"
                                               value="<?= (int)$c['id'] ?>">
                                        <input type="hidden" name="q" value="<?= h($q) ?>">

                                        <button type="submit" class="btn btn-danger">
                                            🗑️ حذف
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <p>لا يوجد مترشحون مسجلون، أو لا توجد نتائج مطابقة للبحث.</p>
        <?php endif; ?>
    </section>

    <?php if ($selectedCandidate): ?>
        <section class="card">
            <h2>📝 تسجيل نتائج الفحص</h2>

            <div class="candidate-info">
                <div><strong>رقم التسجيل:</strong>
                    <?= h($selectedCandidate['numero'] ?? '') ?>
                </div>
                <div><strong>اللقب:</strong>
                    <?= h($selectedCandidate['nom'] ?? '') ?>
                </div>
                <div><strong>الاسم:</strong>
                    <?= h($selectedCandidate['prenom'] ?? '') ?>
                </div>
                <div><strong>الرتبة:</strong>
                    <?= h($selectedCandidate['grade'] ?? '') ?>
                </div>
            </div>

            <form method="POST" action="resultats.php">
                <input type="hidden" name="csrf_token"
                       value="<?= h($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="candidate_id"
                       value="<?= (int)$selectedCandidate['id'] ?>">
                <input type="hidden" name="q" value="<?= h($q) ?>">

                <div class="grid">
                    <div>
                        <label>
                            الحضور
                            <select name="presence_medicale" required>
                                <option value="present"
                                    <?= ($selectedCandidate['presence_medicale'] ?? '') === 'present' ? 'selected' : '' ?>>
                                    حاضر
                                </option>
                                <option value="absent"
                                    <?= ($selectedCandidate['presence_medicale'] ?? '') === 'absent' ? 'selected' : '' ?>>
                                    غائب
                                </option>
                            </select>
                        </label>
                    </div>

                    <div>
                        <label>
                            نتيجة الفحص الطبي
                            <select name="resultat_medical" id="medical" required>
                                <option value="accepted"
                                    <?= ($selectedCandidate['resultat_medical'] ?? '') === 'accepted' ? 'selected' : '' ?>>
                                    مقبول
                                </option>
                                <option value="rejected"
                                    <?= ($selectedCandidate['resultat_medical'] ?? '') === 'rejected' ? 'selected' : '' ?>>
                                    مرفوض
                                </option>
                            </select>
                        </label>

                        <label id="medicalReasonLabel">
                            سبب الرفض الطبي
                            <textarea name="motif_refus_medical" id="medicalReason"
                                placeholder="سبب الرفض الطبي..."><?= h($selectedCandidate['motif_refus_medical'] ?? '') ?></textarea>
                        </label>
                    </div>

                    <div>
                        <label>
                            نتيجة الفحص النفسي
                            <select name="resultat_psychologique" id="psychological" required>
                                <option value="accepted"
                                    <?= ($selectedCandidate['resultat_psychologique'] ?? '') === 'accepted' ? 'selected' : '' ?>>
                                    مقبول
                                </option>
                                <option value="rejected"
                                    <?= ($selectedCandidate['resultat_psychologique'] ?? '') === 'rejected' ? 'selected' : '' ?>>
                                    مرفوض
                                </option>
                            </select>
                        </label>

                        <label id="psychologicalReasonLabel">
                            سبب الرفض النفسي
                            <textarea name="motif_refus_psychologique" id="psychologicalReason"
                                placeholder="سبب الرفض النفسي..."><?= h($selectedCandidate['motif_refus_psychologique'] ?? '') ?></textarea>
                        </label>
                    </div>
                </div>

                <div class="buttons">
                    <button type="submit" class="btn btn-primary">
                        💾 حفظ النتائج
                    </button>
                    <a class="btn btn-secondary"
                       href="resultats.php?q=<?= urlencode($q) ?>">
                        رجوع إلى القائمة
                    </a>
                </div>
            </form>
        </section>
    <?php endif; ?>

    <?php if ($saved && $printData): ?>
        <section class="card">
            <h2>✅ تم حفظ النتائج</h2>
            <p>تم تسجيل النتائج بنجاح، ويمكنك طباعة بطاقة النتيجة.</p>
            <button type="button" class="btn btn-print" onclick="window.print()">
                🖨️ طباعة النتيجة
            </button>
        </section>

        <section id="printResult">
            <div class="print-title">
                <h2>بطاقة نتائج الفحوصات الطبية والنفسية</h2>
                <p>نتائج فحص المترشح</p>
            </div>

            <div class="print-row">
                <strong>رقم التسجيل:</strong> <?= h($printData['numero']) ?>
            </div>
            <div class="print-row">
                <strong>اللقب والاسم:</strong>
                <?= h($printData['nom'] . ' ' . $printData['prenom']) ?>
            </div>
            <div class="print-row">
                <strong>الرتبة:</strong> <?= h($printData['grade']) ?>
            </div>
            <div class="print-row">
                <strong>الحضور:</strong> <?= h($printData['presence']) ?>
            </div>
            <div class="print-row">
                <strong>الفحص الطبي:</strong> <?= h($printData['medical']) ?>
            </div>

            <?php if ($printData['motif_medical'] !== ''): ?>
                <div class="print-row">
                    <strong>سبب الرفض الطبي:</strong>
                    <?= h($printData['motif_medical']) ?>
                </div>
            <?php endif; ?>

            <div class="print-row">
                <strong>الفحص النفسي:</strong>
                <?= h($printData['psychological']) ?>
            </div>

            <?php if ($printData['motif_psychological'] !== ''): ?>
                <div class="print-row">
                    <strong>سبب الرفض النفسي:</strong>
                    <?= h($printData['motif_psychological']) ?>
                </div>
            <?php endif; ?>

            <div class="print-row">
                <strong>النتيجة النهائية:</strong> <?= h($printData['final']) ?>
            </div>
            <div class="print-row">
                <strong>تاريخ التسجيل:</strong> <?= h($printData['date']) ?>
            </div>

            <div class="print-footer">
                <p>الإمضاء والختم: ....................................</p>
            </div>
        </section>
    <?php endif; ?>

</div>

<script>
function updateReasonFields() {
    const medical = document.getElementById('medical');
    const psychological = document.getElementById('psychological');
    const medicalLabel = document.getElementById('medicalReasonLabel');
    const psychologicalLabel =
        document.getElementById('psychologicalReasonLabel');

    if (medical && medicalLabel) {
        medicalLabel.style.display =
            medical.value === 'rejected' ? 'block' : 'none';
    }

    if (psychological && psychologicalLabel) {
        psychologicalLabel.style.display =
            psychological.value === 'rejected' ? 'block' : 'none';
    }
}

document.getElementById('medical')
    ?.addEventListener('change', updateReasonFields);

document.getElementById('psychological')
    ?.addEventListener('change', updateReasonFields);

updateReasonFields();
</script>

</body>
</html>
