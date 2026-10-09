<?php
declare(strict_types=1);

/**
 * Turning an uploaded document into plain text.
 *
 * Shared hosting is the constraint here. There is no Composer, so no PDF
 * library, and command-line tools like pdftotext are usually absent. So each
 * format has a native-PHP path, and pdftotext is used only when the host
 * happens to provide it.
 *
 * What this deliberately does NOT do is guess. If a PDF turns out to be
 * scanned images with no text layer, no amount of parsing will recover the
 * words, and pretending otherwise would seed the knowledge base with nothing
 * or with garbage. In that case it reports the problem and the operator is
 * asked for a text version instead.
 */
final class DocumentText
{
    /** Formats the importer accepts, mapped to a readable name. */
    public const ACCEPTED = [
        'pdf'  => 'PDF document',
        'docx' => 'Word document',
        'txt'  => 'Plain text',
        'md'   => 'Markdown',
        'csv'  => 'Spreadsheet export (CSV)',
        'tsv'  => 'Spreadsheet export (TSV)',
        'html' => 'Web page',
        'htm'  => 'Web page',
        'rtf'  => 'Rich text',
    ];

    /** Anything below this from a PDF almost certainly means a scanned file. */
    private const MIN_USEFUL_CHARS = 200;

    public const MAX_BYTES = 12582912;   // 12 MB per document

    /**
     * Extract text from a stored file.
     *
     * @return array{
     *   ok: bool,
     *   text: string,
     *   method: string,
     *   chars: int,
     *   pages: ?int,
     *   problem: ?string,
     *   advice: ?string
     * }
     */
    public static function extract(string $path, string $extension): array
    {
        $extension = strtolower(trim($extension, '. '));

        if (!is_file($path) || !is_readable($path)) {
            return self::failure('file_unreadable', 'The uploaded file could not be read from storage.');
        }

        $result = match ($extension) {
            'pdf'          => self::fromPdf($path),
            'docx'         => self::fromDocx($path),
            'rtf'          => self::fromRtf($path),
            'html', 'htm'  => self::fromHtml($path),
            'csv', 'tsv'   => self::fromDelimited($path, $extension === 'tsv' ? "\t" : ','),
            'txt', 'md'    => self::fromPlain($path),
            default        => self::failure(
                'unsupported_format',
                'That file type is not supported.',
                'Upload a PDF, Word document, or a plain text file.'
            ),
        };

        if ($result['ok']) {
            $result['text']  = self::tidy($result['text']);
            $result['chars'] = mb_strlen($result['text']);

            if ($result['chars'] < self::MIN_USEFUL_CHARS) {
                return [
                    'ok'      => false,
                    'text'    => $result['text'],
                    'method'  => $result['method'],
                    'chars'   => $result['chars'],
                    'pages'   => $result['pages'],
                    'problem' => $extension === 'pdf'
                        ? 'Almost no text could be read from this PDF, which usually means the pages '
                          . 'are scanned images rather than text.'
                        : 'Almost no text could be read from this file.',
                    'advice'  => $extension === 'pdf'
                        ? 'Open the PDF and try selecting some text with your mouse. If you cannot '
                          . 'select it, the file is images and the words have to come from somewhere '
                          . 'else — paste the text in directly, or upload a Word version.'
                        : 'Check the file opens correctly, or paste the text in directly.',
                ];
            }
        }

        return $result;
    }

    // -----------------------------------------------------------------
    // Plain formats
    // -----------------------------------------------------------------

    private static function fromPlain(string $path): array
    {
        $raw = (string)file_get_contents($path);
        return self::success(self::toUtf8($raw), 'plain text');
    }

    private static function fromDelimited(string $path, string $separator): array
    {
        $handle = fopen($path, 'r');
        if ($handle === false) {
            return self::failure('file_unreadable', 'The file could not be opened.');
        }

        $lines  = [];
        $header = null;
        $row    = 0;

        while (($cells = fgetcsv($handle, 0, $separator)) !== false && $row < 5000) {
            $cells = array_map(static fn($c) => trim((string)$c), $cells);
            if (!array_filter($cells, static fn($c) => $c !== '')) {
                continue;
            }
            if ($header === null) {
                $header = $cells;
                $lines[] = implode(' | ', $cells);
                continue;
            }
            // Pair each value with its column name, so the model reads a row
            // as facts rather than as a bare list of numbers.
            $pairs = [];
            foreach ($cells as $i => $value) {
                if ($value === '') {
                    continue;
                }
                $label = trim((string)($header[$i] ?? ('Column ' . ($i + 1))));
                $pairs[] = ($label !== '' ? $label . ': ' : '') . $value;
            }
            $lines[] = implode('; ', $pairs);
            $row++;
        }
        fclose($handle);

        return self::success(self::toUtf8(implode("\n", $lines)), 'spreadsheet rows');
    }

    private static function fromHtml(string $path): array
    {
        $raw = self::toUtf8((string)file_get_contents($path));
        $raw = preg_replace('#<(script|style|noscript)\b[^>]*>.*?</\1>#is', ' ', $raw) ?? $raw;
        // Keep block boundaries as line breaks so structure survives.
        $raw = preg_replace('#</(p|div|li|tr|h[1-6]|section|article)>#i', "\n", $raw) ?? $raw;
        $raw = preg_replace('#<br\s*/?>#i', "\n", $raw) ?? $raw;
        $text = html_entity_decode(strip_tags($raw), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return self::success($text, 'web page text');
    }

    private static function fromRtf(string $path): array
    {
        $raw = self::toUtf8((string)file_get_contents($path));
        // Drop RTF groups that carry no readable content, then control words.
        $raw = preg_replace('#\{\\\\\*?(?:fonttbl|colortbl|stylesheet|info|pict)[^{}]*(?:\{[^{}]*\})*[^{}]*\}#s', ' ', $raw) ?? $raw;
        $raw = preg_replace('/\\\\par[d]?\b/', "\n", $raw) ?? $raw;
        $raw = preg_replace("/\\\\'([0-9a-f]{2})/ie", '', $raw) ?? $raw;
        $raw = preg_replace('/\\\\[a-z]+-?\d* ?/i', ' ', $raw) ?? $raw;
        $raw = str_replace(['{', '}'], ' ', $raw);
        return self::success($raw, 'rich text');
    }

    // -----------------------------------------------------------------
    // Word documents
    // -----------------------------------------------------------------

    /**
     * A .docx is a ZIP containing XML, so ZipArchive plus the DOM extension
     * is enough. Paragraphs and table cells are read in document order, and
     * table rows are kept as separate lines because pricing tables are
     * exactly the content that matters most.
     */
    private static function fromDocx(string $path): array
    {
        if (!class_exists('ZipArchive')) {
            return self::failure(
                'zip_missing',
                'Word documents need the ZIP extension, which is not enabled on this server.',
                'Save the document as a PDF or plain text and upload that instead.'
            );
        }

        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            return self::failure('docx_unreadable', 'That Word document could not be opened.');
        }

        $parts = [];
        foreach (['word/document.xml', 'word/header1.xml', 'word/footer1.xml'] as $entry) {
            $xml = $zip->getFromName($entry);
            if (is_string($xml) && $xml !== '') {
                $parts[] = $xml;
            }
        }
        $zip->close();

        if (!$parts) {
            return self::failure('docx_empty', 'No readable content was found in that Word document.');
        }

        $text = '';
        foreach ($parts as $xml) {
            $text .= self::docxXmlToText($xml) . "\n";
        }

        return self::success($text, 'Word document');
    }

    private static function docxXmlToText(string $xml): string
    {
        // Turn structural tags into line breaks before stripping the rest.
        $xml = str_replace(
            ['</w:p>', '<w:br/>', '<w:br />', '</w:tr>'],
            ["\n", "\n", "\n", "\n"],
            $xml
        );
        // A table cell boundary becomes a separator, so a pricing row stays
        // readable as "Gold Partner | USD 20,000" rather than running together.
        $xml = str_replace('</w:tc>', ' | ', $xml);

        $previous = libxml_use_internal_errors(true);
        $text = '';

        $doc = new DOMDocument();
        if ($doc->loadXML($xml, LIBXML_NOENT | LIBXML_NONET)) {
            $text = (string)$doc->textContent;
        } else {
            $text = strip_tags($xml);
        }

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        // Tidy the separators introduced above.
        $text = preg_replace('/\s*\|\s*\|\s*/', ' | ', $text) ?? $text;
        $text = preg_replace('/(^|\n)\s*\|\s*/', '$1', $text) ?? $text;
        $text = preg_replace('/\s*\|\s*(\n|$)/', '$1', $text) ?? $text;

        return html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    // -----------------------------------------------------------------
    // PDF documents
    // -----------------------------------------------------------------

    /**
     * pdftotext first when the host provides it, because it handles column
     * layouts and ligatures far better than anything achievable in PHP.
     * Otherwise fall back to reading the content streams directly.
     */
    private static function fromPdf(string $path): array
    {
        $binary = self::pdfToTextBinary();
        if ($binary !== null) {
            $out = self::runPdfToText($binary, $path);
            if ($out !== null && mb_strlen(trim($out)) >= self::MIN_USEFUL_CHARS) {
                return self::success($out, 'PDF text layer (pdftotext)', self::pdfPageCount($path));
            }
        }

        $native = self::pdfNativeText($path);
        if ($native === null) {
            return self::failure(
                'pdf_unreadable',
                'That PDF could not be read.',
                'It may be password protected or damaged. Try re-saving it, or paste the text in directly.'
            );
        }

        return self::success($native, 'PDF content streams (built in)', self::pdfPageCount($path));
    }

    /** Is a usable pdftotext on this host? Checked once per request. */
    private static function pdfToTextBinary(): ?string
    {
        static $resolved = false;
        static $binary = null;

        if ($resolved) {
            return $binary;
        }
        $resolved = true;

        if (!function_exists('proc_open')) {
            return null;
        }
        $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
        if (in_array('proc_open', $disabled, true)) {
            return null;
        }

        foreach (['/usr/bin/pdftotext', '/usr/local/bin/pdftotext', '/opt/bin/pdftotext'] as $candidate) {
            if (is_file($candidate) && is_executable($candidate)) {
                $binary = $candidate;
                return $binary;
            }
        }
        return null;
    }

    private static function runPdfToText(string $binary, string $path): ?string
    {
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = @proc_open(
            [$binary, '-q', '-enc', 'UTF-8', '-layout', $path, '-'],
            $descriptors,
            $pipes
        );
        if (!is_resource($process)) {
            return null;
        }

        $out = (string)stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        return $out === '' ? null : $out;
    }

    private static function pdfPageCount(string $path): ?int
    {
        $raw = (string)file_get_contents($path);
        if (preg_match_all('#/Type\s*/Page\b#', $raw, $m)) {
            $count = count($m[0]);
            return $count > 0 ? $count : null;
        }
        if (preg_match('#/Count\s+(\d+)#', $raw, $m)) {
            return (int)$m[1];
        }
        return null;
    }

    /**
     * Read the text-showing operators out of a PDF's content streams.
     *
     * This handles the ordinary case: streams compressed with Flate, text
     * drawn with Tj, TJ, ' or ". It does not attempt encrypted files or
     * unusual font encodings, and it is not expected to match a real PDF
     * engine — only to recover enough prose to be analysed.
     */
    private static function pdfNativeText(string $path): ?string
    {
        $raw = (string)file_get_contents($path);
        if ($raw === '' || !str_starts_with($raw, '%PDF')) {
            return null;
        }
        // An encrypted PDF cannot be read without the password.
        if (preg_match('#/Encrypt\b#', $raw)) {
            return null;
        }

        $chunks = [];

        // Ordinary streams.
        if (preg_match_all('#stream\r?\n?(.*?)endstream#s', $raw, $matches)) {
            foreach ($matches[1] as $stream) {
                $text = self::pdfDecodeStream($stream);
                if ($text !== null && $text !== '') {
                    $chunks[] = $text;
                }
            }
        }

        if (!$chunks) {
            return null;
        }

        $out = [];
        foreach ($chunks as $content) {
            $piece = self::pdfTextFromContent($content);
            if (trim($piece) !== '') {
                $out[] = $piece;
            }
        }

        return $out ? implode("\n", $out) : '';
    }

    /** Inflate a stream if it is compressed, and ignore anything binary. */
    private static function pdfDecodeStream(string $stream): ?string
    {
        $stream = ltrim($stream, "\r\n");

        $decoded = @gzuncompress($stream);
        if ($decoded === false) {
            $decoded = @gzinflate($stream);
        }
        if ($decoded === false) {
            $decoded = @gzinflate(substr($stream, 2));
        }
        if ($decoded === false) {
            // Possibly an uncompressed content stream.
            $decoded = $stream;
        }

        // Only keep streams that look like page content rather than an image.
        if (!preg_match('#(?:\bTj\b|\bTJ\b|\bBT\b)#', $decoded)) {
            return null;
        }
        return $decoded;
    }

    /** Pull the strings out of a content stream's text operators. */
    private static function pdfTextFromContent(string $content): string
    {
        $out = '';

        // Each text-showing operator, in the order it appears.
        if (!preg_match_all(
            '#(\((?:\\\\.|[^\\\\()])*\)|\[(?:[^\[\]\\\\]|\\\\.)*\])\s*(TJ|Tj|\'|")|\bT\*|\bTd\b|\bTD\b|\bET\b#s',
            $content,
            $tokens,
            PREG_SET_ORDER
        )) {
            return '';
        }

        foreach ($tokens as $token) {
            $whole = $token[0];

            // Positioning and end-of-text operators become whitespace, which
            // is what keeps separate lines from running together.
            if ($whole === 'T*' || $whole === 'ET') {
                $out .= "\n";
                continue;
            }
            if ($whole === 'Td' || $whole === 'TD') {
                $out .= ' ';
                continue;
            }

            $operand = $token[1] ?? '';
            if ($operand === '') {
                continue;
            }

            if ($operand[0] === '[') {
                // A TJ array: strings interleaved with kerning numbers.
                if (preg_match_all('#\((?:\\\\.|[^\\\\()])*\)#s', $operand, $strings)) {
                    foreach ($strings[0] as $string) {
                        $out .= self::pdfUnescapeString(substr($string, 1, -1));
                    }
                }
                $out .= ' ';
            } else {
                $out .= self::pdfUnescapeString(substr($operand, 1, -1)) . ' ';
            }
        }

        return $out;
    }

    /** Resolve PDF string escapes, including octal character codes. */
    private static function pdfUnescapeString(string $string): string
    {
        $map = [
            '\\n' => "\n", '\\r' => "\r", '\\t' => "\t", '\\b' => "\x08",
            '\\f' => "\x0C", '\\(' => '(', '\\)' => ')', '\\\\' => '\\',
        ];
        $string = strtr($string, $map);

        $string = preg_replace_callback(
            '/\\\\([0-7]{1,3})/',
            static fn(array $m): string => chr(intval($m[1], 8) & 0xFF),
            $string
        ) ?? $string;

        return self::toUtf8($string);
    }

    // -----------------------------------------------------------------
    // Shared helpers
    // -----------------------------------------------------------------

    private static function success(string $text, string $method, ?int $pages = null): array
    {
        return [
            'ok'      => true,
            'text'    => $text,
            'method'  => $method,
            'chars'   => mb_strlen($text),
            'pages'   => $pages,
            'problem' => null,
            'advice'  => null,
        ];
    }

    private static function failure(string $code, string $problem, ?string $advice = null): array
    {
        return [
            'ok'      => false,
            'text'    => '',
            'method'  => $code,
            'chars'   => 0,
            'pages'   => null,
            'problem' => $problem,
            'advice'  => $advice,
        ];
    }

    private static function toUtf8(string $text): string
    {
        if ($text === '') {
            return '';
        }
        // UTF-16 with a byte-order mark, which Word and some PDFs produce.
        if (str_starts_with($text, "\xFE\xFF") || str_starts_with($text, "\xFF\xFE")) {
            $converted = @mb_convert_encoding($text, 'UTF-8', 'UTF-16');
            if (is_string($converted) && $converted !== '') {
                return $converted;
            }
        }
        if (mb_check_encoding($text, 'UTF-8')) {
            return $text;
        }
        $converted = @mb_convert_encoding($text, 'UTF-8', 'Windows-1252, ISO-8859-1');
        return is_string($converted) ? $converted : $text;
    }

    /**
     * Normalise whitespace without destroying paragraph structure, and drop
     * the control characters and private-use glyphs that PDF extraction
     * tends to leave behind.
     */
    public static function tidy(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/[\x{0000}-\x{0008}\x{000B}\x{000C}\x{000E}-\x{001F}\x{E000}-\x{F8FF}]/u', '', $text) ?? $text;

        // Normalise the punctuation Word and PDF love to use.
        $text = strtr($text, [
            "\u{00A0}" => ' ', "\u{2019}" => "'", "\u{2018}" => "'",
            "\u{201C}" => '"', "\u{201D}" => '"', "\u{2013}" => '-',
            "\u{2014}" => '-', "\u{2026}" => '...', "\u{FB01}" => 'fi',
            "\u{FB02}" => 'fl', "\u{2022}" => "\u{2022}",
        ]);

        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        $text = preg_replace('/ ?\n ?/', "\n", $text) ?? $text;
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;

        return trim($text);
    }

    /**
     * Split text into overlapping chunks on paragraph boundaries.
     *
     * The overlap matters: a price and the tax note that qualifies it are
     * often in adjacent paragraphs, and a split between them would hand the
     * model a figure with no tax context.
     *
     * @return array<int,array{index:int,text:string,offset:int}>
     */
    public static function chunk(string $text, int $target = 6000, int $overlap = 400): array
    {
        $text = trim($text);
        if ($text === '') {
            return [];
        }
        if (mb_strlen($text) <= $target) {
            return [['index' => 0, 'text' => $text, 'offset' => 0]];
        }

        $paragraphs = preg_split('/\n{2,}/', $text) ?: [$text];
        $chunks  = [];
        $current = '';
        $offset  = 0;
        $cursor  = 0;

        $flush = static function () use (&$chunks, &$current, &$offset, $overlap): void {
            $body = trim($current);
            if ($body === '') {
                return;
            }
            $chunks[] = ['index' => count($chunks), 'text' => $body, 'offset' => $offset];
            $tail = mb_substr($body, max(0, mb_strlen($body) - $overlap));
            $offset += max(1, mb_strlen($body) - mb_strlen($tail));
            $current = $tail . "\n\n";
        };

        foreach ($paragraphs as $paragraph) {
            $paragraph = trim($paragraph);
            if ($paragraph === '') {
                continue;
            }

            // A single oversized paragraph, such as a long table, is cut on
            // line boundaries rather than mid-sentence.
            if (mb_strlen($paragraph) > $target) {
                foreach (preg_split('/\n/', $paragraph) ?: [] as $line) {
                    if (mb_strlen($current) + mb_strlen($line) + 1 > $target) {
                        $flush();
                    }
                    $current .= $line . "\n";
                }
                continue;
            }

            if (mb_strlen($current) + mb_strlen($paragraph) + 2 > $target) {
                $flush();
            }
            $current .= $paragraph . "\n\n";
            $cursor += mb_strlen($paragraph);
        }

        $flush();

        return $chunks;
    }
}
