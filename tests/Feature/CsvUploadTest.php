<?php

namespace Tests\Feature;

use App\Models\ApiClient;
use App\Models\CsvBatchImport;
use App\Jobs\ProcessCsvBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CsvUploadTest extends TestCase
{
    use RefreshDatabase;

    private ApiClient $client;
    private string $plainToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->plainToken = 'test-token-csv';
        $this->client = ApiClient::create([
            'name' => 'test-client',
            'api_key' => hash('sha256', $this->plainToken),
            'monthly_limit' => 10000,
            'current_month_usage' => 0,
            'is_active' => true,
            'preferred_provider' => 'openai',
        ]);
    }

    public function test_login_page_is_accessible(): void
    {
        $response = $this->get('/csv/login');

        $response->assertStatus(200);
        $response->assertSee('API Key');
    }

    public function test_login_with_valid_api_key(): void
    {
        $response = $this->post('/csv/login', [
            'api_key' => $this->plainToken,
        ]);

        $response->assertRedirect(route('csv.index'));
        $this->assertEquals($this->client->id, session('api_client_id'));
    }

    public function test_login_with_invalid_api_key(): void
    {
        $response = $this->post('/csv/login', [
            'api_key' => 'wrong-token',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('error');
        $this->assertNull(session('api_client_id'));
    }

    public function test_login_with_deactivated_client(): void
    {
        $this->client->update(['is_active' => false]);

        $response = $this->post('/csv/login', [
            'api_key' => $this->plainToken,
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('error');
    }

    public function test_index_requires_auth(): void
    {
        $response = $this->get('/csv');

        $response->assertRedirect(route('csv.login'));
    }

    public function test_index_accessible_when_logged_in(): void
    {
        $response = $this->withSession(['api_client_id' => $this->client->id])
            ->get('/csv');

        $response->assertStatus(200);
        $response->assertSee('CSV Batch Import');
        $response->assertSee('Variant A');
        $response->assertSee('Variant B');
        $response->assertSee('Variant C');
    }

    public function test_upload_minimal_csv(): void
    {
        Queue::fake();
        Storage::fake('local');

        $csv = "reference;country;city;address\nORD-1;PL;Warszawa;Marszalkowska 1\nORD-2;DE;Berlin;Friedrichstrasse 5\n";
        $file = UploadedFile::fake()->createWithContent('addresses.csv', $csv);

        $response = $this->withSession(['api_client_id' => $this->client->id])
            ->post('/csv/upload', ['csv_file' => $file]);

        $response->assertRedirect(route('csv.index'));
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('csv_batch_imports', [
            'api_client_id' => $this->client->id,
            'format_variant' => 'minimal',
            'total_rows' => 2,
            'status' => 'pending',
        ]);

        Queue::assertPushed(ProcessCsvBatch::class);
    }

    public function test_upload_standard_csv(): void
    {
        Queue::fake();
        Storage::fake('local');

        $csv = "reference;country;postal_code;city;address;full_name\nORD-1;PL;00-001;Warszawa;Marszalkowska 1;Jan Kowalski\n";
        $file = UploadedFile::fake()->createWithContent('addresses.csv', $csv);

        $response = $this->withSession(['api_client_id' => $this->client->id])
            ->post('/csv/upload', ['csv_file' => $file]);

        $response->assertRedirect(route('csv.index'));

        $this->assertDatabaseHas('csv_batch_imports', [
            'format_variant' => 'standard',
            'total_rows' => 1,
        ]);
    }

    public function test_upload_full_csv(): void
    {
        Queue::fake();
        Storage::fake('local');

        $csv = "reference;country;postal_code;city;address;full_name;company_name\nORD-1;PL;00-001;Warszawa;Marszalkowska 1;Jan K;FHU Test\n";
        $file = UploadedFile::fake()->createWithContent('addresses.csv', $csv);

        $response = $this->withSession(['api_client_id' => $this->client->id])
            ->post('/csv/upload', ['csv_file' => $file]);

        $this->assertDatabaseHas('csv_batch_imports', [
            'format_variant' => 'full',
        ]);
    }

    public function test_rejects_unrecognized_format(): void
    {
        Queue::fake();
        Storage::fake('local');

        $csv = "id;name;email\n1;John;john@test.com\n";
        $file = UploadedFile::fake()->createWithContent('bad.csv', $csv);

        $response = $this->withSession(['api_client_id' => $this->client->id])
            ->post('/csv/upload', ['csv_file' => $file]);

        $response->assertRedirect();
        $response->assertSessionHas('error', 'Unrecognized CSV format. Please check the header row matches one of the supported formats.');

        Queue::assertNotPushed(ProcessCsvBatch::class);
    }

    public function test_rejects_empty_file(): void
    {
        Queue::fake();
        Storage::fake('local');

        $csv = "reference;country;city;address\n";
        $file = UploadedFile::fake()->createWithContent('empty.csv', $csv);

        $response = $this->withSession(['api_client_id' => $this->client->id])
            ->post('/csv/upload', ['csv_file' => $file]);

        $response->assertRedirect();
        $response->assertSessionHas('error', 'File contains no data rows.');
    }

    public function test_rejects_when_quota_insufficient(): void
    {
        Queue::fake();
        Storage::fake('local');

        $this->client->update(['current_month_usage' => 9999]);

        $csv = "reference;country;city;address\nORD-1;PL;Warszawa;Test 1\nORD-2;PL;Krakow;Test 2\n";
        $file = UploadedFile::fake()->createWithContent('addresses.csv', $csv);

        $response = $this->withSession(['api_client_id' => $this->client->id])
            ->post('/csv/upload', ['csv_file' => $file]);

        $response->assertRedirect();
        $response->assertSessionHas('error');

        Queue::assertNotPushed(ProcessCsvBatch::class);
    }

    public function test_status_endpoint_returns_progress(): void
    {
        $import = CsvBatchImport::create([
            'api_client_id' => $this->client->id,
            'original_filename' => 'test.csv',
            'stored_filename' => 'stored.csv',
            'format_variant' => 'minimal',
            'total_rows' => 100,
            'processed_rows' => 50,
            'failed_rows' => 2,
            'status' => 'processing',
        ]);

        $response = $this->withSession(['api_client_id' => $this->client->id])
            ->getJson("/csv/{$import->id}/status");

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'processing',
                'total_rows' => 100,
                'processed_rows' => 50,
                'failed_rows' => 2,
                'progress' => 50,
                'is_finished' => false,
            ]);
    }

    public function test_status_endpoint_forbidden_for_other_client(): void
    {
        $otherClient = ApiClient::create([
            'name' => 'other',
            'api_key' => hash('sha256', 'other-token'),
            'monthly_limit' => 1000,
            'is_active' => true,
        ]);

        $import = CsvBatchImport::create([
            'api_client_id' => $otherClient->id,
            'original_filename' => 'test.csv',
            'stored_filename' => 'stored.csv',
            'format_variant' => 'minimal',
            'total_rows' => 10,
            'status' => 'pending',
        ]);

        $response = $this->withSession(['api_client_id' => $this->client->id])
            ->getJson("/csv/{$import->id}/status");

        $response->assertStatus(403);
    }

    public function test_download_completed_import(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('csv-exports/result_test.csv', "reference;status\nORD-1;ok\n");

        $import = CsvBatchImport::create([
            'api_client_id' => $this->client->id,
            'original_filename' => 'addresses.csv',
            'stored_filename' => 'test.csv',
            'format_variant' => 'minimal',
            'total_rows' => 1,
            'processed_rows' => 1,
            'status' => 'completed',
            'result_filename' => 'result_test.csv',
        ]);

        $response = $this->withSession(['api_client_id' => $this->client->id])
            ->get("/csv/{$import->id}/download");

        $response->assertStatus(200);
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');
    }

    public function test_download_not_ready(): void
    {
        $import = CsvBatchImport::create([
            'api_client_id' => $this->client->id,
            'original_filename' => 'test.csv',
            'stored_filename' => 'stored.csv',
            'format_variant' => 'minimal',
            'total_rows' => 10,
            'status' => 'processing',
            'result_filename' => null,
        ]);

        $response = $this->withSession(['api_client_id' => $this->client->id])
            ->get("/csv/{$import->id}/download");

        $response->assertRedirect();
        $response->assertSessionHas('error');
    }

    public function test_logout(): void
    {
        $response = $this->withSession(['api_client_id' => $this->client->id])
            ->post('/csv/logout');

        $response->assertRedirect(route('csv.login'));
        $this->assertNull(session('api_client_id'));
    }
}
