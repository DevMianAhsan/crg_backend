<?php

namespace App\Http\Controllers;

use App\Models\Candidate;
use App\Models\CandidateDocument;
use App\Models\CandidateSubmission;
use App\Models\CandidateWithdrawal;
use App\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CandidateController extends Controller
{
    // --------------------------------------------------------------------------
    // List — GET /candidates
    // --------------------------------------------------------------------------

    public function index(Request $request): JsonResponse
    {
        $this->requirePermission($request, 'candidates.view');
        $query = Candidate::with(['company', 'documents', 'withdrawal'])
            ->withCount('documents')
            ->orderByDesc('created_at');

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search): void {
                $q->where('first_name', 'ilike', "%{$search}%")
                    ->orWhere('last_name', 'ilike', "%{$search}%")
                    ->orWhere('passport_number', 'ilike', "%{$search}%")
                    ->orWhere('cnic_number', 'ilike', "%{$search}%")
                    ->orWhere('code', 'ilike', "%{$search}%")
                    ->orWhere('trade', 'ilike', "%{$search}%");
            });
        }

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($companyId = $request->query('company_id')) {
            if ($companyId === 'unassigned') {
                $query->whereNull('current_company_id');
            } else {
                $query->where('current_company_id', $companyId);
            }
        }

        $candidates = $query->get();

        return response()->json([
            'candidates' => $candidates->map(fn (Candidate $c): array => $this->presentList($c))->values(),
        ]);
    }

    // --------------------------------------------------------------------------
    // Show — GET /candidates/{candidate}
    // --------------------------------------------------------------------------

    public function show(Request $request, Candidate $candidate): JsonResponse
    {
        $this->requirePermission($request, 'candidates.view');
        $candidate->load(['company', 'documents', 'submissions.company', 'withdrawal']);

        return response()->json([
            'candidate' => $this->presentDetail($candidate),
        ]);
    }

    // --------------------------------------------------------------------------
    // Store — POST /candidates
    // --------------------------------------------------------------------------

    public function store(Request $request): JsonResponse
    {
        $this->requirePermission($request, 'candidates.create');
        $data = $request->validate([
            'firstName'        => ['required', 'string', 'max:100'],
            'lastName'         => ['required', 'string', 'max:100'],
            'email'            => ['nullable', 'email', 'max:255'],
            'phone'            => ['required', 'string', 'max:50'],
            'passportNumber'   => ['required', 'string', 'max:50'],
            'passportExpiry'   => ['required', 'date'],
            'passportIssueDate'=> ['nullable', 'date'],
            'cnicNumber'       => ['required', 'string', 'max:20'],
            'nationality'      => ['nullable', 'string', 'max:100'],
            'currentLocation'  => ['nullable', 'string', 'max:200'],
            'targetCountry'    => ['nullable', 'string', 'max:200'],
            'assignedRecruiter'=> ['nullable', 'string', 'max:100'],
            'trade'            => ['required', 'string', 'max:255'],
            'experienceYears'  => ['nullable', 'integer', 'min:0'],
            'expectedSalary'   => ['nullable', 'numeric', 'min:0'],
            'currency'         => ['nullable', 'string', 'max:10'],
            'balance'          => ['nullable', 'numeric', 'min:0'],
            'photo'            => ['nullable', 'image', 'max:5120'], // 5 MB max
        ]);

        // Enforce unique passport number across all active candidates & historical passports
        $rawPassport = strtoupper(trim((string) $data['passportNumber']));
        $existing = Candidate::whereRaw('UPPER(passport_number) = ?', [$rawPassport])->first();
        if (!$existing) {
            $existing = Candidate::where(function ($q) use ($data, $rawPassport) {
                $q->whereJsonContains('passport_history', [['passportNumber' => $data['passportNumber']]])
                  ->orWhereRaw("UPPER(passport_history::text) LIKE ?", ['%"PASSPORTNUMBER":"' . $rawPassport . '"%']);
            })->first();
        }
        if ($existing) {
            return response()->json([
                'message' => "Passport number '{$data['passportNumber']}' is already registered to candidate {$existing->first_name} {$existing->last_name} ({$existing->code}). Each passport number can only be used once.",
                'errors' => [
                    'passportNumber' => ["Passport number is already registered to candidate {$existing->first_name} {$existing->last_name} ({$existing->code})."],
                ],
            ], 422);
        }

        // Generate sequential code
        $count       = Candidate::withTrashed()->count();
        $code        = 'CRG-' . str_pad((string) ($count + 1001), 4, '0', STR_PAD_LEFT);
        $psnCode     = 'PSN-' . now()->year . '-' . str_pad((string) ($count + 1), 4, '0', STR_PAD_LEFT);

        // Handle photo upload
        $photoPath = null;
        if ($request->hasFile('photo')) {
            $photoPath = $request->file('photo')->store('candidates/photos', 'public');
        }

        $skills = $request->input('skills');
        if (is_string($skills)) {
            $decoded = json_decode($skills, true);
            $skills  = is_array($decoded) ? $decoded : [$skills];
        }
        if (empty($skills) && !empty($data['trade'])) {
            $skills = array_values(array_filter(array_map('trim', explode(',', $data['trade']))));
        }

        $candidate = Candidate::create([
            'code'               => $code,
            'psn_code'           => $psnCode,
            'first_name'         => $data['firstName'],
            'last_name'          => $data['lastName'],
            'email'              => $data['email'] ?? null,
            'phone'              => $data['phone'] ?? null,
            'passport_number'    => $data['passportNumber'],
            'passport_expiry'    => $data['passportExpiry'] ?? null,
            'passport_issue_date'=> $data['passportIssueDate'] ?? null,
            'cnic_number'        => $data['cnicNumber'] ?? null,
            'nationality'        => $data['nationality'] ?? 'Pakistani',
            'current_location'   => $data['currentLocation'] ?? 'Pakistan',
            'target_country'     => $data['targetCountry'] ?? null,
            'assigned_recruiter' => $data['assignedRecruiter'] ?? null,
            'trade'              => $data['trade'],
            'experience_years'   => $data['experienceYears'] ?? 0,
            'expected_salary'    => $data['expectedSalary'] ?? 0,
            'currency'           => $data['currency'] ?? 'AED',
            'photo_path'         => $photoPath,
            'status'             => 'available',
            'recruitment_stage'  => 'registered',
            'skills'             => $skills ?? [],
            'balance'            => $data['balance'] ?? 0,
            'joined_date'        => now()->toDateString(),
        ]);

        $candidate->load(['company', 'documents', 'submissions', 'withdrawal']);

        return response()->json([
            'candidate' => $this->presentDetail($candidate),
        ], 201);
    }

    // --------------------------------------------------------------------------
    // Download Sample Template — GET /candidates/template/download
    // --------------------------------------------------------------------------

    public function downloadTemplate(Request $request): StreamedResponse
    {
        $this->requirePermission($request, 'candidates.view');
        $format = strtolower($request->query('format', 'xlsx'));

        $headers = [
            'First Name',
            'Last Name',
            'Passport Number',
            'Passport Expiry',
            'Trade',
            'Phone',
            'CNIC Number',
            'Email',
            'Passport Issue Date',
            'Nationality',
            'Current Location',
            'Target Country',
            'Experience (Years)',
            'Expected Salary',
            'Currency',
            'Assigned Recruiter',
            'Father Name',
            'Skills',
        ];

        $sampleData = [
            [
                'Muhammad',
                'Ali',
                'BB1234567',
                '2029-10-15',
                'Electrician',
                '+92 300 1234567',
                '35202-1234567-1',
                'ali@example.com',
                '2024-10-15',
                'Pakistani',
                'Lahore, Pakistan',
                'Saudi Arabia',
                5,
                2500,
                'AED',
                'CRG Staff',
                'Ahmad Ali',
                'Electrical Wiring, Circuit Breakers, Conduit Bending',
            ],
            [
                'Tariq',
                'Mahmood',
                'CC9876543',
                '2030-05-20',
                'Pipe Fitter',
                '+92 321 7654321',
                '35201-7654321-3',
                'tariq@example.com',
                '2025-05-20',
                'Pakistani',
                'Rawalpindi, Pakistan',
                'UAE',
                3,
                2200,
                'AED',
                'CRG Staff',
                'Mahmood Khan',
                'Pipe Fabrication, Blueprints, TIG Welding',
            ],
            [
                'Kamran',
                'Ashraf',
                'DD5432198',
                '2028-12-31',
                'Civil Carpenter',
                '+92 333 4567890',
                '35200-4567890-5',
                'kamran@example.com',
                '2023-12-31',
                'Pakistani',
                'Faisalabad, Pakistan',
                'Qatar',
                4,
                2000,
                'AED',
                'CRG Staff',
                'Ashraf Din',
                'Formwork, Shuttering, Woodwork',
            ],
        ];

        if ($format === 'csv') {
            $filename = 'candidates_bulk_template.csv';
            return response()->stream(function () use ($headers, $sampleData): void {
                $file = fopen('php://output', 'w');
                fprintf($file, chr(0xEF) . chr(0xBB) . chr(0xBF)); // UTF-8 BOM
                fputcsv($file, $headers);
                foreach ($sampleData as $row) {
                    fputcsv($file, $row);
                }
                fclose($file);
            }, 200, [
                'Content-Type'        => 'text/csv; charset=UTF-8',
                'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            ]);
        }

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Candidates Template');

        // Headers
        $colIndex = 1;
        foreach ($headers as $header) {
            $sheet->setCellValueExplicit([$colIndex, 1], $header, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $colLetter = Coordinate::stringFromColumnIndex($colIndex);
            $sheet->getColumnDimension($colLetter)->setAutoSize(true);
            $colIndex++;
        }

        $highestCol = Coordinate::stringFromColumnIndex(count($headers));
        $headerRange = "A1:{$highestCol}1";
        $sheet->getStyle($headerRange)->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $sheet->getStyle($headerRange)->getFill()->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setRGB('1E3A8A');

        // Rows
        $rowNum = 2;
        foreach ($sampleData as $row) {
            $colNum = 1;
            foreach ($row as $val) {
                $sheet->setCellValueExplicit([$colNum, $rowNum], (string) $val, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                $colNum++;
            }
            $rowNum++;
        }

        $filename = 'candidates_bulk_template.xlsx';
        $writer = new Xlsx($spreadsheet);

        return response()->streamDownload(function () use ($writer): void {
            $writer->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    // --------------------------------------------------------------------------
    // Bulk Store / Preview — POST /candidates/bulk
    // --------------------------------------------------------------------------

    public function bulkStore(Request $request): JsonResponse
    {
        $this->requirePermission($request, 'candidates.create');

        $isPreview = $request->input('action') === 'preview' || $request->boolean('preview');
        $rawRows = [];

        if ($request->hasFile('file')) {
            $file = $request->file('file');
            $rawRows = $this->parseSpreadsheetFile($file);
        } elseif ($request->has('candidates')) {
            $rawRows = (array) $request->input('candidates', []);
        } else {
            return response()->json([
                'message' => 'No file or candidates data provided for bulk upload.',
            ], 422);
        }

        if (empty($rawRows)) {
            return response()->json([
                'message' => 'No candidate records found in the uploaded file or request.',
            ], 422);
        }

        // Validate each candidate and check duplicates
        $processedRows = [];
        $batchPassports = [];
        $validCandidatesToInsert = [];
        $errorsCount = 0;

        foreach ($rawRows as $index => $row) {
            $rowNumber = $index + 2; // account for header row in spreadsheet
            $candidateData = $this->sanitizeCandidateRow($row);
            $errors = [];

            // Required validations
            if (empty($candidateData['firstName'])) {
                $errors[] = 'First Name is required.';
            }

            if (empty($candidateData['passportNumber'])) {
                $errors[] = 'Passport Number is required.';
            } else {
                $rawPassport = strtoupper(trim($candidateData['passportNumber']));
                $candidateData['passportNumber'] = $rawPassport;

                // Check duplicate within batch
                if (in_array($rawPassport, $batchPassports, true)) {
                    $errors[] = "Duplicate passport '{$rawPassport}' found within the uploaded file.";
                } else {
                    // Check duplicate in database
                    $existing = Candidate::whereRaw('UPPER(passport_number) = ?', [$rawPassport])->first();
                    if (!$existing) {
                        $existing = Candidate::where(function ($q) use ($rawPassport): void {
                            $q->whereJsonContains('passport_history', [['passportNumber' => $rawPassport]])
                              ->orWhereRaw("UPPER(passport_history::text) LIKE ?", ['%"PASSPORTNUMBER":"' . $rawPassport . '"%']);
                        })->first();
                    }

                    if ($existing) {
                        $errors[] = "Passport '{$rawPassport}' is already registered to candidate {$existing->first_name} {$existing->last_name} ({$existing->code}).";
                    } else {
                        $batchPassports[] = $rawPassport;
                    }
                }
            }

            if (empty($candidateData['trade'])) {
                $errors[] = 'Trade / Role is required.';
            }

            $isValid = empty($errors);
            if (!$isValid) {
                $errorsCount++;
            } else {
                $validCandidatesToInsert[] = [
                    'rowNumber' => $rowNumber,
                    'data'      => $candidateData,
                ];
            }

            $processedRows[] = [
                'rowNumber' => $rowNumber,
                'valid'     => $isValid,
                'errors'    => $errors,
                'data'      => $candidateData,
            ];
        }

        // If preview mode, return validation report without database insertion
        if ($isPreview) {
            return response()->json([
                'preview'      => true,
                'totalCount'   => count($processedRows),
                'validCount'   => count($validCandidatesToInsert),
                'invalidCount' => $errorsCount,
                'rows'         => $processedRows,
            ]);
        }

        // If there are no valid candidates to insert
        if (empty($validCandidatesToInsert)) {
            return response()->json([
                'message'      => 'No valid candidates could be imported. Please review the errors.',
                'successCount' => 0,
                'failedCount'  => count($processedRows),
                'errors'       => array_values(array_filter($processedRows, fn ($r) => !$r['valid'])),
                'imported'     => [],
            ], 422);
        }

        // Perform Database insertion in transaction
        $insertedCandidates = [];
        $failedInserts = [];

        DB::beginTransaction();
        try {
            $maxId = Candidate::withTrashed()->max('id') ?? 0;
            $count = Candidate::withTrashed()->count();
            $counter = max($maxId, $count) + 1;

            foreach ($validCandidatesToInsert as $item) {
                $cData = $item['data'];

                // Ensure unique code & psn_code
                do {
                    $code    = 'CRG-' . str_pad((string) ($counter + 1000), 4, '0', STR_PAD_LEFT);
                    $psnCode = 'PSN-' . now()->year . '-' . str_pad((string) $counter, 4, '0', STR_PAD_LEFT);
                    $counter++;
                } while (Candidate::withTrashed()->where('code', $code)->orWhere('psn_code', $psnCode)->exists());

                $skills = $cData['skills'];
                if (is_string($skills)) {
                    $skills = array_values(array_filter(array_map('trim', explode(',', $skills))));
                } elseif (!is_array($skills)) {
                    $skills = [];
                }

                $newCandidate = Candidate::create([
                    'code'                => $code,
                    'psn_code'            => $psnCode,
                    'first_name'          => $cData['firstName'],
                    'last_name'           => $cData['lastName'] ?? '-',
                    'email'               => !empty($cData['email']) ? $cData['email'] : null,
                    'phone'               => !empty($cData['phone']) ? $cData['phone'] : null,
                    'passport_number'     => $cData['passportNumber'],
                    'passport_expiry'     => $cData['passportExpiry'] ?: null,
                    'passport_issue_date' => $cData['passportIssueDate'] ?: null,
                    'cnic_number'         => !empty($cData['cnicNumber']) ? $cData['cnicNumber'] : null,
                    'nationality'         => $cData['nationality'] ?: 'Pakistani',
                    'current_location'    => $cData['currentLocation'] ?: 'Pakistan',
                    'target_country'      => $cData['targetCountry'] ?: null,
                    'assigned_recruiter'  => $cData['assignedRecruiter'] ?: null,
                    'trade'               => $cData['trade'],
                    'experience_years'    => (int) ($cData['experienceYears'] ?? 0),
                    'expected_salary'     => (float) ($cData['expectedSalary'] ?? 0),
                    'currency'            => $cData['currency'] ?: 'AED',
                    'status'              => 'available',
                    'recruitment_stage'   => 'registered',
                    'skills'              => $skills,
                    'balance'             => (float) ($cData['balance'] ?? 0),
                    'father_name'         => $cData['fatherName'] ?: null,
                    'joined_date'         => now()->toDateString(),
                ]);

                $insertedCandidates[] = $this->presentList($newCandidate);
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Database error while saving candidates: ' . $e->getMessage(),
            ], 500);
        }

        $invalidRowsReport = array_values(array_filter($processedRows, fn ($r) => !$r['valid']));

        return response()->json([
            'message'      => sprintf(
                'Bulk upload completed successfully! %d candidate%s imported%s.',
                count($insertedCandidates),
                count($insertedCandidates) === 1 ? '' : 's',
                count($invalidRowsReport) > 0 ? sprintf(', %d skipped due to errors', count($invalidRowsReport)) : ''
            ),
            'successCount' => count($insertedCandidates),
            'failedCount'  => count($invalidRowsReport),
            'imported'     => $insertedCandidates,
            'errors'       => $invalidRowsReport,
        ], 201);
    }

    /**
     * Parse uploaded file (.xlsx, .xls, .csv) into structured candidate rows.
     */
    private function parseSpreadsheetFile($file): array
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $path = $file->getRealPath();
        $rawRows = [];

        if ($extension === 'csv' || $extension === 'txt') {
            if (($handle = fopen($path, 'r')) !== false) {
                // Strip UTF-8 BOM if present
                $bom = fread($handle, 3);
                if ($bom !== "\xEF\xBB\xBF") {
                    rewind($handle);
                }
                while (($data = fgetcsv($handle, 5000, ',')) !== false) {
                    $rawRows[] = $data;
                }
                fclose($handle);
            }
        } else {
            // Excel file via PhpSpreadsheet
            $spreadsheet = IOFactory::load($path);
            $sheet = $spreadsheet->getActiveSheet();
            $rawRows = $sheet->toArray(null, true, false, false);
        }

        if (empty($rawRows)) {
            return [];
        }

        // First row is headers
        $headerRow = array_shift($rawRows);
        $headerMap = $this->buildHeaderMap($headerRow);

        $results = [];
        foreach ($rawRows as $row) {
            // Skip empty rows
            $hasContent = false;
            foreach ($row as $cell) {
                if ($cell !== null && trim((string) $cell) !== '') {
                    $hasContent = true;
                    break;
                }
            }
            if (!$hasContent) {
                continue;
            }

            $mapped = [];
            foreach ($headerMap as $field => $colIndex) {
                $val = $row[$colIndex] ?? null;
                $mapped[$field] = is_string($val) ? trim($val) : $val;
            }
            $results[] = $mapped;
        }

        return $results;
    }

    /**
     * Build mapping between candidate attributes and column indices based on header names.
     */
    private function buildHeaderMap(array $headers): array
    {
        $aliasMap = [
            'firstName'          => ['firstname', 'first_name', 'first', 'givenname', 'given_name', 'fname'],
            'lastName'           => ['lastname', 'last_name', 'last', 'surname', 'familyname', 'family_name', 'lname'],
            'name'               => ['name', 'fullname', 'full_name', 'candidatename', 'candidate_name'],
            'passportNumber'     => ['passportnumber', 'passport_number', 'passportno', 'passport_no', 'passport', 'passno', 'pass_no'],
            'passportExpiry'     => ['passportexpiry', 'passport_expiry', 'expirydate', 'expiry_date', 'passport_expiry_date', 'expiry'],
            'passportIssueDate'  => ['passportissuedate', 'passport_issue_date', 'issuedate', 'issue_date', 'date_of_issue'],
            'trade'              => ['trade', 'jobrole', 'job_role', 'role', 'position', 'designation', 'profession', 'occupation'],
            'phone'              => ['phone', 'phonenumber', 'phone_number', 'mobile', 'mobilenumber', 'mobile_no', 'contact', 'contact_number'],
            'cnicNumber'         => ['cnicnumber', 'cnic_number', 'cnic', 'cnic_no', 'nationalid', 'national_id', 'idnumber', 'id_number'],
            'email'              => ['email', 'emailaddress', 'email_address', 'e_mail'],
            'nationality'        => ['nationality', 'country_of_origin', 'citizenship'],
            'currentLocation'    => ['currentlocation', 'current_location', 'location', 'city', 'residence'],
            'targetCountry'      => ['targetcountry', 'target_country', 'destination', 'destination_country'],
            'experienceYears'    => ['experienceyears', 'experience_years', 'experience', 'experience_yrs', 'exp', 'years_of_experience'],
            'expectedSalary'     => ['expectedsalary', 'expected_salary', 'salary', 'monthly_salary', 'basic_salary'],
            'currency'           => ['currency'],
            'assignedRecruiter'  => ['assignedrecruiter', 'assigned_recruiter', 'recruiter'],
            'fatherName'         => ['fathername', 'father_name', 'father'],
            'skills'             => ['skills', 'skillset', 'skill'],
        ];

        $map = [];
        foreach ($headers as $index => $rawHeader) {
            if ($rawHeader === null) {
                continue;
            }
            $clean = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', (string) $rawHeader));
            foreach ($aliasMap as $field => $aliases) {
                if (in_array($clean, array_map(fn ($a) => preg_replace('/[^a-zA-Z0-9]/', '', $a), $aliases), true)) {
                    if (!isset($map[$field])) {
                        $map[$field] = $index;
                    }
                    break;
                }
            }
        }

        return $map;
    }

    /**
     * Sanitize and format row values into uniform candidate fields.
     */
    private function sanitizeCandidateRow(array $row): array
    {
        $firstName = trim((string) ($row['firstName'] ?? ''));
        $lastName  = trim((string) ($row['lastName'] ?? ''));

        // If firstName is empty but a full 'name' was provided, split it
        if (empty($firstName) && !empty($row['name'])) {
            $parts = preg_split('/\s+/', trim((string) $row['name']), 2);
            $firstName = $parts[0] ?? '';
            $lastName  = $parts[1] ?? '-';
        }

        if (empty($lastName)) {
            $lastName = '-';
        }

        return [
            'firstName'          => $firstName,
            'lastName'           => $lastName,
            'passportNumber'     => strtoupper(trim((string) ($row['passportNumber'] ?? ''))),
            'passportExpiry'     => $this->formatCandidateDate($row['passportExpiry'] ?? null),
            'passportIssueDate'  => $this->formatCandidateDate($row['passportIssueDate'] ?? null),
            'trade'              => trim((string) ($row['trade'] ?? '')),
            'phone'              => trim((string) ($row['phone'] ?? '')),
            'cnicNumber'         => trim((string) ($row['cnicNumber'] ?? '')),
            'email'              => trim((string) ($row['email'] ?? '')),
            'nationality'        => trim((string) ($row['nationality'] ?? 'Pakistani')),
            'currentLocation'    => trim((string) ($row['currentLocation'] ?? 'Pakistan')),
            'targetCountry'      => trim((string) ($row['targetCountry'] ?? '')),
            'experienceYears'    => max(0, (int) ($row['experienceYears'] ?? 0)),
            'expectedSalary'     => max(0, (float) ($row['expectedSalary'] ?? 0)),
            'currency'           => trim((string) ($row['currency'] ?? 'AED')) ?: 'AED',
            'assignedRecruiter'  => trim((string) ($row['assignedRecruiter'] ?? '')),
            'fatherName'         => trim((string) ($row['fatherName'] ?? '')),
            'skills'             => $row['skills'] ?? '',
            'balance'            => max(0, (float) ($row['balance'] ?? 0)),
        ];
    }

    /**
     * Convert various spreadsheet date formats / numbers into YYYY-MM-DD.
     */
    private function formatCandidateDate($val): ?string
    {
        if ($val === null || $val === '') {
            return null;
        }

        if ($val instanceof \DateTimeInterface) {
            return $val->format('Y-m-d');
        }

        if (is_numeric($val)) {
            $num = (float) $val;
            if ($num > 20000 && $num < 80000) {
                try {
                    return ExcelDate::excelToDateTimeObject($num)->format('Y-m-d');
                } catch (\Throwable) {
                    // ignore and fallback
                }
            }
        }

        $str = trim((string) $val);
        $time = strtotime($str);
        if ($time !== false && $time > 0) {
            return date('Y-m-d', $time);
        }

        return null;
    }

    public function update(Request $request, Candidate $candidate): JsonResponse
    {
        $this->requirePermission($request, 'candidates.update');
        $data = $request->validate([
            'firstName'        => ['required', 'string', 'max:100'],
            'lastName'         => ['required', 'string', 'max:100'],
            'email'            => ['nullable', 'email', 'max:255'],
            'phone'            => ['nullable', 'string', 'max:50'],
            'passportNumber'   => ['required', 'string', 'max:50'],
            'passportExpiry'   => ['nullable', 'date'],
            'cnicNumber'       => ['nullable', 'string', 'max:20'],
            'nationality'      => ['nullable', 'string', 'max:100'],
            'currentLocation'  => ['nullable', 'string', 'max:200'],
            'targetCountry'    => ['nullable', 'string', 'max:200'],
            'assignedRecruiter'=> ['nullable', 'string', 'max:100'],
            'trade'            => ['required', 'string', 'max:255'],
            'experienceYears'  => ['nullable', 'integer', 'min:0'],
            'expectedSalary'   => ['nullable', 'numeric', 'min:0'],
            'currency'         => ['nullable', 'string', 'max:10'],
            'balance'          => ['nullable', 'numeric', 'min:0'],
            'photo'            => ['nullable', 'image', 'max:5120'],
        ]);

        // Enforce unique passport number across all candidates (active and history, excluding self)
        $rawPassport = strtoupper(trim((string) $data['passportNumber']));
        $existing = Candidate::whereRaw('UPPER(passport_number) = ?', [$rawPassport])
            ->where('id', '!=', $candidate->id)
            ->first();
        if (!$existing) {
            $existing = Candidate::where(function ($q) use ($data, $rawPassport) {
                $q->whereJsonContains('passport_history', [['passportNumber' => $data['passportNumber']]])
                  ->orWhereRaw("UPPER(passport_history::text) LIKE ?", ['%"PASSPORTNUMBER":"' . $rawPassport . '"%']);
            })
            ->where('id', '!=', $candidate->id)
            ->first();
        }
        if ($existing) {
            return response()->json([
                'message' => "Passport number '{$data['passportNumber']}' is already registered to candidate {$existing->first_name} {$existing->last_name} ({$existing->code}). Each passport number can only be used once.",
                'errors' => [
                    'passportNumber' => ["Passport number is already registered to candidate {$existing->first_name} {$existing->last_name} ({$existing->code})."],
                ],
            ], 422);
        }

        $photoPath = $candidate->photo_path;
        if ($request->hasFile('photo')) {
            // Delete old photo
            if ($photoPath) {
                Storage::disk('public')->delete($photoPath);
            }
            $photoPath = $request->file('photo')->store('candidates/photos', 'public');
        }

        $skills = $request->input('skills');
        if (is_string($skills)) {
            $decoded = json_decode($skills, true);
            $skills  = is_array($decoded) ? $decoded : [$skills];
        }
        if ($skills === null && !empty($data['trade'])) {
            $skills = array_values(array_filter(array_map('trim', explode(',', $data['trade']))));
        }

        $candidate->forceFill([
            'first_name'         => $data['firstName'],
            'last_name'          => $data['lastName'],
            'email'              => $data['email'] ?? null,
            'phone'              => $data['phone'] ?? null,
            'passport_number'    => $data['passportNumber'],
            'passport_expiry'    => $data['passportExpiry'] ?? null,
            'cnic_number'        => $data['cnicNumber'] ?? null,
            'nationality'        => $data['nationality'] ?? 'Pakistani',
            'current_location'   => $data['currentLocation'] ?? 'Pakistan',
            'target_country'     => $data['targetCountry'] ?? null,
            'assigned_recruiter' => $data['assignedRecruiter'] ?? null,
            'trade'              => $data['trade'],
            'experience_years'   => $data['experienceYears'] ?? 0,
            'expected_salary'    => $data['expectedSalary'] ?? 0,
            'currency'           => $data['currency'] ?? 'AED',
            'balance'            => array_key_exists('balance', $data) ? ($data['balance'] ?? 0) : $candidate->balance,
            'skills'             => $skills ?? $candidate->skills,
            'photo_path'         => $photoPath,
        ]);
        $candidate->saveOrFail();
        $savedCandidate = Candidate::with(['company', 'documents', 'submissions', 'withdrawal'])
            ->findOrFail($candidate->id);

        return response()->json([
            'candidate' => $this->presentDetail($savedCandidate),
        ]);
    }

    // --------------------------------------------------------------------------
    // Destroy — DELETE /candidates/{candidate}
    // --------------------------------------------------------------------------

    public function destroy(Request $request, Candidate $candidate): JsonResponse
    {
        $this->requirePermission($request, 'candidates.delete');
        if ($candidate->photo_path) {
            Storage::disk('public')->delete($candidate->photo_path);
        }

        $candidate->delete();

        return response()->json(['message' => 'Candidate deleted successfully.']);
    }

    // --------------------------------------------------------------------------
    // Update Stage — PATCH /candidates/{candidate}/stage
    // --------------------------------------------------------------------------

    public function updateStage(Request $request, Candidate $candidate): JsonResponse
    {
        $this->requirePermission($request, 'candidates.update');
        $data = $request->validate([
            'stage' => ['required', 'in:inquiry,registered,docs_collection,processing,visa_applied,visa_stamped,ready_to_fly'],
        ]);

        $candidate->update(['recruitment_stage' => $data['stage']]);

        return response()->json([
            'candidate' => $this->presentDetail($candidate->fresh()->load(['company', 'documents', 'submissions', 'withdrawal'])),
        ]);
    }

    // --------------------------------------------------------------------------
    // Update Status — PATCH /candidates/{candidate}/status
    // --------------------------------------------------------------------------

    public function updateStatus(Request $request, Candidate $candidate): JsonResponse
    {
        $this->requirePermission($request, 'candidates.update');
        $data = $request->validate([
            'status' => ['required', 'in:available,placed,processing,on_hold,withdrawn,archived'],
        ]);

        $candidate->update(['status' => $data['status']]);

        return response()->json([
            'candidate' => $this->presentDetail($candidate->fresh()->load(['company', 'documents', 'submissions', 'withdrawal'])),
        ]);
    }

    // --------------------------------------------------------------------------
    // Shift to Company — POST /candidates/shift
    // --------------------------------------------------------------------------

    public function shiftToCompany(Request $request): JsonResponse
    {
        $this->requirePermission($request, 'candidates.shift');
        $data = $request->validate([
            'candidateIds'   => ['required', 'array', 'min:1'],
            'candidateIds.*' => ['required', 'integer', 'exists:candidates,id'],
            'companyId'      => ['required', 'integer', 'exists:companies,id'],
            'note'           => ['nullable', 'string', 'max:500'],
        ]);

        $company    = Company::findOrFail($data['companyId']);
        $sharedToken = Str::random(32);
        $shiftedAt   = now();
        $shiftedBy   = $request->user()->name ?? 'System';

        $candidates = Candidate::whereIn('id', $data['candidateIds'])->get();

        foreach ($candidates as $candidate) {
            $candidate->update([
                'status'             => 'placed',
                'current_company_id' => $company->id,
            ]);

            CandidateSubmission::create([
                'candidate_id' => $candidate->id,
                'company_id'   => $company->id,
                'company_name' => $company->name,
                'shifted_at'   => $shiftedAt,
                'note'         => $data['note'] ?? null,
                'shifted_by'   => $shiftedBy,
                'share_token'  => $sharedToken . '-' . $candidate->id,
            ]);
        }

        $shareableUrl = url('/share/' . $sharedToken);

        return response()->json([
            'message'      => count($candidates) . ' candidate(s) shifted to ' . $company->name,
            'shareableUrl' => $shareableUrl,
            'sharedToken'  => $sharedToken,
        ]);
    }

    // --------------------------------------------------------------------------
    // Return to Pool — PATCH /candidates/{candidate}/return
    // --------------------------------------------------------------------------

    public function returnToPool(Request $request, Candidate $candidate): JsonResponse
    {
        $this->requirePermission($request, 'candidates.update');
        $candidate->update([
            'status'             => 'available',
            'current_company_id' => null,
        ]);

        return response()->json([
            'candidate' => $this->presentDetail($candidate->fresh()->load(['company', 'documents', 'submissions', 'withdrawal'])),
            'message'   => 'Candidate returned to the available pool.',
        ]);
    }

    // --------------------------------------------------------------------------
    // Withdraw — POST /candidates/{candidate}/withdraw
    // --------------------------------------------------------------------------

    public function withdraw(Request $request, Candidate $candidate): JsonResponse
    {
        $this->requirePermission($request, 'candidates.update');
        $data = $request->validate([
            'stage'                  => ['required', 'string'],
            'deductionPercentage'    => ['required', 'numeric', 'min:0', 'max:100'],
            'totalPaidSoFar'         => ['required', 'numeric', 'min:0'],
            'cancellationFee'        => ['required', 'numeric', 'min:0'],
            'refundPayable'          => ['required', 'numeric', 'min:0'],
            'reason'                 => ['nullable', 'string'],
            'paymentMode'            => ['required', 'in:cheque,bank_transfer,cash'],
            'chequeNumber'           => ['nullable', 'string', 'max:100'],
            'chequeDate'             => ['nullable', 'date'],
            'alertFinanceBeforeDays' => ['nullable', 'string'],
        ]);

        // Remove any existing withdrawal
        $candidate->withdrawal()->delete();

        CandidateWithdrawal::create([
            'candidate_id'               => $candidate->id,
            'stage'                      => $data['stage'],
            'deduction_percentage'       => $data['deductionPercentage'],
            'total_paid_so_far'          => $data['totalPaidSoFar'],
            'cancellation_fee'           => $data['cancellationFee'],
            'refund_payable'             => $data['refundPayable'],
            'reason'                     => $data['reason'] ?? null,
            'payment_mode'               => $data['paymentMode'],
            'cheque_number'              => $data['chequeNumber'] ?? null,
            'cheque_date'                => $data['chequeDate'] ?? null,
            'alert_finance_before_days'  => $data['alertFinanceBeforeDays'] ?? null,
            'status'                     => 'pending_hr_approval',
            'withdrawn_at'               => now(),
        ]);

        $candidate->update(['status' => 'withdrawn']);

        return response()->json([
            'candidate' => $this->presentDetail($candidate->fresh()->load(['company', 'documents', 'submissions', 'withdrawal'])),
            'message'   => 'Candidate withdrawal recorded.',
        ]);
    }

    // --------------------------------------------------------------------------
    // Upload Document — POST /candidates/{candidate}/documents
    // --------------------------------------------------------------------------

    public function storeDocument(Request $request, Candidate $candidate): JsonResponse
    {
        $this->requirePermission($request, 'documents.create');
        $data = $request->validate([
            'title'            => ['required', 'string', 'max:255'],
            'documentTypeId'   => ['nullable', 'string'],
            'documentTypeName' => ['nullable', 'string', 'max:100'],
            'file'             => ['nullable', 'file', 'max:20480'], // 20 MB
            'issueDate'        => ['nullable', 'date'],
            'expiryDate'       => ['nullable', 'date'],
            'notes'            => ['nullable', 'string'],
        ]);

        $filePath = null;
        $fileName = null;
        $fileSize = null;

        if ($request->hasFile('file')) {
            $file     = $request->file('file');
            $filePath = $file->store('candidates/' . $candidate->id . '/documents', 'public');
            $fileName = $file->getClientOriginalName();
            $fileSize = $this->humanFileSize($file->getSize());
        }

        // Determine document status based on expiry date
        $status = 'pending';
        if ($data['expiryDate'] ?? null) {
            $expiry = \Carbon\Carbon::parse($data['expiryDate']);
            $status = $expiry->isPast() ? 'expired' : 'pending';
        }

        $document = $candidate->documents()->create([
            'title'              => $data['title'],
            'document_type_id'   => $data['documentTypeId'] ?? 'doc-type-1',
            'document_type_name' => $data['documentTypeName'] ?? 'Compliance Document',
            'file_path'          => $filePath,
            'file_name'          => $fileName,
            'file_size'          => $fileSize,
            'issue_date'         => $data['issueDate'] ?? null,
            'expiry_date'        => $data['expiryDate'] ?? null,
            'status'             => $status,
            'notes'              => $data['notes'] ?? null,
        ]);

        // Auto-advance stage to docs_collection on first document upload
        if (in_array($candidate->recruitment_stage, ['inquiry', 'registered'])) {
            $candidate->update(['recruitment_stage' => 'docs_collection']);
        }

        return response()->json([
            'document' => $this->presentDocument($document),
        ], 201);
    }

    // --------------------------------------------------------------------------
    // Update Document — PATCH /candidates/{candidate}/documents/{document}
    // --------------------------------------------------------------------------

    public function updateDocument(Request $request, Candidate $candidate, CandidateDocument $document): JsonResponse
    {
        $this->requirePermission($request, $request->input('status') ? 'documents.verify' : 'documents.update');
        $data = $request->validate([
            'title'            => ['sometimes', 'string', 'max:255'],
            'documentTypeId'   => ['sometimes', 'string'],
            'documentTypeName' => ['sometimes', 'string', 'max:100'],
            'file'             => ['nullable', 'file', 'max:20480'],
            'issueDate'        => ['nullable', 'date'],
            'expiryDate'       => ['nullable', 'date'],
            'notes'            => ['nullable', 'string'],
            'status'           => ['sometimes', 'in:verified,expiring,expired,pending,rejected'],
        ]);

        $filePath = $document->file_path;
        $fileName = $document->file_name;
        $fileSize = $document->file_size;

        if ($request->hasFile('file')) {
            // Delete old file
            if ($filePath) {
                Storage::disk('public')->delete($filePath);
            }
            $file     = $request->file('file');
            $filePath = $file->store('candidates/' . $candidate->id . '/documents', 'public');
            $fileName = $file->getClientOriginalName();
            $fileSize = $this->humanFileSize($file->getSize());
        }

        $updateData = array_filter([
            'title'              => $data['title'] ?? null,
            'document_type_id'   => $data['documentTypeId'] ?? null,
            'document_type_name' => $data['documentTypeName'] ?? null,
            'file_path'          => $filePath,
            'file_name'          => $fileName,
            'file_size'          => $fileSize,
            'issue_date'         => $data['issueDate'] ?? null,
            'expiry_date'        => $data['expiryDate'] ?? null,
            'notes'              => $data['notes'] ?? null,
            'status'             => $data['status'] ?? null,
        ], fn ($v) => $v !== null);

        $document->update($updateData);

        return response()->json([
            'document' => $this->presentDocument($document->fresh()),
        ]);
    }

    // --------------------------------------------------------------------------
    // Delete Document — DELETE /candidates/{candidate}/documents/{document}
    // --------------------------------------------------------------------------

    public function destroyDocument(Request $request, Candidate $candidate, CandidateDocument $document): JsonResponse
    {
        $this->requirePermission($request, 'documents.delete');
        if ($document->file_path) {
            Storage::disk('public')->delete($document->file_path);
        }

        $document->delete();

        return response()->json(['message' => 'Document deleted.']);
    }

    // --------------------------------------------------------------------------
    // Renew Passport — POST /candidates/{candidate}/renew-passport
    // --------------------------------------------------------------------------

    public function renewPassport(Request $request, Candidate $candidate): JsonResponse
    {
        $this->requirePermission($request, 'candidates.update');
        $data = $request->validate([
            'passportNumber'    => ['required', 'string', 'max:50'],
            'passportExpiry'    => ['required', 'date'],
            'passportIssueDate' => ['nullable', 'date'],
            'file'              => ['nullable', 'file', 'max:20480'],
            'notes'             => ['nullable', 'string', 'max:1000'],
        ]);

        $newPassportNum = strtoupper(trim($data['passportNumber']));

        // Check if new passport number is used by another candidate (active or history)
        $existing = Candidate::whereRaw('UPPER(passport_number) = ?', [$newPassportNum])
            ->where('id', '!=', $candidate->id)
            ->first();

        if (!$existing) {
            $existing = Candidate::where(function ($q) use ($data, $newPassportNum) {
                $q->whereJsonContains('passport_history', [['passportNumber' => $data['passportNumber']]])
                  ->orWhereRaw("UPPER(passport_history::text) LIKE ?", ['%"PASSPORTNUMBER":"' . $newPassportNum . '"%']);
            })
            ->where('id', '!=', $candidate->id)
            ->first();
        }

        if ($existing) {
            return response()->json([
                'message' => "Passport number '{$data['passportNumber']}' is already registered to candidate {$existing->first_name} {$existing->last_name} ({$existing->code}). Each passport number can only be used once.",
                'errors' => [
                    'passportNumber' => ["Passport number is already registered to candidate {$existing->first_name} {$existing->last_name} ({$existing->code})."],
                ],
            ], 422);
        }

        // 1. Locate previous passport document (if any) to link in history
        $oldDoc = $candidate->documents()
            ->where(function ($q) {
                $q->where('document_type_name', 'like', '%Passport%')
                  ->orWhere('title', 'like', '%Passport%');
            })
            ->where('status', '!=', 'expired')
            ->latest()
            ->first();

        // 2. Archive current passport to history
        $currentHistory = is_array($candidate->passport_history) ? $candidate->passport_history : [];
        $historyEntry = [
            'passportNumber' => $candidate->passport_number,
            'issueDate'      => $candidate->passport_issue_date?->toDateString(),
            'expiryDate'     => $candidate->passport_expiry?->toDateString(),
            'documentId'     => $oldDoc ? (string) $oldDoc->id : null,
            'documentUrl'    => $oldDoc ? $oldDoc->file_url : null,
            'archivedAt'     => now()->toDateTimeString(),
            'notes'          => $data['notes'] ?? 'Renewed upon expiry / reissue',
        ];
        $currentHistory[] = $historyEntry;

        // Mark old passport document as expired / superseded
        if ($oldDoc) {
            $oldDoc->update([
                'status' => 'expired',
                'title'  => "Passport (Previous - {$candidate->passport_number})",
            ]);
        }

        // 3. Upload new passport document if file provided
        if ($request->hasFile('file')) {
            $file = $request->file('file');
            $filePath = $file->store('candidates/' . $candidate->id . '/documents', 'public');
            $fileName = $file->getClientOriginalName();
            $fileSize = $this->humanFileSize($file->getSize());

            $expiry = \Carbon\Carbon::parse($data['passportExpiry']);
            $docStatus = $expiry->isPast() ? 'expired' : 'verified';

            $candidate->documents()->create([
                'title'              => "Passport (Active - {$data['passportNumber']})",
                'document_type_id'   => 'doc-type-1',
                'document_type_name' => 'Passport',
                'file_path'          => $filePath,
                'file_name'          => $fileName,
                'file_size'          => $fileSize,
                'issue_date'         => $data['passportIssueDate'] ?? null,
                'expiry_date'        => $data['passportExpiry'],
                'status'             => $docStatus,
                'notes'              => "Current active passport. Replaced previous passport {$candidate->passport_number}.",
            ]);
        }

        // 4. Update candidate with new active passport details & updated history
        $candidate->update([
            'passport_number'     => $data['passportNumber'],
            'passport_expiry'     => $data['passportExpiry'],
            'passport_issue_date' => $data['passportIssueDate'] ?? null,
            'passport_history'    => $currentHistory,
        ]);

        $candidate->load(['company', 'documents', 'submissions.company', 'withdrawal']);

        return response()->json([
            'message'   => "Passport renewed successfully! Previous passport {$historyEntry['passportNumber']} has been archived to history.",
            'candidate' => $this->presentDetail($candidate->fresh()),
        ]);
    }

    // --------------------------------------------------------------------------
    // Private presenters
    // --------------------------------------------------------------------------

    private function presentList(Candidate $candidate): array
    {
        return [
            'id'                  => (string) $candidate->id,
            'code'                => $candidate->code,
            'psnCode'             => $candidate->psn_code,
            'firstName'           => $candidate->first_name,
            'lastName'            => $candidate->last_name,
            'email'               => $candidate->email ?? '',
            'passportNumber'      => $candidate->passport_number,
            'passportHistory'     => $candidate->passport_history ?? [],
            'trade'               => $candidate->trade,
            'experienceYears'     => $candidate->experience_years,
            'status'              => $candidate->status,
            'currentCompanyId'    => $candidate->current_company_id ? (string) $candidate->current_company_id : null,
            'currentCompanyName'  => $candidate->company?->name,
            'photoUrl'            => $candidate->photo_url,
            'nationality'         => $candidate->nationality,
            'documents'           => $candidate->documents->map(fn (CandidateDocument $d): array => $this->presentDocument($d))->values(),
        ];
    }

    private function presentDetail(Candidate $candidate): array
    {
        return [
            'id'                  => (string) $candidate->id,
            'code'                => $candidate->code,
            'psnCode'             => $candidate->psn_code,
            'cnicNumber'          => $candidate->cnic_number,
            'firstName'           => $candidate->first_name,
            'lastName'            => $candidate->last_name,
            'email'               => $candidate->email ?? '',
            'phone'               => $candidate->phone ?? '',
            'passportNumber'      => $candidate->passport_number,
            'passportExpiry'      => $candidate->passport_expiry?->toDateString() ?? '',
            'passportSeries'      => $candidate->passport_series,
            'passportIssuedBy'    => $candidate->passport_issued_by,
            'passportIssueDate'   => $candidate->passport_issue_date?->toDateString(),
            'passportHistory'     => $candidate->passport_history ?? [],
            'trade'               => $candidate->trade,
            'experienceYears'     => $candidate->experience_years,
            'nationality'         => $candidate->nationality,
            'currentLocation'     => $candidate->current_location,
            'targetCountry'       => $candidate->target_country ?? '',
            'assignedRecruiter'   => $candidate->assigned_recruiter ?? '',
            'status'              => $candidate->status,
            'recruitmentStage'    => $candidate->recruitment_stage,
            'currentCompanyId'    => $candidate->current_company_id ? (string) $candidate->current_company_id : null,
            'currentCompanyName'  => $candidate->company?->name,
            'joinedDate'          => $candidate->joined_date?->toDateString() ?? now()->toDateString(),
            'skills'              => $candidate->skills ?? [],
            'expectedSalary'      => (float) $candidate->expected_salary,
            'currency'            => $candidate->currency,
            'photoUrl'            => $candidate->photo_url,
            'balance'             => (float) $candidate->balance,
            'fatherName'          => $candidate->father_name,
            'motherName'          => $candidate->mother_name,
            'placeOfBirth'        => $candidate->place_of_birth,
            'dateOfBirth'         => $candidate->date_of_birth?->toDateString(),
            'civilStatus'         => $candidate->civil_status,
            'childrenCount'       => $candidate->children_count,
            'documents'           => $candidate->documents->map(fn (CandidateDocument $d): array => $this->presentDocument($d))->values(),
            'submissionHistory'   => $candidate->submissions->map(fn (CandidateSubmission $s): array => $this->presentSubmission($s))->values(),
            'withdrawalRecord'    => $candidate->withdrawal ? $this->presentWithdrawal($candidate->withdrawal) : null,
        ];
    }

    private function presentDocument(CandidateDocument $doc): array
    {
        return [
            'id'               => (string) $doc->id,
            'candidateId'      => (string) $doc->candidate_id,
            'title'            => $doc->title,
            'documentTypeId'   => $doc->document_type_id,
            'documentTypeName' => $doc->document_type_name,
            'fileUrl'          => $doc->file_url ?? '#',
            'filePreviewUrl'   => $doc->file_url,
            'fileName'         => $doc->file_name ?? '',
            'fileSize'         => $doc->file_size ?? '',
            'issueDate'        => $doc->issue_date?->toDateString() ?? '',
            'expiryDate'       => $doc->expiry_date?->toDateString() ?? '',
            'status'           => $doc->status,
            'verifiedBy'       => $doc->verified_by,
            'notes'            => $doc->notes,
        ];
    }

    private function presentSubmission(CandidateSubmission $sub): array
    {
        return [
            'id'           => (string) $sub->id,
            'candidateId'  => (string) $sub->candidate_id,
            'companyId'    => (string) $sub->company_id,
            'companyName'  => $sub->company_name,
            'shiftedAt'    => $sub->shifted_at?->format('Y-m-d H:i'),
            'note'         => $sub->note ?? '',
            'shiftedBy'    => $sub->shifted_by ?? '',
            'shareableUrl' => url('/share/' . $sub->share_token),
        ];
    }

    private function presentWithdrawal(CandidateWithdrawal $w): array
    {
        return [
            'stage'                  => $w->stage,
            'deductionPercentage'    => (float) $w->deduction_percentage,
            'totalPaidSoFar'         => (float) $w->total_paid_so_far,
            'cancellationFee'        => (float) $w->cancellation_fee,
            'refundPayable'          => (float) $w->refund_payable,
            'reason'                 => $w->reason ?? '',
            'paymentMode'            => $w->payment_mode,
            'chequeNumber'           => $w->cheque_number,
            'chequeDate'             => $w->cheque_date?->toDateString(),
            'alertFinanceBeforeDays' => $w->alert_finance_before_days,
            'status'                 => $w->status,
            'withdrawnAt'            => $w->withdrawn_at?->toDateTimeString(),
        ];
    }

    private function humanFileSize(int $bytes): string
    {
        if ($bytes >= 1024 * 1024) {
            return round($bytes / (1024 * 1024), 1) . ' MB';
        }

        return round($bytes / 1024) . ' KB';
    }
}
