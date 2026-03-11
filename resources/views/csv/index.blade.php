@extends('csv.layout', ['title' => 'CSV Batch Import'])

@section('content')
<div class="space-y-8">

    {{-- Header --}}
    <div class="flex items-center justify-between">
        <div>
            <h2 class="text-2xl font-semibold text-gray-800">CSV Batch Import</h2>
            <p class="text-sm text-gray-500 mt-1">Client: <strong>{{ $client->name }}</strong> &middot; Remaining quota: <strong>{{ number_format($client->remainingQuota()) }}</strong></p>
        </div>
    </div>

    {{-- Upload form --}}
    <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
        <h3 class="text-lg font-medium text-gray-800 mb-4">Upload CSV file</h3>

        <form method="POST" action="{{ route('csv.upload') }}" enctype="multipart/form-data">
            @csrf
            <div class="mb-4">
                <label for="csv_file" class="block text-sm font-medium text-gray-700 mb-1">Select file</label>
                <input type="file" name="csv_file" id="csv_file" accept=".csv,.txt" required
                    class="block w-full text-sm text-gray-500 file:mr-4 file:rounded-md file:border-0 file:bg-blue-50 file:px-4 file:py-2 file:text-sm file:font-medium file:text-blue-700 hover:file:bg-blue-100">
                @error('csv_file')
                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>
            <button type="submit"
                class="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
                Upload & Process
            </button>
        </form>

        <p class="mt-3 text-xs text-gray-400">Max {{ number_format($maxRows) }} rows per file. Semicolon (;) separator. UTF-8 encoding.</p>
    </div>

    {{-- Format instructions --}}
    <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
        <h3 class="text-lg font-medium text-gray-800 mb-4">Supported CSV formats</h3>
        <p class="text-sm text-gray-600 mb-4">
            The system automatically detects the format based on the header row. Use <strong>semicolon (;)</strong> as separator.
            The <code class="bg-gray-100 px-1 rounded text-xs">reference</code> column is your identifier (order number, ID, etc.) and will be returned in the results file.
        </p>

        <div class="space-y-6">
            {{-- Minimal --}}
            <div>
                <h4 class="text-sm font-semibold text-gray-700 mb-2">Variant A &mdash; Minimal (4 columns)</h4>
                <p class="text-xs text-gray-500 mb-2">Required columns only. Use when you only have basic address data.</p>
                <div class="overflow-x-auto">
                    <pre class="bg-gray-50 border border-gray-200 rounded-md p-3 text-xs text-gray-700 whitespace-pre">reference;country;city;address
ORD-12345;PL;Warszawa FHU Kowalski;ul. Marszalkowska 1/2 prosze dzwonic
ORD-12346;CZ;Praha 1 TechSoft s.r.o.;Vodickova 681/14
ORD-12347;DE;Berlin;Friedrichstrasse 123</pre>
                </div>
            </div>

            {{-- Standard --}}
            <div>
                <h4 class="text-sm font-semibold text-gray-700 mb-2">Variant B &mdash; Standard (6 columns)</h4>
                <p class="text-xs text-gray-500 mb-2">Includes postal code and recipient name for better accuracy.</p>
                <div class="overflow-x-auto">
                    <pre class="bg-gray-50 border border-gray-200 rounded-md p-3 text-xs text-gray-700 whitespace-pre">reference;country;postal_code;city;address;full_name
ORD-12345;PL;00-001;Warszawa FHU Kowalski;ul. Marszalkowska 1/2 prosze dzwonic;Jan Kowalski
ORD-12346;CZ;110 00;Praha 1 TechSoft s.r.o.;Vodickova 681/14;Petr Novak
ORD-12347;DE;10115;Berlin;Friedrichstrasse 123;Hans Mueller</pre>
                </div>
            </div>

            {{-- Full --}}
            <div>
                <h4 class="text-sm font-semibold text-gray-700 mb-2">Variant C &mdash; Full (7 columns)</h4>
                <p class="text-xs text-gray-500 mb-2">All fields including known company name from your system.</p>
                <div class="overflow-x-auto">
                    <pre class="bg-gray-50 border border-gray-200 rounded-md p-3 text-xs text-gray-700 whitespace-pre">reference;country;postal_code;city;address;full_name;company_name
ORD-12345;PL;00-001;Warszawa;Marszalkowska 1/2;Jan Kowalski;FHU Kowalski
ORD-12346;CZ;110 00;Praha 1;Vodickova 681/14;Petr Novak;TechSoft s.r.o.
ORD-12347;DE;10115;Berlin;Friedrichstrasse 123;Hans Mueller;TechGmbH</pre>
                </div>
            </div>
        </div>

        <div class="mt-6 rounded-md bg-blue-50 border border-blue-200 p-4">
            <h4 class="text-sm font-semibold text-blue-800 mb-2">Result file columns</h4>
            <p class="text-xs text-blue-700">
                The output CSV contains: <code>reference</code>, <code>status</code>, <code>confidence</code>, <code>source</code>,
                <code>country_code</code>, <code>region</code>, <code>postal_code</code>, <code>city</code>,
                <code>street</code>, <code>house_number</code>, <code>apartment_number</code>, <code>company_name</code>,
                <code>formatted</code>, <code>removed_noise</code>,
                <code>latitude</code>, <code>longitude</code>, <code>validation_granularity</code>,
                <code>address_complete</code>, <code>validation_issues</code>, <code>error</code>.
            </p>
            <p class="text-xs text-blue-600 mt-1">
                Geographic coordinates and validation fields are populated when Google Address Validation is enabled.
            </p>
        </div>
    </div>

    {{-- Imports table --}}
    @if($imports->isNotEmpty())
    <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
        <h3 class="text-lg font-medium text-gray-800 mb-4">Recent imports</h3>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-200 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                        <th class="pb-3 pr-4">File</th>
                        <th class="pb-3 pr-4">Format</th>
                        <th class="pb-3 pr-4">Rows</th>
                        <th class="pb-3 pr-4">Progress</th>
                        <th class="pb-3 pr-4">Status</th>
                        <th class="pb-3 pr-4">Date</th>
                        <th class="pb-3">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach($imports as $import)
                    <tr data-import-id="{{ $import->id }}" class="text-gray-700">
                        <td class="py-3 pr-4 font-medium max-w-[200px] truncate" title="{{ $import->original_filename }}">
                            {{ $import->original_filename }}
                        </td>
                        <td class="py-3 pr-4">
                            <span class="inline-block rounded bg-gray-100 px-2 py-0.5 text-xs">{{ $import->format_variant }}</span>
                        </td>
                        <td class="py-3 pr-4">
                            <span class="processed-count">{{ $import->processed_rows }}</span>/{{ $import->total_rows }}
                            @if($import->failed_rows > 0)
                                <span class="text-red-500 text-xs">({{ $import->failed_rows }} failed)</span>
                            @endif
                        </td>
                        <td class="py-3 pr-4 w-32">
                            <div class="w-full bg-gray-200 rounded-full h-2">
                                <div class="progress-bar h-2 rounded-full transition-all duration-300
                                    {{ $import->status === 'completed' ? 'bg-green-500' : ($import->status === 'failed' ? 'bg-red-500' : 'bg-blue-500') }}"
                                    style="width: {{ $import->progressPercent() }}%">
                                </div>
                            </div>
                        </td>
                        <td class="py-3 pr-4">
                            @php
                                $statusColors = [
                                    'pending' => 'bg-yellow-100 text-yellow-800',
                                    'processing' => 'bg-blue-100 text-blue-800',
                                    'completed' => 'bg-green-100 text-green-800',
                                    'failed' => 'bg-red-100 text-red-800',
                                ];
                            @endphp
                            <span class="status-badge inline-block rounded-full px-2 py-0.5 text-xs font-medium {{ $statusColors[$import->status] ?? 'bg-gray-100 text-gray-800' }}">
                                {{ $import->status }}
                            </span>
                        </td>
                        <td class="py-3 pr-4 text-xs text-gray-500">
                            {{ $import->created_at->format('Y-m-d H:i') }}
                        </td>
                        <td class="py-3">
                            @if($import->result_filename)
                                <a href="{{ route('csv.download', $import) }}"
                                    class="download-link text-blue-600 hover:text-blue-800 text-xs font-medium">
                                    Download
                                </a>
                            @elseif($import->status === 'failed')
                                <span class="text-xs text-red-500" title="{{ $import->error_message }}">Failed</span>
                            @else
                                <span class="download-link text-xs text-gray-400">Processing...</span>
                            @endif
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
    @endif

</div>

{{-- Auto-refresh for pending/processing imports --}}
@if($imports->contains(fn ($i) => !$i->isFinished()))
<script>
    const activeImports = @json($imports->filter(fn ($i) => !$i->isFinished())->pluck('id'));

    function pollStatus() {
        if (activeImports.length === 0) return;

        activeImports.forEach(id => {
            fetch(`/csv/${id}/status`)
                .then(r => r.json())
                .then(data => {
                    const row = document.querySelector(`tr[data-import-id="${id}"]`);
                    if (!row) return;

                    row.querySelector('.processed-count').textContent = data.processed_rows;
                    row.querySelector('.progress-bar').style.width = data.progress + '%';

                    if (data.is_finished) {
                        // Reload to get updated UI
                        window.location.reload();
                    }
                })
                .catch(() => {});
        });
    }

    setInterval(pollStatus, 3000);
</script>
@endif
@endsection
