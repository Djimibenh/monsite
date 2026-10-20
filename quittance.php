
<?php
require_once __DIR__ . '/config.php';

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$id) {
    exit('رقم المتسابق غير صحيح.');
}

$stmt = $pdo->prepare('SELECT * FROM candidats WHERE id = ?');
$stmt->execute([$id]);
$c = $stmt->fetch();

if (!$c) {
    exit('المتسابق غير موجود.');
}

function h($value): string {
    return htmlspecialchars(
        (string)($value ?? ''),
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

function dateAr($value): string {
    if (empty($value)) {
        return 'غير محدد';
    }

    $timestamp = strtotime($value);
    return $timestamp ? date('d/m/Y', $timestamp) : 'غير محدد';
}

function resultatAr($value): string {
    return match ($value) {
        'accepted' => 'مقبول',
        'rejected' => 'مرفوض',
        default => 'لم تُسجل النتيجة',
    };
}

function presenceAr($value): string {
    return match ($value) {
        'present' => 'حاضر',
        'absent' => 'غائب',
        default => 'غير محدد',
    };
}

$numero = $c['numero'] ?? '';

// إنشاء وثيقة Word
header('Content-Type: application/msword; charset=UTF-8');
header(
    'Content-Disposition: attachment; filename="وصل_الفحوصات_' .
    preg_replace('/[^a-zA-Z0-9_-]/', '_', (string)$numero) .
    '.doc"'
);

echo "\xEF\xBB\xBF";
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">


<style>
    @page {
        size: A4;
        margin: 1cm;
    }

    body {
        font-family: "Traditional Arabic", Tahoma, Arial, sans-serif;
        direction: rtl;
        text-align: right;
        font-size: 12pt;
        line-height: 1.35;
        color: #111;
        margin: 0;
    }

    .header {
        text-align: center;
        border-bottom: 2px solid #333;
        padding-bottom: 5px;
        font-size: 12pt;
        font-weight: bold;
    }

    .title {
        text-align: center;
        font-family: "Traditional Arabic", Tahoma, Arial, sans-serif;
        font-size: 18pt;
        font-weight: bold;
        margin: 8px 0;
    }

    .section {
        font-size: 13pt;
        font-weight: bold;
        background: #e9edf2;
        padding: 3px 6px;
        margin-top: 7px;
    }

    table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 3px;
    }

    th, td {
        border: 1px solid #555;
        padding: 3px 6px;
        text-align: right;
        font-size: 12pt;
    }

    th {
        width: 30%;
        background: #f3f3f3;
        font-weight: bold;
    }

    p {
        margin: 5px 0;
    }

    .notice {
        margin-top: 7px;
        border: 1px solid #555;
        padding: 5px;
        font-size: 11pt;
    }

    .signatures {
        margin-top: 9px;
        width: 100%;
    }

    .signatures td {
        height: 42px;
        vertical-align: top;
        width: 50%;
        font-size: 11pt;
    }
</style>


</head>
<body>

<div class="header">
    <strong>الجمهورية الجزائرية الديمقراطية الشعبية</strong><br>
    وزارة العدل<br>
    المدرسة الوطنية لموظفي إدارة السجون
</div>

<div class="title">وصل استلام معلومات ونتائج الفحوصات</div>

<p>
    <strong>رقم الوصل:</strong> <?= h($numero) ?>
    &nbsp;&nbsp;&nbsp;
    <strong>تاريخ التحرير:</strong> <?= date('d/m/Y') ?>
</p>

<div class="section">1- المعلومات الشخصية للمتسابق</div>

<table>
    <tr>
        <th>رقم التسجيل</th>
        <td><?= h($c['numero'] ?? '') ?></td>
    </tr>
    <tr>
        <th>اللقب</th>
        <td><?= h($c['nom'] ?? '') ?></td>
    </tr>
    <tr>
        <th>الاسم</th>
        <td><?= h($c['prenom'] ?? '') ?></td>
    </tr>
    <tr>
        <th>الرتبة</th>
        <td><?= h($c['grade'] ?? '') ?></td>
    </tr>
    <tr>
        <th>الجنس</th>
        <td><?= h($c['sexe'] ?? 'غير محدد') ?></td>
    </tr>
</table>

<div class="section">2- معلومات الفحص الطبي</div>

<table>
    <tr>
        <th>تاريخ الفحص</th>
        <td><?= dateAr($c['date_medicale'] ?? null) ?></td>
    </tr>

    <tr>
        <th>النتيجة</th>
        <td><?= resultatAr($c['resultat_medical'] ?? null) ?></td>
    </tr>
    <tr>
        <th>سبب الرفض</th>
        <td><?= h($c['motif_refus_medical'] ?? 'لا يوجد') ?></td>
    </tr>
</table>

<div class="section">3- معلومات الفحص النفسي</div>

<table>


    <tr>
        <th>النتيجة</th>
        <td><?= resultatAr($c['resultat_psychologique'] ?? null) ?></td>
    </tr>
    <tr>
        <th>سبب الرفض</th>
        <td><?= h($c['motif_refus_psychologique'] ?? 'لا يوجد') ?></td>
    </tr>
</table>

<div class="notice">
    <strong>تأكيد الاستلام:</strong><br>
    أؤكد استلام المعلومات المبينة في هذا الوصل.
    هذا الوصل يثبت الاستلام فقط، ولا يُعدّ قرارًا رسميًا
    بالقبول أو الرفض.
</div>



</body>
</html>
