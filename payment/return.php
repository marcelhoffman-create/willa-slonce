<!DOCTYPE html>
<html lang="pl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Potwierdzenie płatności — Willa Słońce</title>
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
  <style>
    *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
    body{font-family:'Inter',sans-serif;background:#FAF8F5;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px;}
    .card{background:#fff;border-radius:20px;padding:48px 40px;max-width:540px;width:100%;text-align:center;box-shadow:0 4px 32px rgba(0,0,0,.08);}
    .icon{width:72px;height:72px;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 24px;}
    .icon-ok{background:#e8f5e9;}
    .icon-wait{background:#fff8e1;}
    .icon-err{background:#ffebee;}
    .icon svg{width:36px;height:36px;}
    h1{font-family:'Playfair Display',serif;font-size:1.9rem;margin-bottom:12px;color:#1a1a1a;}
    p{color:#555;line-height:1.7;font-size:.97rem;}
    .detail{background:#f5f0e8;border-radius:12px;padding:20px;margin:24px 0;text-align:left;}
    .detail h4{font-size:.88rem;color:#888;text-transform:uppercase;letter-spacing:.04em;margin-bottom:12px;}
    .detail-row{display:flex;justify-content:space-between;font-size:.9rem;padding:6px 0;border-bottom:1px solid #e8e2d8;}
    .detail-row:last-child{border-bottom:none;}
    .detail-row span{color:#888;}
    .detail-row strong{color:#1a1a1a;}
    .btn{display:inline-block;background:#C17817;color:#fff;padding:.85rem 2rem;border-radius:50px;font-weight:600;font-size:.95rem;text-decoration:none;margin-top:8px;transition:background .2s;}
    .btn:hover{background:#D4922E;}
    .notice{font-size:.8rem;color:#aaa;margin-top:16px;}
  </style>
</head>
<body>
<?php
require __DIR__ . '/p24-config.php';

$type      = $_GET['type']    ?? '';
$sessionId = $_GET['session'] ?? '';
$status    = $_GET['status']  ?? '';  // P24 moze dolaczyc status

// Zaladuj zamowienie
$order = $sessionId ? load_order($sessionId) : null;

// Sprawdz status
$isPaid    = $order && ($order['status'] ?? '') === 'paid';
$isPending = $order && ($order['status'] ?? '') === 'pending';

if ($type === 'booking') {
    $backUrl   = '../rezerwacje.html';
    $shopLabel = 'rezerwację';
    $title     = $isPaid ? 'Płatność zatwierdzona!' : 'Dziękujemy!';
    $msg       = $isPaid
        ? 'Twoja płatność za pobyt w Willi Słońce została pomyślnie zaksięgowana. Potwierdzenie rezerwacji wyślemy na Twój adres email.'
        : 'Twoje zamówienie zostało przyjęte. Potwierdzenie prześlemy na email po weryfikacji płatności (zazwyczaj do kilku minut).';
    $iconColor = $isPaid ? 'ok' : 'wait';
    $iconPath  = $isPaid
        ? '<polyline points="20 6 9 17 4 12"/>'
        : '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>';
    $iconStroke = $isPaid ? '#2e7d32' : '#f57c00';

    if ($order && $type === 'booking') {
        $from = $order['checkin']  ?? '';
        $to   = $order['checkout'] ?? '';
        $name = ($order['imie'] ?? '') . ' ' . ($order['nazwisko'] ?? '');
        $kwota = ($order['kwota'] ?? 0) . ' zł';
    }
} else {
    $backUrl   = '../sklep.html';
    $shopLabel = 'zamówienie';
    $title     = $isPaid ? 'Zamówienie opłacone!' : 'Zamówienie przyjęte!';
    $msg       = $isPaid
        ? 'Płatność za Twoje zamówienie ze sklepu Willi Słońce przeszła pomyślnie. Skontaktujemy się wkrótce w sprawie dostawy.'
        : 'Twoje zamówienie zostało przyjęte. Czekamy na potwierdzenie płatności — zajmuje to zazwyczaj kilka minut.';
    $iconColor = $isPaid ? 'ok' : 'wait';
    $iconPath  = $isPaid
        ? '<polyline points="20 6 9 17 4 12"/>'
        : '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>';
    $iconStroke = $isPaid ? '#2e7d32' : '#f57c00';

    if ($order) {
        $name  = $order['name']  ?? '';
        $kwota = ($order['total'] ?? 0) . ' zł';
    }
}
?>
  <div class="card">
    <div class="icon icon-<?= $iconColor ?>">
      <svg viewBox="0 0 24 24" fill="none" stroke="<?= $iconStroke ?>" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
        <?= $iconPath ?>
      </svg>
    </div>

    <h1><?= htmlspecialchars($title) ?></h1>
    <p><?= htmlspecialchars($msg) ?></p>

    <?php if ($order): ?>
    <div class="detail">
      <h4>Szczegóły</h4>
      <?php if ($name ?? ''): ?>
      <div class="detail-row"><span>Imię i nazwisko</span><strong><?= htmlspecialchars($name) ?></strong></div>
      <?php endif; ?>
      <?php if ($type === 'booking' && ($from ?? '')): ?>
      <div class="detail-row"><span>Przyjazd</span><strong><?= htmlspecialchars($from) ?></strong></div>
      <div class="detail-row"><span>Wyjazd</span><strong><?= htmlspecialchars($to) ?></strong></div>
      <?php endif; ?>
      <?php if ($kwota ?? ''): ?>
      <div class="detail-row"><span>Kwota</span><strong><?= htmlspecialchars($kwota) ?></strong></div>
      <?php endif; ?>
      <div class="detail-row"><span>Status</span><strong><?= $isPaid ? '✅ Opłacone' : '⏳ Oczekuje' ?></strong></div>
    </div>
    <?php endif; ?>

    <a href="<?= $backUrl ?>" class="btn">
      <?= $type === 'booking' ? 'Wróć na stronę rezerwacji' : 'Wróć do sklepu' ?>
    </a>
    <p class="notice">Numer ref.: <?= htmlspecialchars($sessionId ?: '—') ?></p>
  </div>
</body>
</html>
