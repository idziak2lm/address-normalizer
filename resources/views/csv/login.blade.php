@extends('csv.layout', ['title' => 'Login'])

@section('content')
<div class="max-w-md mx-auto">
    <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
        <h2 class="text-xl font-semibold text-gray-800 mb-4">CSV Batch Import</h2>
        <p class="text-sm text-gray-600 mb-6">Enter your API key to access the CSV batch upload tool.</p>

        <form method="POST" action="{{ route('csv.login') }}">
            @csrf
            <div class="mb-4">
                <label for="api_key" class="block text-sm font-medium text-gray-700 mb-1">API Key</label>
                <input type="password" name="api_key" id="api_key" required
                    class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
                    placeholder="Your Bearer token">
            </div>
            <button type="submit"
                class="w-full rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
                Login
            </button>
        </form>
    </div>
</div>
@endsection
