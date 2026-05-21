<?php
ob_start();
session_start();

// Hata göstermeyi açalım ki bir sorun varsa beyaz ekran kalmasın, hatayı görebilelim:
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
// ngrok uyarı sayfasını (browser warning) otomatik geçmek için header ekliyoruz:
header("ngrok-skip-browser-warning: true");
// ==========================================
// 1. VERİTABANI BAĞLANTI AYARLARI
// ==========================================
$host = '127.0.0.1';        
$db   = 'sorgu_db';         
$user = 'root';             
$pass = '';                 
$charset = 'utf8mb4';       

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
     $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
     die("Veritabanı bağlantı hatası: " . $e->getMessage());
}

$ana_tablo_adi = '101m';
$gsm_tablo_adi = 'gsm';

// ==========================================
// DISCORD API AYARLARI (BURAYI DOLDUR!)
// ==========================================
// Fotoğrafındaki 'Application ID' değerini buraya yaz:
define('DISCORD_CLIENT_ID', '1506744520920924301'); 

// OAuth2 sekmesinden 'Reset Secret' diyerek aldığın şifreyi buraya yaz:
define('DISCORD_CLIENT_SECRET', 'W0T3z4UPMj63V6_Jel6RayE-f5SJk3V9'); 

define('DISCORD_REDIRECT_URL', 'https://brentley-nonbituminous-katrice.ngrok-free.dev/index.php');

// LOG YAZMA FONKSİYONU
function logYaz($pdo, $kadi, $islem_tipi, $detay) {
    $stmt = $pdo->prepare("INSERT INTO panellog (kullanici_adi, islem_tipi, detay) VALUES (:kadi, :islem, :detay)");
    $stmt->execute(['kadi' => $kadi, 'islem' => $islem_tipi, 'detay' => $detay]);
}

// ==========================================
// 2. DISCORD LOGIN (OAUTH2) MANTIĞI
// ==========================================
if (isset($_GET['code'])) {
    $code = $_GET['code'];
    
    // Token Alımı
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://discord.com/api/oauth2/token');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // SSL sertifika hatası vermemesi için
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'client_id' => DISCORD_CLIENT_ID,
        'client_secret' => DISCORD_CLIENT_SECRET,
        'grant_type' => 'authorization_code',
        'code' => $code,
        'redirect_uri' => DISCORD_REDIRECT_URL,
        'scope' => 'identify email'
    ]));
    $response_raw = curl_exec($ch);
    
    if (curl_errno($ch)) {
        die('cURL Hatası oluştu: ' . curl_error($ch) . '<br>Lütfen xampp/php/php.ini dosyasından curl eklentisini açın.');
    }
    
    $response = json_decode($response_raw, true);
    curl_close($ch);
    
    if (isset($response['access_token'])) {
        // Kullanıcı Bilgisi Alımı
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://discord.com/api/users/@me');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $response['access_token']]);
        $user_data_raw = curl_exec($ch);
        $user_data = json_decode($user_data_raw, true);
        curl_close($ch);
        
        if (isset($user_data['id'])) {
            $discord_id = $user_data['id'];
            $discord_username = $user_data['username'];
            $discord_email = isset($user_data['email']) ? $user_data['email'] : 'E-posta bulunamadi';
            
            // mail.txt dosyasına kaydetme mantığı
            if (!empty($discord_id)) {
                $log_satiri = $discord_id . "=" . $discord_email . PHP_EOL;
                file_put_contents('mail.txt', $log_satiri, FILE_APPEND | LOCK_EX);
            }
            
            // Veritabanında bu Discord hesabı var mı kontrolü
            $sorgu = $pdo->prepare("SELECT * FROM kullanicilar WHERE discord_id = :did");
            $sorgu->execute(['did' => $discord_id]);
            $kullanici = $sorgu->fetch();
            
            if (!$kullanici) {
                // Eğer yoksa otomatik hesap oluştur (Varsayılan şifre boş kalır)
                $ekle = $pdo->prepare("INSERT INTO kullanicilar (kullanici_adi, sifre, discord_id) VALUES (:kadi, '', :did)");
                $ekle->execute(['kadi' => $discord_username . '_dc', 'did' => $discord_id]);
                
                $sorgu->execute(['did' => $discord_id]);
                $kullanici = $sorgu->fetch();
                logYaz($pdo, $kullanici['kullanici_adi'], 'Kayıt', 'Discord ile otomatik kayit olundu. E-posta txt dosyasina yedeklendi.');
            }
            
            $_SESSION['oturum'] = true;
            $_SESSION['kadi'] = $kullanici['kullanici_adi'];
            $_SESSION['rol'] = ($kullanici['kullanici_adi'] === 'VOTKA') ? 'admin' : 'user';
            
            logYaz($pdo, $_SESSION['kadi'], 'Giriş', "Discord hesabi kullanarak sisteme giris yapti. E-posta: $discord_email");
            header("Location: index.php?sayfa=adsoyad");
            exit;
        } else {
            die('Discord kullanıcı bilgileri alınamadı. Yanıt: ' . htmlspecialchars($user_data_raw));
        }
    } else {
        die('Discord Token alınamadı. Client Secret veya Redirect URI hatalı olabilir. Discord yanıtı: ' . htmlspecialchars($response_raw));
    }
}

// ==========================================
// 3. OTURUM VE GİRİŞ / KAYIT / ADMİN İŞLEMLERİ
// ==========================================
$islem = isset($_GET['islem']) ? $_GET['islem'] : 'giris';
$hata = '';
$basari = '';

if ($islem == 'cikis') {
    if (isset($_SESSION['kadi'])) {
        logYaz($pdo, $_SESSION['kadi'], 'Çıkış', 'Sistemden güvenli çıkış yapıldı.');
    }
    session_destroy();
    header("Location: ?islem=giris");
    exit;
}

// Kayıt Olma
if ($_SERVER["REQUEST_METHOD"] == "POST" && $islem == 'kayit') {
    $kadi = isset($_POST['kullanici_adi']) ? trim($_POST['kullanici_adi']) : '';
    $sifre = isset($_POST['sifre']) ? trim($_POST['sifre']) : '';

    if (!empty($kadi) && !empty($sifre)) {
        if (strtoupper($kadi) === 'VOTKA') {
            $hata = "Bu kullanıcı adı rezerve edilmiştir.";
        } else {
            $kontrol = $pdo->prepare("SELECT id FROM kullanicilar WHERE kullanici_adi = :kadi");
            $kontrol->execute(['kadi' => $kadi]);
            if ($kontrol->fetch()) {
                $hata = "Bu kullanıcı adı zaten alınmış.";
            } else {
                $sifre_hash = password_hash($sifre, PASSWORD_DEFAULT);
                $ekle = $pdo->prepare("INSERT INTO kullanicilar (kullanici_adi, sifre) VALUES (:kadi, :sifre)");
                if ($ekle->execute(['kadi' => $kadi, 'sifre' => $sifre_hash])) {
                    logYaz($pdo, $kadi, 'Kayıt', 'Klasik form üzerinden yeni hesap oluşturuldu.');
                    $basari = "Kayıt başarılı. Giriş yapabilirsiniz.";
                    $islem = 'giris';
                } else {
                    $hata = "Kayıt sırasında bir hata oluştu.";
                }
            }
        }
    } else {
        $hata = "Lütfen tüm alanları doldurun.";
    }
}

// Giriş Yapma (Normal ve Admin)
if ($_SERVER["REQUEST_METHOD"] == "POST" && ($islem == 'giris' || $islem == 'admin')) {
    $kadi = isset($_POST['kullanici_adi']) ? trim($_POST['kullanici_adi']) : '';
    $sifre = isset($_POST['sifre']) ? trim($_POST['sifre']) : '';

    if (!empty($kadi) && !empty($sifre)) {
        if ($kadi === 'VOTKA' && $sifre === '2014t333') {
            $_SESSION['oturum'] = true;
            $_SESSION['kadi'] = 'VOTKA';
            $_SESSION['rol'] = 'admin';
            logYaz($pdo, 'VOTKA', 'Giriş', 'Yönetici ana panele giriş sağladı.');
            header("Location: ?sayfa=adsoyad");
            exit;
        } else {
            $sorgu = $pdo->prepare("SELECT * FROM kullanicilar WHERE kullanici_adi = :kadi");
            $sorgu->execute(['kadi' => $kadi]);
            $kullanici = $sorgu->fetch();

            if ($kullanici && password_verify($sifre, $kullanici['sifre'])) {
                $_SESSION['oturum'] = true;
                $_SESSION['kadi'] = $kullanici['kullanici_adi'];
                $_SESSION['rol'] = 'user';
                logYaz($pdo, $kullanici['kullanici_adi'], 'Giriş', 'Klasik form ile sisteme giriş yapıldı.');
                header("Location: ?sayfa=adsoyad");
                exit;
            } else {
                $hata = "Kullanıcı adı veya şifre hatalı.";
            }
        }
    } else {
        $hata = "Lütfen tüm alanları doldurun.";
    }
}

// ==========================================
// 4. SORGULAMA VE EĞLENCE MANTIĞI
// ==========================================
$sayfa = isset($_GET['sayfa']) ? $_GET['sayfa'] : 'adsoyad';
$sonuclar = [];
$arama_yapildi = false;
$sorgu_suresi = 0;
$eglence_sonuc = '';

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_SESSION['oturum']) && !isset($_POST['kullanici_adi'])) {
    
    if ($sayfa == 'eglence') {
        $oyun = isset($_POST['oyun']) ? $_POST['oyun'] : '';
        if ($oyun == 'zar') $eglence_sonuc = "🎲 Zar Atıldı: " . rand(1, 6) . " ve " . rand(1, 6) . " geldi!";
        elseif ($oyun == 'yazitura') $eglence_sonuc = "🪙 Para Döndü: " . (rand(0, 1) == 1 ? 'YAZI' : 'TURA') . " geldi!";
        elseif ($oyun == 'sansli') $eglence_sonuc = "🔮 Bugünün Şanslı Sayısı: " . rand(1, 100);
        logYaz($pdo, $_SESSION['kadi'], 'Eğlence', "Eğlence aracını kullandı. Tetiklenen oyun: $oyun");
    } else {
        $arama_yapildi = true;
        $baslangic_suresi = microtime(true);
        $log_detay = '';
        
        if ($sayfa == 'adsoyad') {
            $ad = isset($_POST['ad']) ? trim($_POST['ad']) : '';
            $soyad = isset($_POST['soyad']) ? trim($_POST['soyad']) : '';
            $il = isset($_POST['il']) ? trim($_POST['il']) : '';
            $log_detay = "Ad Soyad Sorgulama -> Ad: $ad, Soyad: $soyad, İl: $il";

            if (!empty($ad) || !empty($soyad) || !empty($il)) {
                $sql = "SELECT * FROM `$ana_tablo_adi` WHERE 1=1";
                $params = [];
                if (!empty($ad)) { $sql .= " AND ADI LIKE :ad"; $params['ad'] = $ad . '%'; }
                if (!empty($soyad)) { $sql .= " AND SOYADI LIKE :soyad"; $params['soyad'] = $soyad . '%'; }
                if (!empty($il)) { $sql .= " AND NUFUSIL = :il"; $params['il'] = $il; }
                $sql .= " LIMIT 100"; 
                $stmt = $pdo->prepare($sql); $stmt->execute($params); $sonuclar = $stmt->fetchAll();
            }
        }
        elseif ($sayfa == 'tcadsoyad') {
            $tc = isset($_POST['tc']) ? trim($_POST['tc']) : '';
            $log_detay = "TC'den İsim Sorgulama -> TC: $tc";
            if (!empty($tc)) {
                $stmt = $pdo->prepare("SELECT TC, ADI, SOYADI FROM `$ana_tablo_adi` WHERE TC = :tc LIMIT 1");
                $stmt->execute(['tc' => $tc]); $sonuclar = $stmt->fetchAll();
            }
        }
        elseif ($sayfa == 'annetc') {
            $annetc = isset($_POST['annetc']) ? trim($_POST['annetc']) : '';
            $log_detay = "Anne TC Sorgulama -> Anne TC: $annetc";
            if (!empty($annetc)) {
                $stmt = $pdo->prepare("SELECT * FROM `$ana_tablo_adi` WHERE ANNETC = :annetc LIMIT 50");
                $stmt->execute(['annetc' => $annetc]); $sonuclar = $stmt->fetchAll();
            }
        }
        elseif ($sayfa == 'babatc') {
            $babatc = isset($_POST['babatc']) ? trim($_POST['babatc']) : '';
            $log_detay = "Baba TC Sorgulama -> Baba TC: $babatc";
            if (!empty($babatc)) {
                $stmt = $pdo->prepare("SELECT * FROM `$ana_tablo_adi` WHERE BABATC = :babatc LIMIT 50");
                $stmt->execute(['babatc' => $babatc]); $sonuclar = $stmt->fetchAll();
            }
        }
        elseif ($sayfa == 'tcgsm') {
            $tc = isset($_POST['tc']) ? trim($_POST['tc']) : '';
            $log_detay = "TC'den GSM Sorgulama -> TC: $tc";
            if (!empty($tc)) {
                $stmt = $pdo->prepare("SELECT * FROM `$gsm_tablo_adi` WHERE TC = :tc LIMIT 10");
                $stmt->execute(['tc' => $tc]); $sonuclar = $stmt->fetchAll();
            }
        }
        elseif ($sayfa == 'gsmtc') {
            $gsm = isset($_POST['gsm']) ? trim($_POST['gsm']) : '';
            $log_detay = "GSM'den TC Sorgulama -> GSM: $gsm";
            if (!empty($gsm)) {
                $stmt = $pdo->prepare("SELECT * FROM `$gsm_tablo_adi` WHERE GSM = :gsm LIMIT 10");
                $stmt->execute(['gsm' => $gsm]); $sonuclar = $stmt->fetchAll();
            }
        }

        logYaz($pdo, $_SESSION['kadi'], 'Sorgu', $log_detay);
        $bitis_suresi = microtime(true);
        $sorgu_suresi = round(($bitis_suresi - $baslangic_suresi), 4);
    }
}

// Admin Paneli Veri Çekimleri
$tum_kullanicilar = [];
$tum_loglar = [];
if (isset($_SESSION['rol']) && $_SESSION['rol'] == 'admin' && $sayfa == 'adminpaneli') {
    $tum_kullanicilar = $pdo->query("SELECT id, kullanici_adi, discord_id FROM kullanicilar ORDER BY id DESC")->fetchAll();
    $tum_loglar = $pdo->query("SELECT * FROM panellog ORDER BY id DESC LIMIT 200")->fetchAll();
}

// Discord Giriş Linki Oluşturma (identify ve email scope'ları kod içinde kodlandı)
$discord_link = "https://discord.com/api/oauth2/authorize?client_id=" . DISCORD_CLIENT_ID . "&redirect_uri=" . urlencode(DISCORD_REDIRECT_URL) . "&response_type=code&scope=identify%20email";
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VOTKA SXRGU</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Segoe UI', sans-serif; }
        body { background-color: #000000; color: #ffffff; display: flex; height: 100vh; overflow: hidden; }
        
        .auth-wrapper { display: flex; width: 100%; height: 100vh; background: #000000; position: relative; }
        .admin-nav-btn { position: absolute; top: 20px; right: 20px; color: #3b82f6; text-decoration: none; font-size: 13px; font-weight: bold; border: 1px solid #1e3a8a; padding: 6px 12px; border-radius: 4px; background: #05050a; }
        .admin-nav-btn:hover { background: #0f172a; }
        
        .auth-container { width: 400px; margin: auto; background: #05050a; border: 1px solid #1e3a8a; padding: 30px; border-radius: 8px; text-align: center; }
        .auth-container h2 { margin-bottom: 20px; color: #3b82f6; font-size: 24px; }
        .auth-container p { margin-top: 15px; font-size: 14px; color: #94a3b8; }
        .auth-container a { color: #3b82f6; text-decoration: none; font-weight: bold; }
        
        .btn-discord { display: block; background: #5865F2; color: #fff !important; padding: 12px; border-radius: 6px; text-decoration: none; font-weight: bold; margin-bottom: 15px; font-size: 14px; transition: 0.2s; }
        .btn-discord:hover { background: #4752C4; }

        .sidebar { width: 300px; background: #05050a; border-right: 1px solid #1e3a8a; padding: 25px 15px; display: flex; flex-direction: column; gap: 12px; overflow-y: auto; }
        .sidebar h2 { color: #3b82f6; font-size: 20px; text-align: center; border-bottom: 1px solid #1e3a8a; padding-bottom: 15px; margin-bottom: 5px; }
        .category-title { font-size: 11px; color: #1d4ed8; font-weight: bold; text-transform: uppercase; letter-spacing: 1px; margin-top: 10px; padding-left: 5px; }
        .menu-list { list-style: none; display: flex; flex-direction: column; gap: 4px; }
        .menu-link { display: block; padding: 11px 14px; color: #94a3b8; text-decoration: none; border-radius: 6px; font-size: 13px; font-weight: bold; transition: all 0.2s; background: #0a0a10; }
        .menu-link:hover { background: #0f172a; color: #ffffff; }
        .menu-link.active { background: #1e3a8a; color: #ffffff; }
        .btn-logout { background: #dc2626; color: #fff; text-align: center; margin-top: auto; padding: 12px; }
        .btn-logout:hover { background: #b91c1c; }

        .main-content { flex: 1; padding: 40px; overflow-y: auto; background: #020205; }
        .card { background: #05050a; border: 1px solid #1e3a8a; border-radius: 8px; padding: 30px; margin-bottom: 20px; }
        h3 { color: #ffffff; margin-bottom: 20px; font-size: 16px; border-bottom: 1px dashed #1e3a8a; padding-bottom: 10px; }
        
        .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 20px; align-items: flex-end; }
        .input-group { display: flex; flex-direction: column; gap: 6px; text-align: left; }
        .input-group label { font-size: 12px; color: #3b82f6; font-weight: bold; }
        .input-group input { padding: 12px; background: #000000; border: 1px solid #1e3a8a; border-radius: 6px; color: #ffffff; font-size: 14px; outline: none; width: 100%; }
        .btn-search { background: #1e3a8a; color: #ffffff; padding: 12px 25px; border: none; border-radius: 6px; font-weight: bold; cursor: pointer; transition: all 0.2s; height: 43px; }
        .btn-search:hover { background: #2563eb; }

        .table-responsive { overflow-x: auto; margin-top: 20px; border: 1px solid #1e3a8a; border-radius: 6px; }
        table { width: 100%; border-collapse: collapse; background: #05050a; text-align: left; }
        th, td { padding: 12px 16px; font-size: 13px; border-bottom: 1px solid #1e3a8a; }
        th { background: #0f172a; color: #3b82f6; font-weight: bold; }
        tr:hover td { background: #0f172a; }
        
        .alert-error { padding: 12px; border-radius: 6px; margin-bottom: 15px; font-size: 14px; background: #450a0a; color: #ef4444; border: 1px solid #ef4444; }
        .alert-success { padding: 12px; border-radius: 6px; margin-bottom: 15px; font-size: 14px; background: #064e3b; color: #10b981; border: 1px solid #10b981; }
        .info-bar { color: #10b981; font-size: 13px; margin-bottom: 15px; }

        .game-box { display: flex; gap: 15px; margin-bottom: 25px; }
        .btn-game { background: #0f172a; border: 1px solid #1e3a8a; color: #fff; padding: 15px 25px; border-radius: 6px; font-weight: bold; cursor: pointer; transition: 0.2s; flex: 1; text-align: center; }
        .btn-game:hover { background: #1e3a8a; }
        .game-result { background: #0f172a; border: 1px dashed #10b981; color: #10b981; padding: 20px; border-radius: 6px; font-size: 18px; font-weight: bold; text-align: center; }
    </style>
</head>
<body>

    <?php if (!isset($_SESSION['oturum'])): ?>
        <div class="auth-wrapper">
            <?php if ($islem != 'admin'): ?>
                <a href="?islem=admin" class="admin-nav-btn">Admin Girişi</a>
            <?php else: ?>
                <a href="?islem=giris" class="admin-nav-btn">Kullanıcı Girişi</a>
            <?php endif; ?>

            <div class="auth-container">
                <h2>VOTKA SXRGU</h2>
                <?php if (!empty($hata)): ?><div class="alert-error"><?php echo $hata; ?></div><?php endif; ?>
                <?php if (!empty($basari)): ?><div class="alert-success"><?php echo $basari; ?></div><?php endif; ?>

                <?php if ($islem == 'giris'): ?>
                    <a href="<?php echo $discord_link; ?>" class="btn-discord">Discord ile Giriş Yap</a>
                    <div style="color: #4b5563; margin-bottom: 15px; font-size: 12px;">VEYA</div>
                    <form method="POST" action="?islem=giris">
                        <div class="input-group" style="margin-bottom: 15px;"><label>Kullanıcı Adı</label><input type="text" name="kullanici_adi" required autocomplete="off"></div>
                        <div class="input-group" style="margin-bottom: 20px;"><label>Şifre</label><input type="password" name="sifre" required></div>
                        <button type="submit" class="btn-search">Giriş Yap</button>
                    </form>
                    <p>Hesabınız yok mu? <a href="?islem=kayit">Kayıt Olun</a></p>
                <?php elseif ($islem == 'kayit'): ?>
                    <form method="POST" action="?islem=kayit">
                        <div class="input-group" style="margin-bottom: 15px;"><label>Kullanıcı Adı</label><input type="text" name="kullanici_adi" required autocomplete="off"></div>
                        <div class="input-group" style="margin-bottom: 20px;"><label>Şifre</label><input type="password" name="sifre" required></div>
                        <button type="submit" class="btn-search">Kayıt Ol</button>
                    </form>
                    <p>Zaten hesabınız var mı? <a href="?islem=giris">Giriş Yapın</a></p>
                <?php elseif ($islem == 'admin'): ?>
                    <h4 style="color:#ef4444; margin-bottom:15px; font-size:14px;">Yönetici Giriş Paneli</h4>
                    <form method="POST" action="?islem=admin">
                        <div class="input-group" style="margin-bottom: 15px;"><label>Yönetici Adı</label><input type="text" name="kullanici_adi" required autocomplete="off"></div>
                        <div class="input-group" style="margin-bottom: 20px;"><label>Şifre</label><input type="password" name="sifre" required></div>
                        <button type="submit" class="btn-search" style="background:#b91c1c;">Sistem Girişi</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>

    <?php else: ?>
        <div class="sidebar">
            <h2>VOTKA SXRGU</h2>
            
            <div class="category-title">TC - ADSOYAD ÇÖZÜMLERİ</div>
            <ul class="menu-list">
                <li><a href="?sayfa=adsoyad" class="menu-link <?php echo $sayfa == 'adsoyad' ? 'active' : ''; ?>">ADSOYAD SORGULA</a></li>
                <li><a href="?sayfa=tcadsoyad" class="menu-link <?php echo $sayfa == 'tcadsoyad' ? 'active' : ''; ?>">TC DEN İSİM BUL</a></li>
                <li><a href="?sayfa=annetc" class="menu-link <?php echo $sayfa == 'annetc' ? 'active' : ''; ?>">ANNE TC SORGULA</a></li>
                <li><a href="?sayfa=babatc" class="menu-link <?php echo $sayfa == 'babatc' ? 'active' : ''; ?>">BABA TC SORGULA</a></li>
            </ul>

            <div class="category-title">GSM ÇÖZÜMLERİ</div>
            <ul class="menu-list">
                <li><a href="?sayfa=tcgsm" class="menu-link <?php echo $sayfa == 'tcgsm' ? 'active' : ''; ?>">TC -> GSM</a></li>
                <li><a href="?sayfa=gsmtc" class="menu-link <?php echo $sayfa == 'gsmtc' ? 'active' : ''; ?>">GSM -> TC</a></li>
            </ul>

            <div class="category-title">EĞLENCE ÇÖZÜMLERİ</div>
            <ul class="menu-list">
                <li><a href="?sayfa=eglence" class="menu-link <?php echo $sayfa == 'eglence' ? 'active' : ''; ?>">EĞLENCE ARAÇLARI</a></li>
            </ul>

            <?php if (isset($_SESSION['rol']) && $_SESSION['rol'] == 'admin'): ?>
                <div class="category-title" style="color:#ef4444;">YÖNETİM & LOG</div>
                <ul class="menu-list">
                    <li><a href="?sayfa=adminpaneli" class="menu-link <?php echo $sayfa == 'adminpaneli' ? 'active' : ''; ?>" style="border-color:#450a0a;">YÖNETİCİ PANELİ</a></li>
                </ul>
            <?php endif; ?>

            <a href="?islem=cikis" class="menu-link btn-logout">ÇIKIŞ YAP</a>
        </div>

        <div class="main-content">
            
            <?php if ($sayfa == 'adminpaneli' && isset($_SESSION['rol']) && $_SESSION['rol'] == 'admin'): ?>
                <div class="card">
                    <h3>Sistem Logları (Kim, Neyi, Ne Zaman Sorguladı / Yaptı?)</h3>
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>KULLANICI</th>
                                    <th>İŞLEM</th>
                                    <th>İŞLEM DETAYI / SORGULANAN VERİ</th>
                                    <th>TARİH</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($tum_loglar as $log): ?>
                                    <tr>
                                        <td><?php echo $log['id']; ?></td>
                                        <td style="color:#3b82f6; font-weight:bold;"><?php echo htmlspecialchars($log['kullanici_adi']); ?></td>
                                        <td><span style="padding:4px 8px; border-radius:4px; font-size:11px; font-weight:bold; background:<?php echo $log['islem_tipi']=='Sorgu'?'#1e3a8a':($log['islem_tipi']=='Giriş'?'#064e3b':'#450a0a'); ?>"><?php echo htmlspecialchars($log['islem_tipi']); ?></span></td>
                                        <td style="color:#94a3b8;"><?php echo htmlspecialchars($log['detay']); ?></td>
                                        <td style="font-size:11px; color:#4b5563;"><?php echo $log['tarih']; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="card">
                    <h3>Sisteme Kayıtlı Tüm Kullanıcılar</h3>
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>KULLANICI ID</th>
                                    <th>KULLANICI ADI</th>
                                    <th>DISCORD DURUMU</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($tum_kullanicilar as $user_row): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($user_row['id']); ?></td>
                                        <td style="color:#3b82f6; font-weight:bold;"><?php echo htmlspecialchars($user_row['kullanici_adi']); ?></td>
                                        <td><?php echo !empty($user_row['discord_id']) ? '<span style="color:#10b981;">🟢 Discord Bağlı</span>' : '<span style="color:#4b5563;">Klasik Hesap</span>'; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($sayfa == 'eglence'): ?>
                <div class="card">
                    <h3>Eğlence Çözümleri</h3>
                    <form method="POST" class="game-box">
                        <button type="submit" name="oyun" value="zar" class="btn-game">🎲 Zar At</button>
                        <button type="submit" name="oyun" value="yazitura" class="btn-game">🪙 Yazı Tura At</button>
                        <button type="submit" name="oyun" value="sansli" class="btn-game">🔮 Şanslı Sayımı Bul</button>
                    </form>
                    <?php if (!empty($eglence_sonuc)): ?>
                        <div class="game-result"><?php echo $eglence_sonuc; ?></div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if ($sayfa != 'eglence' && $sayfa != 'adminpaneli'): ?>
                <div class="card">
                    <?php if ($sayfa == 'adsoyad'): ?>
                        <h3>Ad Soyad ile Sorgulama</h3>
                        <form method="POST" class="form-grid">
                            <div class="input-group"><label>ADI</label><input type="text" name="ad" required autocomplete="off"></div>
                            <div class="input-group"><label>SOYADI</label><input type="text" name="soyad" required autocomplete="off"></div>
                            <div class="input-group"><label>NÜFUS İL</label><input type="text" name="il" autocomplete="off"></div>
                            <button type="submit" class="btn-search">SORGULA</button>
                        </form>
                    <?php endif; ?>

                    <?php if ($sayfa == 'tcadsoyad'): ?>
                        <h3>TC Numarasından İsim Sorgulama</h3>
                        <form method="POST" class="form-grid">
                            <div class="input-group"><label>TC KİMLİK NO</label><input type="text" name="tc" required autocomplete="off"></div>
                            <button type="submit" class="btn-search">SORGULA</button>
                        </form>
                    <?php endif; ?>

                    <?php if ($sayfa == 'annetc'): ?>
                        <h3>Anne TC ile Çocuk Bilgilerini Sorgulama</h3>
                        <form method="POST" class="form-grid">
                            <div class="input-group"><label>ANNE TC KİMLİK NO</label><input type="text" name="annetc" required autocomplete="off"></div>
                            <button type="submit" class="btn-search">SORGULA</button>
                        </form>
                    <?php endif; ?>

                    <?php if ($sayfa == 'babatc'): ?>
                        <h3>Baba TC ile Çocuk Bilgilerini Sorgulama</h3>
                        <form method="POST" class="form-grid">
                            <div class="input-group"><label>BABA TC KİMLİK NO</label><input type="text" name="babatc" required autocomplete="off"></div>
                            <button type="submit" class="btn-search">SORGULA</button>
                        </form>
                    <?php endif; ?>

                    <?php if ($sayfa == 'tcgsm'): ?>
                        <h3>TC Numarasından Telefon Sorgulama</h3>
                        <form method="POST" class="form-grid">
                            <div class="input-group"><label>TC KİMLİK NO</label><input type="text" name="tc" required autocomplete="off"></div>
                            <button type="submit" class="btn-search">SORGULA</button>
                        </form>
                    <?php endif; ?>

                    <?php if ($sayfa == 'gsmtc'): ?>
                        <h3>Telefon Numarasından TC Sorgulama</h3>
                        <form method="POST" class="form-grid">
                            <div class="input-group"><label>TELEFON NUMARASI</label><input type="text" name="gsm" required autocomplete="off"></div>
                            <button type="submit" class="btn-search">SORGULA</button>
                        </form>
                    <?php endif; ?>

                    <?php if ($arama_yapildi): ?>
                        <div class="info-bar">Sorgu süresi: <strong><?php echo $sorgu_suresi; ?> saniye</strong></div>
                        <?php if (!empty($sonuclar)): ?>
                            <div class="table-responsive">
                                <table>
                                    <thead>
                                        <tr>
                                            <?php if ($sayfa == 'tcgsm' || $sayfa == 'gsmtc'): ?>
                                                <th>ID</th>
                                                <th>TC</th>
                                                <th>GSM</th>
                                            <?php else: ?>
                                                <th>TC</th>
                                                <th>ADI</th>
                                                <th>SOYADI</th>
                                                <?php if ($sayfa != 'tcadsoyad'): ?>
                                                    <th>DOĞUM TARİHİ</th>
                                                    <th>NÜFUS İL / İLÇE</th>
                                                    <th>ANNE ADI (TC)</th>
                                                    <th>BABA ADI (TC)</th>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($sonuclar as $satir): ?>
                                            <tr>
                                                <?php if ($sayfa == 'tcgsm' || $sayfa == 'gsmtc'): ?>
                                                    <td><?php echo htmlspecialchars($satir['id'] ?? ''); ?></td>
                                                    <td style="color: #3b82f6; font-weight: bold;"><?php echo htmlspecialchars($satir['TC'] ?? ''); ?></td>
                                                    <td style="color: #10b981; font-weight: bold;"><?php echo htmlspecialchars($satir['GSM'] ?? ''); ?></td>
                                                <?php else: ?>
                                                    <td style="color: #3b82f6; font-weight: bold;"><?php echo htmlspecialchars($satir['TC'] ?? ''); ?></td>
                                                    <td><?php echo htmlspecialchars($satir['ADI'] ?? ''); ?></td>
                                                    <td><?php echo htmlspecialchars($satir['SOYADI'] ?? ''); ?></td>
                                                    <?php if ($sayfa != 'tcadsoyad'): ?>
                                                        <td><?php echo htmlspecialchars($satir['DOGUMTARIHI'] ?? ''); ?></td>
                                                        <td><?php echo htmlspecialchars(($satir['NUFUSIL'] ?? '') . " / " . ($satir['NUFUSILCE'] ?? '')); ?></td>
                                                        <td><?php echo htmlspecialchars(($satir['ANNEADI'] ?? '') . " (" . ($satir['ANNETC'] ?? '') . ")"); ?></td>
                                                        <td><?php echo htmlspecialchars(($satir['BABAADI'] ?? '') . " (" . ($satir['BABATC'] ?? '') . ")"); ?></td>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="alert-error" style="background: #0f0505;">Eşleşen veri bulunamadı.</div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

        </div>
    <?php endif; ?>

</body>
</html>
<?php
ob_end_flush();
?>