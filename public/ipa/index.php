<?php

declare(strict_types=1);

define('IPA_DISTRIBUTION', true);
require __DIR__.'/distribution.php';

header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; style-src 'self'; script-src 'self'; base-uri 'none'; frame-ancestors 'none'; form-action 'none'");
$host = $_SERVER['HTTP_HOST'] ?? '';
if (! preg_match('/^(?=.{1,253}$)(?:[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?\.)*[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?(?::[0-9]{1,5})?$/D', $host)) {
    http_response_code(400);
    exit('Invalid host');
}
// Always HTTPS, including behind a TLS-terminating reverse proxy. Do not trust
// arbitrary forwarded-host/proto headers to construct an installation URL.
$base = 'https://'.$host.'/ipa';
$escape = static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
if (isset($_GET['manifest'])) {
    $file = $_GET['manifest'];
    if (! is_string($file) || basename($file) !== $file || ! preg_match('/\.ipa$/iD', $file) || str_contains($file, "\0")) {
        http_response_code(404);
        exit('Package not found');
    }
    try {
        $package = ipaPackage(__DIR__.'/'.$file);
        header('Content-Type: application/xml; charset=utf-8');
        echo ipaManifest($package, $base);
    } catch (Throwable) {
        http_response_code(404);
        echo 'Package unavailable';
    }
    exit;
}
$packages = [];
$unavailable = 0;
foreach (scandir(__DIR__) ?: [] as $file) {
    if (! preg_match('/\.ipa$/iD', $file)) {
        continue;
    }
    try {
        $packages[] = ipaPackage(__DIR__.'/'.$file);
    } catch (Throwable) {
        $unavailable++;
    }
}
usort($packages, static fn (array $a, array $b): int => ($b['modified'] <=> $a['modified']) ?: strcmp($a['file'], $b['file']));
header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#11100c">
<title>U 卡 · iOS 下载</title>
<link rel="stylesheet" href="/ipa/style.css">
<script src="/ipa/install.js" defer></script>
</head>
<body>
<main>
<header><div class="brand" aria-hidden="true"><i></i><i></i></div><span>SPEC PAY</span><span class="tag">iOS 分发</span></header>
<section class="intro"><p class="eyebrow">SPEC PAY · GLOBAL PAYMENT</p><h1>U 卡，随身同行。</h1><p>在 iPhone 或 iPad 上用 Safari 打开本页，选择安装包。</p></section>
<p id="browser-tip" class="notice" hidden>请点击右上角菜单，选择“在 Safari 中打开”后安装。</p>
<p id="https-tip" class="notice" hidden>在线安装需要 HTTPS。请通过本页的 HTTPS 地址访问。</p>
<section class="packages" aria-label="可用安装包">
<?php if (! $packages) { ?>
<div class="empty"><h2>安装包准备中</h2><p>安装包发布后会显示在这里，请稍后刷新。</p></div>
<?php } ?>
<?php foreach ($packages as $index => $package) {
    $manifest = $base.'/index.php?manifest='.rawurlencode($package['file']);
    $install = 'itms-services://?action=download-manifest&url='.rawurlencode($manifest);
    ?>
<article class="package">
<div class="app-icon" aria-hidden="true">U</div>
<div class="details"><div class="title"><h2><?= $escape($package['title']) ?></h2><?php if ($index === 0) { ?><span class="badge">最近上传</span><?php } ?></div>
<p>版本 <?= $escape($package['version']) ?> · Build <?= $escape($package['build']) ?> · <?= number_format($package['size'] / 1048576, 1) ?> MB</p>
<p class="filename"><?= $escape($package['file']) ?></p></div>
<div class="actions"><a class="install" href="<?= $escape($install) ?>">安装到设备</a><a class="download" href="<?= $escape('/ipa/'.rawurlencode($package['file'])) ?>" download>下载 IPA 文件</a></div>
</article>
<?php } ?>
</section>
<?php if ($unavailable) { ?><p class="muted">有 <?= $unavailable ?> 个安装包暂不可用，请等待上传完成或联系发布人员。</p><?php } ?>
<p id="install-status" class="notice" role="status" hidden></p>
<section class="guide"><h2>安装说明</h2><ol><li>使用 Safari 打开本页，点击“安装到设备”，并确认系统安装提示。</li><li>返回主屏幕查看安装进度。网页无法确认系统是否已安装完成。</li><li>如系统提示企业开发者未受信任，请在“设置 → 通用 → VPN 与设备管理”中按系统提示操作。</li></ol><p>安装包须具备有效签名。Ad Hoc 包仅限已登记的设备；App Store 包应通过 App Store 或 TestFlight 分发。本页面不会绕过签名、证书或设备限制。</p></section>
<footer>Spec Pay 万事达 U卡</footer>
</main>
</body>
</html>
