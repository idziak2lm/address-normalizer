<?php

namespace App\Jobs;

use App\DTOs\RawAddressInput;
use App\Models\CsvBatchImport;
use App\Services\AddressNormalizer;
use App\Services\CsvFormatDetector;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ProcessCsvBatch implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1800;
    public int $tries = 1;

    public function __construct(
        private readonly int $importId,
    ) {
        $this->onQueue('csv-processing');
    }

    public function handle(AddressNormalizer $normalizer, CsvFormatDetector $detector): void
    {
        $import = CsvBatchImport::with('apiClient')->findOrFail($this->importId);
        $client = $import->apiClient;

        $import->markProcessing();

        $inputPath = Storage::disk('local')->path(
            config('normalizer.csv.upload_path', 'csv-uploads') . '/' . $import->stored_filename
        );

        $resultFilename = 'result_' . $import->stored_filename;
        $outputPath = Storage::disk('local')->path(
            config('normalizer.csv.export_path', 'csv-exports') . '/' . $resultFilename
        );

        // Ensure export directory exists
        Storage::disk('local')->makeDirectory(config('normalizer.csv.export_path', 'csv-exports'));

        try {
            $this->processFile($import, $client, $normalizer, $detector, $inputPath, $outputPath);

            $import->update(['result_filename' => $resultFilename]);
            $import->markCompleted();
        } catch (\Throwable $e) {
            Log::error('CSV batch processing failed', [
                'import_id' => $import->id,
                'error' => $e->getMessage(),
            ]);

            $import->markFailed($e->getMessage());
        }
    }

    private function processFile(
        CsvBatchImport $import,
        $client,
        AddressNormalizer $normalizer,
        CsvFormatDetector $detector,
        string $inputPath,
        string $outputPath,
    ): void {
        $inputHandle = fopen($inputPath, 'r');
        $outputHandle = fopen($outputPath, 'w');

        if (! $inputHandle || ! $outputHandle) {
            throw new \RuntimeException('Cannot open CSV files for processing.');
        }

        // Skip header row
        fgets($inputHandle);

        // Write output header
        fputcsv($outputHandle, $this->outputHeaders(), ';');

        $chunkSize = config('normalizer.csv.chunk_size', 50);
        $chunk = [];
        $chunkReferences = [];
        $processedCount = 0;
        $failedCount = 0;

        while (($line = fgets($inputHandle)) !== false) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            $row = $detector->parseRow($line, $import->format_variant);

            if (! $row) {
                $failedCount++;
                fputcsv($outputHandle, $this->errorRow($line, 'Invalid row format'), ';');
                $processedCount++;
                $this->updateProgress($import, $processedCount, $failedCount);

                continue;
            }

            // Check remaining quota before processing
            $client->refresh();
            if ($client->hasReachedLimit()) {
                fputcsv($outputHandle, $this->errorRow($row['reference'] ?? '', 'Monthly usage limit exceeded'), ';');
                $failedCount++;
                $processedCount++;
                $this->updateProgress($import, $processedCount, $failedCount);

                continue;
            }

            $chunk[] = $this->rowToInput($row, $import->format_variant);
            $chunkReferences[] = $row['reference'] ?? '';

            if (count($chunk) >= $chunkSize) {
                $results = $this->processChunk($normalizer, $client, $chunk, $chunkReferences);
                $this->writeResults($outputHandle, $results);
                $processedCount += count($chunk);
                $failedCount += count(array_filter($results, fn ($r) => $r['status'] === 'error'));
                $this->updateProgress($import, $processedCount, $failedCount);
                $chunk = [];
                $chunkReferences = [];
            }
        }

        // Process remaining chunk
        if (! empty($chunk)) {
            $results = $this->processChunk($normalizer, $client, $chunk, $chunkReferences);
            $this->writeResults($outputHandle, $results);
            $processedCount += count($chunk);
            $failedCount += count(array_filter($results, fn ($r) => $r['status'] === 'error'));
            $this->updateProgress($import, $processedCount, $failedCount);
        }

        fclose($inputHandle);
        fclose($outputHandle);
    }

    private function processChunk(AddressNormalizer $normalizer, $client, array $inputs, array $references): array
    {
        try {
            $import = CsvBatchImport::find($this->importId);
            $googleValidate = $import?->google_validate ? true : null;
            $batchResult = $normalizer->normalizeBatch($inputs, $client, $googleValidate);
            $rows = [];

            foreach ($batchResult['results'] as $i => $result) {
                $ref = $references[$i] ?? '';

                if ($result['status'] === 'ok') {
                    $data = $result['data'];
                    $googleValidation = $data['google_validation'] ?? null;

                    $rows[] = [
                        'reference' => $ref,
                        'status' => 'ok',
                        'confidence' => $result['confidence'],
                        'source' => $result['source'],
                        'country_code' => $data['country_code'] ?? '',
                        'region' => $data['region'] ?? '',
                        'postal_code' => $data['postal_code'] ?? '',
                        'city' => $data['city'] ?? '',
                        'street' => $data['street'] ?? '',
                        'house_number' => $data['house_number'] ?? '',
                        'apartment_number' => $data['apartment_number'] ?? '',
                        'company_name' => $data['company_name'] ?? '',
                        'formatted' => $data['formatted'] ?? '',
                        'removed_noise' => implode(' | ', $result['removed_noise'] ?? []),
                        'latitude' => $googleValidation['latitude'] ?? '',
                        'longitude' => $googleValidation['longitude'] ?? '',
                        'validation_granularity' => $googleValidation['validation_granularity'] ?? '',
                        'address_complete' => isset($googleValidation['address_complete']) ? ($googleValidation['address_complete'] ? 'yes' : 'no') : '',
                        'validation_issues' => implode(' | ', $googleValidation['issues'] ?? []),
                        'error' => '',
                    ];
                } else {
                    $rows[] = $this->errorRow($ref, $result['error'] ?? 'Normalization failed');
                }
            }

            return $rows;
        } catch (\Throwable $e) {
            return array_map(
                fn ($ref) => $this->errorRow($ref, $e->getMessage()),
                $references
            );
        }
    }

    private function rowToInput(array $row, string $variant): RawAddressInput
    {
        $city = $row['city'] ?? '';
        $address = $row['address'] ?? '';

        // For the full variant, prepend company_name to city (AI will extract it)
        if ($variant === CsvFormatDetector::VARIANT_FULL && ! empty($row['company_name'])) {
            $city .= ' ' . $row['company_name'];
        }

        return new RawAddressInput(
            country: $row['country'] ?? '',
            city: trim($city),
            address: $address,
            postal_code: $row['postal_code'] ?? null,
            full_name: $row['full_name'] ?? null,
            id: $row['reference'] ?? null,
        );
    }

    private function outputHeaders(): array
    {
        return [
            'reference', 'status', 'confidence', 'source',
            'country_code', 'region', 'postal_code', 'city',
            'street', 'house_number', 'apartment_number', 'company_name',
            'formatted', 'removed_noise',
            'latitude', 'longitude', 'validation_granularity',
            'address_complete', 'validation_issues',
            'error',
        ];
    }

    private function errorRow(string $reference, string $error): array
    {
        return [
            'reference' => $reference,
            'status' => 'error',
            'confidence' => '',
            'source' => '',
            'country_code' => '',
            'region' => '',
            'postal_code' => '',
            'city' => '',
            'street' => '',
            'house_number' => '',
            'apartment_number' => '',
            'company_name' => '',
            'formatted' => '',
            'removed_noise' => '',
            'latitude' => '',
            'longitude' => '',
            'validation_granularity' => '',
            'address_complete' => '',
            'validation_issues' => '',
            'error' => $error,
        ];
    }

    private function writeResults($handle, array $rows): void
    {
        foreach ($rows as $row) {
            fputcsv($handle, array_values($row), ';');
        }
    }

    private function updateProgress(CsvBatchImport $import, int $processed, int $failed): void
    {
        $import->update([
            'processed_rows' => $processed,
            'failed_rows' => $failed,
        ]);
    }
}
