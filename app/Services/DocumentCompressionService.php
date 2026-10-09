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
            }

            $targetFileName = Str::random(40) . ($outputExt ? '.' . $outputExt : '');

            // Check if compressed file exists and is actually smaller than the original
            if ($tempOutputPath && file_exists($tempOutputPath)) {
                $compressedSize = filesize($tempOutputPath);
                if ($compressedSize > 0 && $compressedSize < $originalSize) {
                    $storagePath = trim($folder, '/') . '/' . $targetFileName;
                    Storage::disk($disk)->put($storagePath, file_get_contents($tempOutputPath));
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
        $extension = strtolower($imageTypeAux[1] ?? 'png');
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
     * Attempt PDF compression via Ghostscript if installed on server.
     */
    protected function compressPdf(string $sourcePath): ?string
    {
        $gsExecutable = $this->findGhostscriptExecutable();
        if (!$gsExecutable) {
            return null;
        }

        $tempFile = tempnam(sys_get_temp_dir(), 'cmp_pdf_') . '.pdf';
        
        // Settings: /ebook = ~150 DPI (optimal for documents), /screen = 72 DPI (smaller), /prepress = 300 DPI
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

        return null;
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
