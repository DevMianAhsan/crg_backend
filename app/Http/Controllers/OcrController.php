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
   - You MUST extract the official Passport Number from the BIO-DATA page (under 'Passport No.', beside/above the photo, and in the bottom/edge MRZ lines, e.g. JA1910572, SD243345, ST1170261).
   - If the bio-data page is rotated sideways (90 degrees or 270 degrees), read the text and MRZ according to its orientation.
   - The passport number in the MRZ line 2 ALWAYS starts with the 9-character passport number (e.g. JA1910572, SD243345, ST1170261).
   - CRITICAL FOR FATHER NAME: Pakistani passports print Father Name as "SURNAME, GIVEN_NAME" (e.g. 'MEHMOOD, ARSHAD', 'KHAN, TARIQ', 'CHAUDHRY, MUHAMMAD'). You MUST ALWAYS return it in natural order: "ARSHAD MEHMOOD", "TARIQ KHAN", "MUHAMMAD CHAUDHRY". NEVER return 'MEHMOOD ARSHAD' or 'MEHMOOD, ARSHAD'.
   - PLACE OF BIRTH: Extract the exact complete Place of Birth / Lieu de naissance (e.g. 'FAISALABAD', 'LAHORE', 'RAWALPINDI', 'MANDI BAHAUDDIN, PAK') into 'place_of_birth' and 'town' fields.
   - CRITICAL FOR CNIC / NATIONAL ID: On Pakistani passports, also extract the 13-digit National Identity Card number / CNIC found under "National ID No." or "No. d'identification nationale" or "Identity No." (e.g. 33100-1234567-1 or 3310012345671) into the "cnic" field formatted as XXXXX-XXXXXXX-X.
2. For Character Certificate / Police Clearance:
   - Extract the certificate reference number (e.g. FSD-12765678, CKW-4755780). DO NOT use the applicant's passport number mentioned in the text.
   - In Pakistan, Police Character Certificates are valid for exactly 180 days (6 months) from the date of issue. The date_of_expiry MUST be exactly 180 days after date_of_issue.
   - If a CNIC / National ID is present on the certificate, also populate "cnic" with it (XXXXX-XXXXXXX-X).
   - CRITICAL FOR ADDRESS: Police character certificates contain the candidate's complete residential address under the "Address" / "Place & Period of Stay" section (e.g. "Permanent: VPO THANEEL KAMAL, TEH & DISTT CHAKWAL" or "Present: ..."). Extract this complete address into the "address" field (e.g. "VPO THANEEL KAMAL, TEH & DISTT CHAKWAL"). Strip leading prefixes like "Permanent:" or "Present:" and return the clean full address.
3. For NADRA Family Registration Certificate (FRC / Family Certificate):
   - Set "document_type" to "FRC".
   - Extract the FRC Certificate Tracking Number / Document Number (e.g. 'EA99823296' under top barcode or tracking number at bottom) into "document_number".
   - Extract the Date of Issue (e.g. 'Date of Issue: 06/10/2025') into "date_of_issue".
   - CRITICAL: DETERMINE THE EXACT FRC TYPE (Checkboxes at top: '[✓] BY BIRTH' vs '[✓] BY MARRIAGE'):

     CATEGORY A: "Family with Parents and Siblings" (FRC by Birth / '[✓] BY BIRTH' / پیدائش کے مطابق)
     - Top checkbox is checked for 'BY BIRTH', or document lists Father, Mother, Brother(s), Sister(s).
     - The applicant is labeled as 'SELF' / 'خود' / 'Applicant'.
     - RULES FOR CATEGORY A:
       * Primary Applicant (SELF / Applicant):
         - Set "given_names" and "surname" to applicant's name.
         - Set "cnic" to applicant's CNIC / citizen number (e.g. 35404-5114951-5).
         - Set "date_of_birth" from applicant's card.
         - Set "gender" ('M' or 'F').
       * Father (Relation: 'Father' / 'والد'):
         - Set "father_name" to Father's full name.
       * Mother (Relation: 'Mother' / 'والدہ'):
         - Set "mother_name" to Mother's full name.
       * Siblings (Relation: 'Brother' / 'بھائی' or 'Sister' / 'بہن'):
         - CRITICAL: Brothers and sisters are SIBLINGS, NOT children!
         - DO NOT extract brothers or sisters into "children_details"!
         - Set "children_details" to [].
         - Set "children_count" to null.
       * Spouse / Wife:
         - CRITICAL: Candidate's Mother is NOT candidate's wife! There is NO spouse in Category A.
         - Set "wife_details" to null.

     CATEGORY B: "Family with Spouse and Children" (FRC by Marriage / '[✓] BY MARRIAGE' / ازدواج کے مطابق)
     - Top checkbox is checked for 'BY MARRIAGE', or document lists Spouse (Wife/Husband) and Son(s) / Daughter(s).
     - Each person is displayed in a card with 'Full Name', 'Citizen Number', 'Date of Birth', 'Father Name', 'Mother Name', and a relation label at top-right of the card.
     - RULES FOR CATEGORY B:
       * Primary Applicant (Card labeled 'SELF / اپنا' or 'Applicant'):
         - Set "given_names" and "surname" to applicant's name (e.g. 'Mateen Afzal Abbas').
         - Set "cnic" to applicant's citizen number (e.g. '35404-5114951-5').
         - Set "date_of_birth" from applicant's card (e.g. '01/08/1994' -> '1994-08-01').
         - Extract "father_name" from applicant's card ('Father Name: Ghulam Abbas Shaker' -> 'Ghulam Abbas Shaker').
         - Extract "mother_name" from applicant's card ('Mother Name: Sughran Bibi' -> 'Sughran Bibi').
         - If applicant is Female (Relation: 'Wife' or female name):
           - Set "gender" to "F".
           - The spouse card (Relation: 'Husband / شوہر') contains HUSBAND's details:
             Extract "wife_details" as:
             {
               "name": Husband's given name,
               "surname": Husband's surname,
               "date_of_birth": "YYYY-MM-DD",
               "age": age as string or integer,
               "cnic": 13-digit CNIC (XXXXX-XXXXXXX-X),
               "relation": "Husband"
             }
         - If applicant is Male (Relation: 'Head' / 'Husband' / 'SELF' with wife / male name):
           - Set "gender" to "M".
           - The spouse card (Relation: 'Wife / زوجہ') contains WIFE's details (e.g. 'Azmat Shaheen', '35404-1335652-4', '01/04/1994'):
             Extract "wife_details" as:
             {
               "name": Wife's given name,
               "surname": Wife's surname,
               "date_of_birth": "YYYY-MM-DD",
               "age": age as string or integer,
               "cnic": 13-digit CNIC (XXXXX-XXXXXXX-X),
               "relation": "Wife"
             }
       * Children (Section 'Details Of Children / اولاد کی تفصیل' or cards labeled 'SON / بیٹا' or 'DAUGHTER / بیٹی'):
         - Extract ALL Sons and Daughters from the cards (across any page) into "children_details":
           [
             {
               "name": Child's given name (e.g. "Muhammad", "Muhamamd"),
               "surname": Child's surname (e.g. "Roshan", "Ayan"),
               "date_of_birth": "YYYY-MM-DD" (e.g. "2022-09-09", "2023-09-20"),
               "age": age as string or integer,
               "gender": "Male" or "Female",
               "cnic": 13-digit CNIC or B-Form / CRC number (e.g. "35404-5459373-5", "35404-7410929-3")
             }
           ]
         - Set "children_count": Total count of sons and daughters listed in the document summary box (e.g. "2").
4. Other documents:
   - CNIC / National Identity Card (with 13-digit identity number like 12345-1234567-1, issue date, expiry date): populate BOTH "document_number" and "cnic" with the 13-digit number.
   - Medical Fitness Certificate / GAMCA (with report/slip number, test date, expiry date)
   - Driving License (with license number, issue date, expiry date)
   - Trade Skill Certificate / Educational Certificate (with certificate/roll number, issue date)
   - Visa / Entry Permit

Extract all visible details and return ONLY a valid JSON object with this exact structure:
{
  "document_type": string (e.g. "Passport", "FRC", "CNIC / National Identity Card", "Character Certificate / Police Clearance", "Medical Fitness Certificate", "Driving License", "Trade Skill Certificate", "Visa", or "Compliance Document"),
  "title": string (suggested concise title e.g. "Passport - DANIEL CAMPBELL", "FRC - MUHAMMAD UMAIR", "CNIC Card - MUHAMMAD UMAIR", "Police Character Certificate", or "GAMCA Medical Fitness"),
  "issuing_country": string or null (Full official country title, e.g. "Pakistan" or "United Kingdom"),
  "country_code": string or null (3-letter ISO code, e.g. "PAK", "GBR"),
  "document_number": string or null (The main number: passport number, FRC certificate tracking number, CNIC identity number, certificate reference number, or license number),
  "passport_series": string or null (The alphabet prefix series like "SD", "JA", "ST"),
  "cnic": string or null (13-digit Pakistani National ID / CNIC formatted as 00000-0000000-0),
  "surname": string or null (Last name),
  "given_names": string or null (First & middle names),
  "father_name": string or null (Father name or Nom du père),
  "mother_name": string or null (Mother name if present on FRC with parents),
  "nationality": string or null (e.g. "Pakistani"),
  "date_of_birth": string or null ("YYYY-MM-DD" format),
  "gender": string or null ("M", "F", or "X"),
  "place_of_birth": string or null (e.g. "FAISALABAD", "LAHORE"),
  "town": string or null (Town / City of origin e.g. "FAISALABAD"),
  "address": string or null (The complete residential/permanent address extracted from Character Certificate / Police clearance or identity document, e.g. "VPO THANEEL KAMAL, TEH & DISTT CHAKWAL"),
  "authority": string or null (e.g. "NADRA", "Islamabad Police", "Punjab Police", "HMPO", "Ministry of Health", "GAMCA"),
  "date_of_issue": string or null ("YYYY-MM-DD" format),
  "date_of_expiry": string or null ("YYYY-MM-DD" format),
  "wife_details": {
    "name": string or null,
    "surname": string or null,
    "date_of_birth": string or null ("YYYY-MM-DD"),
    "age": string or number or null,
    "cnic": string or null,
    "relation": string or null ("Husband" or "Wife")
  } or null,
  "children_details": [
    {
      "name": string,
      "surname": string or null,
      "date_of_birth": string or null ("YYYY-MM-DD"),
      "age": string or number or null,
      "gender": string or null,
      "cnic": string or null
    }
  ] or [],
  "children_count": string or number or null,
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

        // Extract and format CNIC / National ID
        $cnic = $this->formatCnicSafe($extractedJson['cnic'] ?? null);
        if (!$cnic && !empty($extractedJson['document_number'])) {
            $cnic = $this->formatCnicSafe($extractedJson['document_number']);
        }
        if (!$cnic) {
            $jsonStr = json_encode($extractedJson);
            if (preg_match('/\b([1-8]\d{4}[-\s]?\d{7}[-\s]?\d)\b/', $jsonStr, $m)) {
                $cnic = $this->formatCnicSafe($m[1]);
            }
        }

        $docNum = $extractedJson['document_number'] ?? $extractedJson['passport_number'] ?? null;
        $passportSeries = $extractedJson['passport_series'] ?? null;
        if (!$passportSeries && $docNum && preg_match('/^([A-Za-z]+)/', trim($docNum), $pm)) {
            $passportSeries = strtoupper($pm[1]);
        }

        $placeOfBirth = $extractedJson['place_of_birth'] ?? null;
        $town = $extractedJson['town'] ?? null;
        if (!$town && $placeOfBirth) {
            $town = trim($placeOfBirth);
        }
        if (!$placeOfBirth && $town) {
            $placeOfBirth = trim($town);
        }

        $fatherName = $this->normalizePersonNameSafe($extractedJson['father_name'] ?? null, $extractedJson['surname'] ?? null);
        $motherName = $this->normalizePersonNameSafe($extractedJson['mother_name'] ?? null, null);

        // Process Wife / Spouse details for FRC
        $isFrc = str_contains($rawDocType, 'frc') || str_contains($rawDocType, 'family');
        $wifeDetails = $isFrc ? $this->formatWifeDetailsSafe($extractedJson['wife_details'] ?? null) : null;

        // Process Children details for FRC
        $childrenDetails = $isFrc ? $this->formatChildrenDetailsSafe($extractedJson['children_details'] ?? null) : [];
        $childrenCount = $isFrc ? ($extractedJson['children_count'] ?? null) : null;
        if ($isFrc && empty($childrenCount) && !empty($childrenDetails)) {
            $childrenCount = (string) count($childrenDetails);
        }

        $formattedData = [
            'document_type'    => $extractedJson['document_type'] ?? 'Passport',
            'title'            => $title,
            'document_number'  => $docNum,
            'passport_series'  => $passportSeries,
            'cnic'             => $cnic,
            'surname'          => $extractedJson['surname'] ?? null,
            'given_names'      => $extractedJson['given_names'] ?? null,
            'father_name'      => $fatherName,
            'mother_name'      => $motherName,
            'nationality'      => $extractedJson['nationality'] ?? $extractedJson['country_code'] ?? null,
            'issuing_country'  => $extractedJson['issuing_country'] ?? null,
            'country_code'     => $extractedJson['country_code'] ?? null,
            'date_of_birth'    => $dob,
            'gender'           => $extractedJson['gender'] ?? null,
            'place_of_birth'   => $placeOfBirth,
            'town'             => $town,
            'address'          => $isCharacterCert ? $this->cleanAddressSafe($extractedJson['address'] ?? null) : null,
            'authority'        => $extractedJson['authority'] ?? null,
            'date_of_issue'    => $issueDate,
            'date_of_expiry'   => $expiryDate,
            'wife_details'     => $wifeDetails,
            'children_details' => $childrenDetails,
            'children_count'   => $childrenCount,
            'mrz_line1'        => $extractedJson['mrz_line1'] ?? null,
            'mrz_line2'        => $extractedJson['mrz_line2'] ?? null,
            'notes'            => $notes,
            'scan_method'      => 'GEMINI_API',
            'method_label'     => 'Google Gemini Vision API',
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
     * Safely format Wife / Spouse details object.
     */
    protected function formatWifeDetailsSafe(mixed $wife): ?array
    {
        if (!is_array($wife) || empty($wife)) {
            return null;
        }

        $relation = trim((string) ($wife['relation'] ?? $wife['relationship'] ?? ''));
        $relLower = strtolower($relation);

        // Strict guard: reject Mother, Father, Brother, Sister, Self, or Children mistakenly placed in spouse
        if (
            str_contains($relLower, 'mother') || str_contains($relLower, 'walida') ||
            str_contains($relLower, 'father') || str_contains($relLower, 'walid') ||
            str_contains($relLower, 'brother') || str_contains($relLower, 'sister') ||
            str_contains($relLower, 'bhai') || str_contains($relLower, 'behan') ||
            str_contains($relLower, 'self') || str_contains($relLower, 'khud') ||
            str_contains($relLower, 'son') || str_contains($relLower, 'daughter') ||
            str_contains($relLower, 'child') || str_contains($relLower, 'beta') ||
            str_contains($relLower, 'beti')
        ) {
            return null;
        }

        $name = trim((string) ($wife['name'] ?? $wife['given_names'] ?? $wife['first_name'] ?? ''));
        $surname = trim((string) ($wife['surname'] ?? $wife['last_name'] ?? ''));
        $dob = $this->formatDateSafe($wife['date_of_birth'] ?? $wife['dob'] ?? null);
        $age = $wife['age'] ?? null;

        if ($dob && (empty($age) || !is_numeric($age))) {
            try {
                $age = (string) Carbon::parse($dob)->age;
            } catch (Exception) {
                // Keep original
            }
        }

        if (empty($name) && empty($surname) && empty($dob) && empty($age)) {
            return null;
        }

        if (empty($relation)) {
            $relation = 'Wife';
        } else {
            $relation = (stripos($relation, 'husband') !== false || stripos($relation, 'shoher') !== false) ? 'Husband' : 'Wife';
        }

        return [
            'name'        => $name ?: null,
            'surname'     => $surname ?: null,
            'dateOfBirth' => $dob ?: null,
            'age'         => $age !== null && $age !== '' ? (string) $age : null,
            'cnic'        => $this->formatCnicSafe($wife['cnic'] ?? null),
            'relation'    => $relation,
        ];
    }

    /**
     * Safely format Children details array.
     */
    protected function formatChildrenDetailsSafe(mixed $children): ?array
    {
        if (!is_array($children) || empty($children)) {
            return null;
        }

        $formattedList = [];
        foreach ($children as $child) {
            if (!is_array($child)) {
                continue;
            }

            $relLower = strtolower(trim((string) ($child['relation'] ?? $child['relationship'] ?? '')));

            // Strict guard: reject Mother, Father, Brother, Sister, Self, Wife, Husband mistakenly placed in children
            if (
                str_contains($relLower, 'mother') || str_contains($relLower, 'walida') ||
                str_contains($relLower, 'father') || str_contains($relLower, 'walid') ||
                str_contains($relLower, 'brother') || str_contains($relLower, 'sister') ||
                str_contains($relLower, 'bhai') || str_contains($relLower, 'behan') ||
                str_contains($relLower, 'sibling') || str_contains($relLower, 'self') ||
                str_contains($relLower, 'khud') || str_contains($relLower, 'wife') ||
                str_contains($relLower, 'husband') || str_contains($relLower, 'spouse') ||
                str_contains($relLower, 'zoja') || str_contains($relLower, 'shoher')
            ) {
                continue;
            }

            $name = trim((string) ($child['name'] ?? $child['given_names'] ?? $child['first_name'] ?? ''));
            $surname = trim((string) ($child['surname'] ?? $child['last_name'] ?? ''));
            $dob = $this->formatDateSafe($child['date_of_birth'] ?? $child['dob'] ?? null);
            $age = $child['age'] ?? null;

            if ($dob && (empty($age) || !is_numeric($age))) {
                try {
                    $age = (string) Carbon::parse($dob)->age;
                } catch (Exception) {
                    // Keep original
                }
            }

            if (empty($name) && empty($surname) && empty($dob) && empty($age)) {
                continue;
            }

            $formattedList[] = [
                'name'        => $name ?: null,
                'surname'     => $surname ?: null,
                'dateOfBirth' => $dob ?: null,
                'age'         => $age !== null && $age !== '' ? (string) $age : null,
                'gender'      => $child['gender'] ?? null,
                'cnic'        => $this->formatCnicSafe($child['cnic'] ?? null),
            ];
        }

        return !empty($formattedList) ? $formattedList : null;
    }

    /**
     * Safely format Pakistani CNIC / National ID into 00000-0000000-0.
     */
    protected function formatCnicSafe(?string $cnicStr): ?string
    {
        if (!$cnicStr) {
            return null;
        }

        $digits = preg_replace('/\D/', '', $cnicStr);
        if (strlen($digits) === 13) {
            return substr($digits, 0, 5) . '-' . substr($digits, 5, 7) . '-' . substr($digits, 12, 1);
        }

        if (preg_match('/^\d{5}-\d{7}-\d$/', trim($cnicStr))) {
            return trim($cnicStr);
        }

        return null;
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

    /**
     * Safely normalize name from SURNAME, GIVEN_NAMES to GIVEN_NAMES SURNAME.
     */
    protected function normalizePersonNameSafe(?string $name, ?string $candidateSurname = null): ?string
    {
        if ($name === null) {
            return null;
        }
        $cleaned = trim(preg_replace('/\s+/', ' ', str_replace(["\r", "\n"], ' ', $name)));
        if ($cleaned === '') {
            return null;
        }

        // Strip leading/trailing PAK/PAKISTAN
        $cleaned = preg_replace('/^PAK\s+/i', '', $cleaned);
        $cleaned = preg_replace('/[,\\/\\-\\s]+\\b(PAK|PAKISTAN|PAKISTANI)\\b$/i', '', $cleaned);
        $cleaned = trim($cleaned);

        if ($cleaned === '') {
            return null;
        }

        if (str_contains($cleaned, ',')) {
            $parts = array_values(array_filter(
                array_map('trim', explode(',', $cleaned)),
                fn ($p) => $p !== '' && !preg_match('/^(PAK|PAKISTAN|PAKISTANI)$/i', $p)
            ));

            if (count($parts) === 2) {
                return trim($parts[1] . ' ' . $parts[0]);
            }
            if (count($parts) === 1) {
                return $parts[0];
            }
        }

        // If no comma, check if second word is the candidate's surname (e.g. "MEHMOOD ARSHAD" when candidate surname is "ARSHAD")
        if (!empty($candidateSurname)) {
            $sName = strtoupper(trim((string) $candidateSurname));
            $words = array_values(array_filter(preg_split('/\s+/', $cleaned)));
            if (count($words) === 2) {
                $w1 = strtoupper($words[0]);
                $w2 = strtoupper($words[1]);
                if ($w2 === $sName && $w1 !== $sName) {
                    return trim($words[1] . ' ' . $words[0]);
                }
            }
        }

        return $cleaned;
    }

    /**
     * Safely clean and normalize physical / residential address.
     */
    protected function cleanAddressSafe(mixed $addr): ?string
    {
        if (!$addr || !is_string($addr)) {
            return null;
        }

        $cleaned = trim($addr);
        // Remove leading Permanent: / Present: / Address:
        $cleaned = preg_replace('/^(?:permanent|present|residential|current)?\s*(?:address)?\s*[:\-\.]\s*/i', '', $cleaned);
        $cleaned = trim(preg_replace('/\s+/', ' ', str_replace(["\r", "\n"], ' ', $cleaned)));

        if (strlen($cleaned) < 4 || preg_replace('/[^a-zA-Z0-9]/', '', $cleaned) === '') {
            return null;
        }

        return $cleaned;
    }
}
