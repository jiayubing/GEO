@extends('admin.layouts.app')

@php($status = $batch->status?->value ?? (string) $batch->status)
@php($isApprovalCenter = $isApprovalCenter ?? false)
@php($canManageContentAdministration = $canManageContentAdministration ?? false)
@section('content')
<div class="px-4 sm:px-0">
    <div class="flex items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">发布批次 #{{ $batch->id }}</h1>
            <p class="mt-1 text-sm text-gray-600">状态：{{ $status }}</p>
            @if($isApprovalCenter)
                <p class="mt-1 text-sm text-gray-600">客户：{{ $batch->clientProject?->client?->name ?? '—' }} · 项目：{{ $batch->clientProject?->name ?? '—' }} · 提交人：{{ $batch->submitter?->name ?? '—' }}</p>
            @endif
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <a href="{{ route($batchIndexRoute) }}" class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700">{{ $isApprovalCenter ? '审批中心' : '批次列表' }}</a>
            @if(! $isApprovalCenter && $canManageContentAdministration)
                <a href="{{ route('admin.publication-batches.create') }}" class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700">新建批次</a>
            @endif
        </div>
    </div>
    <div class="mt-6 flex flex-wrap gap-3">
        @if(! $isApprovalCenter && in_array($status, ['draft', 'returned'], true))
            <form method="POST" action="{{ route('admin.publication-batches.submit', ['batchId' => $batch->id]) }}">@csrf<button class="rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white">提交审核</button></form>
        @endif
        @if($status === 'submitted' && $canDecide)
            @foreach(['approve' => '批准', 'return' => '退回', 'reject' => '拒绝'] as $action => $label)
                <form method="POST" action="{{ route($batchActionRoute.'.'.$action, ['batchId' => $batch->id]) }}">@csrf<button class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700">{{ $label }}</button></form>
            @endforeach
        @endif
        @if($isApprovalCenter && $canExecuteLocal && in_array($status, ['approved', 'partial'], true) && ($approvedLocalItemCount ?? 0) > 0)
            <form method="POST" action="{{ route('admin.publication-batch-approvals.execute-local', ['batchId' => $batch->id]) }}">@csrf<button class="rounded-md bg-green-600 px-4 py-2 text-sm font-semibold text-white">一键发布本地文章（{{ $approvedLocalItemCount }} 篇）</button></form>
        @endif
    </div>
    <section class="mt-6 overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
        <div class="divide-y divide-gray-100">
            @forelse($batch->items as $item)
                <div class="flex flex-col gap-3 p-5 sm:flex-row sm:items-center sm:justify-between">
                    <div><p class="font-medium text-gray-900">文章 #{{ $item->article_id }} · {{ $item->target_type?->value }}</p><p class="text-sm text-gray-500">项目目标：{{ $item->target_identity }} · 状态：{{ $item->status?->value ?? $item->status }}</p></div>
                </div>
            @empty
                <p class="p-5 text-sm text-gray-500">暂无批次项目。</p>
            @endforelse
        </div>
    </section>
</div>
@endsection
