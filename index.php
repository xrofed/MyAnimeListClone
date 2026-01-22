<?php
// ==========================================
// 1. KONFIGURASI & HELPER
// ==========================================
define('APP_NAME', 'MyAnimeList Clone');
// Auto Detect Base URL (Aman untuk HTTP/HTTPS)
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
define('BASE_URL', $protocol . $_SERVER['HTTP_HOST'] . str_replace('/index.php', '', $_SERVER['SCRIPT_NAME']));
define('CACHE_TIME', 3600); // Cache 1 Jam

// Helper: Ubah Judul jadi Slug URL (contoh: One Piece -> one-piece)
function slugify($text) {
    $text = preg_replace('~[^\pL\d]+~u', '-', $text);
    $text = iconv('utf-8', 'us-ascii//TRANSLIT', $text);
    $text = preg_replace('~[^-\w]+~', '', $text);
    return strtolower(trim($text, '-'));
}

// Helper: Ambil Data API dengan Cache
function fetchAPI($endpoint) {
    // Buat folder cache otomatis jika belum ada
    if (!is_dir(__DIR__ . '/cache')) mkdir(__DIR__ . '/cache', 0755, true);
    
    $cacheFile = __DIR__ . '/cache/' . md5($endpoint) . '.json';

    // Cek apakah ada cache yang valid (< 1 jam)
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile) < CACHE_TIME)) {
        return json_decode(file_get_contents($cacheFile), true);
    }

    // Jika tidak, ambil dari Jikan API
    $ch = curl_init("https://api.jikan.moe/v4" . $endpoint);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'MALClone/v6-Final');
    $res = curl_exec($ch);
    curl_close($ch);
    
    $data = json_decode($res, true);
    
    // Simpan ke cache jika data valid
    if($data) file_put_contents($cacheFile, json_encode($data));
    return $data;
}

// ==========================================
// 2. ROUTING & LOGIC
// ==========================================
$route = $_GET['route'] ?? 'home';
$id = $_GET['id'] ?? null;
$q = $_GET['q'] ?? '';

// --- GENERATOR ROBOTS.TXT ---
if ($route == 'robots') {
    header("Content-Type: text/plain");
    echo "User-agent: *\nAllow: /\nDisallow: /cache/\nSitemap: " . BASE_URL . "/sitemap.xml.gz";
    exit;
}

// --- GENERATOR SITEMAP (XML & GZIP) ---
if ($route == 'sitemap') {
    // Tampung XML dalam variabel string
    $xml = '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
    
    // Tambah Home
    $xml .= '<url><loc>'.BASE_URL.'/</loc><changefreq>daily</changefreq><priority>1.0</priority></url>';
    
    // Tambah Data Anime (Top 25)
    $top = fetchAPI('/top/anime');
    if(isset($top['data'])){
        foreach($top['data'] as $anime){
            $url = BASE_URL . "/anime/" . $anime['mal_id'] . "/" . slugify($anime['title']);
            $loc = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
            $xml .= "<url><loc>{$loc}</loc><changefreq>weekly</changefreq><priority>0.8</priority></url>";
        }
    }
    $xml .= '</urlset>';

    // Cek Mode GZIP (dari .htaccess)
    if (isset($_GET['gz']) && $_GET['gz'] == 1) {
        $gzip = gzencode($xml, 9); // Kompresi level max
        header("Content-Type: application/x-gzip");
        header("Content-Encoding: gzip");
        header("Content-Disposition: attachment; filename=\"sitemap.xml.gz\"");
        header("Content-Length: " . strlen($gzip));
        echo $gzip;
    } else {
        header("Content-type: text/xml");
        echo $xml;
    }
    exit;
}

// --- CONTROLLER HALAMAN ---
$metaData = ['title' => APP_NAME, 'desc' => 'Database Anime Terlengkap dan Cepat.', 'image' => ''];
$currentPageUrl = BASE_URL . $_SERVER['REQUEST_URI'];

if ($route == 'detail' && $id) {
    // Ambil detail anime
    $fullData = fetchAPI("/anime/{$id}/full");
    $anime = $fullData['data'] ?? null;
    $charData = fetchAPI("/anime/{$id}/characters");
    
    if($anime) {
        $metaData['title'] = $anime['title'] . " - " . APP_NAME;
        $metaData['desc'] = substr($anime['synopsis'], 0, 160) . "...";
        $metaData['image'] = $anime['images']['webp']['large_image_url'];
    }
} elseif ($route == 'search') {
    $searchData = fetchAPI("/anime?q=" . urlencode($q) . "&sfw=true");
    $metaData['title'] = "Cari: $q - " . APP_NAME;
} else {
    // Halaman Depan
    $seasonNow = fetchAPI("/seasons/now?limit=12");
    $topAnime = fetchAPI("/top/anime?filter=airing&limit=10");
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $metaData['title'] ?></title>
    <meta name="description" content="<?= $metaData['desc'] ?>">
    <meta name="robots" content="index, follow">
    
    <meta property="og:title" content="<?= $metaData['title'] ?>" />
    <meta property="og:description" content="<?= $metaData['desc'] ?>" />
    <meta property="og:image" content="<?= $metaData['image'] ?>" />
    <meta property="og:url" content="<?= $currentPageUrl ?>" />

    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .mal-blue { background-color: #2e51a2; }
        .text-mal-blue { color: #2e51a2; }
        body { background-color: #f3f4f6; font-family: Verdana, sans-serif; }
        .sidebar-header { font-weight: bold; border-bottom: 1px solid #e5e7eb; margin-bottom: 0.5rem; padding-bottom: 0.25rem; font-size: 0.75rem; }
    </style>
</head>
<body class="text-gray-800 text-sm">

<nav class="mal-blue text-white shadow-md sticky top-0 z-50">
    <div class="container mx-auto px-4 h-12 flex items-center justify-between">
        <a href="<?= BASE_URL ?>/" class="font-bold text-lg">MyAnimeList<span class="font-normal opacity-70">Clone</span></a>
        <form action="<?= BASE_URL ?>/index.php" method="GET" class="hidden md:flex" onsubmit="window.location.href='<?= BASE_URL ?>/search/'+this.q.value; return false;">
            <input type="text" name="q" placeholder="Cari Anime..." class="px-2 py-1 rounded-l text-black text-xs w-48 focus:outline-none">
            <button type="submit" class="bg-gray-800 px-3 py-1 rounded-r text-xs font-bold hover:bg-gray-700"></button>
        </form>
    </div>
</nav>

<div class="container mx-auto px-2 md:px-4 py-6">

    <?php if ($route == 'detail' && isset($anime)): ?>
        <div class="bg-white border-b-2 border-gray-300 mb-2 p-3 rounded-t">
            <h1 class="text-xl md:text-2xl font-bold"><?= $anime['title'] ?></h1>
            <h2 class="text-gray-500 text-xs"><?= $anime['title_english'] ?></h2>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-4 gap-6">
            
            <div class="md:col-span-1 space-y-4">
                
                <div class="flex flex-col items-center md:block">
                    <img src="<?= $anime['images']['webp']['large_image_url'] ?>" class="w-40 md:w-full border rounded shadow-sm">
                </div>

                <div class="bg-white border p-2 rounded text-center shadow-sm">
                    <div class="text-xs text-gray-500">Score</div>
                    <div class="text-2xl font-bold text-mal-blue"><?= $anime['score'] ?></div>
                    <div class="text-xs text-gray-400"><?= number_format($anime['scored_by']) ?> users</div>
                    <div class="mt-2 text-xs border-t pt-2 flex justify-between px-4">
                        <span>Ranked <strong>#<?= $anime['rank'] ?></strong></span>
                        <span>Pop <strong>#<?= $anime['popularity'] ?></strong></span>
                    </div>
                </div>

                <div class="bg-white border p-3 text-xs leading-loose rounded shadow-sm">
                    <h3 class="sidebar-header">Information</h3>
                    <p><span class="text-mal-blue font-bold">Type:</span> <?= $anime['type'] ?></p>
                    <p><span class="text-mal-blue font-bold">Eps:</span> <?= $anime['episodes'] ?></p>
                    <p><span class="text-mal-blue font-bold">Status:</span> <?= $anime['status'] ?></p>
                    <p><span class="text-mal-blue font-bold">Aired:</span> <?= $anime['aired']['string'] ?></p>
                    <p><span class="text-mal-blue font-bold">Studio:</span> <?= $anime['studios'][0]['name'] ?? '-' ?></p>
                    <p><span class="text-mal-blue font-bold">Rating:</span> <?= $anime['rating'] ?></p>
                </div>

                <?php if(!empty($anime['external'])): ?>
                <div class="bg-white border p-3 text-xs rounded shadow-sm">
                    <h3 class="sidebar-header">Resources</h3>
                    <?php foreach(array_slice($anime['external'], 0, 5) as $link): ?>
                        <a href="<?= $link['url'] ?>" target="_blank" class="block py-1 hover:underline text-mal-blue truncate">
                            <i class="fas fa-external-link-alt text-[10px] mr-1"></i> <?= $link['name'] ?>
                        </a>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>

            <div class="md:col-span-3 space-y-6">
                
                <div class="bg-white border-t-2 border-blue-600 shadow-sm">
                    <div class="bg-gray-100 px-3 py-2 font-bold text-xs border-b">Synopsis</div>
                    <div class="p-4 text-sm leading-relaxed whitespace-pre-line text-justify text-gray-700">
                        <?= $anime['synopsis'] ?>
                    </div>
                </div>

                <?php if($anime['trailer']['embed_url']): ?>
                <div class="bg-white border-t-2 border-blue-600 shadow-sm">
                    <div class="bg-gray-100 px-3 py-2 font-bold text-xs border-b">PV & Trailer</div>
                    <div class="aspect-w-16 aspect-h-9 p-4 bg-black">
                        <iframe src="<?= $anime['trailer']['embed_url'] ?>" class="w-full h-64 md:h-96" frameborder="0" allowfullscreen></iframe>
                    </div>
                </div>
                <?php endif; ?>

                <?php if(isset($charData['data'])): ?>
                <div class="bg-white border-t-2 border-blue-600 shadow-sm">
                    <div class="bg-gray-100 px-3 py-2 font-bold text-xs border-b flex justify-between">
                        <span>Characters & Voice Actors</span>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-0">
                        <?php foreach(array_slice($charData['data'], 0, 10) as $char): ?>
                        <div class="flex justify-between border-b p-2 hover:bg-gray-50">
                            <div class="flex gap-3">
                                <img src="<?= $char['character']['images']['jpg']['image_url'] ?>" class="w-12 h-16 object-cover border">
                                <div class="text-xs flex flex-col justify-center">
                                    <a href="#" class="font-bold text-mal-blue hover:underline"><?= $char['character']['name'] ?></a>
                                    <span class="text-gray-500"><?= $char['role'] ?></span>
                                </div>
                            </div>
                            <?php 
                                $jpVA = array_filter($char['voice_actors'], fn($v) => $v['language'] === 'Japanese');
                                $va = reset($jpVA); 
                            ?>
                            <?php if($va): ?>
                            <div class="flex gap-3 text-right">
                                <div class="text-xs flex flex-col justify-center items-end">
                                    <a href="#" class="font-bold text-mal-blue hover:underline"><?= $va['person']['name'] ?></a>
                                    <span class="text-gray-500">Japanese</span>
                                </div>
                                <img src="<?= $va['person']['images']['jpg']['image_url'] ?>" class="w-12 h-16 object-cover border">
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

            </div>
        </div>

    <?php elseif ($route == 'search'): ?>
        <h2 class="text-xl font-bold mb-4">Hasil Pencarian: "<?= htmlspecialchars($q) ?>"</h2>
        <div class="grid grid-cols-2 md:grid-cols-5 gap-4">
            <?php foreach($searchData['data'] as $item): ?>
                <a href="<?= BASE_URL ?>/anime/<?= $item['mal_id'] ?>/<?= slugify($item['title']) ?>" class="group block bg-white rounded shadow hover:shadow-lg transition">
                    <div class="relative overflow-hidden">
                        <img src="<?= $item['images']['webp']['large_image_url'] ?>" class="w-full h-60 object-cover group-hover:scale-110 transition duration-300">
                        <div class="absolute bottom-0 w-full bg-black/70 text-white text-xs p-1 text-center">
                             <?= $item['score'] ?>
                        </div>
                    </div>
                    <div class="p-2 h-14">
                        <h3 class="font-bold text-xs text-mal-blue group-hover:underline line-clamp-2"><?= $item['title'] ?></h3>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>

    <?php else: ?>
        <div class="grid grid-cols-1 md:grid-cols-4 gap-6">
            
            <div class="md:col-span-3">
                <div class="flex justify-between border-b-2 border-blue-800 mb-3 pb-1 items-end">
                    <h2 class="font-bold text-lg">Seasonal Anime</h2>
                    <span class="text-xs text-gray-500"><?= date('F Y') ?></span>
                </div>
                
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                    <?php if(isset($seasonNow['data'])) foreach($seasonNow['data'] as $anime): ?>
                    <div class="bg-white p-1 rounded hover:shadow-md transition">
                        <a href="<?= BASE_URL ?>/anime/<?= $anime['mal_id'] ?>/<?= slugify($anime['title']) ?>">
                            <img src="<?= $anime['images']['webp']['large_image_url'] ?>" class="w-full h-56 object-cover mb-2 border rounded">
                        </a>
                        <a href="<?= BASE_URL ?>/anime/<?= $anime['mal_id'] ?>/<?= slugify($anime['title']) ?>" class="font-bold text-xs text-mal-blue hover:underline line-clamp-2 leading-tight">
                            <?= $anime['title'] ?>
                        </a>
                        <div class="flex justify-between mt-1 text-[10px] text-gray-500">
                            <span><?= $anime['type'] ?></span>
                            <span> <?= $anime['score'] ?? '-' ?></span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="md:col-span-1">
                <div class="bg-gray-100 p-2 border-l-4 border-blue-800 font-bold text-xs mb-2 uppercase">Top Airing</div>
                <div class="bg-white border rounded shadow-sm">
                <?php $rank=1; if(isset($topAnime['data'])) foreach($topAnime['data'] as $top): ?>
                    <a href="<?= BASE_URL ?>/anime/<?= $top['mal_id'] ?>/<?= slugify($top['title']) ?>" class="flex gap-2 p-2 border-b last:border-0 hover:bg-gray-50 items-center transition">
                        <span class="text-xl font-bold text-gray-300 w-6 text-center italic"><?= $rank++ ?></span>
                        <img src="<?= $top['images']['webp']['image_url'] ?>" class="w-12 h-16 object-cover border rounded">
                        <div class="flex-1 min-w-0">
                            <h3 class="text-mal-blue font-bold text-xs truncate group-hover:underline"><?= $top['title'] ?></h3>
                            <div class="text-[10px] text-gray-500 mt-1">Score: <span class="font-bold text-gray-700"><?= $top['score'] ?></span></div>
                            <div class="text-[10px] text-gray-400"><?= number_format($top['members']) ?> members</div>
                        </div>
                    </a>
                <?php endforeach; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>

</div>

<footer class="bg-blue-900 text-white text-center py-8 mt-12 text-xs">
    <div class="container mx-auto">
        <p class="font-bold text-sm mb-2">&copy; <?= date('Y') ?> <?= APP_NAME ?></p>
        <p class="opacity-70">Data provided by Jikan API (Unofficial MyAnimeList API).</p>
        <div class="mt-4 flex justify-center gap-6">
            <a href="<?= BASE_URL ?>/sitemap.xml" class="hover:text-blue-200 underline">XML Sitemap</a>
            <a href="<?= BASE_URL ?>/sitemap.xml.gz" class="hover:text-blue-200 underline">GZIP Sitemap</a>
            <a href="<?= BASE_URL ?>/robots.txt" class="hover:text-blue-200 underline">Robots.txt</a>
        </div>
    </div>
</footer>

</body>
</html>
