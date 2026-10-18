<?php

namespace App;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

class RosterImporter
{
    public function parse(UploadedFile $file): array
    {
        $rows = strtolower($file->getClientOriginalExtension()) === 'xlsx' ? $this->xlsx($file->getRealPath()) : $this->csv($file->getRealPath());
        $header = array_map(fn ($v) => mb_strtolower(trim(ltrim((string) $v, "\xEF\xBB\xBF"))), array_shift($rows) ?: []);
        if (! in_array('name', $header, true) || ! in_array('email', $header, true)) {
            throw ValidationException::withMessages(['file' => 'Tệp cần có cột name, email và có thể thêm cột phone.']);
        }
        $result = [];
        foreach ($rows as $i => $row) {
            if (! array_filter($row, fn ($v) => trim((string) $v) !== '')) {
                continue;
            }
            $values = array_pad(array_slice($row, 0, count($header)), count($header), '');
            $mapped = array_combine($header, $values);
            $result[] = ['name' => trim((string) $mapped['name']), 'email' => mb_strtolower(trim((string) $mapped['email'])), 'phone' => trim((string) ($mapped['phone'] ?? ''))];
            if (count($result) > 1000) {
                throw ValidationException::withMessages(['file' => 'Mỗi lần nhập tối đa 1.000 học sinh.']);
            }
        }
        if (! $result) {
            throw ValidationException::withMessages(['file' => 'Tệp chưa có học sinh.']);
        }

        return $result;
    }

    private function csv(string $path): array
    {
        $stream = fopen($path, 'r');
        $rows = [];
        try {
            while (($row = fgetcsv($stream, 0, ',', '"', '')) !== false) {
                $rows[] = $row;
                if (count($rows) > 1001) {
                    throw ValidationException::withMessages(['file' => 'Tệp vượt quá 1.000 dòng dữ liệu.']);
                }
            }
        } finally {
            fclose($stream);
        }

        return $rows;
    }

    private function xml(string $text): \SimpleXMLElement
    {
        if (stripos($text, '<!DOCTYPE') !== false) {
            throw ValidationException::withMessages(['file' => 'Không chấp nhận khai báo DTD trong XLSX.']);
        }
        $xml = simplexml_load_string($text, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        if (! $xml) {
            throw ValidationException::withMessages(['file' => 'Nội dung XLSX không hợp lệ.']);
        }

        return $xml;
    }

    private function xlsx(string $path): array
    {
        $zip = new \ZipArchive;
        if ($zip->open($path) !== true) {
            throw ValidationException::withMessages(['file' => 'Không thể đọc XLSX.']);
        }
        try {
            $size = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $size += $zip->statIndex($i)['size'];
                if ($size > 10485760 || $zip->numFiles > 1000) {
                    throw ValidationException::withMessages(['file' => 'XLSX vượt giới hạn giải nén an toàn.']);
                }
            }
            $strings = [];
            $shared = $zip->getFromName('xl/sharedStrings.xml');
            if ($shared !== false) {
                foreach ($this->xml($shared)->xpath('//*[local-name()="si"]') as $item) {
                    $strings[] = implode('', array_map('strval', $item->xpath('.//*[local-name()="t"]')));
                }
            }
            $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
            if ($sheet === false) {
                throw ValidationException::withMessages(['file' => 'XLSX cần có trang tính đầu tiên.']);
            }
            $xml = $this->xml($sheet);
            if ($xml->xpath('//*[local-name()="f"]')) {
                throw ValidationException::withMessages(['file' => 'Bảng danh sách cần dùng dữ liệu văn bản, không chứa công thức.']);
            }
            $rows = [];
            foreach ($xml->xpath('//*[local-name()="row"]') as $row) {
                $values = [];
                foreach ($row->xpath('./*[local-name()="c"]') as $cell) {
                    preg_match('/^([A-Z]+)/', (string) $cell['r'], $match);
                    $column = 0;
                    foreach (str_split($match[1] ?? 'A') as $letter) {
                        $column = $column * 26 + ord($letter) - 64;
                    }
                    if ($column > 30) {
                        continue;
                    }
                    $value = (string) ($cell->xpath('./*[local-name()="v"]')[0] ?? '');
                    $values[$column - 1] = (string) $cell['t'] === 's' ? ($strings[(int) $value] ?? '') : ((string) $cell['t'] === 'inlineStr' ? implode('', array_map('strval', $cell->xpath('.//*[local-name()="t"]'))) : $value);
                }
                $rows[] = array_replace(array_fill(0, $values ? max(array_keys($values)) + 1 : 0, ''), $values);
                if (count($rows) > 1001) {
                    throw ValidationException::withMessages(['file' => 'Mỗi lần nhập tối đa 1.000 học sinh.']);
                }
            }

            return $rows;
        } finally {
            $zip->close();
        }
    }
}
