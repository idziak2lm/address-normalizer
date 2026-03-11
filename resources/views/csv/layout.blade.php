<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title ?? 'CSV Batch Import' }} - Address Normalizer</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50 min-h-screen">
    <nav class="bg-white border-b border-gray-200">
        <div class="max-w-5xl mx-auto px-4 py-3 flex items-center justify-between">
            <h1 class="text-lg font-semibold text-gray-800">Address Normalizer</h1>
            @if(session('api_client_id'))
                <form method="POST" action="{{ route('csv.logout') }}">
                    @csrf
                    <button type="submit" class="text-sm text-gray-500 hover:text-gray-700">Logout</button>
                </form>
            @endif
        </div>
    </nav>

    <main class="max-w-5xl mx-auto px-4 py-8">
        @if(session('error'))
            <div class="mb-6 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-700">
                {{ session('error') }}
            </div>
        @endif

        @if(session('success'))
            <div class="mb-6 rounded-lg border border-green-200 bg-green-50 p-4 text-sm text-green-700">
                {{ session('success') }}
            </div>
        @endif

        @yield('content')
    </main>
</body>
</html>
