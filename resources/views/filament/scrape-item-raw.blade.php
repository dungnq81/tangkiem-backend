<div class="space-y-3 p-4">
    <div class="text-sm text-gray-500 dark:text-gray-400">
        <strong>URL:</strong>
        <a href="{{ $url }}" target="_blank" class="text-primary-500 hover:underline">{{ $url }}</a>
    </div>

    <div class="overflow-auto max-h-96 rounded-lg bg-gray-50 dark:bg-gray-900 p-3">
        <table class="w-full text-sm">
            <tbody>
                @foreach ($data as $key => $value)
                    <tr class="border-b border-gray-200 dark:border-gray-700">
                        <td class="py-2 pr-4 font-medium text-gray-600 dark:text-gray-300 whitespace-nowrap align-top">
                            {{ $key }}
                        </td>
                        <td class="py-2 text-gray-800 dark:text-gray-200">
                            @if (is_array($value))
                                <code class="text-xs">{{ json_encode($value, JSON_UNESCAPED_UNICODE) }}</code>
                            @else
                                {{ $value }}
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
