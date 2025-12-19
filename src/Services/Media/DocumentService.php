<?php

namespace LaravelAIEngine\Services\Media;

use Illuminate\Support\Facades\Log;

class DocumentService
{
    /**
     * Extract text from document
     */
    public function extractText(string $filePath, string $extension): string
    {
        if (!file_exists($filePath)) {
            throw new \InvalidArgumentException("Document file not found: {$filePath}");
        }

        $extension = strtolower($extension);

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
        } catch (\Exception $e) {
            Log::warning('Document text extraction failed, returning empty', [
                'file_path' => $filePath,
                'extension' => $extension,
                'error' => $e->getMessage(),
            ]);
            return ''; // Return empty instead of throwing to allow graceful degradation
        }
    }

    /**
     * Extract text from PDF
     */
    protected function extractFromPDF(string $filePath): string
    {
        // Try pdftotext (poppler-utils) first
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

        throw new \RuntimeException('PDF text extraction failed. Please install poppler-utils (pdftotext).');
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
            throw new \RuntimeException('Cannot extract text from DOC file. Please install antiword or catdoc.');
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
        if (!class_exists('\ZipArchive')) {
            throw new \RuntimeException('ZipArchive extension required for Excel extraction');
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
     * Extract text from PowerPoint (PPTX)
     */
    protected function extractFromPowerPoint(string $filePath): string
    {
        if (!class_exists('\ZipArchive')) {
            throw new \RuntimeException('ZipArchive extension required for PowerPoint extraction');
        }

        $zip = new \ZipArchive();
        
        if ($zip->open($filePath) !== true) {
            throw new \RuntimeException('Failed to open PowerPoint file');
        }

        $text = [];
        
        // Extract text from all slides
        for ($i = 1; $i <= 100; $i++) { // Check up to 100 slides
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
            'mime_type' => mime_content_type($filePath),
            'extension' => pathinfo($filePath, PATHINFO_EXTENSION),
            'modified_at' => date('Y-m-d H:i:s', filemtime($filePath)),
        ];
    }
}
