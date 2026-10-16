<?php

declare(strict_types=1);

/**
 * Archives that are hard to build by accident, built on purpose.
 *
 * The traversal and link cases cannot be produced by `PharData` in a way that
 * survives being read back - which is exactly how the first version of the
 * extraction check came to be ineffective. So this writes tar and zip bytes by
 * hand, from one set of primitives, and the tests then feed the result to the
 * real reader. A fixture built the same way the writer builds a file proves
 * nothing about the reader.
 */
final class FixtureArchive
{
    /**
     * A regular tar entry.
     */
    public static function tarEntry(string $name, string $data, string $type = '0', int $mode = 0o644): string
    {
        $header = str_pad($name, 100, "\0");
        $header .= str_pad(sprintf("%07o\0", $mode), 8);
        $header .= str_pad("0000000\0", 8);
        $header .= str_pad("0000000\0", 8);
        $header .= str_pad(sprintf("%011o\0", strlen($type === '0' ? $data : '')), 12);
        $header .= str_pad(sprintf("%011o\0", 0), 12);
        $header .= str_pad('', 8, ' ');          // checksum: spaces while summing
        $header .= $type;
        $header .= str_pad('', 100, "\0");        // link name
        $header .= str_pad('ustar', 6, "\0") . str_pad('00', 2, ' ');
        $header .= str_pad('root', 32, "\0");
        $header .= str_pad('root', 32, "\0");
        $header .= str_pad('', 8, "\0");          // devmajor
        $header .= str_pad('', 8, "\0");          // devminor
        $header .= str_pad('', 155, "\0");        // prefix
        $header = str_pad($header, 512, "\0");

        $sum = 0;
        for ($i = 0; $i < 512; $i++) {
            $sum += ord($header[$i]);
        }
        $header = substr_replace($header, str_pad(sprintf("%06o", $sum) . "\0 ", 8), 148, 8);

        $payload = $type === '0' ? $data : '';
        $padding = (512 - (strlen($payload) % 512)) % 512;

        return $header . $payload . str_repeat("\0", $padding);
    }

    /**
     * A tar, terminated the way a tar is (two zero blocks).
     */
    public static function tar(string ...$entries): string
    {
        return implode('', $entries) . str_repeat("\0", 1024);
    }

    /**
     * The same bytes, gzipped the way `tar -zcf` writes them.
     */
    public static function tarGz(string ...$entries): string
    {
        return (string) gzencode(self::tar(...$entries));
    }

    public static function write(string $contents, string $suffix): string
    {
        $path = tempnam(sys_get_temp_dir(), 'fixture-');
        if ($path === false) {
            throw new RuntimeException('could not create a fixture file');
        }

        $final = $path . '.' . $suffix;
        rename($path, $final);
        file_put_contents($final, $contents);

        return $final;
    }

    /**
     * A zip containing one regular entry.
     */
    public static function zip(string $name, string $data): string
    {
        $path = tempnam(sys_get_temp_dir(), 'fixture-');
        if ($path === false) {
            throw new RuntimeException('could not create a fixture file');
        }
        $final = $path . '.zip';
        rename($path, $final);
        @unlink($final);

        $zip = new ZipArchive();
        if ($zip->open($final, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('could not create a fixture zip');
        }
        $zip->addFromString($name, $data);
        $zip->close();

        return $final;
    }

    /**
     * A zip whose stored entry name is exactly what a hostile server would use.
     */
    public static function hostileZip(string $name, string $data): string
    {
        return self::zip($name, $data);
    }
}
