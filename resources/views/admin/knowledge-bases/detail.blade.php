@extends('admin.layouts.app')

@php
    $vditorLocaleMap = [
        'zh_CN' => 'zh_CN',
        'en' => 'en_US',
        'en_US' => 'en_US',
        'ja' => 'ja_JP',
        'ja_JP' => 'ja_JP',
        'ru' => 'ru_RU',
        'ru_RU' => 'ru_RU',
        'pt_BR' => 'pt_BR',
        'es' => 'es_ES',
        'es_ES' => 'es_ES',
    ];
    $vditorLang = $vditorLocaleMap[str_replace('-', '_', app()->getLocale())] ?? 'en_US';
@endphp

@push('styles')
    <link rel="stylesheet" href="{{ asset('vendor/vditor/dist/index.css') }}">
    <style>
        .knowledge-markdown-editor .vditor {
            border: 0;
            border-radius: 0 0 0.75rem 0.75rem;
            min-height: 720px;
        }

        .knowledge-markdown-editor .vditor-toolbar {
            background: #f9fafb;
            border-bottom-color: #e5e7eb;
            padding: 8px 10px;
            position: sticky;
            top: 0;
            z-index: 10;
        }

        .knowledge-markdown-editor .vditor-ir,
        .knowledge-markdown-editor .vditor-sv,
        .knowledge-markdown-editor .vditor-wysiwyg {
            min-height: 660px;
            padding: 30px 36px;
            font-size: 16px;
            line-height: 1.85;
        }

        .knowledge-markdown-editor .vditor-reset {
            color: #111827;
        }

        .knowledge-markdown-editor .vditor-reset h1,
        .knowledge-markdown-editor .vditor-reset h2,
        .knowledge-markdown-editor .vditor-reset h3 {
            color: #111827;
            letter-spacing: 0;
        }

        .knowledge-markdown-editor .vditor-preview {
            background: #fff;
        }
    </style>
@endpush

@section('content')
    <div class="px-4 sm:px-0">
        <div class="mb-8 flex items-center justify-between">
            <div class="flex items-center space-x-4">
                <a href="{{ route('admin.knowledge-bases.index') }}" class="text-gray-400 hover:text-gray-600">
                    <i data-lucide="arrow-left" class="w-5 h-5"></i>
                </a>
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">{{ __('admin.knowledge_detail.heading') }}</h1>
                    <p class="mt-1 text-sm text-gray-600">{{ $knowledgeBase->name }}</p>
                </div>
            </div>
        </div>

        <div class="bg-white shadow rounded-lg mb-6">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-medium text-gray-900">{{ __('admin.knowledge_detail.content_title') }}</h3>
            </div>
            <form id="knowledge-detail-form" method="POST" action="{{ route('admin.knowledge-bases.detail.update', ['knowledgeBaseId' => (int) $knowledgeBase->id]) }}" class="p-6 space-y-4">
                @csrf
                @method('PUT')
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">{{ __('admin.knowledge_detail.field_name') }}</label>
                        <input type="text" name="name" value="{{ old('name', (string) $knowledgeBase->name) }}" class="w-full border-gray-300 rounded-md shadow-sm focus:ring-orange-500 focus:border-orange-500 text-sm" required>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">{{ __('admin.knowledge_bases.field_doc_type') }}</label>
                        <select name="file_type" class="w-full border-gray-300 rounded-md shadow-sm focus:ring-orange-500 focus:border-orange-500 text-sm" required>
                            <option value="markdown" @selected(old('file_type', (string) ($knowledgeBase->file_type ?? 'markdown')) === 'markdown')>{{ __('admin.status.markdown') }}</option>
                            <option value="word" @selected(old('file_type', (string) ($knowledgeBase->file_type ?? 'markdown')) === 'word')>{{ __('admin.status.word_document') }}</option>
                            <option value="text" @selected(old('file_type', (string) ($knowledgeBase->file_type ?? 'markdown')) === 'text')>{{ __('admin.status.text') }}</option>
                        </select>
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">{{ __('admin.knowledge_detail.field_description') }}</label>
                    <textarea name="description" rows="3" class="w-full border-gray-300 rounded-md shadow-sm focus:ring-orange-500 focus:border-orange-500 text-sm">{{ old('description', (string) ($knowledgeBase->description ?? '')) }}</textarea>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">{{ __('admin.knowledge_detail.field_content') }}</label>
                    <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
                        <div class="flex flex-col gap-3 border-b border-gray-200 bg-gray-50 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                            <span class="inline-flex w-fit items-center rounded-full bg-orange-50 px-3 py-1 text-sm font-semibold text-orange-700">
                                <i data-lucide="file-pen-line" class="mr-2 h-4 w-4"></i>
                                {{ __('admin.knowledge_detail.editor_badge') }}
                            </span>
                            <span class="text-sm text-gray-500">{{ __('admin.knowledge_detail.editor_hint') }}</span>
                        </div>
                        <textarea id="knowledge-content-textarea" name="content" rows="18" class="hidden w-full border-gray-300 rounded-md shadow-sm focus:ring-orange-500 focus:border-orange-500 text-sm">{{ old('content', (string) ($knowledgeBase->content ?? '')) }}</textarea>
                        <div id="knowledge-content-editor" class="knowledge-markdown-editor min-h-[720px]"></div>
                    </div>
                </div>
            </form>
            <div class="-mt-2 flex flex-col gap-3 px-6 pb-6 sm:flex-row sm:items-center sm:justify-end">
                <button type="submit" form="knowledge-detail-form" class="inline-flex items-center justify-center px-4 py-2 border border-transparent rounded-md text-sm text-white bg-orange-600 hover:bg-orange-700">
                    <i data-lucide="save" class="w-4 h-4 mr-2"></i>
                    {{ __('admin.knowledge_detail.save_changes') }}
                </button>
            </div>
        </div>

        <div class="grid grid-cols-1 gap-6 mb-6">
            <div class="bg-white shadow rounded-lg p-5">
                <div class="text-sm text-gray-500">{{ __('admin.knowledge_detail.updated_at') }}</div>
                <div class="mt-2 text-sm font-medium text-gray-900">{{ optional($knowledgeBase->updated_at)->format('Y-m-d H:i:s') ?? '-' }}</div>
            </div>
        </div>

        <div class="bg-white shadow rounded-lg mb-6">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-medium text-gray-900">{{ __('admin.common.related_tasks') }}</h3>
            </div>
            @if ($relatedTasks->isEmpty())
                <div class="px-6 py-5 text-sm text-gray-500">{{ __('admin.knowledge_detail.related_tasks_empty') }}</div>
            @else
                <div class="divide-y divide-gray-200">
                    @foreach ($relatedTasks as $task)
                        <div class="px-6 py-4 flex items-center justify-between">
                            <div class="text-sm text-gray-900">#{{ (int) $task->id }} {{ $task->name }}</div>
                            <div class="text-xs text-gray-500">{{ $task->status }}</div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

    </div>
@endsection

@push('scripts')
    <script src="{{ asset('vendor/vditor/dist/index.min.js') }}"></script>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const textarea = document.getElementById('knowledge-content-textarea');
            const editorNode = document.getElementById('knowledge-content-editor');
            const form = document.getElementById('knowledge-detail-form');

            if (!textarea || !editorNode) {
                return;
            }

            if (typeof Vditor === 'undefined') {
                textarea.classList.remove('hidden');
                textarea.required = true;
                editorNode.classList.add('hidden');
                return;
            }

            let editor = null;

            editor = new Vditor('knowledge-content-editor', {
                value: textarea.value || '',
                height: 720,
                mode: 'wysiwyg',
                cdn: @json(asset('vendor/vditor')),
                lang: @json($vditorLang),
                cache: { enable: false },
                preview: {
                    markdown: { toc: true },
                    hljs: { lineNumber: false },
                },
                toolbar: [
                    'emoji',
                    'headings',
                    'bold',
                    'italic',
                    'strike',
                    '|',
                    'line',
                    'quote',
                    'list',
                    'ordered-list',
                    'check',
                    '|',
                    'code',
                    'inline-code',
                    'table',
                    'link',
                    '|',
                    'undo',
                    'redo',
                    'fullscreen',
                    'preview',
                ],
                input(value) {
                    textarea.value = value;
                },
                after() {
                    if (editor) {
                        textarea.value = editor.getValue();
                    }

                    if (window.lucide) {
                        window.lucide.createIcons();
                    }
                },
            });

            form?.addEventListener('submit', () => {
                if (editor) {
                    textarea.value = editor.getValue();
                }
            });
        });
    </script>
@endpush
