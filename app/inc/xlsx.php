<?php
declare(strict_types=1);
/*
 * Minimal Excel (.xlsx) writer: one or more sheets, bold frozen header row, filters on, column widths.
 * $sheets = [['name' => 'Sheet', 'header' => [...], 'rows' => [[...], ...]], ...]
 * Falls back to CSV (first sheet) if the server has no ZipArchive.
 */

function xlsx_col(int $i): string { $s = ''; for ($i++; $i > 0; $i = intdiv($i - 1, 26)) $s = chr(65 + ($i - 1) % 26) . $s; return $s; }

function xlsx_x($v): string {
    $v = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}]/u', '', (string)$v);
    return htmlspecialchars($v, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

function xlsx_sheet_xml(array $sh): string {
    $rows = array_merge([$sh['header']], $sh['rows']);
    $nCols = max(1, count($sh['header']));
    $w = array_fill(0, $nCols, 8);
    foreach ($rows as $r) foreach (array_values($r) as $c => $v) if ($c < $nCols) $w[$c] = min(60, max($w[$c], mb_strlen((string)$v) + 2));
    $x = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
       . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><cols>';
    foreach ($w as $c => $width) $x .= '<col min="' . ($c + 1) . '" max="' . ($c + 1) . '" width="' . $width . '" customWidth="1"/>';
    $x .= '</cols><sheetData>';
    foreach ($rows as $ri => $r) {
        $x .= '<row r="' . ($ri + 1) . '">';
        foreach (array_values($r) as $c => $v) {
            if ($c >= $nCols) break;
            $ref = xlsx_col($c) . ($ri + 1);
            $st = $ri === 0 ? ' s="1"' : '';
            if ($ri > 0 && (is_int($v) || is_float($v))) $x .= '<c r="' . $ref . '"' . $st . '><v>' . $v . '</v></c>';
            elseif ($v === null || $v === '') $x .= '<c r="' . $ref . '"' . $st . '/>';
            else $x .= '<c r="' . $ref . '" t="inlineStr"' . $st . '><is><t xml:space="preserve">' . xlsx_x($v) . '</t></is></c>';
        }
        $x .= '</row>';
    }
    $x .= '</sheetData>';
    if (count($rows) > 1) $x .= '<autoFilter ref="A1:' . xlsx_col($nCols - 1) . count($rows) . '"/>';
    return $x . '</worksheet>';
}

function xlsx_send(string $filename, array $sheets): void {
    if (!class_exists('ZipArchive')) {           // fallback: CSV of the first sheet
        send_csv(preg_replace('/\.xlsx$/', '.csv', $filename), $sheets[0]['header'], $sheets[0]['rows']);
    }
    $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
    $z = new ZipArchive();
    $z->open($tmp, ZipArchive::OVERWRITE);
    $ct = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
    $wb = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>';
    $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
    $names = []; $defined = '';
    foreach ($sheets as $i => $sh) {
        $n = $i + 1;
        $name = mb_substr(preg_replace('#[\[\]\*\?/\\\\:]#', ' ', $sh['name']), 0, 31) ?: "Sheet$n";
        while (in_array(mb_strtolower($name), $names, true)) $name = mb_substr($name, 0, 28) . " $n";
        $names[] = mb_strtolower($name);
        $ct .= '<Override PartName="/xl/worksheets/sheet' . $n . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        $wb .= '<sheet name="' . xlsx_x($name) . '" sheetId="' . $n . '" r:id="rId' . $n . '"/>';
        $rels .= '<Relationship Id="rId' . $n . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $n . '.xml"/>';
        if ($sh['rows']) $defined .= '<definedName name="_xlnm._FilterDatabase" localSheetId="' . $i . '" hidden="1">\'' . str_replace("'", "''", xlsx_x($name)) . '\'!$A$1:$' . xlsx_col(count($sh['header']) - 1) . '$' . (count($sh['rows']) + 1) . '</definedName>';
        $z->addFromString("xl/worksheets/sheet$n.xml", xlsx_sheet_xml($sh));
    }
    $n = count($sheets) + 1;
    $rels .= '<Relationship Id="rId' . $n . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>';
    $wb .= '</sheets>' . ($defined ? "<definedNames>$defined</definedNames>" : '') . '</workbook>';
    $z->addFromString('[Content_Types].xml', $ct . '</Types>');
    $z->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
    $z->addFromString('xl/workbook.xml', $wb);
    $z->addFromString('xl/_rels/workbook.xml.rels', $rels);
    $z->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font></fonts>'
        . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF1F4E79"/><bgColor indexed="64"/></patternFill></fill></fills>'
        . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/></cellXfs>'
        . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>');
    $z->close();
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($tmp));
    readfile($tmp); unlink($tmp); exit;
}
