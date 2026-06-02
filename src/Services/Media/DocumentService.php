<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Media;

use Illuminate\Support\Facades\Log;
use LaravelAIEngine\Exceptions\DocumentExtractionException;

class DocumentService
{
    /**
     * Extract text from document.
     *
     * On failure the behaviour depends on the
     * `ai-engine.media.document_extraction.graceful_degradation` config flag:
     *  - true (default): logs a warning and returns '' to preserve the legacy
     *    graceful-degradation contract relied upon by media embedding callers.
     *  - false: throws a {@see DocumentExtractionException} so callers can react.
     *
     * Either way the failure is always logged so it is observable.
     */
    public function extractText(string $filePath, string $extension): string
    {
        if (!file_exists($filePath)) {
            throw new \InvalidArgumentException("Document file not found: {$filePath}");
        }

        $extension = strtolower($extension);

        $this->assertWithinSizeLimit($filePath, $extension);

        try {
            return match ($extension) {
                'pdf' => $this->extractFromPDF($filePath),
                'docx' => $this->extractFromDOCX($filePath),
                'doc' => $this->extractFromDOC($filePath),
                'txt', 'text', 'md', 'markdown', 'log', 'json', 'xml', 'html', 'htm', 'css', 'js', 'php', 'py', 'rb', 'java', 'c', 'cpp', 'h', 'sh', 'bash', 'yml', 'yaml', 'ini', 'conf', 'cfg' => $this->extractFromTXT($filePath),
                'rtf' => $this->extractFromRTF($filePath),
                'odt' => $this->extractFromODT($filePath),
                'csv' => $this->extractFromCSV($filePath),
                'xls', 'xlsx' => $this->extractFromExcel($filePath),
                'ppt', 'pptx' => $this->extractFromPowerPoint($filePath),
                default => $this->extractFromTXT($filePath), // Try as text for unknown formats
            };
        } catch (\Throwable $e) {
            Log::warning('Document text extraction failed', [
                'file_path' => $filePath,
                'extension' => $extension,
                'error' => $e->getMessage(),
                'graceful_degradation' => $this->gracefulDegradationEnabled(),
            ]);

            if ($this->gracefulDegradationEnabled()) {
                return ''; // Preserve legacy graceful-degradation contract.
            }

            if ($e instanceof DocumentExtractionException) {
                throw $e;
            }

            throw new DocumentExtractionException(
                "Failed to extract text from {$extension} document: {$e->getMessage()}",
                $extension,
                $filePath,
                $e
            );
        }
    }

    /**
     * Whether extraction failures should be swallowed into an empty string.
     */
    protected function gracefulDegradationEnabled(): bool
    {
        return (bool) $this->config('graceful_degradation', true);
    }

    /**
     * Read a document-extraction config value with a safe fallback when the
     * Laravel config repository is unavailable (e.g. standalone usage).
     */
    protected function config(string $key, mixed $default): mixed
    {
        if (function_exists('config')) {
            return config("ai-engine.media.document_extraction.{$key}", $default);
        }

        return $default;
    }

    /**
     * Enforce the configurable maximum file size before extraction.
     */
    protected function assertWithinSizeLimit(string $filePath, string $extension): void
    {
        $maxBytes = (int) $this->config('max_file_size', 0);
        if ($maxBytes <= 0) {
            return; // Cap disabled.
        }

        $size = @filesize($filePath);
        if ($size !== false && $size > $maxBytes) {
            throw new DocumentExtractionException(
                "Document exceeds configured size limit ({$size} > {$maxBytes} bytes).",
                $extension,
                $filePath
            );
        }
    }

    /**
     * Extract text from PDF
     */
    protected function extractFromPDF(string $filePath): string
    {
        // Prefer the smalot/pdfparser PHP library when available (mirrors
        // MagicAI's ParserService). It is loss-tolerant and requires no CLI.
        if (class_exists(\Smalot\PdfParser\Parser::class)) {
            $parser = new \Smalot\PdfParser\Parser();
            $text = $parser->parseFile($filePath)->getText();

            return trim($text);
        }

        // Try pdftotext (poppler-utils) next.
        if ($this->isPdfToTextAvailable()) {
            $tempFile = tempnam(sys_get_temp_dir(), 'pdf_');
            $command = sprintf(
                'pdftotext %s %s 2>&1',
                escapeshellarg($filePath),
                escapeshellarg($tempFile)
            );

            exec($command, $output, $returnCode);

            if ($returnCode === 0 && file_exists($tempFile)) {
                $text = file_get_contents($tempFile);
                unlink($tempFile);
                return trim($text);
            }
        }

        // Fallback: Try to read as text (works for some PDFs)
        $content = file_get_contents($filePath);
        
        // Basic PDF text extraction (very limited)
        if (preg_match_all('/\((.*?)\)/s', $content, $matches)) {
            return implode(' ', $matches[1]);
        }

        throw new DocumentExtractionException(
            'PDF text extraction failed. Install the smalot/pdfparser library (composer require smalot/pdfparser) or poppler-utils (pdftotext).',
            'pdf',
            $filePath
        );
    }

    /**
     * Extract text from DOCX
     */
    protected function extractFromDOCX(string $filePath): string
    {
        if (!class_exists('\ZipArchive')) {
            throw new \RuntimeException('ZipArchive extension required for DOCX extraction');
        }

        $zip = new \ZipArchive();
        
        if ($zip->open($filePath) !== true) {
            throw new \RuntimeException('Failed to open DOCX file');
        }

        // Extract document.xml
        $content = $zip->getFromName('word/document.xml');
        $zip->close();

        if ($content === false) {
            throw new \RuntimeException('Failed to extract text from DOCX');
        }

        // Parse XML and extract text
        $xml = simplexml_load_string($content);
        if ($xml === false) {
            throw new \RuntimeException('Failed to parse DOCX XML');
        }

        // Register namespace
        $xml->registerXPathNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

        // Extract all text nodes
        $textNodes = $xml->xpath('//w:t');
        $text = [];
        
        foreach ($textNodes as $node) {
            $text[] = (string) $node;
        }

        return implode(' ', $text);
    }

    /**
     * Extract text from legacy DOC (binary format)
     * Uses antiword or catdoc if available, otherwise attempts basic extraction
     */
    protected function extractFromDOC(string $filePath): string
    {
        // Try antiword first (best quality)
        if ($this->isCommandAvailable('antiword')) {
            $command = sprintf('antiword %s 2>&1', escapeshellarg($filePath));
            exec($command, $output, $returnCode);
            
            if ($returnCode === 0) {
                return implode("\n", $output);
            }
        }

        // Try catdoc as fallback
        if ($this->isCommandAvailable('catdoc')) {
            $command = sprintf('catdoc %s 2>&1', escapeshellarg($filePath));
            exec($command, $output, $returnCode);
            
            if ($returnCode === 0) {
                return implode("\n", $output);
            }
        }

        // Try LibreOffice/OpenOffice conversion
        if ($this->isCommandAvailable('soffice')) {
            $tempDir = sys_get_temp_dir();
            $command = sprintf(
                'soffice --headless --convert-to txt:Text --outdir %s %s 2>&1',
                escapeshellarg($tempDir),
                escapeshellarg($filePath)
            );
            exec($command, $output, $returnCode);
            
            if ($returnCode === 0) {
                $txtFile = $tempDir . '/' . pathinfo($filePath, PATHINFO_FILENAME) . '.txt';
                if (file_exists($txtFile)) {
                    $content = file_get_contents($txtFile);
                    @unlink($txtFile);
                    return $content;
                }
            }
        }

        // Last resort: try to extract readable text from binary
        $content = file_get_contents($filePath);
        
        // Remove binary characters and extract readable text
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F-\xFF]/', ' ', $content);
        $text = preg_replace('/\s+/', ' ', $text);
        
        // If we got mostly garbage, throw an error
        if (strlen(trim($text)) < 50) {
            throw new DocumentExtractionException(
                'Cannot extract text from legacy DOC file. Install antiword, catdoc, or LibreOffice (soffice).',
                'doc',
                $filePath
            );
        }

        return trim($text);
    }

    /**
     * Check if a command is available on the system
     */
    protected function isCommandAvailable(string $command): bool
    {
        $which = PHP_OS_FAMILY === 'Windows' ? 'where' : 'which';
        exec("$which $command 2>&1", $output, $returnCode);
        return $returnCode === 0;
    }

    /**
     * Extract text from TXT
     */
    protected function extractFromTXT(string $filePath): string
    {
        return file_get_contents($filePath);
    }

    /**
     * Extract text from RTF
     */
    protected function extractFromRTF(string $filePath): string
    {
        $content = file_get_contents($filePath);
        
        // Basic RTF to text conversion (removes control words)
        $text = preg_replace('/\{\\\\[^}]+\}/', '', $content);
        $text = preg_replace('/\\\\[a-z]+\d*\s?/', '', $text);
        $text = preg_replace('/[\{\}]/', '', $text);
        
        return trim($text);
    }

    /**
     * Extract text from ODT
     */
    protected function extractFromODT(string $filePath): string
    {
        if (!class_exists('\ZipArchive')) {
            throw new \RuntimeException('ZipArchive extension required for ODT extraction');
        }

        $zip = new \ZipArchive();
        
        if ($zip->open($filePath) !== true) {
            throw new \RuntimeException('Failed to open ODT file');
        }

        $content = $zip->getFromName('content.xml');
        $zip->close();

        if ($content === false) {
            throw new \RuntimeException('Failed to extract text from ODT');
        }

        // Parse XML and extract text
        $xml = simplexml_load_string($content);
        if ($xml === false) {
            throw new \RuntimeException('Failed to parse ODT XML');
        }

        // Extract all text content
        $text = strip_tags($xml->asXML());
        
        return trim($text);
    }

    /**
     * Extract text from CSV
     */
    protected function extractFromCSV(string $filePath): string
    {
        // Prefer phpoffice/phpspreadsheet when available (mirrors MagicAI's
        // ParserExcelService), falling back to native fgetcsv parsing.
        if (class_exists(\PhpOffice\PhpSpreadsheet\IOFactory::class)) {
            return $this->extractWithPhpSpreadsheet($filePath);
        }

        $handle = fopen($filePath, 'r');
        if ($handle === false) {
            throw new \RuntimeException('Failed to open CSV file');
        }

        $rows = [];
        while (($data = fgetcsv($handle)) !== false) {
            $rows[] = implode(' ', $data);
        }
        fclose($handle);

        return implode("\n", $rows);
    }

    /**
     * Extract text from Excel (XLSX)
     */
    protected function extractFromExcel(string $filePath): string
    {
        // Prefer phpoffice/phpspreadsheet when available (mirrors MagicAI's
        // ParserExcelService). It reads xls/xlsx across all sheets correctly.
        if (class_exists(\PhpOffice\PhpSpreadsheet\IOFactory::class)) {
            return $this->extractWithPhpSpreadsheet($filePath);
        }

        if (!class_exists('\ZipArchive')) {
            throw new DocumentExtractionException(
                'Excel extraction requires the phpoffice/phpspreadsheet library or the ZipArchive extension.',
                'xlsx',
                $filePath
            );
        }

        $zip = new \ZipArchive();
        
        if ($zip->open($filePath) !== true) {
            throw new \RuntimeException('Failed to open Excel file');
        }

        // Extract shared strings
        $sharedStrings = [];
        $sharedStringsXml = $zip->getFromName('xl/sharedStrings.xml');
        if ($sharedStringsXml !== false) {
            $xml = simplexml_load_string($sharedStringsXml);
            if ($xml !== false) {
                foreach ($xml->si as $si) {
                    $sharedStrings[] = (string) $si->t;
                }
            }
        }

        // Extract sheet data
        $text = [];
        $sheet1 = $zip->getFromName('xl/worksheets/sheet1.xml');
        if ($sheet1 !== false) {
            $xml = simplexml_load_string($sheet1);
            if ($xml !== false) {
                $xml->registerXPathNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
                $cells = $xml->xpath('//x:c');
                
                foreach ($cells as $cell) {
                    if (isset($cell->v)) {
                        $value = (string) $cell->v;
                        // Check if it's a shared string reference
                        if (isset($cell['t']) && (string) $cell['t'] === 's') {
                            $index = (int) $value;
                            $value = $sharedStrings[$index] ?? $value;
                        }
                        $text[] = $value;
                    }
                }
            }
        }

        $zip->close();

        return implode(' ', $text);
    }

    /**
     * Extract text from a spreadsheet using phpoffice/phpspreadsheet.
     * Reads every worksheet and flattens non-empty cell values, mirroring
     * MagicAI's ParserExcelService.
     */
    protected function extractWithPhpSpreadsheet(string $filePath): string
    {
        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($filePath);

        $values = [];
        foreach ($spreadsheet->getAllSheets() as $sheet) {
            foreach ($sheet->toArray() as $row) {
                foreach ($row as $cell) {
                    if ($cell !== null && $cell !== '') {
                        $values[] = (string) $cell;
                    }
                }
            }
        }

        return implode(' ', $values);
    }

    /**
     * Extract text from PowerPoint (PPTX)
     */
    protected function extractFromPowerPoint(string $filePath): string
    {
        if (!class_exists('\ZipArchive')) {
            throw new DocumentExtractionException(
                'PowerPoint extraction requires the ZipArchive extension.',
                'pptx',
                $filePath
            );
        }

        $zip = new \ZipArchive();

        if ($zip->open($filePath) !== true) {
            throw new \RuntimeException('Failed to open PowerPoint file');
        }

        $text = [];

        $maxSlides = (int) $this->config('max_slides', 100);
        if ($maxSlides <= 0) {
            $maxSlides = 100;
        }

        // Extract text from slides (configurable cap).
        for ($i = 1; $i <= $maxSlides; $i++) {
            $slideContent = $zip->getFromName("ppt/slides/slide{$i}.xml");
            if ($slideContent === false) {
                break;
            }

            $xml = simplexml_load_string($slideContent);
            if ($xml !== false) {
                $xml->registerXPathNamespace('a', 'http://schemas.openxmlformats.org/drawingml/2006/main');
                $textNodes = $xml->xpath('//a:t');
                
                foreach ($textNodes as $node) {
                    $text[] = (string) $node;
                }
            }
        }

        $zip->close();

        return implode(' ', $text);
    }

    /**
     * Check if pdftotext is available
     */
    protected function isPdfToTextAvailable(): bool
    {
        exec('pdftotext -v 2>&1', $output, $returnCode);
        return $returnCode === 0 || $returnCode === 99; // 99 is also success for pdftotext
    }

    /**
     * Check if document type is supported
     */
    public function isSupported(string $extension): bool
    {
        $supported = ['pdf', 'doc', 'docx', 'txt', 'rtf', 'odt', 'xls', 'xlsx', 'ppt', 'pptx', 'csv'];
        return in_array(strtolower($extension), $supported);
    }

    /**
     * Get document metadata
     */
    public function getMetadata(string $filePath): array
    {
        return [
            'file_size' => filesize($filePath),
            'mime_type' => $this->detectMimeType($filePath),
            'extension' => pathinfo($filePath, PATHINFO_EXTENSION),
            'modified_at' => date('Y-m-d H:i:s', filemtime($filePath)),
        ];
    }

    /**
     * Detect a file's MIME type via finfo (preferred over mime_content_type()),
     * with a graceful fallback when the fileinfo extension is unavailable.
     */
    protected function detectMimeType(string $filePath): ?string
    {
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $mime = finfo_file($finfo, $filePath);
                finfo_close($finfo);
                if (is_string($mime) && $mime !== '') {
                    return $mime;
                }
            }
        }

        if (function_exists('mime_content_type')) {
            $mime = @mime_content_type($filePath);
            if (is_string($mime) && $mime !== '') {
                return $mime;
            }
        }

        return null;
    }
}
