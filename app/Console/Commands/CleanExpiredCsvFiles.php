<?php

namespace App\Console\Commands;

use App\Models\CsvBatchImport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class CleanExpiredCsvFiles extends Command
{
    protected $signature = 'csv:clean-expired';
    protected $description = 'Delete CSV uploads and exports older than retention period';

    public function handle(): int
    {
        $retentionDays = config('normalizer.csv.retention_days', 7);
        $cutoff = now()->subDays($retentionDays);

        $expired = CsvBatchImport::where('created_at', '<', $cutoff)->get();

        $deleted = 0;

        foreach ($expired as $import) {
            $uploadPath = config('normalizer.csv.upload_path', 'csv-uploads') . '/' . $import->stored_filename;
            Storage::disk('local')->delete($uploadPath);

            if ($import->result_filename) {
                $exportPath = config('normalizer.csv.export_path', 'csv-exports') . '/' . $import->result_filename;
                Storage::disk('local')->delete($exportPath);
            }

            $import->delete();
            $deleted++;
        }

        $this->info("Deleted {$deleted} expired CSV import(s).");

        return self::SUCCESS;
    }
}
