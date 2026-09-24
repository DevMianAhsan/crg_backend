<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SystemSettingController extends Controller
{
    private const ALLOWED_DATE_FORMATS = [
        'DD/MM/YYYY',
        'DD-MM-YYYY',
        'DD.MM.YYYY',
        'YYYY-MM-DD',
        'DD MMM YYYY',
        'MM/DD/YYYY',
    ];

    private const DEFAULT_FORMAT = 'DD/MM/YYYY';

    /**
     * GET /settings/date-format
     * Returns the current global date format.
     * Falls back to default if the table doesn't exist yet.
     */
    public function getDateFormat(): JsonResponse
    {
        try {
            $row = DB::table('system_settings')->where('key', 'date_format')->first();
            $format = $row ? $row->value : self::DEFAULT_FORMAT;
        } catch (\Throwable) {
            // Table may not exist yet — return default
            $format = self::DEFAULT_FORMAT;
        }

        return response()->json([
            'date_format' => $format,
        ]);
    }

    /**
     * POST /settings/date-format
     * Updates the global date format.
     * Returns 503 with a message if the table doesn't exist yet.
     */
    public function setDateFormat(Request $request): JsonResponse
    {
        $request->validate([
            'date_format' => ['required', 'string', 'in:' . implode(',', self::ALLOWED_DATE_FORMATS)],
        ]);

        try {
            DB::table('system_settings')->updateOrInsert(
                ['key' => 'date_format'],
                ['value' => $request->date_format, 'updated_at' => now(), 'created_at' => now()]
            );
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Settings table not ready. Please run: php artisan migrate',
                'error'   => $e->getMessage(),
            ], 503);
        }

        return response()->json([
            'message'     => 'Date format updated successfully.',
            'date_format' => $request->date_format,
        ]);
    }
}
