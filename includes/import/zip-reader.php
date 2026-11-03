<?php
/**
 * Import engine - pure-PHP zip reader (no ZipArchive needed, only zlib).
 *
 * Reads the central directory of a zip held in memory and inflates stored(0) /
 * deflate(8) entries on demand. Hard caps guard against zip bombs:
 *   - max entries, max total declared unpacked bytes, max bytes per entry,
 *   - per-entry compression-ratio check,
 *   - inflate is itself limited to the declared size and the CRC is verified.
 * Encrypted and zip64 archives are refused with a plain message.
 * Nothing is ever extracted to disk.
 */

if (!function_exists('import_zip_default_limits')) {

    function import_zip_default_limits(): array
    {
        return [
            'max_file_bytes'  => 25 * 1024 * 1024,   // the zip itself
            'max_entries'     => 2000,
            'max_total_bytes' => 100 * 1024 * 1024,  // sum of declared unpacked sizes
            'max_entry_bytes' => 60 * 1024 * 1024,   // one entry
            'max_ratio'       => 200,                // unpacked / packed, applied above ratio_min_bytes
            'ratio_min_bytes' => 262144,             // tiny entries may compress very well
        ];
    }

    /**
     * Open a zip from a file path ($isData=false) or from a raw string ($isData=true).
     * Returns ['ok'=>bool, 'error'=>string, 'entries'=>[name => meta], 'data'=>string].
     */
    function import_zip_open(string $source, bool $isData = false, array $limits = []): array
    {
        $lim = array_merge(import_zip_default_limits(), $limits);
        $fail = function (string $msg) {
            return ['ok' => false, 'error' => $msg, 'entries' => [], 'data' => ''];
        };

        if ($isData) {
            $data = $source;
        } else {
            if (!is_file($source) || !is_readable($source)) {
                return $fail('The file could not be read.');
            }
            $size = filesize($source);
            if ($size === false || $size > $lim['max_file_bytes']) {
                return $fail('The file is too large to import (limit ' . round($lim['max_file_bytes'] / 1048576) . ' MB).');
            }
            $data = file_get_contents($source);
            if ($data === false) {
                return $fail('The file could not be read.');
            }
        }
        $len = strlen($data);
        if ($len > $lim['max_file_bytes']) {
            return $fail('The file is too large to import (limit ' . round($lim['max_file_bytes'] / 1048576) . ' MB).');
        }
        if ($len < 22 || substr($data, 0, 2) !== 'PK') {
            return $fail('This is not a valid Excel/Word file (it is not a zip container).');
        }

        // End-of-central-directory record: scan backwards, validate comment length.
        $eocd = -1;
        $scanFrom = $len - 22;
        $scanTo = max(0, $len - 22 - 65535);
        for ($i = $scanFrom; $i >= $scanTo; $i--) {
            if (substr($data, $i, 4) === "PK\x05\x06") {
                $commentLen = unpack('v', substr($data, $i + 20, 2))[1];
                if ($i + 22 + $commentLen === $len) {
                    $eocd = $i;
                    break;
                }
            }
        }
        if ($eocd < 0) {
            return $fail('The file looks damaged or incomplete (zip directory not found). Please save it again from Excel and retry.');
        }
        $h = unpack('vdisk/vcdDisk/vcdCountDisk/vcdCount/VcdSize/VcdOffset', substr($data, $eocd + 4, 16));
        if ($h['cdCount'] === 0xFFFF || $h['cdSize'] === 0xFFFFFFFF || $h['cdOffset'] === 0xFFFFFFFF) {
            return $fail('This file uses the zip64 format, which is not supported. Please save it as a normal .xlsx and retry.');
        }
        if ($h['disk'] !== 0 || $h['cdDisk'] !== 0) {
            return $fail('Multi-part zip files are not supported.');
        }
        if ($h['cdCount'] > $lim['max_entries']) {
            return $fail('The file contains too many parts (' . $h['cdCount'] . ') and was refused for safety.');
        }
        if ($h['cdOffset'] + $h['cdSize'] > $eocd) {
            return $fail('The file looks damaged (zip directory is out of range).');
        }

        $entries = [];
        $pos = $h['cdOffset'];
        $total = 0;
        for ($n = 0; $n < $h['cdCount']; $n++) {
            if ($pos + 46 > $len || substr($data, $pos, 4) !== "PK\x01\x02") {
                return $fail('The file looks damaged (zip directory entry is unreadable).');
            }
            $e = unpack('vverMade/vverNeed/vflags/vmethod/vtime/vdate/Vcrc/Vcsize/Vusize/vnameLen/vextraLen/vcommentLen/vdiskStart/vintAttr/VextAttr/VlocalOffset', substr($data, $pos + 4, 42));
            $name = substr($data, $pos + 46, $e['nameLen']);
            $pos += 46 + $e['nameLen'] + $e['extraLen'] + $e['commentLen'];
            if ($pos > $len) {
                return $fail('The file looks damaged (zip directory is truncated).');
            }
            if (($e['flags'] & 0x1) || ($e['flags'] & 0x40)) {
                return $fail('This file is password-protected/encrypted. Please remove the password and retry.');
            }
            if ($e['csize'] === 0xFFFFFFFF || $e['usize'] === 0xFFFFFFFF || $e['localOffset'] === 0xFFFFFFFF) {
                return $fail('This file uses the zip64 format, which is not supported. Please save it as a normal .xlsx and retry.');
            }
            $name = str_replace('\\', '/', $name);
            if (substr($name, -1) === '/') {
                continue; // directory entry
            }
            if ($name === '' || strpos($name, "\0") !== false) {
                return $fail('The file contains an invalid part name and was refused for safety.');
            }
            $key = strtolower($name);
            if (isset($entries[$key])) {
                return $fail('The file contains duplicate parts and was refused for safety.');
            }
            if ($e['usize'] > $lim['max_entry_bytes']) {
                return $fail('A part of this file is too large when unpacked (possible zip bomb) and was refused for safety.');
            }
            $total += $e['usize'];
            if ($total > $lim['max_total_bytes']) {
                return $fail('This file expands to far more data than expected (possible zip bomb) and was refused for safety.');
            }
            if ($e['usize'] > $lim['ratio_min_bytes'] && $e['csize'] > 0 && ($e['usize'] / $e['csize']) > $lim['max_ratio']) {
                return $fail('This file is compressed suspiciously hard (possible zip bomb) and was refused for safety.');
            }
            if ($e['usize'] > $lim['ratio_min_bytes'] && $e['csize'] === 0) {
                return $fail('This file is compressed suspiciously hard (possible zip bomb) and was refused for safety.');
            }
            if ($e['method'] !== 0 && $e['method'] !== 8) {
                $e['unsupported'] = true;
            }
            $entries[$key] = [
                'name'   => $name,
                'method' => $e['method'],
                'csize'  => $e['csize'],
                'usize'  => $e['usize'],
                'crc'    => $e['crc'],
                'offset' => $e['localOffset'],
                'unsupported' => !empty($e['unsupported']),
            ];
        }
        return ['ok' => true, 'error' => '', 'entries' => $entries, 'data' => $data];
    }

    function import_zip_has(array $zip, string $name): bool
    {
        return isset($zip['entries'][strtolower($name)]);
    }

    /** List the entry names. */
    function import_zip_names(array $zip): array
    {
        $out = [];
        foreach ($zip['entries'] as $e) {
            $out[] = $e['name'];
        }
        return $out;
    }

    /**
     * Read one entry. Returns ['ok'=>bool,'data'=>string,'error'=>string].
     */
    function import_zip_read(array $zip, string $name): array
    {
        $key = strtolower($name);
        if (empty($zip['ok']) || !isset($zip['entries'][$key])) {
            return ['ok' => false, 'data' => '', 'error' => 'Part not found in file: ' . $name];
        }
        $e = $zip['entries'][$key];
        if ($e['unsupported']) {
            return ['ok' => false, 'data' => '', 'error' => 'This file uses an unsupported compression method.'];
        }
        $data = $zip['data'];
        $off = $e['offset'];
        if ($off + 30 > strlen($data) || substr($data, $off, 4) !== "PK\x03\x04") {
            return ['ok' => false, 'data' => '', 'error' => 'The file looks damaged (a part cannot be located).'];
        }
        $l = unpack('vnameLen/vextraLen', substr($data, $off + 26, 4));
        $start = $off + 30 + $l['nameLen'] + $l['extraLen'];
        if ($start + $e['csize'] > strlen($data)) {
            return ['ok' => false, 'data' => '', 'error' => 'The file looks truncated (a part is incomplete). Please save it again and retry.'];
        }
        $raw = substr($data, $start, $e['csize']);
        if ($e['method'] === 0) {
            $out = $raw;
        } else {
            $out = @gzinflate($raw, max(1, $e['usize']));
            if ($out === false) {
                return ['ok' => false, 'data' => '', 'error' => 'A part of the file could not be unpacked (damaged, or larger than declared).'];
            }
        }
        if (strlen($out) !== $e['usize']) {
            return ['ok' => false, 'data' => '', 'error' => 'A part of the file has the wrong size (damaged).'];
        }
        if ((crc32($out) & 0xFFFFFFFF) !== $e['crc']) {
            return ['ok' => false, 'data' => '', 'error' => 'A part of the file failed its integrity check (damaged).'];
        }
        return ['ok' => true, 'data' => $out, 'error' => ''];
    }
}
