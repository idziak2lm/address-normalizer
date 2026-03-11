<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessCsvBatch;
use App\Models\ApiClient;
use App\Models\CsvBatchImport;
use App\Services\CsvFormatDetector;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CsvUploadController extends Controller
{
    public function __construct(
        private readonly CsvFormatDetector $detector,
    ) {}

    public function showLoginForm(): View
    {
        return view('csv.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $request->validate([
            'api_key' => 'required|string',
        ]);

        $hash = hash('sha256', $request->input('api_key'));
        $client = ApiClient::where('api_key', $hash)->where('is_active', true)->first();

        if (! $client) {
            return back()->with('error', 'Invalid API key or deactivated client.');
        }

        session(['api_client_id' => $client->id]);

        return redirect()->route('csv.index');
    }

    public function logout(): RedirectResponse
    {
        session()->forget('api_client_id');

        return redirect()->route('csv.login');
    }

    public function index(Request $request): View
    {
        $client = $request->user();

        $imports = CsvBatchImport::where('api_client_id', $client->id)
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();

        return view('csv.index', [
            'client' => $client,
            'imports' => $imports,
            'variants' => CsvFormatDetector::supportedVariants(),
            'maxRows' => config('normalizer.csv.max_rows', 10000),
        ]);
    }

    public function upload(Request $request): RedirectResponse
    {
        $request->validate([
            'csv_file' => 'required|file|mimes:csv,txt|max:10240',
        ]);

        $client = $request->user();
        $file = $request->file('csv_file');
        $filePath = $file->getRealPath();

        // Read header to detect format
        $handle = fopen($filePath, 'r');

        if (! $handle) {
            return back()->with('error', 'Cannot read uploaded file.');
        }

        $headerLine = fgets($handle);
        fclose($handle);

        if (! $headerLine) {
            return back()->with('error', 'File is empty.');
        }

        $variant = $this->detector->detect($headerLine);

        if (! $variant) {
            return back()->with('error', 'Unrecognized CSV format. Please check the header row matches one of the supported formats.');
        }

        // Count rows
        $rowCount = $this->detector->countRows($filePath);
        $maxRows = config('normalizer.csv.max_rows', 10000);

        if ($rowCount === 0) {
            return back()->with('error', 'File contains no data rows.');
        }

        if ($rowCount > $maxRows) {
            return back()->with('error', "File contains {$rowCount} rows. Maximum allowed is {$maxRows}.");
        }

        // Check quota
        if ($client->remainingQuota() < $rowCount) {
            return back()->with('error', "Insufficient quota. You have {$client->remainingQuota()} requests remaining, but the file contains {$rowCount} rows.");
        }

        // Store file
        $storedFilename = Str::uuid() . '.csv';
        $uploadPath = config('normalizer.csv.upload_path', 'csv-uploads');
        Storage::disk('local')->makeDirectory($uploadPath);
        $file->storeAs($uploadPath, $storedFilename, 'local');

        // Create import record
        $import = CsvBatchImport::create([
            'api_client_id' => $client->id,
            'original_filename' => $file->getClientOriginalName(),
            'stored_filename' => $storedFilename,
            'format_variant' => $variant,
            'total_rows' => $rowCount,
            'status' => CsvBatchImport::STATUS_PENDING,
        ]);

        // Dispatch job
        ProcessCsvBatch::dispatch($import->id);

        return redirect()->route('csv.index')
            ->with('success', "File uploaded successfully. Processing {$rowCount} addresses in background (format: {$variant}).");
    }

    public function status(Request $request, CsvBatchImport $import): JsonResponse
    {
        $client = $request->user();

        if ($import->api_client_id !== $client->id) {
            abort(403);
        }

        return response()->json([
            'id' => $import->id,
            'status' => $import->status,
            'total_rows' => $import->total_rows,
            'processed_rows' => $import->processed_rows,
            'failed_rows' => $import->failed_rows,
            'progress' => $import->progressPercent(),
            'is_finished' => $import->isFinished(),
            'error_message' => $import->error_message,
            'has_result' => $import->result_filename !== null,
        ]);
    }

    public function download(Request $request, CsvBatchImport $import): StreamedResponse|RedirectResponse
    {
        $client = $request->user();

        if ($import->api_client_id !== $client->id) {
            abort(403);
        }

        if (! $import->result_filename) {
            return back()->with('error', 'Result file is not ready yet.');
        }

        $exportPath = config('normalizer.csv.export_path', 'csv-exports') . '/' . $import->result_filename;

        if (! Storage::disk('local')->exists($exportPath)) {
            return back()->with('error', 'Result file not found.');
        }

        $downloadName = 'normalized_' . pathinfo($import->original_filename, PATHINFO_FILENAME) . '.csv';

        return Storage::disk('local')->download($exportPath, $downloadName, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
