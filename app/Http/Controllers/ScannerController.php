<?php

namespace App\Http\Controllers;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ScannerController extends Controller
{
    /**
     * Get list of connected WIA / TWAIN scanner devices on the Windows host.
     */
    public function devices(): JsonResponse
    {
        // Detect Windows OS
        if (strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN') {
            return response()->json([
                'success' => true,
                'isWindows' => false,
                'devices' => [],
                'message' => 'Scanner direct acquisition is supported on Windows hosts or via Web Camera scanner.',
            ]);
        }

        try {
            $psScript = <<<'POWERSHELL'
[Console]::OutputEncoding = [System.Text.Encoding]::UTF8;
try {
    $mgr = New-Object -ComObject WIA.DeviceManager
    $scanners = @()
    foreach ($info in $mgr.DeviceInfos) {
        if ($info.Type -eq 1) { # 1 = ScannerDeviceType
            $name = "Unknown Scanner"
            $mfg = "Generic"
            try { $name = $info.Properties.Item("Name").Value } catch {}
            try { $mfg = $info.Properties.Item("Manufacturer").Value } catch {}
            $scanners += @{
                id = $info.DeviceID
                name = "$name"
                manufacturer = "$mfg"
                type = "scanner"
            }
        }
    }
    $res = @{ success = $true; count = $scanners.Count; devices = $scanners }
    $res | ConvertTo-Json -Compress
} catch {
    @{ success = $false; error = $_.Exception.Message; devices = @() } | ConvertTo-Json -Compress
}
POWERSHELL;

            $tempPs = tempnam(sys_get_temp_dir(), 'wia_dev_') . '.ps1';
            file_put_contents($tempPs, $psScript);

            $cmd = 'powershell.exe -NoProfile -ExecutionPolicy Bypass -File "' . $tempPs . '"';
            $output = shell_exec($cmd);
            @unlink($tempPs);

            if ($output) {
                $json = json_decode(trim($output), true);
                if (is_array($json)) {
                    return response()->json([
                        'success' => true,
                        'isWindows' => true,
                        'count' => count($json['devices'] ?? []),
                        'devices' => $json['devices'] ?? [],
                    ]);
                }
            }

            return response()->json([
                'success' => true,
                'isWindows' => true,
                'count' => 0,
                'devices' => [],
                'message' => 'No physical scanners detected by Windows WIA. You can use the Live Document Camera scanner.',
            ]);
        } catch (Exception $e) {
            Log::error('Scanner device list error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'isWindows' => true,
                'devices' => [],
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Trigger a scan on a connected flatbed/document scanner and return the image data in-memory.
     */
    public function scan(Request $request): JsonResponse
    {
        $deviceId = $request->input('deviceId');
        $colorMode = $request->input('colorMode', 'color'); // 'color', 'grayscale', 'bw'
        $dpi = (int) $request->input('dpi', 200); // 150, 200, 300
        $format = 'jpeg';

        if (strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN') {
            return response()->json([
                'success' => false,
                'message' => 'Direct hardware scanner trigger is only supported on Windows. Please use the Live Camera Scanner option.',
            ], 400);
        }

        $tempOutputFile = tempnam(sys_get_temp_dir(), 'wia_scan_') . '.jpg';
        @unlink($tempOutputFile); // Ensure destination path does not exist yet

        // WIA Color intent: 1 = Color, 2 = Grayscale, 4 = Black & White
        $wiaIntent = 1;
        if ($colorMode === 'grayscale') {
            $wiaIntent = 2;
        } elseif ($colorMode === 'bw') {
            $wiaIntent = 4;
        }

        $escapedDeviceId = $deviceId ? addslashes($deviceId) : '';
        $escapedOutputFile = addslashes($tempOutputFile);

        $psScript = <<<POWERSHELL
[Console]::OutputEncoding = [System.Text.Encoding]::UTF8;
try {
    \$dialog = New-Object -ComObject WIA.CommonDialog
    \$mgr = New-Object -ComObject WIA.DeviceManager
    
    \$device = \$null
    \$targetId = "$escapedDeviceId"

    if (\$targetId -and \$targetId.Length -gt 0) {
        foreach (\$info in \$mgr.DeviceInfos) {
            if (\$info.DeviceID -eq \$targetId) {
                \$device = \$info.Connect()
                break
            }
        }
    }

    \$image = \$null
    if (\$device -ne \$null) {
        # Configure scanner properties
        \$item = \$device.Items(1)
        try {
            # Intent: 1 = Color, 2 = Grayscale
            # 6146 = Current Intent
            \$item.Properties.Item("6146").Value = $wiaIntent
        } catch {}
        try {
            # 6147 = Horizontal Resolution, 6148 = Vertical Resolution
            \$item.Properties.Item("6147").Value = $dpi
            \$item.Properties.Item("6148").Value = $dpi
        } catch {}

        \$image = \$dialog.ShowTransfer(\$item, "{B96B3CAE-0728-11D3-9D7B-0000F81EF32E}", \$false)
    } else {
        # Fallback to interactive acquire or default scanner
        \$image = \$dialog.ShowAcquireImage(1, $wiaIntent, 131072, "{B96B3CAE-0728-11D3-9D7B-0000F81EF32E}", \$false, \$true, \$false)
    }

    if (\$image -ne \$null) {
        \$image.SaveFile("$escapedOutputFile")
        @{ success = \$true; path = "$escapedOutputFile" } | ConvertTo-Json -Compress
    } else {
        @{ success = \$false; error = "Scan was cancelled or no image returned." } | ConvertTo-Json -Compress
    }
} catch {
    @{ success = \$false; error = \$_.Exception.Message } | ConvertTo-Json -Compress
}
POWERSHELL;

        $tempPs = tempnam(sys_get_temp_dir(), 'wia_scan_script_') . '.ps1';
        file_put_contents($tempPs, $psScript);

        $cmd = 'powershell.exe -NoProfile -ExecutionPolicy Bypass -File "' . $tempPs . '"';
        $output = shell_exec($cmd);
        @unlink($tempPs);

        $resultJson = $output ? json_decode(trim($output), true) : null;

        if (file_exists($tempOutputFile) && filesize($tempOutputFile) > 0) {
            $rawBytes = file_get_contents($tempOutputFile);
            $base64 = base64_encode($rawBytes);
            $mime = 'image/jpeg';
            $size = strlen($rawBytes);

            // Immediately delete temporary scan from disk so nothing is stored in local storage
            @unlink($tempOutputFile);

            return response()->json([
                'success' => true,
                'mimeType' => $mime,
                'data' => 'data:' . $mime . ';base64,' . $base64,
                'size' => $size,
                'fileName' => 'scanned_passport_' . date('Ymd_His') . '.jpg',
                'message' => 'Passport scanned successfully.',
            ]);
        }

        // Clean up temp file if any
        if (file_exists($tempOutputFile)) {
            @unlink($tempOutputFile);
        }

        $errorMsg = $resultJson['error'] ?? 'Scanner did not return an image. Ensure the scanner is powered on, connected, and has a passport placed on the glass.';
        return response()->json([
            'success' => false,
            'message' => $errorMsg,
        ], 422);
    }
}
