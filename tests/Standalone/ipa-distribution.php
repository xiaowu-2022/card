<?php

declare(strict_types=1);

define('IPA_DISTRIBUTION', true);
require dirname(__DIR__, 2).'/public/h5/ipa/distribution.php';
function check(bool $condition, string $label): void
{
    if (! $condition) {
        throw new RuntimeException($label);
    }
    echo 'PASS '.$label.PHP_EOL;
}
$fixtures = json_decode(file_get_contents(dirname(__DIR__).'/Fixtures/ipa-plists.json'), true, flags: JSON_THROW_ON_ERROR);
$temporary = sys_get_temp_dir().'/ipa-distribution-'.bin2hex(random_bytes(8));
mkdir($temporary);
try {
    foreach ($fixtures as $format => $encoded) {
        $bytes = base64_decode($encoded, true);
        $metadata = ipaPlist($bytes);
        check($metadata['CFBundleDisplayName'] === '测试 U卡 & <示例>', $format.' Unicode and scalar parsing');
        check(! isset($metadata['Ignored']), $format.' ignores unrelated nested objects');
        $zip = $temporary.'/'.$format.'.zip';
        $archive = new PharData($zip);
        $archive['Payload/Example.app/Info.plist'] = $bytes;
        // Extensions do not count as the main application's Info.plist.
        $archive['Payload/Example.app/PlugIns/Other.appex/Info.plist'] = $bytes;
        unset($archive);
        $ipa = $temporary.'/测试 U卡 '.$format.'.ipa';
        rename($zip, $ipa);
        $package = ipaPackage($ipa);
        check($package['bundle'] === 'com.example.distribution' && $package['build'] === '42' && $package['version'] === '2.1.0', $format.' real IPA metadata');
        $xml = ipaManifest($package, 'https://example.test/ipa');
        $plist = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET);
        check($plist !== false && str_contains($xml, '%E6%B5%8B%E8%AF%95%20U%E5%8D%A1%20'), $format.' XML manifest and encoded filename');
        check(str_contains($xml, '<key>bundle-version</key><string>42</string>'), 'manifest uses native bundle build');
        check(! str_contains($xml, '<示例>') && str_contains($xml, '&amp;'), 'manifest escapes app title');
        $link = $temporary.'/link-'.$format.'.ipa';
        symlink($ipa, $link);
        try {
            ipaPackage($link);
            throw new LogicException('Symlink accepted');
        } catch (RuntimeException $error) {
            check($error->getMessage() === 'Unavailable package', 'reject symlink');
        }
    }
    foreach (['bplist00', str_repeat("\xff", 40), '<plist><dict>', '<!ENTITY x SYSTEM "file:///etc/passwd"><plist/>'] as $broken) {
        try {
            ipaPlist($broken);
            throw new LogicException('Invalid plist accepted');
        } catch (RuntimeException) {
            echo "PASS invalid plist rejected\n";
        }
    }
} finally {
    foreach (glob($temporary.'/*') as $file) {
        unlink($file);
    }
    rmdir($temporary);
}

check(ipaFailureCode(new RuntimeException('Archive support unavailable')) === 'IPA-PHP-PHAR', 'missing PHP archive support is explicit');
check(ipaFailureCode(new RuntimeException('Unavailable package')) === 'IPA-FILE-ACCESS', 'unreadable file is explicit');
check(ipaFailureCode(new UnexpectedValueException('private path')) === 'IPA-ARCHIVE', 'archive diagnostics do not reveal raw errors');

$remoteDirectory = sys_get_temp_dir().'/ipa-remote-'.bin2hex(random_bytes(8));
mkdir($remoteDirectory);
try {
    check(ipaRemotePackage($remoteDirectory) === null, 'unconfigured remote retains local distribution');
    $remote = ['file' => 'U-card-2.6.20.ipa', 'bundle' => 'com.example.distribution', 'build' => '42',
        'version' => '2.1.0', 'title' => 'U 卡', 'size' => 123, 'modified' => 123456,
        'sha256' => str_repeat('a', 64), 'url' => 'https://cdn.example/U%20%E5%8D%A1.ipa?v=42'];
    file_put_contents($remoteDirectory.'/oss-release.json', json_encode($remote));
    $loaded = ipaRemotePackage($remoteDirectory);
    check($loaded === $remote, 'remote release works without local IPA');
    check(ipaDownloadUrl($loaded, 'https://company.example/ipa') === $remote['url'], 'direct download uses OSS');
    check(str_contains(ipaManifest($loaded, 'https://company.example/ipa'), '<string>'.$remote['url'].'</string>'), 'OTA manifest downloads from OSS');
    foreach (['http://cdn.example/app.ipa', 'https://user:secret@cdn.example/app.ipa', 'javascript:alert(1)'] as $url) {
        file_put_contents($remoteDirectory.'/oss-release.json', json_encode(array_replace($remote, ['url' => $url])));
        try {
            ipaRemotePackage($remoteDirectory);
            throw new LogicException('Unsafe remote accepted');
        } catch (RuntimeException) {
            echo "PASS unsafe remote URL rejected\n";
        }
    }
} finally {
    unlink($remoteDirectory.'/oss-release.json');
    rmdir($remoteDirectory);
}
