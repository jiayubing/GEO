@extends('admin.layouts.app')

@php($status = $batch->status?->value ?? (string) $batch->status)
@section('content')
<div class="px-4 sm:px-0">
    <div class="flex items-center justify-between gap-4">
        <div><h1 class="text-2xl font-bold text-gray-900">发布批次 #{{ $batch->id }}</h1><p class="mt-1 text-sm text-gray-600">状态：{{ $status }}</p></div>
        <div class="flex flex-wrap items-center gap-2">
            <a href="{{ route('admin.publication-batches.index') }}" class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700">批次列表</a>
            <a href="{{ route('admin.publication-batches.create') }}" class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700">新建批次</a>
        </div>
    </div>
    <div class="mt-6 flex flex-wrap gap-3">
        @if(in_array($status, ['draft', 'returned'], true))
            <form method="POST" action="{{ route('admin.publication-batches.submit', ['batchId' => $batch->id]) }}">@csrf<button class="rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white">提交审核</button></form>
        @endif
        @if($status === 'submitted')
            @foreach(['approve' => '批准', 'return' => '退回', 'reject' => '拒绝'] as $action => $label)
                <form method="POST" action="{{ route('admin.publication-batches.'.$action, ['batchId' => $batch->id]) }}">@csrf<button class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700">{{ $label }}</button></form>
            @endforeach
        @endif
    </div>
    <section class="mt-6 overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
        <div class="divide-y divide-gray-100">
            @forelse($batch->items as $item)
                <div class="flex flex-col gap-3 p-5 sm:flex-row sm:items-center sm:justify-between">
                    <div><p class="font-medium text-gray-900">文章 #{{ $item->article_id }} · {{ $item->target_type?->value }}</p><p class="text-sm text-gray-500">项目目标：{{ $item->target_identity }} · 状态：{{ $item->status?->value ?? $item->status }}</p></div>
                    @if(($item->status?->value ?? $item->status) === 'approved' && $item->target_type?->value === 'local')
                        <form method="POST" action="{{ route('admin.publication-batches.items.execute-local', ['batchId' => $batch->id, 'itemId' => $item->id]) }}">@csrf<button class="rounded-md bg-green-600 px-4 py-2 text-sm font-semibold text-white">执行本地发布</button></form>
                    @endif
                </div>
            @empty
                <p class="p-5 text-sm text-gray-500">暂无批次项目。</p>
            @endforelse
        </div>
    </section>
</div>
@endsection
