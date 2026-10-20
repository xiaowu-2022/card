<?php

declare(strict_types=1);

if (! defined('IPA_DISTRIBUTION')) {
    http_response_code(404);
    exit;
}

// Standalone: no application session, database, authentication or provider reads.
function ipaString(array $info, string $key): string
{
    $value = $info[$key] ?? null;
    if (! is_string($value) || $value === '' || strlen($value) > 300 || preg_match('/[\x00-\x1f]/', $value)) {
        throw new RuntimeException('Invalid application metadata');
    }

    return $value;
}

function ipaPlist(string $bytes): array
{
    if (strlen($bytes) > 1048576) {
        throw new RuntimeException('Oversized plist');
    }
    $wanted = ['CFBundleIdentifier', 'CFBundleVersion', 'CFBundleShortVersionString', 'CFBundleDisplayName', 'CFBundleName'];
    if (! str_starts_with($bytes, 'bplist00')) {
        $old = libxml_use_internal_errors(true);
        try {
            if (stripos($bytes, '<!ENTITY') !== false) {
                throw new RuntimeException('Unsupported plist');
            }
            $xml = simplexml_load_string($bytes, SimpleXMLElement::class, LIBXML_NONET);
            if ($xml === false || ! isset($xml->dict)) {
                throw new RuntimeException('Invalid XML plist');
            }
            $result = [];
            $key = null;
            foreach ($xml->dict->children() as $node) {
                if ($node->getName() === 'key') {
                    $key = (string) $node;
                } else {
                    if (in_array($key, $wanted, true) && $node->getName() === 'string') {
                        $result[$key] = (string) $node;
                    }
                    $key = null;
                }
            }

            return $result;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($old);
        }
    }
    // Read only root dictionary keys and the five scalar metadata strings. Never
    // traverse arbitrary object graphs or deserialize IPA contents into PHP objects.
    $size = strlen($bytes);
    $uint = static function (int $offset, int $length) use ($bytes, $size): int {
        if ($length < 1 || $length > 8 || $offset < 0 || $offset + $length > $size) {
            throw new RuntimeException('Invalid plist integer');
        }
        $value = 0;
        for ($i = 0; $i < $length; $i++) {
            if ($value > intdiv(PHP_INT_MAX - 255, 256)) {
                throw new RuntimeException('Plist integer overflow');
            }
            $value = $value * 256 + ord($bytes[$offset + $i]);
        }

        return $value;
    };
    if ($size < 40) {
        throw new RuntimeException('Invalid binary plist');
    }
    $offsetSize = ord($bytes[$size - 26]);
    $refSize = ord($bytes[$size - 25]);
    $count = $uint($size - 24, 8);
    $top = $uint($size - 16, 8);
    $table = $uint($size - 8, 8);
    if ($count < 1 || $count > 50000 || $top >= $count || $offsetSize < 1 || $offsetSize > 8
        || $refSize < 1 || $refSize > 8 || $table < 8 || $table + $count * $offsetSize > $size - 32) {
        throw new RuntimeException('Invalid plist trailer');
    }
    $object = static function (int $ref) use ($uint, $table, $offsetSize, $count): int {
        if ($ref >= $count) {
            throw new RuntimeException('Invalid plist reference');
        }

        return $uint($table + $ref * $offsetSize, $offsetSize);
    };
    $header = static function (int $offset) use ($bytes, $uint, $table): array {
        if ($offset < 8 || $offset >= $table) {
            throw new RuntimeException('Invalid plist object');
        }
        $marker = ord($bytes[$offset++]);
        $length = $marker & 15;
        if ($length === 15) {
            if ($offset >= $table) {
                throw new RuntimeException('Truncated plist length');
            }
            $integer = ord($bytes[$offset++]);
            if (($integer >> 4) !== 1 || ($integer & 15) > 3) {
                throw new RuntimeException('Invalid plist length');
            }
            $width = 1 << ($integer & 15);
            $length = $uint($offset, $width);
            $offset += $width;
        }
        if ($length > 50000) {
            throw new RuntimeException('Oversized plist object');
        }

        return [$marker >> 4, $length, $offset];
    };
    $string = static function (int $ref) use ($object, $header, $bytes, $table): string {
        [$kind, $length, $start] = $header($object($ref));
        if (! in_array($kind, [5, 6], true) || $start + $length * ($kind === 6 ? 2 : 1) > $table) {
            throw new RuntimeException('Invalid plist string');
        }
        $value = substr($bytes, $start, $length * ($kind === 6 ? 2 : 1));
        $value = $kind === 6 ? iconv('UTF-16BE', 'UTF-8', $value) : $value;
        if ($value === false) {
            throw new RuntimeException('Invalid plist encoding');
        }

        return $value;
    };
    [$kind, $length, $start] = $header($object($top));
    if ($kind !== 13 || $start + $length * $refSize * 2 > $table) {
        throw new RuntimeException('Invalid plist root');
    }
    $result = [];
    for ($i = 0; $i < $length; $i++) {
        $key = $string($uint($start + $i * $refSize, $refSize));
        if (in_array($key, $wanted, true)) {
            $result[$key] = $string($uint($start + ($length + $i) * $refSize, $refSize));
        }
    }

    return $result;
}

function ipaPackage(string $path): array
{
    if (is_link($path) || ! is_file($path) || ! is_readable($path)) {
        throw new RuntimeException('Unavailable package');
    }
    // PharData reads ZIP without requiring the optional ZipArchive extension.
    $archive = new PharData($path);
    $main = [];
    foreach (new RecursiveIteratorIterator($archive) as $entry) {
        if (preg_match('~^phar://.+/Payload/[^/]+\.app/Info\.plist$~', $entry->getPathname())) {
            $main[] = $entry;
        }
    }
    if (count($main) !== 1 || $main[0]->getSize() > 1048576) {
        throw new RuntimeException('Missing application plist');
    }
    $info = ipaPlist($main[0]->getContent());
    $bundle = ipaString($info, 'CFBundleIdentifier');
    if (! preg_match('/^[A-Za-z0-9-]+(?:\.[A-Za-z0-9-]+)+$/D', $bundle)) {
        throw new RuntimeException('Invalid bundle identifier');
    }

    return [
        'file' => basename($path), 'bundle' => $bundle,
        'build' => ipaString($info, 'CFBundleVersion'),
        'version' => ipaString($info, 'CFBundleShortVersionString'),
        'title' => ipaString($info, isset($info['CFBundleDisplayName']) ? 'CFBundleDisplayName' : 'CFBundleName'),
        'size' => filesize($path), 'modified' => filemtime($path),
    ];
}

function ipaManifest(array $package, string $base): string
{
    $escape = static fn (string $text): string => htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    $url = $escape($base.'/'.rawurlencode($package['file']));
    $bundle = $escape($package['bundle']);
    $version = $escape($package['build']);
    $title = $escape($package['title']);

    return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0"><dict><key>items</key><array><dict>
<key>assets</key><array><dict><key>kind</key><string>software-package</string><key>url</key><string>{$url}</string></dict></array>
<key>metadata</key><dict><key>bundle-identifier</key><string>{$bundle}</string><key>bundle-version</key><string>{$version}</string><key>kind</key><string>software</string><key>title</key><string>{$title}</string></dict>
</dict></array></dict></plist>
XML;
}
