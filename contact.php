<?php
// ================================================
// contact.php – صفحة التواصل
// ================================================
require_once 'db_config.php';

session_start();

// ── Arabic date ──────────────────────────────────
$ar_days   = ['الأحد','الاثنين','الثلاثاء','الأربعاء','الخميس','الجمعة','السبت'];
$ar_months = ['يناير','فبراير','مارس','أبريل','مايو','يونيو','يوليو','أغسطس','سبتمبر','أكتوبر','نوفمبر','ديسمبر'];
$today = $ar_days[date('w')] . '، ' . date('j') . ' ' . $ar_months[(int)date('n') - 1] . ' ' . date('Y');

// ════════════════════════════════════════════════
// ✏️  غيّر البيانات دي لبيانات الكنيسة الحقيقية
// ════════════════════════════════════════════════
$church = [
    'name'      => 'كنيسة العذراء والملاك ميخائيل بالخلفاوي',
    'address'   => '13 ش عطية الاشقر الخلفاوى',
    'phones'    => ['0222027004', '0222026468', '0224316864'],
    'email'     => 'st.mary.and.archangel.michael1@gmail.com',
    'facebook'  => 'https://www.facebook.com/StMary.ArchMichael.Khalafawy',
    // Google Maps Embed URL
    'map_embed' => 'https://www.google.com/maps?q=%D9%83%D9%86%D9%8A%D8%B3%D8%A9%20%D8%A7%D9%84%D8%B3%D9%8A%D8%AF%D9%87%20%D8%A7%D9%84%D8%B9%D8%B0%D8%B1%D8%A7%D8%A1%20%D9%88%D8%A7%D9%84%D9%85%D9%84%D8%A7%D9%83%20%D9%85%D9%8A%D8%AE%D8%A7%D8%A6%D9%8A%D9%84%20%D8%A8%D8%A7%D9%84%D8%AE%D9%84%D9%81%D8%A7%D9%88%D9%8A%2013%20%D8%B4%20%D8%B9%D8%B7%D9%8A%D8%A9%20%D8%A7%D9%84%D8%A7%D8%B4%D9%82%D8%B1%20%D8%A7%D9%84%D8%AE%D9%84%D9%81%D8%A7%D9%88%D9%89&output=embed',
    // أوقات القداسات
    'masses' => [
        ['day' => 'الجمعة',    'time' => '6:00 ص – 8:30 ص'],
        ['day' => 'السبت',     'time' => '7:00 ص – 9:30 ص'],
        ['day' => 'الأحد',     'time' => '7:00 ص – 10:00 ص'],
        ['day' => 'الأعياد',   'time' => 'حسب الإعلان'],
    ],
];
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تواصل معنا – كنيسة العذراء والملاك ميخائيل</title>
    <link rel="icon" type="image/png" href="logo.png">
    <link rel="stylesheet" href="login.css">
    <link rel="stylesheet" href="contact.css">
</head>
<body>

<!-- ════ HEADER ════ -->
<header>
    <div class="logo-area">
        <svg class="logo-icon" viewBox="0 0 80 80" fill="none" xmlns="http://www.w3.org/2000/svg">
            <circle cx="40" cy="40" r="38" stroke="#C8973A" stroke-width="2" fill="none" opacity="0.25"/>
            <rect x="36" y="12" width="8" height="56" rx="3" fill="#C8973A"/>
            <rect x="16" y="34" width="48" height="8" rx="3" fill="#C8973A"/>
            <circle cx="40" cy="38" r="6" fill="#F5F0E8" stroke="#C8973A" stroke-width="1.5"/>
            <circle cx="40" cy="14" r="4" fill="none" stroke="#C8973A" stroke-width="1.5"/>
        </svg>
        <div class="logo-text">
            <h1>كنيسة العذراء والملاك ميخائيل</h1>
            <span class="header-date"><?= htmlspecialchars($today) ?></span>
        </div>
    </div>
    <nav>
        <a href="login.php">تسجيل الدخول</a>
        <a href="register.php" class="btn-outline">إنشاء حساب</a>
    </nav>
</header>

<!-- ════ HERO ════ -->
<div class="contact-hero">
    <svg class="hero-cross" viewBox="0 0 60 70" fill="none" xmlns="http://www.w3.org/2000/svg">
        <rect x="26" y="2" width="8" height="66" rx="3" fill="#C8973A"/>
        <rect x="8" y="22" width="44" height="8" rx="3" fill="#C8973A"/>
        <circle cx="30" cy="26" r="6" fill="#FDFAF4" stroke="#C8973A" stroke-width="1.5"/>
        <circle cx="30" cy="4" r="4" fill="none" stroke="#C8973A" stroke-width="1.5"/>
    </svg>
    <h1 class="hero-title">تواصل معنا</h1>
    <p class="hero-sub">يسعدنا التواصل معك في أي وقت</p>
</div>

<!-- ════ MAIN ════ -->
<main class="main-contact">

    <!-- ── Info Cards Row ── -->
    <div class="info-grid">

        <!-- Address -->
        <div class="info-card">
            <div class="info-icon">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
            </div>
            <div class="info-body">
                <h3>العنوان</h3>
                <p><?= htmlspecialchars($church['address']) ?></p>
            </div>
        </div>

        <!-- Phone -->
        <div class="info-card">
            <div class="info-icon">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 12 19.79 19.79 0 0 1 1.61 3.4 2 2 0 0 1 3.6 1.22h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.69 2.81a2 2 0 0 1-.45 2.11L7.91 8.96a16 16 0 0 0 6.13 6.13l1.28-.84a2 2 0 0 1 2.11-.45c.91.33 1.85.56 2.81.69A2 2 0 0 1 22 16.92z"/></svg>
            </div>
            <div class="info-body">
                <h3>التليفون</h3>
                <?php foreach ($church['phones'] as $phone): ?>
                    <p dir="ltr"><?= htmlspecialchars($phone) ?></p>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Email -->
        <div class="info-card">
            <div class="info-icon">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
            </div>
            <div class="info-body">
                <h3>البريد الإلكتروني</h3>
                <p dir="ltr"><?= htmlspecialchars($church['email']) ?></p>
            </div>
        </div>

        <!-- Mass Times -->
        <div class="info-card">
            <div class="info-icon">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
            </div>
            <div class="info-body">
                <h3>أوقات القداسات</h3>
                <?php foreach ($church['masses'] as $m): ?>
                    <p><strong><?= htmlspecialchars($m['day']) ?>:</strong> <?= htmlspecialchars($m['time']) ?></p>
                <?php endforeach; ?>
            </div>
        </div>

    </div><!-- /info-grid -->

    <!-- ── Google Map ── -->
    <div class="map-section">
        <div class="map-header">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
            موقع الكنيسة على الخريطة
        </div>
        <div class="map-wrapper">
            <iframe
                src="<?= htmlspecialchars($church['map_embed']) ?>"
                width="100%"
                height="100%"
                style="border:0;"
                allowfullscreen=""
                loading="lazy"
                referrerpolicy="no-referrer-when-downgrade"
                title="موقع كنيسة العذراء والملاك ميخائيل">
            </iframe>
        </div>
        <div class="map-footer">
            <a href="https://maps.google.com/?q=<?= urlencode($church['address']) ?>"
               target="_blank" rel="noopener" class="directions-btn">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polygon points="3 11 22 2 13 21 11 13 3 11"/></svg>
                الاتجاهات عبر Google Maps
            </a>
        </div>
    </div>

</main>

<!-- ════ FOOTER ════ -->
<footer class="site-footer">
    <p>© <?= date('Y') ?> <?= htmlspecialchars($church['name']) ?> – جميع الحقوق محفوظة</p>
</footer>

</body>
</html>
