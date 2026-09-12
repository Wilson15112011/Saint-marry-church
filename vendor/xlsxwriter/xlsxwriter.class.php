<?php
/**
 * XLSXWriter — minimal zero-dependency XLSX generator
 * PHP 8+ compatible. MIT licence.
 */
if (!class_exists('XLSXWriter')) {
class XLSXWriter
{
    private string $author = 'Unknown';
    private array  $sheets = [];   // name => ['rows'=>[], 'widths'=>[]]
    private array  $sst    = [];   // shared strings list
    private array  $sstMap = [];   // string => index

    public function setAuthor(string $a): void { $this->author = $a; }

    /** Call ONLY to set column widths — never writes a data row. */
    public function writeSheetHeader(string $sheet, array $colDefs, array $opts = []): void
    {
        $this->ensureSheet($sheet);
        if (!empty($opts['widths'])) {
            $this->sheets[$sheet]['widths'] = $opts['widths'];
        }
    }

    /** Write one data row with an optional per-row style. */
    public function writeSheetRow(string $sheet, array $row, array $style = []): void
    {
        $this->ensureSheet($sheet);
        $this->sheets[$sheet]['rows'][] = ['data' => $row, 'style' => $style];
    }

    public function writeToStdOut(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx_');
        $this->writeToFile($tmp);
        readfile($tmp);
        unlink($tmp);
    }

    public function writeToFile(string $path): void
    {
        // Pre-build all shared strings and style indexes
        $styleIndex = [];  // serialized style => xf index (0 = default)
        $fonts   = ["<font><sz val=\"11\"/><color rgb=\"FF000000\"/><name val=\"Arial\"/></font>"];
        $fills   = ["<fill><patternFill patternType=\"none\"/></fill>",
                    "<fill><patternFill patternType=\"gray125\"/></fill>"];
        $borders = ["<border><left/><right/><top/><bottom/><diagonal/></border>"];
        $xfs     = [];  // built xf XML strings (index 0 = default added in buildStyles)

        $getStyle = function(array $s) use (&$styleIndex, &$fonts, &$fills, &$borders, &$xfs): int {
            $key = serialize($s);
            if (isset($styleIndex[$key])) return $styleIndex[$key];

            // font
            $bold   = ($s['font-style'] ?? '') === 'bold' ? '<b/>' : '';
            $sz     = (int)($s['font-size']   ?? 11);
            $fcolor = $s['font-color'] ?? '000000';
            $fontXml = "<font><sz val=\"$sz\"/><color rgb=\"FF$fcolor\"/><name val=\"Arial\"/>$bold</font>";
            $fi = array_search($fontXml, $fonts);
            if ($fi === false) { $fi = count($fonts); $fonts[] = $fontXml; }

            // fill
            $fillColor = $s['fill'] ?? null;
            if ($fillColor) {
                $fillXml = "<fill><patternFill patternType=\"solid\"><fgColor rgb=\"FF$fillColor\"/></patternFill></fill>";
            } else {
                $fillXml = "<fill><patternFill patternType=\"none\"/></fill>";
            }
            $li = array_search($fillXml, $fills);
            if ($li === false) { $li = count($fills); $fills[] = $fillXml; }

            // border
            $btype = $s['border'] ?? 'none';
            if ($btype === 'thin') {
                $borderXml = '<border><left style="thin"/><right style="thin"/><top style="thin"/><bottom style="thin"/><diagonal/></border>';
            } else {
                $borderXml = '<border><left/><right/><top/><bottom/><diagonal/></border>';
            }
            $bi = array_search($borderXml, $borders);
            if ($bi === false) { $bi = count($borders); $borders[] = $borderXml; }

            // alignment
            $alignParts = [];
            if (!empty($s['halign']))    $alignParts[] = "horizontal=\"{$s['halign']}\"";
            if (!empty($s['valign']))    $alignParts[] = "vertical=\"{$s['valign']}\"";
            if (!empty($s['wrap_text'])) $alignParts[] = "wrapText=\"1\"";
            $alignXml  = $alignParts ? '<alignment ' . implode(' ', $alignParts) . '/>' : '';
            $applyAlign = $alignParts ? 'applyAlignment="1"' : '';

            $xfXml = "<xf numFmtId=\"0\" fontId=\"$fi\" fillId=\"$li\" borderId=\"$bi\" "
                   . "applyFont=\"1\" applyFill=\"1\" applyBorder=\"1\" $applyAlign>$alignXml</xf>";
            $xfs[] = $xfXml;
            $idx = count($xfs); // +1 because index 0 is the default xf added in buildStyles
            $styleIndex[$key] = $idx;
            return $idx;
        };

        // Pre-register all styles so indexes are stable
        foreach ($this->sheets as $s) {
            foreach ($s['rows'] as $r) {
                $getStyle($r['style']);
            }
        }

        $sharedStr = function(string $s): int {
            if (!isset($this->sstMap[$s])) {
                $this->sstMap[$s] = count($this->sst);
                $this->sst[] = $s;
            }
            return $this->sstMap[$s];
        };

        $colLetter = function(int $n): string {
            $s = '';
            for ($n++; $n > 0; $n = (int)(($n - 1) / 26))
                $s = chr((($n - 1) % 26) + 65) . $s;
            return $s;
        };

        $names = array_keys($this->sheets);

        // ── Build sheet XML ────────────────────────────────────────────────
        $sheetXmls = [];
        foreach ($names as $name) {
            $sd  = $this->sheets[$name];
            $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                 . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
                 . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">';

            if (!empty($sd['widths'])) {
                $xml .= '<cols>';
                foreach ($sd['widths'] as $i => $w) {
                    $c = $i + 1;
                    $xml .= "<col min=\"$c\" max=\"$c\" width=\"$w\" customWidth=\"1\"/>";
                }
                $xml .= '</cols>';
            }

            $xml .= '<sheetData>';
            $rowIdx = 1;
            foreach ($sd['rows'] as $rowDef) {
                $si = $getStyle($rowDef['style']);
                $xml .= "<row r=\"$rowIdx\">";
                $colIdx = 0;
                foreach ($rowDef['data'] as $val) {
                    $ref = $colLetter($colIdx) . $rowIdx;
                    if (is_int($val) || is_float($val)) {
                        $xml .= "<c r=\"$ref\" s=\"$si\" t=\"n\"><v>$val</v></c>";
                    } elseif ($val === '' || $val === null) {
                        $xml .= "<c r=\"$ref\" s=\"$si\"/>";
                    } else {
                        $ssi = $sharedStr((string)$val);
                        $xml .= "<c r=\"$ref\" s=\"$si\" t=\"s\"><v>$ssi</v></c>";
                    }
                    $colIdx++;
                }
                $xml .= '</row>';
                $rowIdx++;
            }
            $xml .= '</sheetData></worksheet>';
            $sheetXmls[] = $xml;
        }

        // ── Styles XML ────────────────────────────────────────────────────
        $defaultXf = '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" applyFont="1" applyFill="1" applyBorder="1"/>';
        $xfList    = $defaultXf . implode('', $xfs);
        $xfCount   = count($xfs) + 1;

        $stylesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="' . count($fonts) . '">' . implode('', $fonts) . '</fonts>'
            . '<fills count="' . count($fills) . '">' . implode('', $fills) . '</fills>'
            . '<borders count="' . count($borders) . '">' . implode('', $borders) . '</borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . "<cellXfs count=\"$xfCount\">$xfList</cellXfs>"
            . '</styleSheet>';

        // ── Shared strings XML ─────────────────────────────────────────────
        $sstCount = count($this->sst);
        $sstXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . "<sst xmlns=\"http://schemas.openxmlformats.org/spreadsheetml/2006/main\""
                . " count=\"$sstCount\" uniqueCount=\"$sstCount\">";
        foreach ($this->sst as $str) {
            $sstXml .= '<si><t xml:space="preserve">' . htmlspecialchars($str, ENT_XML1 | ENT_COMPAT, 'UTF-8') . '</t></si>';
        }
        $sstXml .= '</sst>';

        // ── Content types ─────────────────────────────────────────────────
        $ctSheets = '';
        foreach ($names as $i => $_) {
            $n = $i + 1;
            $ctSheets .= "<Override PartName=\"/xl/worksheets/sheet$n.xml\""
                       . " ContentType=\"application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml\"/>";
        }
        $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml"  ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/styles.xml"   ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>'
            . $ctSheets
            . '</Types>';

        // ── Relationships ─────────────────────────────────────────────────
        $rootRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';

        // ── Workbook ─────────────────────────────────────────────────────
        $wbSheets = '';
        foreach ($names as $i => $name) {
            $n = $i + 1;
            $ename = htmlspecialchars($name, ENT_XML1 | ENT_COMPAT, 'UTF-8');
            $wbSheets .= "<sheet name=\"$ename\" sheetId=\"$n\" r:id=\"rId$n\"/>";
        }
        $workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . "<sheets>$wbSheets</sheets>"
            . '</workbook>';

        // ── Workbook rels ─────────────────────────────────────────────────
        $wbRels = '';
        foreach ($names as $i => $_) {
            $n = $i + 1;
            $wbRels .= "<Relationship Id=\"rId$n\""
                     . " Type=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet\""
                     . " Target=\"worksheets/sheet$n.xml\"/>";
        }
        $n2 = count($names) + 1; $n3 = $n2 + 1;
        $wbRels .= "<Relationship Id=\"rId$n2\""
                 . " Type=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles\""
                 . " Target=\"styles.xml\"/>";
        $wbRels .= "<Relationship Id=\"rId$n3\""
                 . " Type=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings\""
                 . " Target=\"sharedStrings.xml\"/>";
        $wbRelsXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                   . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                   . $wbRels . '</Relationships>';

        // ── Write ZIP ─────────────────────────────────────────────────────
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true)
            throw new RuntimeException("Cannot create XLSX zip at: $path");

        $zip->addFromString('[Content_Types].xml',          $contentTypes);
        $zip->addFromString('_rels/.rels',                  $rootRels);
        $zip->addFromString('xl/workbook.xml',              $workbook);
        $zip->addFromString('xl/_rels/workbook.xml.rels',   $wbRelsXml);
        $zip->addFromString('xl/styles.xml',                $stylesXml);
        $zip->addFromString('xl/sharedStrings.xml',         $sstXml);

        foreach ($names as $i => $_) {
            $zip->addFromString("xl/worksheets/sheet" . ($i + 1) . ".xml", $sheetXmls[$i]);
        }

        $zip->close();
    }

    private function ensureSheet(string $name): void
    {
        if (!isset($this->sheets[$name]))
            $this->sheets[$name] = ['rows' => [], 'widths' => []];
    }
}
}