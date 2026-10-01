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
            $devId = ""
            try { $name = $info.Properties.Item("Name").Value } catch {}
            try { $mfg = $info.Properties.Item("Manufacturer").Value } catch {}
            try { $devId = $info.DeviceID } catch {}
            
            $isCanon = ($name -match 'Canon|imageFORMULA|DR-' -or $mfg -match 'Canon')
            
            $scanners += @{
                id = $devId
                name = "$name"
                manufacturer = "$mfg"
                type = "scanner"
                isCanon = [bool]$isCanon
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
     * Trigger a scan on a connected Canon imageFORMULA DR scanner or flatbed scanner.
     */
    public function scan(Request $request): JsonResponse
    {
        $deviceId = $request->input('deviceId');
        $colorMode = $request->input('colorMode', 'color'); // 'color', 'grayscale', 'bw'
        $paperSource = $request->input('paperSource', 'auto'); // 'auto', 'feeder', 'flatbed'
        $dpi = (int) $request->input('dpi', 200);

        if (strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN') {
            return response()->json([
                'success' => false,
                'message' => 'Direct hardware scanner trigger is only supported on Windows. Please use the Live Camera Scanner option.',
            ], 400);
        }

        $tempOutputFile = tempnam(sys_get_temp_dir(), 'wia_scan_') . '.jpg';
        @unlink($tempOutputFile);

        // WIA Color intent: 1 = Color, 2 = Grayscale, 4 = Black & White
        $wiaIntent = 1;
        if ($colorMode === 'grayscale') {
            $wiaIntent = 2;
        } elseif ($colorMode === 'bw') {
            $wiaIntent = 4;
        }

        $escapedDeviceId = $deviceId ? addslashes($deviceId) : '';
        $escapedOutputFile = addslashes($tempOutputFile);
        $escapedPaperSource = addslashes($paperSource);

        $psScript = <<<POWERSHELL
[Console]::OutputEncoding = [System.Text.Encoding]::UTF8;
try {
    \$dialog = New-Object -ComObject WIA.CommonDialog
    \$mgr = New-Object -ComObject WIA.DeviceManager
    
    \$device = \$null
    \$targetId = "$escapedDeviceId"
    \$paperSource = "$escapedPaperSource"

    if (\$targetId -and \$targetId.Length -gt 0) {
        foreach (\$info in \$mgr.DeviceInfos) {
            if (\$info.DeviceID -eq \$targetId) {
                \$device = \$info.Connect()
                break
            }
        }
    }

    # If not found by exact ID, find first available scanner (preferring Canon imageFORMULA if present)
    if (\$device -eq \$null) {
        \$canonInfo = \$null
        foreach (\$info in \$mgr.DeviceInfos) {
            if (\$info.Type -eq 1) {
                \$name = ""
                try { \$name = \$info.Properties.Item("Name").Value } catch {}
                if (\$name -match "Canon|imageFORMULA|DR-") {
                    \$canonInfo = \$info
                    break
                }
                if (\$canonInfo -eq \$null) {
                    \$canonInfo = \$info
                }
            }
        }
        if (\$canonInfo -ne \$null) {
            \$device = \$canonInfo.Connect()
        }
    }

    \$image = \$null
    if (\$device -ne \$null) {
        # Check if device is Canon or sheet-fed document scanner
        \$devName = ""
        try { \$devName = \$device.Properties.Item("Name").Value } catch {}

        # Configure Root Device Document Handling (Feeder vs Flatbed for Canon DR scanners)
        try {
            # 3088 = WIA_DPS_DOCUMENT_HANDLING_SELECT (1=FEEDER, 2=FLATBED, 4=DUPLEX)
            # 3087 = WIA_DPS_DOCUMENT_HANDLING_STATUS
            if (\$paperSource -eq "feeder" -or (\$paperSource -eq "auto" -and (\$devName -match "Canon|imageFORMULA|DR-"))) {
                \$device.Properties.Item("3088").Value = 1 # Force FEEDER for Canon DR series
            } elseif (\$paperSource -eq "flatbed") {
                \$device.Properties.Item("3088").Value = 2 # FLATBED
            }
        } catch {
            # Some drivers do not expose 3088 at root level; ignore
        }

        # Configure scanner item properties
        \$item = \$device.Items(1)
        try {
            # 6146 = Current Intent (1=Color, 2=Gray)
            \$item.Properties.Item("6146").Value = $wiaIntent
        } catch {}
        try {
            # 6147 = Horizontal Resolution, 6148 = Vertical Resolution
            \$item.Properties.Item("6147").Value = $dpi
            \$item.Properties.Item("6148").Value = $dpi
        } catch {}

        try {
            # Transfer directly
            \$image = \$dialog.ShowTransfer(\$item, "{B96B3CAE-0728-11D3-9D7B-0000F81EF32E}", \$false)
        } catch {
            # If ShowTransfer fails, fallback to interactive Acquire Dialog
            \$image = \$dialog.ShowAcquireImage(1, $wiaIntent, 131072, "{B96B3CAE-0728-11D3-9D7B-0000F81EF32E}", \$false, \$true, \$false)
        }
    } else {
        # Fallback to interactive acquire
        \$image = \$dialog.ShowAcquireImage(1, $wiaIntent, 131072, "{B96B3CAE-0728-11D3-9D7B-0000F81EF32E}", \$false, \$true, \$false)
    }

    if (\$image -ne \$null) {
        \$image.SaveFile("$escapedOutputFile")
        @{ success = \$true; path = "$escapedOutputFile" } | ConvertTo-Json -Compress
    } else {
        @{ success = \$false; error = "Scan was cancelled or no image was returned by Canon imageFORMULA scanner." } | ConvertTo-Json -Compress
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

            // Immediately delete temporary scan from disk so nothing is stored locally
            @unlink($tempOutputFile);

            return response()->json([
                'success' => true,
                'mimeType' => $mime,
                'data' => 'data:' . $mime . ';base64,' . $base64,
                'size' => $size,
                'fileName' => 'canon_dr_scan_' . date('Ymd_His') . '.jpg',
                'message' => 'Canon imageFORMULA scanner scan completed successfully.',
            ]);
        }

        if (file_exists($tempOutputFile)) {
            @unlink($tempOutputFile);
        }

        $errorMsg = $resultJson['error'] ?? 'Canon scanner did not return an image. Ensure the Canon imageFORMULA DR scanner is powered on, USB cable is connected, and the passport is placed in the scanner feed slot/tray.';
        return response()->json([
            'success' => false,
            'message' => $errorMsg,
        ], 422);
    }
}
