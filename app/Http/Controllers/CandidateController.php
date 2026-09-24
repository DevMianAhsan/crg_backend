<?php

namespace App\Http\Controllers;

use App\Models\Candidate;
use App\Models\CandidateDocument;
use App\Models\CandidateShare;
use App\Models\CandidateSubmission;
use App\Models\CandidateWithdrawal;
use App\Models\Company;
use App\Models\DocumentType;
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
                $cleanDigits = preg_replace('/\D/', '', $search);
                $q->where('first_name', 'ilike', "%{$search}%")
                    ->orWhere('last_name', 'ilike', "%{$search}%")
                    ->orWhere('passport_number', 'ilike', "%{$search}%")
                    ->orWhere('cnic_number', 'ilike', "%{$search}%")
                    ->orWhere('code', 'ilike', "%{$search}%")
                    ->orWhere('trade', 'ilike', "%{$search}%");

                if (strlen($cleanDigits) >= 4) {
                    $q->orWhereRaw("REPLACE(REPLACE(COALESCE(cnic_number, ''), '-', ''), ' ', '') ILIKE ?", ["%{$cleanDigits}%"]);
                }
            });
        }

        if ($status = $request->query('status')) {
            if ($status === 'active') {
                $query->whereIn('status', ['available', 'active']);
                $mandatoryTypes = DocumentType::where('is_mandatory', true)->get();
                foreach ($mandatoryTypes as $dt) {
                    $query->whereHas('documents', function ($q) use ($dt): void {
                        $q->where(function ($sub) use ($dt): void {
                            $sub->where('document_type_id', (string) $dt->id)
                                ->orWhere('document_type_id', $dt->code)
                                ->orWhere('document_type_name', $dt->name);
                            if (strcasecmp($dt->code, 'PASSPORT') === 0) {
                                $sub->orWhere('document_type_id', 'doc-type-1')
                                    ->orWhere('document_type_name', 'like', '%Passport%');
                            }
                        });
                    });
                }
            } elseif ($status === 'processing') {
                $query->where(function ($q): void {
                    $q->where('status', 'processing')
                      ->orWhere(function ($subQ): void {
                          $subQ->whereIn('status', ['available', 'active']);
                          $mandatoryTypes = DocumentType::where('is_mandatory', true)->get();
                          if ($mandatoryTypes->isNotEmpty()) {
                              $subQ->where(function ($missingQ) use ($mandatoryTypes): void {
                                  foreach ($mandatoryTypes as $dt) {
                                      $missingQ->orWhereDoesntHave('documents', function ($q) use ($dt): void {
                                          $q->where(function ($inner) use ($dt): void {
                                              $inner->where('document_type_id', (string) $dt->id)
                                                  ->orWhere('document_type_id', $dt->code)
                                                  ->orWhere('document_type_name', $dt->name);
                                              if (strcasecmp($dt->code, 'PASSPORT') === 0) {
                                                  $inner->orWhere('document_type_id', 'doc-type-1')
                                                      ->orWhere('document_type_name', 'like', '%Passport%');
                                              }
                                          });
                                      });
                                  }
                              });
                          }
                      });
                });
            } else {
                $query->where('status', $status);
            }
        }

        if ($companyId = $request->query('company_id')) {
            if ($companyId === 'unassigned') {
                $query->whereNull('current_company_id');
            } else {
                $query->where('current_company_id', $companyId);
            }
        }

        if ($trade = $request->query('trade')) {
            $query->where('trade', 'ilike', "%{$trade}%");
        }

        $candidates = $query->get();

        // Calculate counts across candidates (scoped by company if filtered)
        $countsQuery = Candidate::query();
        if ($companyId = $request->query('company_id')) {
            if ($companyId === 'unassigned') {
                $countsQuery->whereNull('current_company_id');
            } else {
                $countsQuery->where('current_company_id', $companyId);
            }
        }

        $allScoped = (clone $countsQuery)->with('documents')->get();
        $totalCount = $allScoped->count();
        $placedCount = $allScoped->where('status', 'placed')->count();
        $withdrawnCount = $allScoped->where('status', 'withdrawn')->count();

        $activeCount = 0;
        $processingCount = 0;
        foreach ($allScoped as $cand) {
            if ($cand->status === 'placed' || $cand->status === 'withdrawn') {
                continue;
            }
            if ($this->candidateHasAllMandatoryDocuments($cand)) {
                $activeCount++;
            } else {
                $processingCount++;
            }
        }

        return response()->json([
            'candidates' => $candidates->map(fn (Candidate $c): array => $this->presentList($c))->values(),
            'counts' => [
                'all' => $totalCount,
                'active' => $activeCount,
                'available' => $activeCount,
                'processing' => $processingCount,
                'placed' => $placedCount,
                'withdrawn' => $withdrawnCount,
            ],
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
            'signature'        => ['nullable'],
            'fatherName'       => ['nullable', 'string', 'max:150'],
            'motherName'       => ['nullable', 'string', 'max:150'],
            'placeOfBirth'     => ['nullable', 'string', 'max:150'],
            'dateOfBirth'      => ['nullable', 'date'],
            'civilStatus'      => ['nullable', 'string', 'max:50'],
            'childrenCount'    => ['nullable', 'string', 'max:20'],
            'passportSeries'   => ['nullable', 'string', 'max:50'],
            'passportIssuedBy' => ['nullable', 'string', 'max:150'],
            'formerName'       => ['nullable', 'string', 'max:150'],
            'citizenship'      => ['nullable', 'string', 'max:100'],
            'town'             => ['nullable', 'string', 'max:150'],
            'country'          => ['nullable', 'string', 'max:100'],
            'occupationField'  => ['nullable', 'string', 'max:255'],
            'careOf'           => ['nullable', 'string', 'max:150'],
            'age'              => ['nullable', 'integer', 'min:0', 'max:150'],
            'license'          => ['nullable', 'string', 'max:150'],
            'currentJob'       => ['nullable', 'string', 'max:150'],
            'qualification'    => ['nullable', 'string', 'max:150'],
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

        // Generate guaranteed unique sequential codes
        [$code, $psnCode] = $this->generateUniqueCandidateCodes();

        // Handle photo upload
        $photoPath = null;
        if ($request->hasFile('photo')) {
            $photoPath = $request->file('photo')->store('candidates/photos', 'public');
        }

        // Handle signature upload (file or base64)
        $signaturePath = null;
        if ($request->hasFile('signature')) {
            $signaturePath = $request->file('signature')->store('candidates/signatures', 'public');
        } elseif ($request->filled('signature') && is_string($request->input('signature')) && str_starts_with($request->input('signature'), 'data:image')) {
            $base64Image = $request->input('signature');
            $imageParts = explode(';base64,', $base64Image);
            if (count($imageParts) === 2) {
                $imageTypeAux = explode('image/', $imageParts[0]);
                $imageType = $imageTypeAux[1] ?? 'png';
                $imageBase64 = base64_decode($imageParts[1]);
                if ($imageBase64 !== false) {
                    $fileName = 'candidates/signatures/' . uniqid('sig_', true) . '.' . $imageType;
                    Storage::disk('public')->put($fileName, $imageBase64);
                    $signaturePath = $fileName;
                }
            }
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
            'nationality'        => !empty($data['nationality']) ? $data['nationality'] : null,
            'current_location'   => !empty($data['currentLocation']) ? $data['currentLocation'] : null,
            'target_country'     => $data['targetCountry'] ?? null,
            'assigned_recruiter' => $data['assignedRecruiter'] ?? null,
            'trade'              => $data['trade'],
            'experience_years'   => $data['experienceYears'] ?? 0,
            'expected_salary'    => !empty($data['expectedSalary']) ? $data['expectedSalary'] : null,
            'currency'           => !empty($data['currency']) ? $data['currency'] : null,
            'photo_path'         => $photoPath,
            'signature_path'     => $signaturePath,
            'status'             => 'processing',
            'recruitment_stage'  => 'registered',
            'skills'             => $skills ?? [],
            'balance'            => $data['balance'] ?? 0,
            'joined_date'        => now()->toDateString(),
            'father_name'        => $data['fatherName'] ?? null,
            'mother_name'        => $data['motherName'] ?? null,
            'place_of_birth'     => $data['placeOfBirth'] ?? null,
            'date_of_birth'      => $data['dateOfBirth'] ?? null,
            'civil_status'       => $data['civilStatus'] ?? null,
            'children_count'     => $data['childrenCount'] ?? null,
            'passport_series'    => $data['passportSeries'] ?? null,
            'passport_issued_by' => $data['passportIssuedBy'] ?? null,
            'former_name'        => $data['formerName'] ?? null,
            'citizenship'        => $data['citizenship'] ?? null,
            'town'               => $data['town'] ?? null,
            'country'            => $data['country'] ?? null,
            'occupation_field'   => $data['occupationField'] ?? null,
            'care_of'            => $data['careOf'] ?? null,
            'age'                => $data['age'] ?? null,
            'license'            => $data['license'] ?? null,
            'current_job'        => $data['currentJob'] ?? null,
            'qualification'      => $data['qualification'] ?? null,
        ]);

        $this->syncCandidateStatus($candidate);
        $candidate->load(['company', 'documents', 'submissions', 'withdrawal']);

        return response()->json([
            'candidate' => $this->presentDetail($candidate->fresh()),
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
            'SR NO',
            'NAME',
            'FATHER NAME',
            'PASSPORT NO',
            'PASSPORT EXPIRE DATE',
            'C/O',
            'CONTACT NO',
            'RATE',
            'DATE OF BIRTH',
            'AGE',
            'FILE RECEIVING DATE',
            'MOTHER NAME',
            'MARTIAL STATUS',
            'KIDS',
            'LICENSE',
            'CURRENT JOB',
            'PLACE OF BIRTH',
            'QUALIFICATION',
            'STATUS',
            'TRADE',
        ];

        $sampleData = [
            [
                1,
                'MUHAMMAD UMAIR',
                'MUHAMMAD YOUSAF TAHIR',
                'CK2298911',
                '23.04.2035',
                'SELF',
                '0370-3849513 / 0317-1798225',
                '4,000,000',
                '28.04.2006',
                20,
                '07.09.2026',
                'NUSRAT PARVEEN',
                'SINGLE',
                'N/A',
                'BIKE',
                'FREE',
                'MANDI BAHAUDDIN, PAK',
                'MIDDLE',
                'available',
                '',
            ],
            [
                2,
                'TARIQ MAHMOOD',
                'MAHMOOD KHAN',
                'CC9876543',
                '15.10.2032',
                'SELF',
                '0321-7654321',
                '3,500,000',
                '15.08.1998',
                28,
                '10.09.2026',
                'FATIMA BIBI',
                'MARRIED',
                '2',
                'CAR',
                'ELECTRICIAN',
                'RAWALPINDI, PAK',
                'MATRIC',
                'available',
                'Electrician',
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
    // Export Selected Candidates — POST /candidates/export
    // --------------------------------------------------------------------------

    public function exportExcel(Request $request): StreamedResponse
    {
        $this->requirePermission($request, 'candidates.view');

        $format = strtolower($request->input('format', 'xlsx'));
        $candidateIds = $request->input('candidate_ids', []);
        $selectedFields = $request->input('fields', []);

        if (is_string($candidateIds)) {
            $candidateIds = array_values(array_filter(array_map('trim', explode(',', $candidateIds))));
        }

        $query = Candidate::with(['company', 'documents', 'withdrawal']);
        if (!empty($candidateIds)) {
            $query->whereIn('id', $candidateIds);
        }

        $candidates = $query->orderBy('id', 'asc')->get();

        $fieldDefinitions = $this->getExportFieldDefinitions();

        // If no fields specified, use standard default fields
        if (empty($selectedFields) || !is_array($selectedFields)) {
            $selectedFields = [
                'code',
                'name',
                'father_name',
                'passport_number',
                'passport_expiry',
                'cnic_number',
                'phone',
                'trade',
                'experience_years',
                'company',
                'status',
            ];
        }

        $fieldsToExport = [];
        foreach ($selectedFields as $f) {
            $key = $this->normalizeFieldKey((string) $f);
            if (isset($fieldDefinitions[$key])) {
                $fieldsToExport[$key] = $fieldDefinitions[$key];
            }
        }

        if (empty($fieldsToExport)) {
            $fieldsToExport = $fieldDefinitions;
        }

        $headers = array_column(array_values($fieldsToExport), 'label');
        $timestamp = now()->format('Y-m-d_His');

        if ($format === 'csv') {
            $filename = "candidates_export_{$timestamp}.csv";
            return response()->streamDownload(function () use ($headers, $fieldsToExport, $candidates): void {
                $file = fopen('php://output', 'w');
                fprintf($file, chr(0xEF) . chr(0xBB) . chr(0xBF)); // UTF-8 BOM
                fputcsv($file, $headers);

                foreach ($candidates as $cand) {
                    $row = [];
                    foreach ($fieldsToExport as $fieldDef) {
                        $resolver = $fieldDef['resolver'];
                        $row[] = (string) $resolver($cand);
                    }
                    fputcsv($file, $row);
                }
                fclose($file);
            }, $filename, [
                'Content-Type' => 'text/csv; charset=UTF-8',
            ]);
        }

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Selected Candidates');

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
        $sheet->getRowDimension(1)->setRowHeight(28);

        // Freeze top header row
        $sheet->freezePane('A2');

        // Rows
        $rowNum = 2;
        foreach ($candidates as $cand) {
            $colNum = 1;
            foreach ($fieldsToExport as $fieldDef) {
                $resolver = $fieldDef['resolver'];
                $val = (string) $resolver($cand);
                $sheet->setCellValueExplicit([$colNum, $rowNum], $val, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                $colNum++;
            }

            // Zebra striping
            if ($rowNum % 2 === 1) {
                $rowRange = "A{$rowNum}:{$highestCol}{$rowNum}";
                $sheet->getStyle($rowRange)->getFill()->setFillType(Fill::FILL_SOLID)
                    ->getStartColor()->setRGB('F8FAFC');
            }
            $sheet->getRowDimension($rowNum)->setRowHeight(22);
            $rowNum++;
        }

        $filename = "candidates_export_{$timestamp}.xlsx";
        $writer = new Xlsx($spreadsheet);

        return response()->streamDownload(function () use ($writer): void {
            $writer->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    private function normalizeFieldKey(string $key): string
    {
        $clean = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', trim($key)));
        $aliases = [
            'candidate_name'       => 'name',
            'full_name'            => 'name',
            'company_name'         => 'company',
            'current_company_name' => 'company',
            'current_company'      => 'company',
            'current_company_id'   => 'company',
            'passport_no'          => 'passport_number',
            'passport'             => 'passport_number',
            'contact'              => 'phone',
            'contact_no'           => 'phone',
            'rate'                 => 'expected_salary',
            'salary'               => 'expected_salary',
            'exp'                  => 'experience_years',
            'experience'           => 'experience_years',
            'martial_status'       => 'civil_status',
            'marital_status'       => 'civil_status',
            'kids'                 => 'children_count',
            'file_receiving_date'  => 'joined_date',
        ];

        return $aliases[$clean] ?? $clean;
    }

    private function getExportFieldDefinitions(): array
    {
        return [
            // Basic & Identification
            'code' => [
                'label'    => 'Candidate Code',
                'category' => 'Basic & Identification',
                'resolver' => fn ($c) => $c->code ?? '',
            ],
            'psn_code' => [
                'label'    => 'PSN Code',
                'category' => 'Basic & Identification',
                'resolver' => fn ($c) => $c->psn_code ?? '',
            ],
            'status' => [
                'label'    => 'Status',
                'category' => 'Basic & Identification',
                'resolver' => fn ($c) => strtoupper($c->status ?? ''),
            ],
            'recruitment_stage' => [
                'label'    => 'Recruitment Stage',
                'category' => 'Basic & Identification',
                'resolver' => fn ($c) => ucwords(str_replace('_', ' ', $c->recruitment_stage ?? '')),
            ],
            'joined_date' => [
                'label'    => 'File Receiving / Joined Date',
                'category' => 'Basic & Identification',
                'resolver' => fn ($c) => $c->joined_date ? \Carbon\Carbon::parse($c->joined_date)->format('d/m/Y') : '',
            ],

            // Personal Information
            'name' => [
                'label'    => 'Candidate Name (Full)',
                'category' => 'Personal Information',
                'resolver' => fn ($c) => trim(($c->first_name ?? '') . ' ' . ($c->last_name ?? '')),
            ],
            'first_name' => [
                'label'    => 'First Name',
                'category' => 'Personal Information',
                'resolver' => fn ($c) => $c->first_name ?? '',
            ],
            'last_name' => [
                'label'    => 'Last Name',
                'category' => 'Personal Information',
                'resolver' => fn ($c) => $c->last_name ?? '',
            ],
            'father_name' => [
                'label'    => 'Father Name',
                'category' => 'Personal Information',
                'resolver' => fn ($c) => $c->father_name ?? '',
            ],
            'mother_name' => [
                'label'    => 'Mother Name',
                'category' => 'Personal Information',
                'resolver' => fn ($c) => $c->mother_name ?? '',
            ],
            'care_of' => [
                'label'    => 'C/O (Care Of)',
                'category' => 'Personal Information',
                'resolver' => fn ($c) => $c->care_of ?? '',
            ],
            'date_of_birth' => [
                'label'    => 'Date of Birth',
                'category' => 'Personal Information',
                'resolver' => fn ($c) => $c->date_of_birth ? \Carbon\Carbon::parse($c->date_of_birth)->format('d/m/Y') : '',
            ],
            'age' => [
                'label'    => 'Age',
                'category' => 'Personal Information',
                'resolver' => fn ($c) => $c->age ?? '',
            ],
            'place_of_birth' => [
                'label'    => 'Place of Birth',
                'category' => 'Personal Information',
                'resolver' => fn ($c) => $c->place_of_birth ?? '',
            ],
            'civil_status' => [
                'label'    => 'Marital / Civil Status',
                'category' => 'Personal Information',
                'resolver' => fn ($c) => ucfirst($c->civil_status ?? ''),
            ],
            'children_count' => [
                'label'    => 'Children Count',
                'category' => 'Personal Information',
                'resolver' => fn ($c) => $c->children_count ?? '',
            ],
            'license' => [
                'label'    => 'License',
                'category' => 'Personal Information',
                'resolver' => fn ($c) => $c->license ?? '',
            ],
            'former_name' => [
                'label'    => 'Former Name',
                'category' => 'Personal Information',
                'resolver' => fn ($c) => $c->former_name ?? '',
            ],

            // Passport & Identity
            'passport_number' => [
                'label'    => 'Passport Number',
                'category' => 'Passport & Identity',
                'resolver' => fn ($c) => $c->passport_number ?? '',
            ],
            'passport_expiry' => [
                'label'    => 'Passport Expiry Date',
                'category' => 'Passport & Identity',
                'resolver' => fn ($c) => $c->passport_expiry ? \Carbon\Carbon::parse($c->passport_expiry)->format('d/m/Y') : '',
            ],
            'passport_issue_date' => [
                'label'    => 'Passport Issue Date',
                'category' => 'Passport & Identity',
                'resolver' => fn ($c) => $c->passport_issue_date ? \Carbon\Carbon::parse($c->passport_issue_date)->format('d/m/Y') : '',
            ],
            'passport_series' => [
                'label'    => 'Passport Series',
                'category' => 'Passport & Identity',
                'resolver' => fn ($c) => $c->passport_series ?? '',
            ],
            'passport_issued_by' => [
                'label'    => 'Passport Issued By',
                'category' => 'Passport & Identity',
                'resolver' => fn ($c) => $c->passport_issued_by ?? '',
            ],
            'cnic_number' => [
                'label'    => 'CNIC / Identity Number',
                'category' => 'Passport & Identity',
                'resolver' => fn ($c) => $c->cnic_number ?? '',
            ],
            'nationality' => [
                'label'    => 'Nationality',
                'category' => 'Passport & Identity',
                'resolver' => fn ($c) => $c->nationality ?? '',
            ],
            'citizenship' => [
                'label'    => 'Citizenship',
                'category' => 'Passport & Identity',
                'resolver' => fn ($c) => $c->citizenship ?? '',
            ],

            // Contact & Location
            'phone' => [
                'label'    => 'Phone / Contact Number',
                'category' => 'Contact & Location',
                'resolver' => fn ($c) => $c->phone ?? '',
            ],
            'email' => [
                'label'    => 'Email Address',
                'category' => 'Contact & Location',
                'resolver' => fn ($c) => $c->email ?? '',
            ],
            'current_location' => [
                'label'    => 'Current Location / Address',
                'category' => 'Contact & Location',
                'resolver' => fn ($c) => $c->current_location ?? '',
            ],
            'town' => [
                'label'    => 'Town / City',
                'category' => 'Contact & Location',
                'resolver' => fn ($c) => $c->town ?? '',
            ],
            'country' => [
                'label'    => 'Country',
                'category' => 'Contact & Location',
                'resolver' => fn ($c) => $c->country ?? '',
            ],
            'target_country' => [
                'label'    => 'Target Country',
                'category' => 'Contact & Location',
                'resolver' => fn ($c) => $c->target_country ?? '',
            ],

            // Professional & Experience
            'trade' => [
                'label'    => 'Trade / Role',
                'category' => 'Professional & Experience',
                'resolver' => fn ($c) => $c->trade ?? '',
            ],
            'occupation_field' => [
                'label'    => 'Occupation Field',
                'category' => 'Professional & Experience',
                'resolver' => fn ($c) => $c->occupation_field ?? '',
            ],
            'experience_years' => [
                'label'    => 'Experience (Years)',
                'category' => 'Professional & Experience',
                'resolver' => fn ($c) => isset($c->experience_years) ? "{$c->experience_years} yrs" : '0 yrs',
            ],
            'current_job' => [
                'label'    => 'Current Job',
                'category' => 'Professional & Experience',
                'resolver' => fn ($c) => $c->current_job ?? '',
            ],
            'qualification' => [
                'label'    => 'Qualification / Education',
                'category' => 'Professional & Experience',
                'resolver' => fn ($c) => $c->qualification ?? '',
            ],
            'skills' => [
                'label'    => 'Skills',
                'category' => 'Professional & Experience',
                'resolver' => fn ($c) => is_array($c->skills) ? implode(', ', $c->skills) : ($c->skills ?? ''),
            ],
            'cv_summary' => [
                'label'    => 'CV Summary / Profile',
                'category' => 'Professional & Experience',
                'resolver' => fn ($c) => $c->cv_summary ?? '',
            ],

            // Company & Financial
            'company' => [
                'label'    => 'Assigned Company',
                'category' => 'Company & Financial',
                'resolver' => fn ($c) => $c->company ? $c->company->name : 'Unassigned',
            ],
            'assigned_recruiter' => [
                'label'    => 'Assigned Recruiter',
                'category' => 'Company & Financial',
                'resolver' => fn ($c) => $c->assigned_recruiter ?? '',
            ],
            'expected_salary' => [
                'label'    => 'Rate / Expected Salary',
                'category' => 'Company & Financial',
                'resolver' => fn ($c) => $c->expected_salary ? number_format((float) $c->expected_salary, 2) : '0.00',
            ],
            'currency' => [
                'label'    => 'Currency',
                'category' => 'Company & Financial',
                'resolver' => fn ($c) => $c->currency ?? 'AED',
            ],
            'balance' => [
                'label'    => 'Financial Balance',
                'category' => 'Company & Financial',
                'resolver' => fn ($c) => $c->balance ? number_format((float) $c->balance, 2) : '0.00',
            ],

            // Documents & Compliance
            'documents_count' => [
                'label'    => 'Total Uploaded Documents',
                'category' => 'Documents & Compliance',
                'resolver' => fn ($c) => (string) ($c->documents ? $c->documents->count() : 0),
            ],
            'documents_list' => [
                'label'    => 'Document Names',
                'category' => 'Documents & Compliance',
                'resolver' => fn ($c) => $c->documents ? $c->documents->pluck('title')->filter()->implode(', ') : '',
            ],
            'work_permit_status' => [
                'label'    => 'Work Permit Issued',
                'category' => 'Documents & Compliance',
                'resolver' => function ($c) {
                    if (!$c->documents || $c->documents->isEmpty()) return 'No';
                    $hasPermit = $c->documents->contains(function ($doc) {
                        $t = strtolower(($doc->title ?? '') . ' ' . ($doc->document_type_name ?? ''));
                        return str_contains($t, 'permit');
                    });
                    return $hasPermit ? 'Yes' : 'No';
                },
            ],
        ];
    }

    // --------------------------------------------------------------------------
    // Bulk Store / Preview — POST /candidates/bulk
    // --------------------------------------------------------------------------

    public function bulkStore(Request $request): JsonResponse
    {
        $this->requirePermission($request, 'candidates.create');

        @set_time_limit(300);
        @ini_set('memory_limit', '512M');

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

        // Pre-extract all candidate passport numbers to batch-query existing database records in 1 query
        $allPassportsToLookup = [];
        foreach ($rawRows as $r) {
            $p = strtoupper(trim((string) ($r['passportNumber'] ?? '')));
            if (!empty($p)) {
                $allPassportsToLookup[] = $p;
            }
        }
        $allPassportsToLookup = array_values(array_unique($allPassportsToLookup));

        $existingCandidatesMap = [];
        if (!empty($allPassportsToLookup)) {
            // 1. Direct passport_number lookup in chunks of 500
            foreach (array_chunk($allPassportsToLookup, 500) as $chunk) {
                $found = Candidate::whereIn(DB::raw('UPPER(passport_number)'), $chunk)
                    ->get(['id', 'code', 'first_name', 'last_name', 'passport_number']);
                foreach ($found as $cand) {
                    $existingCandidatesMap[strtoupper($cand->passport_number)] = $cand;
                }
            }

            // 2. Check historical passports if any remain unmatched
            $unmatchedPassports = array_values(array_diff($allPassportsToLookup, array_keys($existingCandidatesMap)));
            if (!empty($unmatchedPassports)) {
                $historyCandidates = Candidate::whereNotNull('passport_history')
                    ->where(function ($q) use ($unmatchedPassports): void {
                        foreach (array_chunk($unmatchedPassports, 50) as $chunk) {
                            $q->orWhere(function ($sq) use ($chunk): void {
                                foreach ($chunk as $p) {
                                    $sq->orWhereRaw("UPPER(passport_history::text) LIKE ?", ['%"' . $p . '"%']);
                                }
                            });
                        }
                    })->get(['id', 'code', 'first_name', 'last_name', 'passport_history']);

                foreach ($historyCandidates as $cand) {
                    $history = (array) ($cand->passport_history ?? []);
                    foreach ($history as $hItem) {
                        $histP = strtoupper(trim((string) ($hItem['passportNumber'] ?? '')));
                        if (in_array($histP, $unmatchedPassports, true) && !isset($existingCandidatesMap[$histP])) {
                            $existingCandidatesMap[$histP] = $cand;
                        }
                    }
                }
            }
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
                    // Fast in-memory check against pre-fetched database records
                    $existing = $existingCandidatesMap[$rawPassport] ?? null;

                    if ($existing) {
                        $errors[] = "Passport '{$rawPassport}' is already registered to candidate {$existing->first_name} {$existing->last_name} ({$existing->code}).";
                    } else {
                        $batchPassports[] = $rawPassport;
                    }
                }
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

        DB::beginTransaction();
        try {
            // Find highest existing numeric code across all existing candidates
            $maxExistingCodeNum = 1000;
            $allCodes = Candidate::withTrashed()->pluck('code')->all();
            foreach ($allCodes as $c) {
                if ($c && preg_match('/CRG-(\d+)/i', $c, $m)) {
                    $num = (int) $m[1];
                    if ($num > $maxExistingCodeNum) {
                        $maxExistingCodeNum = $num;
                    }
                }
            }

            $currentYear = now()->year;
            $maxPsn = 0;
            $allPsn = Candidate::withTrashed()
                ->where('psn_code', 'LIKE', "PSN-{$currentYear}-%")
                ->pluck('psn_code')
                ->all();
            foreach ($allPsn as $p) {
                if ($p && preg_match('/PSN-\d+-(\d+)/i', $p, $m)) {
                    $pNum = (int) $m[1];
                    if ($pNum > $maxPsn) {
                        $maxPsn = $pNum;
                    }
                }
            }

            $counter = $maxExistingCodeNum + 1;
            $psnCounter = $maxPsn + 1;

            foreach ($validCandidatesToInsert as $item) {
                $cData = $item['data'];

                while (Candidate::withTrashed()->where('code', 'CRG-' . str_pad((string) $counter, 4, '0', STR_PAD_LEFT))->exists()) {
                    $counter++;
                }
                $code = 'CRG-' . str_pad((string) $counter, 4, '0', STR_PAD_LEFT);
                $counter++;

                while (Candidate::withTrashed()->where('psn_code', 'PSN-' . $currentYear . '-' . str_pad((string) $psnCounter, 4, '0', STR_PAD_LEFT))->exists()) {
                    $psnCounter++;
                }
                $psnCode = 'PSN-' . $currentYear . '-' . str_pad((string) $psnCounter, 4, '0', STR_PAD_LEFT);
                $psnCounter++;

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
                    'last_name'           => $cData['lastName'] ?: null,
                    'email'               => !empty($cData['email']) ? $cData['email'] : null,
                    'phone'               => !empty($cData['phone']) ? $cData['phone'] : null,
                    'passport_number'     => $cData['passportNumber'],
                    'passport_expiry'     => $cData['passportExpiry'] ?: null,
                    'passport_issue_date' => $cData['passportIssueDate'] ?: null,
                    'cnic_number'         => !empty($cData['cnicNumber']) ? $cData['cnicNumber'] : null,
                    'nationality'         => $cData['nationality'] ?: null,
                    'current_location'    => $cData['currentLocation'] ?: null,
                    'target_country'      => $cData['targetCountry'] ?: null,
                    'assigned_recruiter'  => $cData['assignedRecruiter'] ?: null,
                    'trade'               => $cData['trade'] ?: null,
                    'experience_years'    => (int) ($cData['experienceYears'] ?? 0),
                    'expected_salary'     => (float) ($cData['expectedSalary'] ?? 0),
                    'currency'            => $cData['currency'] ?: null,
                    'status'              => in_array($cData['status'] ?? '', ['placed', 'withdrawn'], true) ? $cData['status'] : 'processing',
                    'recruitment_stage'   => 'registered',
                    'skills'              => $skills,
                    'balance'             => (float) ($cData['balance'] ?? 0),
                    'father_name'         => $cData['fatherName'] ?: null,
                    'mother_name'         => $cData['motherName'] ?: null,
                    'place_of_birth'      => $cData['placeOfBirth'] ?: null,
                    'date_of_birth'       => $cData['dateOfBirth'] ?: null,
                    'age'                 => !empty($cData['age']) ? (int) $cData['age'] : null,
                    'civil_status'        => $cData['civilStatus'] ?: null,
                    'children_count'      => $cData['childrenCount'] ?: null,
                    'care_of'             => $cData['careOf'] ?: null,
                    'license'             => $cData['license'] ?: null,
                    'current_job'         => $cData['currentJob'] ?: null,
                    'qualification'       => $cData['qualification'] ?: null,
                    'joined_date'         => $cData['joinedDate'] ?: null,
                ]);

                $this->syncCandidateStatus($newCandidate);
                $insertedCandidates[] = $this->presentList($newCandidate->fresh());
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
            // Excel file via PhpSpreadsheet (optimized for memory & speed)
            $reader = IOFactory::createReaderForFile($path);
            $reader->setReadDataOnly(true);
            $spreadsheet = $reader->load($path);
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
            'name'               => ['name', 'fullname', 'full_name', 'candidatename', 'candidate_name'],
            'firstName'          => ['firstname', 'first_name', 'first', 'givenname', 'given_name', 'fname'],
            'lastName'           => ['lastname', 'last_name', 'last', 'surname', 'familyname', 'family_name', 'lname'],
            'fatherName'         => ['fathername', 'father_name', 'father'],
            'passportNumber'     => ['passportno', 'passport_no', 'passportnumber', 'passport_number', 'passport', 'passno', 'pass_no'],
            'careOf'             => ['co', 'c_o', 'careof', 'care_of'],
            'phone'              => ['contactno', 'contact_no', 'contact', 'contactnumber', 'phone', 'phonenumber', 'phone_number', 'mobile', 'mobilenumber', 'mobile_no'],
            'balance'            => ['rate', 'package_rate', 'packagerate', 'contract_rate', 'contractrate', 'balance', 'fee'],
            'dateOfBirth'        => ['dateofbirth', 'date_of_birth', 'dob', 'birthdate', 'birth_date'],
            'age'                => ['age'],
            'joinedDate'         => ['filereceivingdate', 'file_receiving_date', 'receivingdate', 'receiving_date', 'filedate', 'file_date', 'joineddate', 'joined_date'],
            'motherName'         => ['mothername', 'mother_name', 'mother'],
            'civilStatus'        => ['martialstatus', 'maritalstatus', 'martial_status', 'marital_status', 'civilstatus', 'civil_status'],
            'childrenCount'      => ['kids', 'children', 'childrencount', 'children_count', 'kidscount', 'kids_count', 'no_of_kids'],
            'license'            => ['license', 'licence', 'driving_license', 'drivinglicense'],
            'currentJob'         => ['currentjob', 'current_job', 'present_job', 'occupation', 'job'],
            'placeOfBirth'       => ['placeofbirth', 'place_of_birth', 'birthplace', 'birth_place', 'pob'],
            'qualification'      => ['qualification', 'education', 'academic_qualification', 'degree'],
            'status'             => ['status'],
            'trade'              => ['trade', 'jobrole', 'job_role', 'role', 'position', 'designation', 'profession'],
            'cnicNumber'         => ['cnicnumber', 'cnic_number', 'cnic', 'cnic_no', 'nationalid', 'national_id', 'idnumber', 'id_number'],
            'email'              => ['email', 'emailaddress', 'email_address', 'e_mail'],
            'nationality'        => ['nationality', 'country_of_origin', 'citizenship'],
            'currentLocation'    => ['currentlocation', 'current_location', 'location', 'city', 'residence'],
            'targetCountry'      => ['targetcountry', 'target_country', 'destination', 'destination_country'],
            'experienceYears'    => ['experienceyears', 'experience_years', 'experience', 'experience_yrs', 'exp', 'years_of_experience'],
            'expectedSalary'     => ['expectedsalary', 'expected_salary', 'salary', 'monthly_salary', 'basic_salary'],
            'currency'           => ['currency'],
            'assignedRecruiter'  => ['assignedrecruiter', 'assigned_recruiter', 'recruiter'],
            'skills'             => ['skills', 'skillset', 'skill'],
            'passportExpiry'     => [
                'passportexpiry', 'passport_expiry', 'passportexpiredate', 'passport_expire_date',
                'passportexpire', 'passport_expire', 'expiredate', 'expire_date', 'expirydate',
                'expiry_date', 'passportexpirydate', 'passport_expiry_date', 'expiry', 'expire',
            ],
            'passportIssueDate'  => ['passportissuedate', 'passport_issue_date', 'issuedate', 'issue_date', 'date_of_issue'],
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
            $lastName  = $parts[1] ?? '';
        }

        // Clean Rate / Balance: e.g. "4,000,000" -> 4000000.00
        $balanceRaw = $row['balance'] ?? $row['rate'] ?? null;
        $balance = 0.0;
        if ($balanceRaw !== null && $balanceRaw !== '') {
            if (is_numeric($balanceRaw)) {
                $balance = (float) $balanceRaw;
            } elseif (is_string($balanceRaw)) {
                $cleaned = preg_replace('/[^\d.]/', '', $balanceRaw);
                $balance = is_numeric($cleaned) ? (float) $cleaned : 0.0;
            }
        }

        $dob = $this->formatCandidateDate($row['dateOfBirth'] ?? null);
        $age = !empty($row['age']) ? (int) $row['age'] : null;
        if ($age === null && !empty($dob)) {
            try {
                $age = (new \DateTime($dob))->diff(new \DateTime())->y;
            } catch (\Throwable) {
                $age = null;
            }
        }

        $joinedDate = $this->formatCandidateDate($row['joinedDate'] ?? $row['fileReceivingDate'] ?? null);

        $statusRaw = strtolower(trim((string) ($row['status'] ?? 'processing')));
        $validStatuses = ['available', 'placed', 'processing', 'active', 'withdrawn', 'archived'];
        $status = in_array($statusRaw, $validStatuses, true) ? $statusRaw : 'processing';

        return [
            'firstName'          => $firstName,
            'lastName'           => $lastName ?: null,
            'fatherName'         => trim((string) ($row['fatherName'] ?? '')) ?: null,
            'passportNumber'     => strtoupper(trim((string) ($row['passportNumber'] ?? ''))),
            'careOf'             => trim((string) ($row['careOf'] ?? '')) ?: null,
            'phone'              => trim((string) ($row['phone'] ?? '')) ?: null,
            'balance'            => max(0, $balance),
            'dateOfBirth'        => $dob,
            'age'                => $age,
            'joinedDate'         => $joinedDate,
            'motherName'         => trim((string) ($row['motherName'] ?? '')) ?: null,
            'civilStatus'        => trim((string) ($row['civilStatus'] ?? '')) ?: null,
            'childrenCount'      => trim((string) ($row['childrenCount'] ?? '')) ?: null,
            'license'            => trim((string) ($row['license'] ?? '')) ?: null,
            'currentJob'         => trim((string) ($row['currentJob'] ?? '')) ?: null,
            'placeOfBirth'       => trim((string) ($row['placeOfBirth'] ?? '')) ?: null,
            'qualification'      => trim((string) ($row['qualification'] ?? '')) ?: null,
            'status'             => $status,
            'trade'              => trim((string) ($row['trade'] ?? '')) ?: null,
            'passportExpiry'     => $this->formatCandidateDate($row['passportExpiry'] ?? null),
            'passportIssueDate'  => $this->formatCandidateDate($row['passportIssueDate'] ?? null),
            'cnicNumber'         => trim((string) ($row['cnicNumber'] ?? '')) ?: null,
            'email'              => trim((string) ($row['email'] ?? '')) ?: null,
            'nationality'        => trim((string) ($row['nationality'] ?? '')) ?: null,
            'currentLocation'    => trim((string) ($row['currentLocation'] ?? '')) ?: null,
            'targetCountry'      => trim((string) ($row['targetCountry'] ?? '')) ?: null,
            'experienceYears'    => max(0, (int) ($row['experienceYears'] ?? 0)),
            'expectedSalary'     => max(0, (float) ($row['expectedSalary'] ?? 0)),
            'currency'           => trim((string) ($row['currency'] ?? '')) ?: null,
            'assignedRecruiter'  => trim((string) ($row['assignedRecruiter'] ?? '')) ?: null,
            'skills'             => $row['skills'] ?? '',
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

        // Support YYYY-MM-DD or YYYY.MM.DD or YYYY/MM/DD
        if (preg_match('/^(\d{4})[\.\/\-](\d{1,2})[\.\/\-](\d{1,2})$/', $str, $matches)) {
            return sprintf('%04d-%02d-%02d', (int) $matches[1], (int) $matches[2], (int) $matches[3]);
        }

        // Support DD.MM.YYYY or DD/MM/YYYY or DD-MM-YYYY
        if (preg_match('/^(\d{1,2})[\.\/\-](\d{1,2})[\.\/\-](\d{4})$/', $str, $matches)) {
            return sprintf('%04d-%02d-%02d', (int) $matches[3], (int) $matches[2], (int) $matches[1]);
        }

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
            'lastName'         => ['nullable', 'string', 'max:100'],
            'email'            => ['nullable', 'email', 'max:255'],
            'phone'            => ['nullable', 'string', 'max:50'],
            'passportNumber'   => ['required', 'string', 'max:50'],
            'passportExpiry'   => ['nullable', 'date'],
            'passportIssueDate'=> ['nullable', 'date'],
            'cnicNumber'       => ['nullable', 'string', 'max:20'],
            'nationality'      => ['nullable', 'string', 'max:100'],
            'currentLocation'  => ['nullable', 'string', 'max:200'],
            'targetCountry'    => ['nullable', 'string', 'max:200'],
            'assignedRecruiter'=> ['nullable', 'string', 'max:100'],
            'trade'            => ['nullable', 'string', 'max:255'],
            'experienceYears'  => ['nullable', 'integer', 'min:0'],
            'expectedSalary'   => ['nullable', 'numeric', 'min:0'],
            'currency'         => ['nullable', 'string', 'max:10'],
            'balance'          => ['nullable', 'numeric', 'min:0'],
            'photo'            => ['nullable', 'image', 'max:5120'],
            'fatherName'       => ['nullable', 'string', 'max:150'],
            'motherName'       => ['nullable', 'string', 'max:150'],
            'placeOfBirth'     => ['nullable', 'string', 'max:150'],
            'dateOfBirth'      => ['nullable', 'date'],
            'civilStatus'      => ['nullable', 'string', 'max:50'],
            'childrenCount'    => ['nullable', 'string', 'max:20'],
            'passportSeries'   => ['nullable', 'string', 'max:50'],
            'passportIssuedBy' => ['nullable', 'string', 'max:150'],
            'formerName'       => ['nullable', 'string', 'max:150'],
            'citizenship'      => ['nullable', 'string', 'max:100'],
            'town'             => ['nullable', 'string', 'max:150'],
            'country'          => ['nullable', 'string', 'max:100'],
            'occupationField'  => ['nullable', 'string', 'max:255'],
            'cvSummary'        => ['nullable', 'string'],
            'cvData'           => ['nullable', 'array'],
            'careOf'           => ['nullable', 'string', 'max:150'],
            'age'              => ['nullable', 'integer', 'min:0', 'max:150'],
            'license'          => ['nullable', 'string', 'max:150'],
            'currentJob'       => ['nullable', 'string', 'max:150'],
            'qualification'    => ['nullable', 'string', 'max:150'],
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

        $signaturePath = $candidate->signature_path;
        if ($request->hasFile('signature')) {
            if ($signaturePath) {
                Storage::disk('public')->delete($signaturePath);
            }
            $signaturePath = $request->file('signature')->store('candidates/signatures', 'public');
        } elseif ($request->filled('signature') && is_string($request->input('signature')) && str_starts_with($request->input('signature'), 'data:image')) {
            if ($signaturePath) {
                Storage::disk('public')->delete($signaturePath);
            }
            $base64Image = $request->input('signature');
            $imageParts = explode(';base64,', $base64Image);
            if (count($imageParts) === 2) {
                $imageTypeAux = explode('image/', $imageParts[0]);
                $imageType = $imageTypeAux[1] ?? 'png';
                $imageBase64 = base64_decode($imageParts[1]);
                if ($imageBase64 !== false) {
                    $fileName = 'candidates/signatures/' . uniqid('sig_', true) . '.' . $imageType;
                    Storage::disk('public')->put($fileName, $imageBase64);
                    $signaturePath = $fileName;
                }
            }
        } elseif ($request->boolean('removeSignature')) {
            if ($signaturePath) {
                Storage::disk('public')->delete($signaturePath);
            }
            $signaturePath = null;
        }

        $skills = $request->input('skills');
        if (is_string($skills)) {
            $decoded = json_decode($skills, true);
            $skills  = is_array($decoded) ? $decoded : [$skills];
        }
        $tradeValue = array_key_exists('trade', $data) ? $data['trade'] : $candidate->trade;
        if ($skills === null && !empty($tradeValue)) {
            $skills = array_values(array_filter(array_map('trim', explode(',', $tradeValue))));
        }

        $cvSummary = array_key_exists('cvSummary', $data) ? $data['cvSummary'] : $candidate->cv_summary;
        $cvData    = array_key_exists('cvData', $data) ? $data['cvData'] : $candidate->cv_data;
        $expYears  = (int) (array_key_exists('experienceYears', $data) ? $data['experienceYears'] : ($candidate->experience_years ?? 0));

        if (!empty($cvSummary) && str_contains($cvSummary, 'with over 0 years')) {
            if ($expYears > 0) {
                $cvSummary = str_replace('with over 0 years', "with over {$expYears} " . ($expYears === 1 ? 'year' : 'years'), $cvSummary);
            } else {
                $cvSummary = str_replace('with over 0 years of hands-on expertise', 'with strong practical skills and proven capabilities', $cvSummary);
            }
            if (is_array($cvData)) {
                $cvData['summary'] = $cvSummary;
            }
        }

        $candidate->forceFill([
            'first_name'         => $data['firstName'],
            'last_name'          => array_key_exists('lastName', $data) ? $data['lastName'] : $candidate->last_name,
            'email'              => $data['email'] ?? null,
            'phone'              => $data['phone'] ?? null,
            'passport_number'    => $data['passportNumber'],
            'passport_expiry'    => $data['passportExpiry'] ?? null,
            'passport_issue_date'=> array_key_exists('passportIssueDate', $data) ? $data['passportIssueDate'] : $candidate->passport_issue_date,
            'cnic_number'        => $data['cnicNumber'] ?? null,
            'nationality'        => $data['nationality'] ?? 'Pakistani',
            'current_location'   => $data['currentLocation'] ?? 'Pakistan',
            'target_country'     => $data['targetCountry'] ?? null,
            'assigned_recruiter' => $data['assignedRecruiter'] ?? null,
            'trade'              => $tradeValue,
            'experience_years'   => $data['experienceYears'] ?? 0,
            'expected_salary'    => array_key_exists('expectedSalary', $data) ? (!empty($data['expectedSalary']) ? $data['expectedSalary'] : null) : $candidate->expected_salary,
            'currency'           => array_key_exists('currency', $data) ? (!empty($data['currency']) ? $data['currency'] : null) : $candidate->currency,
            'balance'            => array_key_exists('balance', $data) ? ($data['balance'] ?? 0) : $candidate->balance,
            'skills'             => $skills ?? $candidate->skills,
            'photo_path'         => $photoPath,
            'signature_path'     => $signaturePath,
            'father_name'        => array_key_exists('fatherName', $data) ? $data['fatherName'] : $candidate->father_name,
            'mother_name'        => array_key_exists('motherName', $data) ? $data['motherName'] : $candidate->mother_name,
            'place_of_birth'     => array_key_exists('placeOfBirth', $data) ? $data['placeOfBirth'] : $candidate->place_of_birth,
            'date_of_birth'      => array_key_exists('dateOfBirth', $data) ? $data['dateOfBirth'] : $candidate->date_of_birth,
            'civil_status'       => array_key_exists('civilStatus', $data) ? $data['civilStatus'] : $candidate->civil_status,
            'children_count'     => array_key_exists('childrenCount', $data) ? $data['childrenCount'] : $candidate->children_count,
            'passport_series'    => array_key_exists('passportSeries', $data) ? $data['passportSeries'] : $candidate->passport_series,
            'passport_issued_by' => array_key_exists('passportIssuedBy', $data) ? $data['passportIssuedBy'] : $candidate->passport_issued_by,
            'former_name'        => array_key_exists('formerName', $data) ? $data['formerName'] : $candidate->former_name,
            'citizenship'        => array_key_exists('citizenship', $data) ? $data['citizenship'] : $candidate->citizenship,
            'town'               => array_key_exists('town', $data) ? $data['town'] : $candidate->town,
            'country'            => array_key_exists('country', $data) ? $data['country'] : $candidate->country,
            'occupation_field'   => array_key_exists('occupationField', $data) ? $data['occupationField'] : $candidate->occupation_field,
            'cv_summary'         => $cvSummary,
            'cv_data'            => $cvData,
            'care_of'            => array_key_exists('careOf', $data) ? $data['careOf'] : $candidate->care_of,
            'age'                => array_key_exists('age', $data) ? $data['age'] : $candidate->age,
            'license'            => array_key_exists('license', $data) ? $data['license'] : $candidate->license,
            'current_job'        => array_key_exists('currentJob', $data) ? $data['currentJob'] : $candidate->current_job,
            'qualification'      => array_key_exists('qualification', $data) ? $data['qualification'] : $candidate->qualification,
        ]);
        $candidate->saveOrFail();
        $savedCandidate = Candidate::with(['company', 'documents', 'submissions', 'withdrawal'])
            ->findOrFail($candidate->id);

        return response()->json([
            'candidate' => $this->presentDetail($savedCandidate),
        ]);
    }

    public function saveCv(Request $request, Candidate $candidate): JsonResponse
    {
        $this->requirePermission($request, 'candidates.update');

        $data = $request->validate([
            'cvData'           => ['required', 'array'],
            'candidateFields'  => ['nullable', 'array'],
        ]);

        $cvData = $data['cvData'];
        $fields = $data['candidateFields'] ?? [];

        $expYears = (int) ($candidate->experience_years ?? 0);
        $summary = $cvData['summary'] ?? $candidate->cv_summary;
        if (!empty($summary) && str_contains($summary, 'with over 0 years')) {
            if ($expYears > 0) {
                $summary = str_replace('with over 0 years', "with over {$expYears} " . ($expYears === 1 ? 'year' : 'years'), $summary);
            } else {
                $summary = str_replace('with over 0 years of hands-on expertise', 'with strong practical skills and proven capabilities', $summary);
            }
            $cvData['summary'] = $summary;
        }

        $fillData = [
            'cv_data'    => $cvData,
            'cv_summary' => $summary,
        ];

        if (!empty($cvData['surname']) || !empty($fields['lastName'])) {
            $fillData['last_name'] = $fields['lastName'] ?? $cvData['surname'] ?? $candidate->last_name;
        }
        if (!empty($cvData['givenName']) || !empty($fields['firstName'])) {
            $fillData['first_name'] = $fields['firstName'] ?? $cvData['givenName'] ?? $candidate->first_name;
        }
        if (array_key_exists('formerName', $cvData)) {
            $fillData['former_name'] = $cvData['formerName'];
        }
        if (array_key_exists('fatherName', $cvData)) {
            $fillData['father_name'] = $cvData['fatherName'];
        }
        if (array_key_exists('motherName', $cvData)) {
            $fillData['mother_name'] = $cvData['motherName'];
        }
        if (array_key_exists('placeOfBirth', $cvData)) {
            $fillData['place_of_birth'] = $cvData['placeOfBirth'];
        }
        if (!empty($cvData['dateOfBirth'])) {
            $dob = $cvData['dateOfBirth'];
            if (preg_match('/^(\d{1,2})[.\-\/](\d{1,2})[.\-\/](\d{4})$/', $dob, $m)) {
                $dob = "{$m[3]}-{$m[2]}-{$m[1]}";
            }
            try {
                $fillData['date_of_birth'] = \Carbon\Carbon::parse($dob)->toDateString();
            } catch (\Throwable) {}
        }
        if (array_key_exists('civilStatus', $cvData)) {
            $fillData['civil_status'] = $cvData['civilStatus'];
        }
        if (array_key_exists('childrenCount', $cvData)) {
            $fillData['children_count'] = $cvData['childrenCount'];
        }
        if (array_key_exists('citizenship', $cvData)) {
            $fillData['citizenship'] = $cvData['citizenship'];
        }
        if (array_key_exists('nationality', $cvData)) {
            $fillData['nationality'] = $cvData['nationality'];
        }
        if (array_key_exists('passportSeries', $cvData)) {
            $fillData['passport_series'] = $cvData['passportSeries'];
        }
        if (array_key_exists('passportIssuedBy', $cvData)) {
            $fillData['passport_issued_by'] = $cvData['passportIssuedBy'];
        }
        if (!empty($cvData['passportIssueDate'])) {
            $pid = $cvData['passportIssueDate'];
            if (preg_match('/^(\d{1,2})[.\-\/](\d{1,2})[.\-\/](\d{4})$/', $pid, $m)) {
                $pid = "{$m[3]}-{$m[2]}-{$m[1]}";
            }
            try {
                $fillData['passport_issue_date'] = \Carbon\Carbon::parse($pid)->toDateString();
            } catch (\Throwable) {}
        }
        if (!empty($cvData['passportExpiryDate'])) {
            $ped = $cvData['passportExpiryDate'];
            if (preg_match('/^(\d{1,2})[.\-\/](\d{1,2})[.\-\/](\d{4})$/', $ped, $m)) {
                $ped = "{$m[3]}-{$m[2]}-{$m[1]}";
            }
            try {
                $fillData['passport_expiry'] = \Carbon\Carbon::parse($ped)->toDateString();
            } catch (\Throwable) {}
        }
        if (!empty($cvData['passportNo']) && trim((string)$cvData['passportNo']) !== '') {
            $fillData['passport_number'] = trim((string)$cvData['passportNo']);
        }
        if (array_key_exists('town', $cvData)) {
            $fillData['town'] = $cvData['town'];
        }
        if (array_key_exists('country', $cvData)) {
            $fillData['country'] = $cvData['country'];
        }
        if (array_key_exists('occupationField', $cvData)) {
            $fillData['occupation_field'] = $cvData['occupationField'];
        }
        if (!empty($cvData['phone'])) {
            $fillData['phone'] = $cvData['phone'];
        }
        if (!empty($cvData['email'])) {
            $fillData['email'] = $cvData['email'];
        }
        if (!empty($cvData['cnic'])) {
            $fillData['cnic_number'] = $cvData['cnic'];
        }
        if (!empty($cvData['location'])) {
            $fillData['current_location'] = $cvData['location'];
        }
        if (!empty($cvData['skills']) && is_array($cvData['skills'])) {
            $fillData['skills'] = $cvData['skills'];
        }
        if (array_key_exists('careOf', $fields) || array_key_exists('careOf', $cvData)) {
            $fillData['care_of'] = $fields['careOf'] ?? $cvData['careOf'] ?? null;
        }
        if (array_key_exists('age', $fields) || array_key_exists('age', $cvData)) {
            $fillData['age'] = $fields['age'] ?? $cvData['age'] ?? null;
        }
        if (array_key_exists('license', $fields) || array_key_exists('license', $cvData)) {
            $fillData['license'] = $fields['license'] ?? $cvData['license'] ?? null;
        }
        if (array_key_exists('currentJob', $fields) || array_key_exists('currentJob', $cvData)) {
            $fillData['current_job'] = $fields['currentJob'] ?? $cvData['currentJob'] ?? null;
        }
        if (array_key_exists('qualification', $fields) || array_key_exists('qualification', $cvData)) {
            $fillData['qualification'] = $fields['qualification'] ?? $cvData['qualification'] ?? null;
        }

        $candidate->forceFill($fillData);
        $candidate->saveOrFail();

        $savedCandidate = Candidate::with(['company', 'documents', 'submissions', 'withdrawal'])
            ->findOrFail($candidate->id);

        return response()->json([
            'message'   => 'CV and candidate details successfully saved to database.',
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

        $company     = Company::findOrFail($data['companyId']);
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

        // Record the batch share for multi-candidate shareable view
        CandidateShare::create([
            'share_token'   => $sharedToken,
            'title'         => 'Shifted to ' . $company->name,
            'note'          => $data['note'] ?? null,
            'company_id'    => $company->id,
            'company_name'  => $company->name,
            'candidate_ids' => $candidates->pluck('id')->values()->toArray(),
            'created_by'    => $shiftedBy,
        ]);

        $frontendUrl  = rtrim(env('FRONTEND_URL', 'http://localhost:3000'), '/');
        $shareableUrl = $frontendUrl . '/share/' . $sharedToken;

        return response()->json([
            'message'      => count($candidates) . ' candidate(s) shifted to ' . $company->name,
            'shareableUrl' => $shareableUrl,
            'sharedToken'  => $sharedToken,
        ]);
    }

    // --------------------------------------------------------------------------
    // Share Candidates Batch — POST /candidates/share
    // --------------------------------------------------------------------------

    public function createShare(Request $request): JsonResponse
    {
        $this->requirePermission($request, 'candidates.view');
        $data = $request->validate([
            'candidateIds'   => ['required', 'array', 'min:1'],
            'candidateIds.*' => ['required', 'integer', 'exists:candidates,id'],
            'title'          => ['nullable', 'string', 'max:255'],
            'note'           => ['nullable', 'string', 'max:1000'],
            'companyId'      => ['nullable', 'integer', 'exists:companies,id'],
        ]);

        $companyName = null;
        if (! empty($data['companyId'])) {
            $company = Company::find($data['companyId']);
            $companyName = $company?->name;
        }

        $sharedToken = Str::random(32);
        $createdBy   = $request->user()->name ?? 'System';

        CandidateShare::create([
            'share_token'   => $sharedToken,
            'title'         => $data['title'] ?? 'Candidate Profiles Package',
            'note'          => $data['note'] ?? null,
            'company_id'    => $data['companyId'] ?? null,
            'company_name'  => $companyName,
            'candidate_ids' => array_values($data['candidateIds']),
            'created_by'    => $createdBy,
        ]);

        $frontendUrl  = rtrim(env('FRONTEND_URL', 'http://localhost:3000'), '/');
        $shareableUrl = $frontendUrl . '/share/' . $sharedToken;

        return response()->json([
            'message'      => count($data['candidateIds']) . ' candidate(s) shareable link created',
            'shareableUrl' => $shareableUrl,
            'sharedToken'  => $sharedToken,
        ]);
    }

    // --------------------------------------------------------------------------
    // Shared Candidate View — GET /candidates/share/{token}
    // --------------------------------------------------------------------------

    public function showShared(Request $request, string $token): JsonResponse
    {
        // 1. Check if token exists in candidate_shares
        $share = CandidateShare::where('share_token', $token)->first();

        if ($share) {
            $candidateIds = $share->candidate_ids ?? [];
            $candidates = Candidate::whereIn('id', $candidateIds)
                ->with(['company', 'documents', 'submissions', 'withdrawal'])
                ->get();

            // Maintain order of candidateIds
            $orderMap = array_flip($candidateIds);
            $candidates = $candidates->sortBy(fn ($c) => $orderMap[$c->id] ?? 999999)->values();
            $presentedCandidates = $candidates->map(fn ($c) => $this->presentDetail($c))->values();

            $submissionSummary = null;
            if ($share->company_name || $share->company_id) {
                $submissionSummary = [
                    'id'           => (string) $share->id,
                    'candidateId'  => (string) ($candidateIds[0] ?? ''),
                    'companyId'    => (string) ($share->company_id ?? ''),
                    'companyName'  => $share->company_name ?? '',
                    'shiftedAt'    => $share->created_at?->format('Y-m-d H:i'),
                    'note'         => $share->note ?? '',
                    'shiftedBy'    => $share->created_by ?? '',
                    'shareableUrl' => rtrim(env('FRONTEND_URL', 'http://localhost:3000'), '/') . '/share/' . $share->share_token,
                ];
            }

            return response()->json([
                'token'           => $share->share_token,
                'title'           => $share->title ?? 'Candidate Package',
                'note'            => $share->note ?? '',
                'companyName'     => $share->company_name ?? '',
                'shiftedAt'       => $share->created_at?->format('Y-m-d H:i'),
                'shiftedBy'       => $share->created_by ?? '',
                'totalCandidates' => count($presentedCandidates),
                'candidates'      => $presentedCandidates,
                'candidate'       => $presentedCandidates[0] ?? null,
                'submission'      => $submissionSummary,
            ]);
        }

        // 2. Fallback to candidate_submissions for legacy links or prefix matches
        $submissions = CandidateSubmission::where('share_token', $token)
            ->orWhere('share_token', 'like', $token . '-%')
            ->orWhere('share_token', 'like', $token . '%')
            ->with(['candidate.company', 'candidate.documents', 'candidate.submissions', 'candidate.withdrawal', 'company'])
            ->get();

        if ($submissions->isEmpty()) {
            return response()->json([
                'message' => 'Candidate share link not found or expired.',
            ], 404);
        }

        $candidates = $submissions->pluck('candidate')->filter()->unique('id')->values();
        $presentedCandidates = $candidates->map(fn ($c) => $this->presentDetail($c))->values();
        $firstSub = $submissions->first();

        return response()->json([
            'token'           => $token,
            'title'           => $firstSub ? 'Shifted to ' . $firstSub->company_name : 'Shared Candidates',
            'note'            => $firstSub?->note ?? '',
            'companyName'     => $firstSub?->company_name ?? '',
            'shiftedAt'       => $firstSub?->shifted_at?->format('Y-m-d H:i'),
            'shiftedBy'       => $firstSub?->shifted_by ?? '',
            'totalCandidates' => count($presentedCandidates),
            'candidates'      => $presentedCandidates,
            'candidate'       => $presentedCandidates[0] ?? null,
            'submission'      => $firstSub ? $this->presentSubmission($firstSub) : null,
        ]);
    }

    // --------------------------------------------------------------------------
    // Shared Candidate Export — GET/POST /candidates/share/{token}/export
    // --------------------------------------------------------------------------

    public function exportSharedExcel(Request $request, string $token): StreamedResponse
    {
        $share = CandidateShare::where('share_token', $token)->first();
        $candidateIds = [];
        $companyName = '';
        $packageName = 'Candidate_Package';

        if ($share) {
            $candidateIds = $share->candidate_ids ?? [];
            $companyName = $share->company_name ?? '';
            $packageName = $share->title ?: ($companyName ? 'Shifted_to_' . $companyName : 'Candidate_Package');
        } else {
            $submissions = CandidateSubmission::where('share_token', $token)
                ->orWhere('share_token', 'like', $token . '-%')
                ->orWhere('share_token', 'like', $token . '%')
                ->get();
            $candidateIds = $submissions->pluck('candidate_id')->filter()->unique()->values()->all();
            $firstSub = $submissions->first();
            if ($firstSub) {
                $companyName = $firstSub->company_name ?? '';
                $packageName = $companyName ? 'Shifted_to_' . $companyName : 'Shared_Candidates';
            }
        }

        if (empty($candidateIds)) {
            abort(404, 'Shared package not found or contains no candidates.');
        }

        // Allow filtering by candidate_ids if specified from frontend
        $reqCandidateIds = $request->input('candidate_ids', []);
        if (is_string($reqCandidateIds)) {
            $reqCandidateIds = array_values(array_filter(array_map('trim', explode(',', $reqCandidateIds))));
        }
        if (!empty($reqCandidateIds)) {
            $candidateIds = array_values(array_intersect($candidateIds, $reqCandidateIds));
        }

        $query = Candidate::with(['company', 'documents', 'withdrawal'])
            ->whereIn('id', $candidateIds);

        // Maintain original share order if available
        $orderMap = array_flip($candidateIds);
        $candidates = $query->get()->sortBy(fn ($c) => $orderMap[$c->id] ?? 999999)->values();

        $format = strtolower($request->input('format', 'xlsx'));
        $fieldDefinitions = $this->getExportFieldDefinitions();

        // Comprehensive detail fields for shared package
        $selectedFields = [
            'code',
            'name',
            'father_name',
            'mother_name',
            'date_of_birth',
            'age',
            'civil_status',
            'nationality',
            'citizenship',
            'passport_number',
            'passport_issue_date',
            'passport_expiry',
            'passport_issued_by',
            'cnic_number',
            'phone',
            'email',
            'trade',
            'experience_years',
            'current_location',
            'target_country',
            'expected_salary',
            'currency',
            'status',
            'recruitment_stage',
            'company',
            'documents_count',
            'documents_list',
        ];

        $fieldsToExport = [];
        foreach ($selectedFields as $f) {
            $key = $this->normalizeFieldKey((string) $f);
            if (isset($fieldDefinitions[$key])) {
                $fieldsToExport[$key] = $fieldDefinitions[$key];
            }
        }
        if (empty($fieldsToExport)) {
            $fieldsToExport = $fieldDefinitions;
        }

        $headers = array_merge(['Sr #'], array_column(array_values($fieldsToExport), 'label'));
        $cleanPackageName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $packageName) ?: 'candidates';
        $timestamp = now()->format('Y-m-d');

        if ($format === 'csv') {
            $filename = "{$cleanPackageName}_candidates_{$timestamp}.csv";
            return response()->streamDownload(function () use ($headers, $fieldsToExport, $candidates): void {
                $file = fopen('php://output', 'w');
                fprintf($file, chr(0xEF) . chr(0xBB) . chr(0xBF)); // UTF-8 BOM
                fputcsv($file, $headers);

                $sr = 1;
                foreach ($candidates as $cand) {
                    $row = [$sr++];
                    foreach ($fieldsToExport as $fieldDef) {
                        $resolver = $fieldDef['resolver'];
                        $row[] = (string) $resolver($cand);
                    }
                    fputcsv($file, $row);
                }
                fclose($file);
            }, $filename, [
                'Content-Type' => 'text/csv; charset=UTF-8',
            ]);
        }

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(substr($packageName, 0, 31));

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
            ->getStartColor()->setRGB('107C41'); // Microsoft Excel Brand Green
        $sheet->getRowDimension(1)->setRowHeight(28);

        // Freeze top header row
        $sheet->freezePane('A2');

        // Rows
        $rowNum = 2;
        $sr = 1;
        foreach ($candidates as $cand) {
            $colNum = 1;
            $sheet->setCellValueExplicit([$colNum++, $rowNum], (string) $sr++, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC);

            foreach ($fieldsToExport as $fieldDef) {
                $resolver = $fieldDef['resolver'];
                $val = (string) $resolver($cand);
                $sheet->setCellValueExplicit([$colNum++, $rowNum], $val, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            }

            // Zebra striping
            if ($rowNum % 2 === 1) {
                $rowRange = "A{$rowNum}:{$highestCol}{$rowNum}";
                $sheet->getStyle($rowRange)->getFill()->setFillType(Fill::FILL_SOLID)
                    ->getStartColor()->setRGB('F8FAFC');
            }
            $sheet->getRowDimension($rowNum)->setRowHeight(22);
            $rowNum++;
        }

        $filename = "{$cleanPackageName}_candidates_{$timestamp}.xlsx";
        $writer = new Xlsx($spreadsheet);

        return response()->streamDownload(function () use ($writer): void {
            $writer->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    // --------------------------------------------------------------------------
    // Candidate Public Agreement View — GET /candidates/agreement/{token}
    // --------------------------------------------------------------------------

    public function showAgreement(Request $request, string $token): JsonResponse
    {
        $candidate = Candidate::where('agreement_token', $token)
            ->orWhere(function ($q) use ($token) {
                if (is_numeric($token)) {
                    $q->where('id', $token);
                }
            })
            ->first();

        if (! $candidate) {
            return response()->json([
                'message' => 'Candidate agreement link not found or expired.',
            ], 404);
        }

        $candidate->ensureAgreementToken();

        return response()->json([
            'candidate' => [
                'id'              => (string) $candidate->id,
                'code'            => $candidate->code,
                'psnCode'         => $candidate->psn_code,
                'firstName'       => $candidate->first_name,
                'lastName'        => $candidate->last_name,
                'phone'           => $candidate->phone ?? '',
                'cnicNumber'      => $candidate->cnic_number ?? '',
                'passportNumber'  => $candidate->passport_number ?? '',
                'passportExpiry'  => $candidate->passport_expiry?->toDateString() ?? '',
                'trade'           => $candidate->trade ?? 'General Worker',
                'targetCountry'   => $candidate->target_country ?? 'Romania',
                'currentLocation' => $candidate->current_location ?? '',
                'nationality'     => $candidate->nationality ?? 'Pakistani',
                'careOf'          => $candidate->care_of ?? '',
                'photoUrl'        => $candidate->photo_url,
                'signatureUrl'    => $candidate->signature_url,
                'agreementToken'  => $candidate->agreement_token,
                'termsAgreedAt'   => $candidate->terms_agreed_at?->toIso8601String(),
                'hasAgreed'       => ! empty($candidate->terms_agreed_at) || ! empty($candidate->signature_path),
                'createdAt'       => $candidate->created_at?->toIso8601String(),
            ],
        ]);
    }

    // --------------------------------------------------------------------------
    // Candidate Public Sign Agreement — POST /candidates/agreement/{token}/sign
    // --------------------------------------------------------------------------

    public function signAgreement(Request $request, string $token): JsonResponse
    {
        $candidate = Candidate::where('agreement_token', $token)
            ->orWhere(function ($q) use ($token) {
                if (is_numeric($token)) {
                    $q->where('id', $token);
                }
            })
            ->first();

        if (! $candidate) {
            return response()->json([
                'message' => 'Candidate agreement link not found or expired.',
            ], 404);
        }

        // Restrict public shareable link to one-time submission only
        $isFromPortal = $request->boolean('from_portal');
        if (! $isFromPortal && ($candidate->terms_agreed_at !== null || ! empty($candidate->signature_path))) {
            return response()->json([
                'message'           => 'یہ معاہدہ پہلے ہی جمع ہو چکا ہے اور دوبارہ جمع نہیں کیا جا سکتا۔ (This agreement has already been submitted and recorded.)',
                'already_submitted' => true,
            ], 400);
        }

        if (! $request->boolean('agreed')) {
            return response()->json([
                'message' => 'You must agree to the terms & refund policy before submitting agreement.',
            ], 422);
        }

        $signaturePath = $candidate->signature_path;

        if ($request->hasFile('signature')) {
            if ($signaturePath) {
                Storage::disk('public')->delete($signaturePath);
            }
            $signaturePath = $request->file('signature')->store('candidates/signatures', 'public');
        } elseif ($request->filled('signature') && is_string($request->input('signature')) && str_starts_with($request->input('signature'), 'data:image')) {
            if ($signaturePath) {
                Storage::disk('public')->delete($signaturePath);
            }
            $base64Image = $request->input('signature');
            $imageParts = explode(';base64,', $base64Image);
            if (count($imageParts) === 2) {
                $imageTypeAux = explode('image/', $imageParts[0]);
                $imageType = $imageTypeAux[1] ?? 'png';
                $imageBase64 = base64_decode($imageParts[1]);
                if ($imageBase64 !== false) {
                    $fileName = 'candidates/signatures/' . uniqid('sig_', true) . '.' . $imageType;
                    Storage::disk('public')->put($fileName, $imageBase64);
                    $signaturePath = $fileName;
                }
            }
        }

        if (! $signaturePath) {
            return response()->json([
                'message' => 'Please provide a valid agreement confirmation before submitting.',
            ], 422);
        }

        $candidate->update([
            'signature_path'  => $signaturePath,
            'terms_agreed_at' => now(),
        ]);

        return response()->json([
            'message'   => 'Candidate agreement submitted successfully.',
            'candidate' => [
                'id'             => (string) $candidate->id,
                'firstName'      => $candidate->first_name,
                'lastName'       => $candidate->last_name,
                'signatureUrl'   => $candidate->signature_url,
                'termsAgreedAt'  => $candidate->terms_agreed_at?->toIso8601String(),
                'hasAgreed'      => true,
                'agreementToken' => $candidate->agreement_token,
            ],
        ]);
    }

    // --------------------------------------------------------------------------
    // Return to Pool — PATCH /candidates/{candidate}/return
    // --------------------------------------------------------------------------

    public function returnToPool(Request $request, Candidate $candidate): JsonResponse
    {
        $this->requirePermission($request, 'candidates.update');
        $candidate->update([
            'status'             => 'processing',
            'current_company_id' => null,
        ]);
        $this->syncCandidateStatus($candidate);

        return response()->json([
            'candidate' => $this->presentDetail($candidate->fresh()->load(['company', 'documents', 'submissions', 'withdrawal'])),
            'message'   => 'Candidate returned to the pool.',
        ]);
    }

    // --------------------------------------------------------------------------
    // Return Batch to Pool — POST /candidates/return-batch
    // --------------------------------------------------------------------------

    public function returnBatchToPool(Request $request): JsonResponse
    {
        $this->requirePermission($request, 'candidates.update');

        $candidateIds = $request->input('candidate_ids', []);
        if (is_string($candidateIds)) {
            $candidateIds = array_values(array_filter(array_map('trim', explode(',', $candidateIds))));
        }

        if (empty($candidateIds) || !is_array($candidateIds)) {
            return response()->json([
                'message' => 'No candidates specified.',
                'count'   => 0,
            ], 422);
        }

        $candidates = Candidate::whereIn('id', $candidateIds)->get();
        $updatedCount = 0;

        DB::beginTransaction();
        try {
            foreach ($candidates as $candidate) {
                $candidate->update([
                    'status'             => 'processing',
                    'current_company_id' => null,
                ]);
                $this->syncCandidateStatus($candidate);
                $updatedCount++;
            }
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to return candidates: ' . $e->getMessage(),
            ], 500);
        }

        return response()->json([
            'count'   => $updatedCount,
            'message' => "Successfully removed {$updatedCount} candidate(s) from company.",
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
    // Reactivate / Re-enable — POST /candidates/{candidate}/reactivate
    // --------------------------------------------------------------------------

    public function reactivate(Request $request, Candidate $candidate): JsonResponse
    {
        $this->requirePermission($request, 'candidates.update');

        // Delete candidate withdrawal record if any
        $candidate->withdrawal()?->delete();

        // Restore candidate status with mandatory docs check
        $candidate->update(['status' => 'processing']);
        $this->syncCandidateStatus($candidate);

        return response()->json([
            'candidate' => $this->presentDetail($candidate->fresh()->load(['company', 'documents', 'submissions', 'withdrawal'])),
            'message'   => 'Candidate re-enabled successfully.',
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
            'documentNumber'   => ['nullable', 'string', 'max:255'],
            'file'             => ['nullable', 'file', 'max:102400'], // 100 MB
            'issueDate'        => ['nullable', 'date'],
            'expiryDate'       => ['nullable', 'date'],
            'notes'            => ['nullable', 'string'],
        ]);

        $docTypeId = $data['documentTypeId'] ?? null;
        $docType = null;
        if ($docTypeId) {
            $docType = is_numeric($docTypeId) ? DocumentType::find($docTypeId) : DocumentType::where('code', $docTypeId)->first();
        }

        if ($docType && $docType->requires_expiry_date && empty($data['expiryDate'])) {
            return response()->json([
                'message' => "Expiry date is required for {$docType->name}.",
                'errors'  => ['expiryDate' => ["Expiry date is required for {$docType->name}."]],
            ], 422);
        }

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
            'document_number'    => $data['documentNumber'] ?? null,
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

        $this->syncCandidateStatus($candidate);

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
            'documentNumber'   => ['sometimes', 'nullable', 'string', 'max:255'],
            'file'             => ['nullable', 'file', 'max:102400'], // 100 MB
            'issueDate'        => ['nullable', 'date'],
            'expiryDate'       => ['nullable', 'date'],
            'notes'            => ['nullable', 'string'],
            'status'           => ['sometimes', 'in:verified,expiring,expired,pending,rejected'],
        ]);

        if (isset($data['status']) && $data['status'] === 'verified') {
            $effectiveExpiry = $data['expiryDate'] ?? $document->expiry_date?->toDateString();
            $isExpired = ($document->status === 'expired') || ($effectiveExpiry && \Carbon\Carbon::parse($effectiveExpiry)->endOfDay()->isPast());
            if ($isExpired) {
                return response()->json([
                    'message' => 'Expired documents cannot be verified.',
                    'errors'  => ['status' => ['Expired documents cannot be verified.']],
                ], 422);
            }
        }

        $docTypeId = $data['documentTypeId'] ?? $document->document_type_id;
        $docType = null;
        if ($docTypeId) {
            $docType = is_numeric($docTypeId) ? DocumentType::find($docTypeId) : DocumentType::where('code', $docTypeId)->first();
        }

        if (array_key_exists('expiryDate', $data) || array_key_exists('documentTypeId', $data)) {
            $effectiveExpiry = array_key_exists('expiryDate', $data) ? $data['expiryDate'] : $document->expiry_date?->toDateString();
            if ($docType && $docType->requires_expiry_date && empty($effectiveExpiry)) {
                return response()->json([
                    'message' => "Expiry date is required for {$docType->name}.",
                    'errors'  => ['expiryDate' => ["Expiry date is required for {$docType->name}."]],
                ], 422);
            }
        }

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

        if (array_key_exists('documentNumber', $data)) {
            $updateData['document_number'] = $data['documentNumber'];
        }

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
        $this->syncCandidateStatus($candidate);

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
            'documentUrl'    => $oldDoc && $oldDoc->file_url ? url($oldDoc->file_url) : null,
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

        $this->syncCandidateStatus($candidate);
        $candidate->load(['company', 'documents', 'submissions.company', 'withdrawal']);

        return response()->json([
            'message'   => "Passport renewed successfully! Previous passport {$historyEntry['passportNumber']} has been archived to history.",
            'candidate' => $this->presentDetail($candidate->fresh()),
        ]);
    }

    // --------------------------------------------------------------------------
    // Update Candidate Signature — POST /candidates/{candidate}/signature
    // --------------------------------------------------------------------------

    public function updateSignature(Request $request, Candidate $candidate): JsonResponse
    {
        $this->requirePermission($request, 'candidates.update');

        $signaturePath = $candidate->signature_path;

        if ($request->hasFile('signature')) {
            if ($signaturePath) {
                Storage::disk('public')->delete($signaturePath);
            }
            $signaturePath = $request->file('signature')->store('candidates/signatures', 'public');
        } elseif ($request->filled('signature') && is_string($request->input('signature')) && str_starts_with($request->input('signature'), 'data:image')) {
            if ($signaturePath) {
                Storage::disk('public')->delete($signaturePath);
            }
            $base64Image = $request->input('signature');
            $imageParts = explode(';base64,', $base64Image);
            if (count($imageParts) === 2) {
                $imageTypeAux = explode('image/', $imageParts[0]);
                $imageType = $imageTypeAux[1] ?? 'png';
                $imageBase64 = base64_decode($imageParts[1]);
                if ($imageBase64 !== false) {
                    $fileName = 'candidates/signatures/' . uniqid('sig_', true) . '.' . $imageType;
                    Storage::disk('public')->put($fileName, $imageBase64);
                    $signaturePath = $fileName;
                }
            }
        } elseif ($request->boolean('removeSignature')) {
            if ($signaturePath) {
                Storage::disk('public')->delete($signaturePath);
            }
            $signaturePath = null;
        }

        $candidate->update(['signature_path' => $signaturePath]);
        $candidate->load(['company', 'documents', 'submissions.company', 'withdrawal']);

        return response()->json([
            'message'   => 'Candidate electronic signature updated successfully.',
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
            'status'              => $candidate->status,
            'currentCompanyId'    => $candidate->current_company_id ? (string) $candidate->current_company_id : null,
            'currentCompanyName'  => $candidate->company?->name,
            'photoUrl'            => $candidate->photo_url,
            'signatureUrl'        => $candidate->signature_url,
            'agreementToken'      => $candidate->agreement_token,
            'termsAgreedAt'       => $candidate->terms_agreed_at?->toIso8601String(),
            'nationality'         => $candidate->nationality,
            'currentLocation'     => $candidate->current_location,
            'targetCountry'       => $candidate->target_country ?? '',
            'fatherName'          => $candidate->father_name,
            'motherName'          => $candidate->mother_name,
            'placeOfBirth'        => $candidate->place_of_birth,
            'dateOfBirth'         => $candidate->date_of_birth?->toDateString(),
            'civilStatus'         => $candidate->civil_status,
            'childrenCount'       => $candidate->children_count,
            'formerName'          => $candidate->former_name,
            'citizenship'         => $candidate->citizenship,
            'town'                => $candidate->town,
            'country'             => $candidate->country,
            'occupationField'     => $candidate->occupation_field,
            'careOf'              => $candidate->care_of,
            'age'                 => $candidate->age,
            'license'             => $candidate->license,
            'currentJob'          => $candidate->current_job,
            'qualification'       => $candidate->qualification,
            'cvSummary'           => $candidate->cv_summary,
            'cvData'              => $candidate->cv_data,
            'balance'             => (float) $candidate->balance,
            'joinedDate'          => $candidate->joined_date?->toDateString(),
            'documents'           => $candidate->relationLoaded('documents') ? $candidate->documents->map(fn (CandidateDocument $d): array => $this->presentDocument($d))->values() : [],
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
            'joinedDate'          => $candidate->joined_date?->toDateString() ?? '',
            'skills'              => $candidate->skills ?? [],
            'expectedSalary'      => (float) $candidate->expected_salary,
            'currency'            => $candidate->currency,
            'photoUrl'            => $candidate->photo_url,
            'signatureUrl'        => $candidate->signature_url,
            'agreementToken'      => $candidate->agreement_token,
            'termsAgreedAt'       => $candidate->terms_agreed_at?->toIso8601String(),
            'balance'             => (float) $candidate->balance,
            'fatherName'          => $candidate->father_name,
            'motherName'          => $candidate->mother_name,
            'placeOfBirth'        => $candidate->place_of_birth,
            'dateOfBirth'         => $candidate->date_of_birth?->toDateString(),
            'civilStatus'         => $candidate->civil_status,
            'childrenCount'       => $candidate->children_count,
            'formerName'          => $candidate->former_name,
            'citizenship'         => $candidate->citizenship,
            'town'                => $candidate->town,
            'country'             => $candidate->country,
            'occupationField'     => $candidate->occupation_field,
            'careOf'              => $candidate->care_of,
            'age'                 => $candidate->age,
            'license'             => $candidate->license,
            'currentJob'          => $candidate->current_job,
            'qualification'       => $candidate->qualification,
            'cvSummary'           => $candidate->cv_summary,
            'cvData'              => $candidate->cv_data,
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
            'documentNumber'   => $doc->document_number ?? '',
            'fileUrl'          => $doc->file_url ? url($doc->file_url) : '#',
            'filePreviewUrl'   => $doc->file_url ? url($doc->file_url) : null,
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
        $frontendUrl = rtrim(env('FRONTEND_URL', 'http://localhost:3000'), '/');

        return [
            'id'           => (string) $sub->id,
            'candidateId'  => (string) $sub->candidate_id,
            'companyId'    => (string) $sub->company_id,
            'companyName'  => $sub->company_name,
            'shiftedAt'    => $sub->shifted_at?->format('Y-m-d H:i'),
            'note'         => $sub->note ?? '',
            'shiftedBy'    => $sub->shifted_by ?? '',
            'shareableUrl' => $frontendUrl . '/share/' . $sub->share_token,
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

    public function syncCandidateStatus(Candidate $candidate): void
    {
        if (in_array($candidate->status, ['placed', 'withdrawn'], true)) {
            return;
        }

        $candidate->loadMissing('documents');
        $hasAllMandatory = $this->candidateHasAllMandatoryDocuments($candidate);

        $newStatus = $hasAllMandatory ? 'active' : 'processing';
        if ($candidate->status !== $newStatus) {
            $candidate->update(['status' => $newStatus]);
        }
    }

    public function candidateHasAllMandatoryDocuments(Candidate $candidate): bool
    {
        $mandatoryDocTypes = DocumentType::where('is_mandatory', true)->get();
        if ($mandatoryDocTypes->isEmpty()) {
            return true;
        }

        $docs = $candidate->documents;
        if ($docs->isEmpty()) {
            return false;
        }

        foreach ($mandatoryDocTypes as $dt) {
            $matched = $docs->contains(function (CandidateDocument $doc) use ($dt): bool {
                $hasFile = !empty($doc->file_path) || !empty($doc->file_name);
                if (!$hasFile) {
                    return false;
                }

                $dtId = (string) $dt->id;
                $dtCode = strtolower(trim((string) $dt->code));
                $dtName = strtolower(trim((string) $dt->name));

                $docTypeId = trim((string) $doc->document_type_id);
                $docTypeIdLower = strtolower($docTypeId);
                $docTypeName = strtolower(trim((string) $doc->document_type_name));
                $docTitle = strtolower(trim((string) $doc->title));

                if ($docTypeId === $dtId) return true;
                if ($dtCode && $docTypeIdLower === $dtCode) return true;
                if ($dtName && ($docTypeName === $dtName || $docTitle === $dtName)) return true;

                // Aliases
                if ($dtCode === 'passport' || str_contains($dtName, 'passport')) {
                    if ($docTypeIdLower === 'doc-type-1' || $docTypeIdLower === 'passport' || str_contains($docTypeName, 'passport') || str_contains($docTitle, 'passport')) {
                        return true;
                    }
                }
                if ($dtCode === 'cnic' || str_contains($dtName, 'cnic') || str_contains($dtName, 'identity')) {
                    if ($docTypeIdLower === 'cnic' || str_contains($docTypeName, 'cnic') || str_contains($docTypeName, 'identity') || str_contains($docTitle, 'cnic')) {
                        return true;
                    }
                }
                if ($dtCode === 'police_clearance' || str_contains($dtName, 'police') || str_contains($dtName, 'character')) {
                    if ($docTypeIdLower === 'police_clearance' || str_contains($docTypeName, 'police') || str_contains($docTypeName, 'character') || str_contains($docTitle, 'police') || str_contains($docTitle, 'character')) {
                        return true;
                    }
                }
                if ($dtCode === 'medical_fitness' || str_contains($dtName, 'medical')) {
                    if ($docTypeIdLower === 'medical_fitness' || str_contains($docTypeName, 'medical') || str_contains($docTitle, 'medical')) {
                        return true;
                    }
                }
                if ($dtCode === 'skill_cert' || str_contains($dtName, 'skill') || str_contains($dtName, 'trade')) {
                    if ($docTypeIdLower === 'skill_cert' || str_contains($docTypeName, 'skill') || str_contains($docTitle, 'skill')) {
                        return true;
                    }
                }
                if ($dtCode === 'visa_stamp' || str_contains($dtName, 'visa')) {
                    if ($docTypeIdLower === 'visa_stamp' || str_contains($docTypeName, 'visa') || str_contains($docTitle, 'visa')) {
                        return true;
                    }
                }

                return false;
            });

            if (!$matched) {
                return false;
            }
        }

        return true;
    }

    private function generateUniqueCandidateCodes(): array
    {
        $maxNum = 1000;
        $allCodes = Candidate::withTrashed()->pluck('code')->all();
        foreach ($allCodes as $c) {
            if ($c && preg_match('/CRG-(\d+)/i', $c, $m)) {
                $num = (int) $m[1];
                if ($num > $maxNum) {
                    $maxNum = $num;
                }
            }
        }

        $nextNum = $maxNum + 1;
        $code = 'CRG-' . str_pad((string) $nextNum, 4, '0', STR_PAD_LEFT);
        while (Candidate::withTrashed()->where('code', $code)->exists()) {
            $nextNum++;
            $code = 'CRG-' . str_pad((string) $nextNum, 4, '0', STR_PAD_LEFT);
        }

        $year = now()->year;
        $maxPsn = 0;
        $allPsn = Candidate::withTrashed()
            ->where('psn_code', 'LIKE', "PSN-{$year}-%")
            ->pluck('psn_code')
            ->all();

        foreach ($allPsn as $p) {
            if ($p && preg_match('/PSN-\d+-(\d+)/i', $p, $m)) {
                $pNum = (int) $m[1];
                if ($pNum > $maxPsn) {
                    $maxPsn = $pNum;
                }
            }
        }

        $nextPsn = $maxPsn + 1;
        $psnCode = 'PSN-' . $year . '-' . str_pad((string) $nextPsn, 4, '0', STR_PAD_LEFT);
        while (Candidate::withTrashed()->where('psn_code', $psnCode)->exists()) {
            $nextPsn++;
            $psnCode = 'PSN-' . $year . '-' . str_pad((string) $nextPsn, 4, '0', STR_PAD_LEFT);
        }

        return [$code, $psnCode];
    }
}
