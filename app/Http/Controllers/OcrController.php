<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OcrController extends Controller
{
    /**
     * Google Gemini model candidates to attempt in order of preference.
     */
    protected array $candidateModels = [
        'gemini-flash-lite-latest',
        'gemini-3.5-flash-lite',
        'gemini-3.1-flash-lite',
        'gemini-3.6-flash',
        'gemini-flash-latest',
    ];

    /**
     * Handle document OCR extraction using Google Gemini Vision.
     */
    public function extract(Request $request): JsonResponse
    {
        $file = $request->file('file') ?? $request->file('image');

        if (!$file || !$file->isValid()) {
            return response()->json([
                'success' => false,
                'fallbackToLocal' => true,
                'message' => 'No valid document file uploaded. Please provide an image or PDF file.',
            ], 400);
        }

        $apiKey = env('GEMINI_API_KEY')
            ?: $request->header('x-gemini-api-key')
            ?: config('services.gemini.key');

        if (!$apiKey || trim($apiKey) === '') {
            return response()->json([
                'success' => false,
                'fallbackToLocal' => true,
                'message' => 'GEMINI_API_KEY is not configured on the server. Fallback to local OCR.',
            ]);
        }

        $realPath = $file->getRealPath();
        $ext = strtolower($file->getClientOriginalExtension());
        $mimeType = $file->getMimeType() ?: 'image/jpeg';
        if ($ext === 'pdf') {
            $mimeType = 'application/pdf';
        }
        $base64Data = null;

        // Optimize high-resolution document scans to accelerate Gemini transfer (<4s response)
        if (str_starts_with($mimeType, 'image/') && function_exists('imagecreatefromstring')) {
            try {
                $rawContent = file_get_contents($realPath);
                $gdImg = @imagecreatefromstring($rawContent);
                if ($gdImg !== false) {
                    $w = imagesx($gdImg);
                    $h = imagesy($gdImg);
                    $maxDim = 1280;
                    if ($w > $maxDim || $h > $maxDim) {
                        $scale = min($maxDim / $w, $maxDim / $h);
                        $newW = (int) ($w * $scale);
                        $newH = (int) ($h * $scale);
                        $resized = imagecreatetruecolor($newW, $newH);
                        imagecopyresampled($resized, $gdImg, 0, 0, 0, 0, $newW, $newH, $w, $h);
                        ob_start();
                        imagejpeg($resized, null, 85);
                        $compressed = ob_get_clean();
                        imagedestroy($resized);
                        imagedestroy($gdImg);
                        if ($compressed && strlen($compressed) > 0) {
                            $base64Data = base64_encode($compressed);
                            $mimeType = 'image/jpeg';
                        }
                    } else {
                        imagedestroy($gdImg);
                    }
                }
            } catch (Exception $e) {
                // Ignore and fall back to raw file content
            }
        }

        if (!$base64Data) {
            $base64Data = base64_encode(file_get_contents($realPath));
        }

        $prompt = <<<PROMPT
You are an expert travel, identification, medical, and compliance document analyst.
Analyze this document (image or PDF) thoroughly.

CRITICAL RULES FOR PAKISTANI PASSPORTS:
1. When two pages of an open passport booklet are visible (the green inside cover page with the Pakistan emblem on one side, and the bio-data page with applicant photograph and MRZ lines on the other side):
   - The large bold number starting with 'G' printed on the green inside cover page (e.g. G8863371, G4427638) is ONLY a booklet/tracking number. It is NOT the passport number!
   - You MUST extract the official Passport Number from the BIO-DATA page (under 'Passport No.', beside/above the photo, and in the bottom/edge MRZ lines, e.g. JA1910572, ST1170261).
   - If the bio-data page is rotated sideways (90 degrees or 270 degrees), read the text and MRZ according to its orientation.
   - The passport number in the MRZ line 2 ALWAYS starts with the 9-character passport number (e.g. JA1910572, ST1170261).
2. For Character Certificate / Police Clearance:
   - Extract the certificate reference number (e.g. FSD-12765678, CKW-4755780). DO NOT use the applicant's passport number mentioned in the text.
   - In Pakistan, Police Character Certificates are valid for exactly 180 days (6 months) from the date of issue. The date_of_expiry MUST be exactly 180 days after date_of_issue.
3. Other documents:
   - CNIC / National Identity Card (with 13-digit identity number like 12345-1234567-1, issue date, expiry date)
   - Medical Fitness Certificate / GAMCA (with report/slip number, test date, expiry date)
   - Driving License (with license number, issue date, expiry date)
   - Trade Skill Certificate / Educational Certificate (with certificate/roll number, issue date)
   - Visa / Entry Permit

Extract all visible details and return ONLY a valid JSON object with this exact structure:
{
  "document_type": string (e.g. "Passport", "CNIC / National Identity Card", "Character Certificate / Police Clearance", "Medical Fitness Certificate", "Driving License", "Trade Skill Certificate", "Visa", or "Compliance Document"),
  "title": string (suggested concise title e.g. "Passport - DANIEL CAMPBELL", "CNIC Card - MUHAMMAD UMAIR", "Police Character Certificate", or "GAMCA Medical Fitness"),
  "issuing_country": string or null (Full official country title, e.g. "Pakistan" or "United Kingdom"),
  "country_code": string or null (3-letter ISO code, e.g. "PAK", "GBR"),
  "document_number": string or null (The main number: passport number, CNIC identity number, certificate reference number, or license number),
  "surname": string or null (Last name / father name if applicable),
  "given_names": string or null (First & middle names),
  "nationality": string or null (e.g. "Pakistani"),
  "date_of_birth": string or null ("YYYY-MM-DD" format),
  "gender": string or null ("M", "F", or "X"),
  "place_of_birth": string or null,
  "authority": string or null (e.g. "NADRA", "Islamabad Police", "Punjab Police", "HMPO", "Ministry of Health", "GAMCA"),
  "date_of_issue": string or null ("YYYY-MM-DD" format),
  "date_of_expiry": string or null ("YYYY-MM-DD" format),
  "mrz_line1": string or null (Bottom MRZ first line if passport),
  "mrz_line2": string or null (Bottom MRZ second line if passport),
  "notes": string or null (Concise verification summary remarks, e.g. "Doc No: 35202-1234567-1 | Issued by: NADRA | Expiry: 2032-05-10")
}
PROMPT;

        $lastError = null;
        $extractedJson = null;

        foreach ($this->candidateModels as $model) {
            try {
                $endpoint = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key=" . trim($apiKey);

                $response = Http::timeout(25)->withHeaders([
                    'Content-Type' => 'application/json',
                ])->post($endpoint, [
                    'contents' => [
                        [
                            'parts' => [
                                ['text' => $prompt],
                                [
                                    'inline_data' => [
                                        'mime_type' => $mimeType,
                                        'data' => $base64Data,
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'generationConfig' => [
                        'response_mime_type' => 'application/json',
                    ],
                ]);

                if ($response->successful()) {
                    $json = $response->json();
                    $text = $json['candidates'][0]['content']['parts'][0]['text'] ?? null;
                    if ($text) {
                        // Strip any potential markdown code blocks
                        $cleaned = trim(preg_replace('/^```(?:json)?\s*/i', '', $text));
                        $cleaned = trim(preg_replace('/\s*```$/', '', $cleaned));
                        $parsed = json_decode($cleaned, true);
                        if (is_array($parsed)) {
                            $extractedJson = $parsed;
                            break;
                        }
                    }
                } else {
                    $lastError = "Model {$model} returned status " . $response->status() . ": " . $response->body();
                    Log::warning("[Gemini OCR] " . $lastError);
                }
            } catch (Exception $e) {
                $lastError = $e->getMessage();
                Log::warning("[Gemini OCR] Model {$model} exception: " . $lastError);
            }
        }

        if (!$extractedJson) {
            return response()->json([
                'success' => false,
                'fallbackToLocal' => true,
                'message' => 'Gemini AI document extraction failed. Switch to local OCR.',
                'error' => $lastError,
            ], 422);
        }

        $issueDate = $this->formatDateSafe($extractedJson['date_of_issue'] ?? null);
        $expiryDate = $this->formatDateSafe($extractedJson['date_of_expiry'] ?? null);
        $dob = $this->formatDateSafe($extractedJson['date_of_birth'] ?? null);

        // Enforce 180-day validity rule for Character Certificate / Police Clearance
        $rawDocType = strtolower(($extractedJson['document_type'] ?? '') . ' ' . ($extractedJson['title'] ?? ''));
        $isCharacterCert = str_contains($rawDocType, 'character') || str_contains($rawDocType, 'police') || str_contains($rawDocType, 'clearance') || str_contains($rawDocType, 'crecter');

        if ($isCharacterCert && $issueDate) {
            try {
                $calc180 = Carbon::parse($issueDate)->addDays(180)->format('Y-m-d');
                if (!$expiryDate) {
                    $expiryDate = $calc180;
                } else {
                    $diff = abs(Carbon::parse($expiryDate)->diffInDays(Carbon::parse($calc180)));
                    if ($diff > 15) {
                        $expiryDate = $calc180;
                    }
                }
            } catch (Exception $e) {
                // Fallback to original
            }
        }

        // Infer Passport Issue Date if missing and expiry date is present
        $isPassport = str_contains($rawDocType, 'passport') || !empty($extractedJson['mrz_line2']);
        if ($isPassport && $expiryDate && !$issueDate) {
            try {
                $expCarbon = Carbon::parse($expiryDate);
                $expYear = $expCarbon->year;
                $currentYear = Carbon::now()->year;
                $validity = ($expYear - $currentYear > 5) ? 10 : 5;
                $issueDate = $expCarbon->copy()->subYears($validity)->addDay()->format('Y-m-d');
            } catch (Exception $e) {
                // Fallback
            }
        }

        // Build notes if missing
        $notes = $extractedJson['notes'] ?? null;
        if (!$notes) {
            $parts = [];
            $docNum = $extractedJson['document_number'] ?? $extractedJson['passport_number'] ?? null;
            if ($docNum) {
                $parts[] = "Doc #: {$docNum}";
            }
            $auth = $extractedJson['authority'] ?? $extractedJson['issuing_country'] ?? null;
            if ($auth) {
                $parts[] = "Authority: {$auth}";
            }
            if (!empty($extractedJson['nationality'])) {
                $parts[] = "Nationality: {$extractedJson['nationality']}";
            }
            if ($expiryDate) {
                $parts[] = "Expires: {$expiryDate}";
            }
            $notes = !empty($parts) ? implode(' | ', $parts) : null;
        }

        $title = $extractedJson['title'] ?? null;
        if (!$title) {
            $type = $extractedJson['document_type'] ?? 'Document';
            $names = trim(($extractedJson['given_names'] ?? '') . ' ' . ($extractedJson['surname'] ?? ''));
            $title = trim("{$type} - {$names}");
        }

        $formattedData = [
            'document_type' => $extractedJson['document_type'] ?? 'Passport',
            'title' => $title,
            'document_number' => $extractedJson['document_number'] ?? $extractedJson['passport_number'] ?? null,
            'surname' => $extractedJson['surname'] ?? null,
            'given_names' => $extractedJson['given_names'] ?? null,
            'nationality' => $extractedJson['nationality'] ?? $extractedJson['country_code'] ?? null,
            'issuing_country' => $extractedJson['issuing_country'] ?? null,
            'country_code' => $extractedJson['country_code'] ?? null,
            'date_of_birth' => $dob,
            'gender' => $extractedJson['gender'] ?? null,
            'place_of_birth' => $extractedJson['place_of_birth'] ?? null,
            'authority' => $extractedJson['authority'] ?? null,
            'date_of_issue' => $issueDate,
            'date_of_expiry' => $expiryDate,
            'mrz_line1' => $extractedJson['mrz_line1'] ?? null,
            'mrz_line2' => $extractedJson['mrz_line2'] ?? null,
            'notes' => $notes,
            'scan_method' => 'GEMINI_API',
            'method_label' => 'Google Gemini Vision API',
        ];

        return response()->json([
            'success' => true,
            'message' => 'Document scanned successfully using Google Gemini Vision API',
            'scan_method' => 'GEMINI_API',
            'method_label' => 'Google Gemini Vision API',
            'data' => $formattedData,
        ]);
    }

    /**
     * Safely format dates into YYYY-MM-DD.
     */
    protected function formatDateSafe(?string $dateStr): ?string
    {
        if (!$dateStr) {
            return null;
        }

        $dateStr = trim($dateStr);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateStr)) {
            return $dateStr;
        }

        try {
            return Carbon::parse($dateStr)->format('Y-m-d');
        } catch (Exception) {
            return null;
        }
    }
}
