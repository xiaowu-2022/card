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
