<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DocumentCompressionService
{
    /**
     * Maximum width or height in pixels for compressed images.
     */
    protected int $maxImageDimension = 1920;

    /**
     * Default JPEG quality (1-100). 78-82 gives excellent visual quality with 70-90% size reduction.
     */
    protected int $jpegQuality = 80;

    /**
     * Default WEBP quality (1-100).
     */
    protected int $webpQuality = 80;

    /**
     * Default PNG compression level (0-9).
     */
    protected int $pngCompression = 8;

    /**
     * Store and compress an uploaded file into the specified folder on storage disk.
     *
     * @param UploadedFile $file
     * @param string $folder e.g. 'candidates/1/documents' or 'company/2' or 'drive'
     * @param string $disk Storage disk name (default 'public')
     * @param array $options Optional configuration overrides
     * @return array ['path' => string, 'file_name' => string, 'file_size' => string, 'size_bytes' => int, 'mime_type' => string, 'was_compressed' => bool]
     */
    public function storeAndCompress(
        UploadedFile $file,
        string $folder,
        string $disk = 'public',
        array $options = []
    ): array {
        @set_time_limit(120);
        @ini_set('memory_limit', '512M');

        $originalName = $file->getClientOriginalName();
        $mimeType = $file->getMimeType() ?: 'application/octet-stream';
        $originalSize = $file->getSize();
        $extension = strtolower($file->getClientOriginalExtension() ?: pathinfo($originalName, PATHINFO_EXTENSION));

        $maxDim = $options['max_dimension'] ?? $this->maxImageDimension;
        $jpegQ = $options['jpeg_quality'] ?? $this->jpegQuality;
        $webpQ = $options['webp_quality'] ?? $this->webpQuality;

        $tempOutputPath = null;
        $outputExt = $extension;
        $wasCompressed = false;

        try {
            if (in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'gif', 'bmp']) && function_exists('imagecreatefromstring')) {
                $compressResult = $this->compressImage($file->getRealPath(), $extension, $maxDim, $jpegQ, $webpQ);
                if ($compressResult) {
                    $tempOutputPath = $compressResult['path'];
                    $outputExt = $compressResult['extension'];
                }
            } elseif ($extension === 'pdf') {
                $tempOutputPath = $this->compressPdf($file->getRealPath());
                $outputExt = 'pdf';
            } elseif (in_array($extension, ['mp4', 'mov', 'avi', 'mkv', 'webm', 'wmv', '3gp', 'flv', 'm4v'])) {
                $tempOutputPath = $this->compressVideo($file->getRealPath(), $options);
                if ($tempOutputPath && file_exists($tempOutputPath)) {
                    $outputExt = 'mp4';
                    $mimeType = 'video/mp4';
                }
            }

            $targetFileName = Str::random(40) . ($outputExt ? '.' . $outputExt : '');

            // Check if compressed file exists and is actually smaller than the original
            if ($tempOutputPath && file_exists($tempOutputPath)) {
                $compressedSize = filesize($tempOutputPath);
                if ($compressedSize > 0 && $compressedSize < $originalSize) {
                    $storagePath = trim($folder, '/') . '/' . $targetFileName;
                    $stream = fopen($tempOutputPath, 'r');
                    if ($stream) {
                        Storage::disk($disk)->put($storagePath, $stream);
                        if (is_resource($stream)) {
                            fclose($stream);
                        }
                    } else {
                        Storage::disk($disk)->put($storagePath, file_get_contents($tempOutputPath));
                    }
                    $finalSize = $compressedSize;
                    $wasCompressed = true;
                } else {
                    // Original was already smaller or equal, use original
                    $fallbackName = Str::random(40) . ($extension ? '.' . $extension : '');
                    $storagePath = $file->storeAs(trim($folder, '/'), $fallbackName, $disk);
                    $finalSize = $originalSize;
                }
            } else {
                // Compression not applicable or produced no output, store original
                $fallbackName = Str::random(40) . ($extension ? '.' . $extension : '');
                $storagePath = $file->storeAs(trim($folder, '/'), $fallbackName, $disk);
                $finalSize = $originalSize;
            }
        } catch (\Throwable $e) {
            Log::warning('Document compression failed, falling back to standard upload: ' . $e->getMessage(), [
                'file' => $originalName,
            ]);
            $fallbackName = Str::random(40) . ($extension ? '.' . $extension : '');
            $storagePath = $file->storeAs(trim($folder, '/'), $fallbackName, $disk);
            $finalSize = $originalSize;
        } finally {
            if ($tempOutputPath && file_exists($tempOutputPath)) {
                @unlink($tempOutputPath);
            }
        }

        return [
            'path'           => $storagePath,
            'file_name'      => $originalName,
            'file_size'      => $this->formatFileSize($finalSize),
            'size_bytes'     => $finalSize,
            'mime_type'      => $mimeType,
            'was_compressed' => $wasCompressed,
        ];
    }

    /**
     * Store and compress a base64 encoded image string (e.g. data:image/png;base64,...)
     *
     * @param string $base64String
     * @param string $folder
     * @param string $disk
     * @param string $prefix
     * @param array $options
     * @return string|null The stored relative path on disk, or null on failure
     */
    public function storeBase64AndCompress(
        string $base64String,
        string $folder,
        string $disk = 'public',
        string $prefix = 'img_',
        array $options = []
    ): ?string {
        $imageParts = explode(';base64,', $base64String);
        if (count($imageParts) !== 2) {
            return null;
        }

        $imageTypeAux = explode('image/', $imageParts[0]);
        $rawExt = explode(';', $imageTypeAux[1] ?? 'png')[0];
        $extension = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $rawExt)) ?: 'png';
        if ($extension === 'jpeg') {
            $extension = 'jpg';
        }

        $binaryData = base64_decode($imageParts[1]);
        if ($binaryData === false) {
            return null;
        }

        return $this->storeBinaryAndCompress($binaryData, $extension, $folder, $disk, $prefix, $options);
    }

    /**
     * Store and compress binary image data into storage.
     */
    public function storeBinaryAndCompress(
        string $binaryData,
        string $extension,
        string $folder,
        string $disk = 'public',
        string $prefix = 'img_',
        array $options = []
    ): ?string {
        $maxDim = $options['max_dimension'] ?? $this->maxImageDimension;
        $jpegQ = $options['jpeg_quality'] ?? $this->jpegQuality;
        $webpQ = $options['webp_quality'] ?? $this->webpQuality;

        $tempSource = null;
        $tempOutput = null;
        $outputExt = $extension;

        try {
            if (in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'gif', 'bmp']) && function_exists('imagecreatefromstring')) {
                $tempSource = tempnam(sys_get_temp_dir(), 'b64_src_');
                file_put_contents($tempSource, $binaryData);
                $originalSize = strlen($binaryData);

                $compressResult = $this->compressImage($tempSource, $extension, $maxDim, $jpegQ, $webpQ);

                if ($compressResult && file_exists($compressResult['path'])) {
                    $tempOutput = $compressResult['path'];
                    $outputExt = $compressResult['extension'];
                    $compressedSize = filesize($tempOutput);
                    if ($compressedSize > 0 && $compressedSize < $originalSize) {
                        $targetFileName = trim($folder, '/') . '/' . uniqid($prefix, true) . '.' . $outputExt;
                        Storage::disk($disk)->put($targetFileName, file_get_contents($tempOutput));
                        return $targetFileName;
                    }
                }
            }

            // Fallback: save raw binary data
            $targetFileName = trim($folder, '/') . '/' . uniqid($prefix, true) . '.' . $extension;
            Storage::disk($disk)->put($targetFileName, $binaryData);
            return $targetFileName;
        } catch (\Throwable $e) {
            Log::warning('Base64 image compression failed: ' . $e->getMessage());
            $targetFileName = trim($folder, '/') . '/' . uniqid($prefix, true) . '.' . $extension;
            Storage::disk($disk)->put($targetFileName, $binaryData);
            return $targetFileName;
        } finally {
            if ($tempSource && file_exists($tempSource)) {
                @unlink($tempSource);
            }
            if ($tempOutput && file_exists($tempOutput)) {
                @unlink($tempOutput);
            }
        }
    }

    /**
     * Compress and optimize an image file. Returns array with temp path and extension.
     */
    protected function compressImage(
        string $sourcePath,
        string $extension,
        int $maxDimension,
        int $jpegQuality,
        int $webpQuality
    ): ?array {
        $data = @file_get_contents($sourcePath);
        if (!$data) {
            return null;
        }

        $image = @imagecreatefromstring($data);
        if (!$image) {
            return null;
        }

        // Auto-orient based on EXIF if JPEG
        if (in_array($extension, ['jpg', 'jpeg']) && function_exists('exif_read_data')) {
            $image = $this->fixExifOrientation($image, $sourcePath);
        }

        @ini_set('memory_limit', '512M');

        // Get original dimensions
        $width = imagesx($image);
        $height = imagesy($image);

        // Resize if larger than maxDimension
        if ($width > $maxDimension || $height > $maxDimension) {
            if ($width > $height) {
                $newWidth = $maxDimension;
                $newHeight = (int) round(($height / $width) * $maxDimension);
            } else {
                $newHeight = $maxDimension;
                $newWidth = (int) round(($width / $height) * $maxDimension);
            }

            $newImage = imagecreatetruecolor($newWidth, $newHeight);

            // Handle transparency for PNG / WEBP / GIF vs white background for JPEG / documents
            if (in_array($extension, ['png', 'webp', 'gif'])) {
                imagealphablending($newImage, false);
                imagesavealpha($newImage, true);
                $transparent = imagecolorallocatealpha($newImage, 255, 255, 255, 127);
                imagefilledrectangle($newImage, 0, 0, $newWidth, $newHeight, $transparent);
            } else {
                $bg = imagecolorallocate($newImage, 255, 255, 255);
                imagefilledrectangle($newImage, 0, 0, $newWidth, $newHeight, $bg);
            }

            imagecopyresampled($newImage, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
            imagedestroy($image);
            $image = $newImage;
        }

        $tempFile = tempnam(sys_get_temp_dir(), 'cmp_img_');
        $outputExt = $extension;

        switch ($extension) {
            case 'jpg':
            case 'jpeg':
                imagejpeg($image, $tempFile, $jpegQuality);
                $outputExt = 'jpg';
                break;
            case 'png':
                // Check if PNG has transparent pixels or if it's a photographic/scanned agreement document
                $hasAlpha = $this->imageHasTransparency($image);
                if ($hasAlpha) {
                    imagealphablending($image, false);
                    imagesavealpha($image, true);
                    imagepng($image, $tempFile, $this->pngCompression);
                    $outputExt = 'png';
                } else {
                    // For opaque documents/photos uploaded as PNG, converting to high-res JPEG reduces file size by 85-90%
                    $jpgCanvas = imagecreatetruecolor(imagesx($image), imagesy($image));
                    $white = imagecolorallocate($jpgCanvas, 255, 255, 255);
                    imagefilledrectangle($jpgCanvas, 0, 0, imagesx($image), imagesy($image), $white);
                    imagecopy($jpgCanvas, $image, 0, 0, 0, 0, imagesx($image), imagesy($image));
                    imagejpeg($jpgCanvas, $tempFile, $jpegQuality);
                    imagedestroy($jpgCanvas);
                    $outputExt = 'jpg';
                }
                break;
            case 'webp':
                if (function_exists('imagewebp')) {
                    imagewebp($image, $tempFile, $webpQuality);
                    $outputExt = 'webp';
                } else {
                    imagejpeg($image, $tempFile, $jpegQuality);
                    $outputExt = 'jpg';
                }
                break;
            case 'gif':
                imagegif($image, $tempFile);
                $outputExt = 'gif';
                break;
            case 'bmp':
                imagejpeg($image, $tempFile, $jpegQuality);
                $outputExt = 'jpg';
                break;
            default:
                imagejpeg($image, $tempFile, $jpegQuality);
                $outputExt = 'jpg';
                break;
        }

        imagedestroy($image);
        return [
            'path'      => $tempFile,
            'extension' => $outputExt,
        ];
    }

    /**
     * Check if a GD image resource contains any transparent pixels.
     */
    protected function imageHasTransparency($image): bool
    {
        $w = imagesx($image);
        $h = imagesy($image);
        
        // Fast sample check around corners and center
        $samplePoints = [
            [0, 0], [$w - 1, 0], [0, $h - 1], [$w - 1, $h - 1],
            [(int)($w / 2), (int)($h / 2)],
            [(int)($w / 4), (int)($h / 4)],
            [(int)($w * 3 / 4), (int)($h * 3 / 4)],
        ];

        foreach ($samplePoints as [$x, $y]) {
            if ($x >= 0 && $x < $w && $y >= 0 && $y < $h) {
                $rgba = imagecolorat($image, $x, $y);
                $alpha = ($rgba & 0x7F000000) >> 24;
                if ($alpha > 0) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Compress PDF files via Ghostscript (if installed) or pure PHP PDF stream optimizer.
     */
    protected function compressPdf(string $sourcePath): ?string
    {
        // 1. Try Ghostscript if installed on server
        $gsExecutable = $this->findGhostscriptExecutable();
        if ($gsExecutable) {
            $tempFile = tempnam(sys_get_temp_dir(), 'cmp_pdf_') . '.pdf';
            $cmd = sprintf(
                '%s -sDEVICE=pdfwrite -dCompatibilityLevel=1.4 -dPDFSETTINGS=/ebook -dNOPAUSE -dQUIET -dBATCH -sOutputFile=%s %s 2>&1',
                escapeshellarg($gsExecutable),
                escapeshellarg($tempFile),
                escapeshellarg($sourcePath)
            );

            @exec($cmd, $output, $returnCode);

            if ($returnCode === 0 && file_exists($tempFile) && filesize($tempFile) > 0) {
                return $tempFile;
            }

            if (file_exists($tempFile)) {
                @unlink($tempFile);
            }
        }

        // 2. Pure PHP PDF stream and embedded image compressor
        return $this->compressPdfPurePhp($sourcePath);
    }

    /**
     * Pure PHP PDF stream compressor. Compresses embedded DCT/Flate image streams safely.
     */
    protected function compressPdfPurePhp(string $sourcePath): ?string
    {
        @ini_set('memory_limit', '512M');
        $pdfData = @file_get_contents($sourcePath);
        if (!$pdfData || !str_starts_with($pdfData, '%PDF-')) {
            return null;
        }

        // Safety Guard 1: Password-protected or encrypted PDFs must never be modified
        if (strpos($pdfData, '/Encrypt') !== false) {
            return null;
        }

        // Safety Guard 2: Linearized PDFs (Fast Web View) have fixed offset tables
        if (preg_match('/\/Linearized\s+\d+/i', $pdfData)) {
            return null;
        }

        // Safety Guard 3: Modern vector/text PDFs (e.g. Word/Canva CV exports) use compressed Object Streams (/ObjStm),
        // XRef streams (/XRef), or indirect length objects (/Length 5 0 R). They are already ultra-compact.
        if (strpos($pdfData, '/ObjStm') !== false || strpos($pdfData, '/XRef') !== false || preg_match('/\/Length\s+\d+\s+\d+\s+R/', $pdfData)) {
            return null;
        }

        // Safety Guard 4: PDFs with incremental updates (/Prev in trailer)
        if (preg_match('/\/Prev\s+\d+/i', $pdfData)) {
            return null;
        }

        $len = strlen($pdfData);
        $offset = 0;
        $modified = false;
        $result = '';
        $maxDimension = $this->maxImageDimension;
        $jpegQuality = $this->jpegQuality;

        while ($offset < $len) {
            $streamPos = strpos($pdfData, 'stream', $offset);
            if ($streamPos === false) {
                $result .= substr($pdfData, $offset);
                break;
            }

            $objPos = strrpos(substr($pdfData, 0, $streamPos), 'obj');
            if ($objPos === false || $objPos < $offset) {
                $result .= substr($pdfData, $offset, $streamPos + 6 - $offset);
                $offset = $streamPos + 6;
                continue;
            }

            $header = substr($pdfData, $offset, $streamPos - $offset);
            
            $dataStart = $streamPos + 6;
            if (substr($pdfData, $dataStart, 2) === "\r\n") {
                $dataStart += 2;
                $streamDelim = "\r\n";
            } elseif (substr($pdfData, $dataStart, 1) === "\n" || substr($pdfData, $dataStart, 1) === "\r") {
                $streamDelim = substr($pdfData, $dataStart, 1);
                $dataStart += 1;
            } else {
                $streamDelim = "\n";
            }

            $dict = substr($pdfData, $objPos, $streamPos - $objPos);

            $streamLen = null;
            if (preg_match('/\/Length\s+(\d+)/i', $dict, $m)) {
                $streamLen = (int) $m[1];
            }

            if ($streamLen !== null && ($dataStart + $streamLen <= $len)) {
                $endstreamPos = $dataStart + $streamLen;
                $checkPos = strpos($pdfData, 'endstream', $endstreamPos - 10);
                if ($checkPos !== false && abs($checkPos - $endstreamPos) < 20) {
                    $endstreamPos = $checkPos;
                } else {
                    $checkPos = strpos($pdfData, 'endstream', $dataStart);
                    if ($checkPos !== false) {
                        $endstreamPos = $checkPos;
                    }
                }
            } else {
                $endstreamPos = strpos($pdfData, 'endstream', $dataStart);
            }

            if ($endstreamPos === false) {
                $result .= substr($pdfData, $offset);
                break;
            }

            $streamData = substr($pdfData, $dataStart, $endstreamPos - $dataStart);
            $streamData = rtrim($streamData, "\r\n");

            $isImage = (bool) preg_match('/\/Subtype\s*\/Image/i', $dict);
            $isDCT = (bool) preg_match('/\/Filter\s*\/DCTDecode/i', $dict);
            $isFlate = (bool) preg_match('/\/Filter\s*\/FlateDecode/i', $dict);
            $hasMask = (bool) (preg_match('/\/SMask\s+/i', $dict) || preg_match('/\/Mask\s+/i', $dict));

            $streamReplaced = false;

            if ($isImage && ($isDCT || $isFlate)) {
                $rawImage = null;
                if ($isDCT) {
                    $rawImage = $streamData;
                } elseif ($isFlate) {
                    $rawImage = @gzuncompress($streamData);
                }

                if ($rawImage) {
                    $gdImg = @imagecreatefromstring($rawImage);
                    if ($gdImg) {
                        $w = imagesx($gdImg);
                        $h = imagesy($gdImg);

                        // If the image has an SMask or Mask, do NOT resize dimensions to avoid mask mismatch
                        $canResize = !$hasMask && ($w > $maxDimension || $h > $maxDimension);

                        if ($canResize) {
                            if ($w > $h) {
                                $newW = $maxDimension;
                                $newH = (int) round(($h / $w) * $maxDimension);
                            } else {
                                $newH = $maxDimension;
                                $newW = (int) round(($w / $h) * $maxDimension);
                            }
                            $canvas = imagecreatetruecolor($newW, $newH);
                            $bg = imagecolorallocate($canvas, 255, 255, 255);
                            imagefilledrectangle($canvas, 0, 0, $newW, $newH, $bg);
                            imagecopyresampled($canvas, $gdImg, 0, 0, 0, 0, $newW, $newH, $w, $h);
                            imagedestroy($gdImg);
                            $gdImg = $canvas;
                            $w = $newW;
                            $h = $newH;
                        }

                        ob_start();
                        imagejpeg($gdImg, null, $jpegQuality);
                        $newJpg = ob_get_clean();
                        imagedestroy($gdImg);

                        if ($newJpg && strlen($newJpg) < strlen($streamData)) {
                            $newLen = strlen($newJpg);
                            $dictPart = substr($header, $objPos - $offset);
                            $preDict = substr($header, 0, $objPos - $offset);

                            $dictPart = preg_replace('/\/Width\s+\d+/i', '/Width ' . $w, $dictPart);
                            $dictPart = preg_replace('/\/Height\s+\d+/i', '/Height ' . $h, $dictPart);
                            $dictPart = preg_replace('/\/Length\s+\d+/i', '/Length ' . $newLen, $dictPart);

                            // Update Filter to DCTDecode
                            if (!preg_match('/\/Filter\s*\/DCTDecode/i', $dictPart)) {
                                if (preg_match('/\/Filter\s*\/[a-zA-Z0-9_]+/i', $dictPart)) {
                                    $dictPart = preg_replace('/\/Filter\s*\/[a-zA-Z0-9_]+/i', '/Filter /DCTDecode', $dictPart);
                                } else {
                                    $dictPart = preg_replace('/(<<)/', '$1 /Filter /DCTDecode', $dictPart, 1);
                                }
                            }

                            // Sync ColorSpace to DeviceRGB since output JPEG is 3-channel RGB
                            if (preg_match('/\/ColorSpace\s*\/[a-zA-Z0-9_]+/i', $dictPart)) {
                                $dictPart = preg_replace('/\/ColorSpace\s*\/[a-zA-Z0-9_]+/i', '/ColorSpace /DeviceRGB', $dictPart);
                            } elseif (!preg_match('/\/ColorSpace/i', $dictPart)) {
                                $dictPart = preg_replace('/(<<)/', '$1 /ColorSpace /DeviceRGB', $dictPart, 1);
                            }

                            // Ensure BitsPerComponent is 8
                            if (preg_match('/\/BitsPerComponent\s+\d+/i', $dictPart)) {
                                $dictPart = preg_replace('/\/BitsPerComponent\s+\d+/i', '/BitsPerComponent 8', $dictPart);
                            } elseif (!preg_match('/\/BitsPerComponent/i', $dictPart)) {
                                $dictPart = preg_replace('/(<<)/', '$1 /BitsPerComponent 8', $dictPart, 1);
                            }

                            $result .= $preDict . $dictPart . 'stream' . $streamDelim . $newJpg . $streamDelim;
                            $streamReplaced = true;
                            $modified = true;
                        }
                    }
                }
            }

            if (!$streamReplaced) {
                $result .= $header . 'stream' . $streamDelim . $streamData . $streamDelim;
            }

            $endstreamEnd = $endstreamPos + strlen('endstream');
            $result .= 'endstream';
            $offset = $endstreamEnd;
        }

        if (!$modified) {
            return null;
        }

        $validPdf = $this->rebuildPdfXref($result);
        if (!$validPdf || !str_contains($validPdf, 'startxref') || !str_contains($validPdf, '%%EOF')) {
            return null;
        }

        $tempFile = tempnam(sys_get_temp_dir(), 'cmp_pdf_') . '.pdf';
        file_put_contents($tempFile, $validPdf);
        return $tempFile;
    }

    /**
     * Rebuilds PDF xref cross-reference table and trailer for clean PDF standard compliance.
     */
    protected function rebuildPdfXref(string $cleanPdf): string
    {
        $xrefPos = strrpos($cleanPdf, 'xref');
        if ($xrefPos !== false) {
            $body = substr($cleanPdf, 0, $xrefPos);
        } else {
            $body = $cleanPdf;
        }

        $trailerDict = '<< /Size 1 /Root 1 0 R >>';
        if (preg_match('/trailer\s*(<<.*?>>)/s', $cleanPdf, $tm)) {
            $trailerDict = $tm[1];
        }

        $offsets = [];
        $maxObjNum = 0;
        
        preg_match_all('/(\d+)\s+(\d+)\s+obj/m', $body, $matches, PREG_OFFSET_CAPTURE);
        
        if (empty($matches[0])) {
            return $cleanPdf;
        }

        foreach ($matches[0] as $i => $match) {
            $objNum = (int) $matches[1][$i][0];
            $byteOffset = $match[1];
            $offsets[$objNum] = $byteOffset;
            if ($objNum > $maxObjNum) {
                $maxObjNum = $objNum;
            }
        }

        $size = $maxObjNum + 1;
        $newTrailerDict = preg_replace('/\/Size\s+\d+/i', '/Size ' . $size, $trailerDict);
        if (!preg_match('/\/Size/i', $newTrailerDict)) {
            $newTrailerDict = preg_replace('/(<<)/', '$1 /Size ' . $size, $newTrailerDict, 1);
        }

        $startXref = strlen($body);
        $xref = "\nxref\n0 {$size}\n";
        $xref .= "0000000000 65535 f \n";

        for ($i = 1; $i <= $maxObjNum; $i++) {
            if (isset($offsets[$i])) {
                $xref .= sprintf("%010d 00000 n \n", $offsets[$i]);
            } else {
                $xref .= "0000000000 65535 f \n";
            }
        }

        $xref .= "trailer\n" . $newTrailerDict . "\n";
        $xref .= "startxref\n" . $startXref . "\n%%EOF\n";

        return $body . $xref;
    }

    /**
     * Find Ghostscript executable path if installed.
     */
    protected function findGhostscriptExecutable(): ?string
    {
        $candidates = [
            'gs',
            'gswin64c',
            'gswin32c',
            'C:\\Program Files\\gs\\gs*\\bin\\gswin64c.exe',
            'C:\\Program Files (x86)\\gs\\gs*\\bin\\gswin32c.exe',
        ];

        foreach ($candidates as $cmd) {
            if (str_contains($cmd, '*')) {
                $matches = glob($cmd);
                if (!empty($matches)) {
                    return $matches[0];
                }
            } else {
                $check = PHP_OS_FAMILY === 'Windows' ? "where {$cmd} 2>NUL" : "which {$cmd} 2>/dev/null";
                $res = @shell_exec($check);
                if ($res && trim($res) !== '') {
                    return trim(explode("\n", trim($res))[0]);
                }
            }
        }

        return null;
    }

    /**
     * Rotate image if EXIF orientation flags exist.
     */
    protected function fixExifOrientation($image, string $sourcePath)
    {
        try {
            $exif = @exif_read_data($sourcePath);
            if (!empty($exif['Orientation'])) {
                switch ($exif['Orientation']) {
                    case 3:
                        $image = imagerotate($image, 180, 0);
                        break;
                    case 6:
                        $image = imagerotate($image, -90, 0);
                        break;
                    case 8:
                        $image = imagerotate($image, 90, 0);
                        break;
                }
            }
        } catch (\Throwable) {
            // Ignore EXIF read errors
        }

        return $image;
    }

    /**
     * Compress a video file using FFmpeg with visually lossless H.264 settings (CRF 24),
     * AAC audio, faststart metadata for instant web streaming, and 1080p max resolution.
     *
     * @param string $sourcePath Path to the uploaded temporary video file
     * @param array $options Optional overrides (e.g. crf, max_height)
     * @return string|null Path to compressed video file, or null if compression skipped/failed
     */
    public function compressVideo(string $sourcePath, array $options = []): ?string
    {
        $ffmpeg = $this->findFfmpegExecutable();
        if (!$ffmpeg) {
            Log::info('FFmpeg executable not found. Storing original video without re-encoding.');
            return null;
        }

        if (!file_exists($sourcePath) || filesize($sourcePath) === 0) {
            return null;
        }

        // Allow up to 300 seconds for video encoding
        @set_time_limit(300);

        $tempOutput = tempnam(sys_get_temp_dir(), 'rcg_vid_') . '.mp4';

        $crf = $options['video_crf'] ?? 24; // 23-25 is visually lossless for standard human viewing
        $preset = $options['video_preset'] ?? 'fast';
        $maxDimension = $options['max_video_dimension'] ?? 1920;

        // Video filter: cap max width to 1920 while maintaining aspect ratio and ensuring even pixel dimensions
        $vf = "scale=min({$maxDimension}\,iw):-2";

        // Build command with -nostdin and output redirection.
        // On Windows PHP, proc_open pipe reading can deadlock on FFmpeg's large stderr stream.
        // exec() with 2>&1 or redirected stderr avoids pipe blocking completely.
        $cmd = sprintf(
            '%s -nostdin -y -i %s -c:v libx264 -crf %d -preset %s -vf %s -c:a aac -b:a 128k -movflags +faststart %s 2>&1',
            escapeshellarg($ffmpeg),
            escapeshellarg($sourcePath),
            (int) $crf,
            escapeshellarg($preset),
            escapeshellarg($vf),
            escapeshellarg($tempOutput)
        );

        try {
            $outputLines = [];
            $exitCode = -1;
            @exec($cmd, $outputLines, $exitCode);

            if ($exitCode === 0 && file_exists($tempOutput) && filesize($tempOutput) > 0) {
                return $tempOutput;
            }

            Log::warning('FFmpeg encoding finished with non-zero exit code: ' . $exitCode, [
                'output' => implode("\n", array_slice($outputLines, -15)),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Video compression failed: ' . $e->getMessage());
        }

        if (file_exists($tempOutput)) {
            @unlink($tempOutput);
        }

        return null;
    }

    /**
     * Locate the FFmpeg executable on the server.
     */
    protected function findFfmpegExecutable(): ?string
    {
        // 1. Check environment or config override if defined
        $customPath = config('services.ffmpeg.path') ?? env('FFMPEG_PATH');
        if ($customPath && file_exists($customPath)) {
            return $customPath;
        }

        // 2. Check system PATH via 'where' (Windows) or 'which' (Linux/macOS)
        $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
        $whichCmd = $isWindows ? 'where ffmpeg 2>nul' : 'which ffmpeg 2>/dev/null';
        $output = @shell_exec($whichCmd);
        if ($output) {
            $lines = array_filter(array_map('trim', explode("\n", $output)));
            foreach ($lines as $line) {
                if (file_exists($line)) {
                    return $line;
                }
            }
        }

        // 3. Common fallback locations
        $commonPaths = [
            'C:\\ffmpeg\\bin\\ffmpeg.exe',
            'C:\\Program Files\\ffmpeg\\bin\\ffmpeg.exe',
            'C:\\ProgramData\\chocolatey\\bin\\ffmpeg.exe',
            '/usr/bin/ffmpeg',
            '/usr/local/bin/ffmpeg',
        ];

        foreach ($commonPaths as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Convert bytes to human readable format.
     */
    public function formatFileSize(int $bytes): string
    {
        if ($bytes <= 0) {
            return '0 B';
        }
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = (int) floor(log($bytes, 1024));
        $i = min($i, count($units) - 1);
        return round($bytes / pow(1024, $i), 2) . ' ' . $units[$i];
    }
}

