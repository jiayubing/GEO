@extends('admin.layouts.app')

@section('content')
    <div class="px-4 sm:px-0">
        <div class="mb-8 flex items-center justify-between">
            <div class="flex items-center space-x-4">
                <a href="{{ route('admin.materials.index') }}" class="text-gray-400 hover:text-gray-600">
                    <i data-lucide="arrow-left" class="w-5 h-5"></i>
                </a>
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">{{ __('admin.knowledge_bases.heading') }}</h1>
                    <p class="mt-1 text-sm text-gray-600">{{ __('admin.knowledge_bases.subtitle') }}</p>
                </div>
            </div>
            <div class="flex items-center gap-3">
                <a href="{{ route('admin.knowledge-bases.create') }}" class="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                    <i data-lucide="plus" class="w-4 h-4 mr-2"></i>
                    {{ __('admin.knowledge_bases.create_first') }}
                </a>
                <a href="{{ route('admin.knowledge-bases.create', ['mode' => 'upload']) }}" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-orange-600 hover:bg-orange-700">
                    <i data-lucide="upload" class="w-4 h-4 mr-2"></i>
                    {{ __('admin.knowledge_bases.import_unified') }}
                </a>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <i data-lucide="brain" class="h-6 w-6 text-orange-600"></i>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate">{{ __('admin.knowledge_bases.total') }}</dt>
                                <dd class="text-lg font-medium text-gray-900">{{ (int) ($stats['total_knowledge'] ?? 0) }}</dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>
            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <i data-lucide="file-text" class="h-6 w-6 text-blue-600"></i>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate">{{ __('admin.knowledge_bases.total_words') }}</dt>
                                <dd class="text-lg font-medium text-gray-900">{{ number_format((int) ($stats['total_words'] ?? 0)) }}</dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>
            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <i data-lucide="hash" class="h-6 w-6 text-green-600"></i>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate">{{ __('admin.knowledge_bases.markdown_count') }}</dt>
                                <dd class="text-lg font-medium text-gray-900">{{ (int) ($stats['markdown_count'] ?? 0) }}</dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>
            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <i data-lucide="file" class="h-6 w-6 text-purple-600"></i>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate">{{ __('admin.knowledge_bases.word_count') }}</dt>
                                <dd class="text-lg font-medium text-gray-900">{{ (int) ($stats['word_count'] ?? 0) }}</dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="bg-white shadow rounded-lg">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-medium text-gray-900">{{ __('admin.knowledge_bases.list_title') }}</h3>
            </div>
            @if (empty($knowledgeBases))
                <div class="px-6 py-8 text-center">
                    <i data-lucide="brain" class="w-12 h-12 mx-auto text-gray-400 mb-4"></i>
                    <h3 class="text-lg font-medium text-gray-900 mb-2">{{ __('admin.knowledge_bases.empty') }}</h3>
                    <p class="text-gray-500 mb-4">{{ __('admin.knowledge_bases.empty_desc') }}</p>
                    <div class="flex justify-center space-x-2">
                        <a href="{{ route('admin.knowledge-bases.create') }}" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-orange-600 hover:bg-orange-700">
                            <i data-lucide="plus" class="w-4 h-4 mr-2"></i>
                            {{ __('admin.knowledge_bases.create_first') }}
                        </a>
                        <a href="{{ route('admin.knowledge-bases.create', ['mode' => 'upload']) }}" class="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                            <i data-lucide="upload" class="w-4 h-4 mr-2"></i>
                            {{ __('admin.knowledge_bases.import_unified') }}
                        </a>
                    </div>
                </div>
            @else
                <div class="flex items-center justify-between gap-6 px-6 py-3 border-b border-gray-200 bg-gray-50 text-xs font-semibold uppercase tracking-wide text-gray-500">
                    <div>{{ __('admin.knowledge_bases.column_knowledge_base') }}</div>
                    <div class="text-right">{{ __('admin.common.actions') }}</div>
                </div>
                <div class="divide-y divide-gray-200">
                    @foreach ($knowledgeBases as $item)
                        <div class="px-6 py-6">
                            <div class="flex flex-col gap-5 lg:flex-row lg:items-center">
                                <div class="min-w-0 lg:flex-1">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <h4 class="text-lg font-medium text-gray-900">
                                            <a href="{{ route('admin.knowledge-bases.detail', ['knowledgeBaseId' => (int) $item['id']]) }}" class="hover:text-orange-600">
                                                {{ $item['name'] }}
                                            </a>
                                        </h4>
                                        @php
                                            $type = (string) ($item['file_type'] ?? 'markdown');
                                            $typeBadgeClass = $type === 'markdown'
                                                ? 'bg-green-100 text-green-800'
                                                : ($type === 'word' ? 'bg-purple-100 text-purple-800' : 'bg-blue-100 text-blue-800');
                                            $typeText = $type === 'markdown'
                                                ? __('admin.status.markdown')
                                                : ($type === 'word' ? __('admin.status.word_document') : __('admin.status.text'));
                                        @endphp
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium {{ $typeBadgeClass }}">
                                            {{ $typeText }}
                                        </span>
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-orange-100 text-orange-800">
                                            {{ __('admin.knowledge_bases.text_unit', ['count' => number_format((int) $item['word_count'])]) }}
                                        </span>
                                    </div>
                                    @if ($item['description'] !== '')
                                        <p class="mt-1 text-sm text-gray-600">{{ $item['description'] }}</p>
                                    @endif
                                    <div class="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-gray-500">
                                        <span>
                                            {{ __('admin.knowledge_bases.created_at', ['value' => $item['created_at'] ? \Illuminate\Support\Carbon::parse($item['created_at'])->format('Y-m-d H:i') : '-']) }}
                                        </span>
                                        <span>
                                            {{ __('admin.knowledge_bases.updated_at', ['value' => $item['updated_at'] ? \Illuminate\Support\Carbon::parse($item['updated_at'])->format('Y-m-d H:i') : '-']) }}
                                        </span>
                                        @if ((int) ($item['usage_count'] ?? 0) > 0)
                                            <span>{{ __('admin.knowledge_bases.usage_count', ['count' => (int) $item['usage_count']]) }}</span>
                                        @endif
                                    </div>
                                </div>

                                <div class="flex flex-wrap items-start justify-start gap-2 lg:shrink-0 lg:justify-end lg:pl-8">
                                    <a href="{{ route('admin.knowledge-bases.detail', ['knowledgeBaseId' => (int) $item['id']]) }}" class="inline-flex items-center px-3 py-1.5 border border-gray-300 text-xs font-medium rounded text-gray-700 bg-white hover:bg-gray-50">
                                        <i data-lucide="eye" class="w-4 h-4 mr-1"></i>
                                        {{ __('admin.button.view') }}
                                    </a>
                                    <form method="POST" action="{{ route('admin.knowledge-bases.delete', ['knowledgeBaseId' => (int) $item['id']]) }}" onsubmit="return confirm(@js(__('admin.knowledge_bases.confirm_delete', ['name' => $item['name']])));" class="inline-block">
                                        @csrf
                                        <button type="submit" class="inline-flex items-center px-3 py-1.5 border border-transparent text-xs font-medium rounded text-white bg-red-600 hover:bg-red-700">
                                            <i data-lucide="trash-2" class="w-4 h-4 mr-1"></i>
                                            {{ __('admin.button.delete') }}
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>

@endsection
