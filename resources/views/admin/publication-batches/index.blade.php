@extends('admin.layouts.app')

@php
    $statusLabels = [
        'draft' => '草稿',
        'submitted' => '待审核',
        'approved' => '已批准',
        'returned' => '已退回',
        'rejected' => '已拒绝',
        'publishing' => '发布中',
        'completed' => '已完成',
        'partial' => '部分完成',
        'uncertain' => '结果待确认',
        'failed' => '失败',
    ];
@endphp

@section('content')
<div class="px-4 sm:px-0">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">发布批次</h1>
            <p class="mt-1 text-sm leading-6 text-gray-600">查看当前项目所有运营提交的发布任务。</p>
        </div>
        @if($canManageContentAdministration)
            <a href="{{ route('admin.publication-batches.create') }}" class="inline-flex w-fit items-center rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700">新建批次</a>
        @endif
    </div>

    <form method="GET" action="{{ route('admin.publication-batches.index') }}" class="mt-6 flex flex-col gap-3 rounded-xl border border-gray-200 bg-white p-4 shadow-sm sm:flex-row sm:items-end">
        <div>
            <label for="batch-status" class="block text-sm font-medium text-gray-700">状态</label>
            <select id="batch-status" name="status" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:w-56">
                <option value="">全部状态</option>
                @foreach($statuses as $batchStatus)
                    <option value="{{ $batchStatus->value }}" @selected($status === $batchStatus->value)>{{ $statusLabels[$batchStatus->value] ?? $batchStatus->value }}</option>
                @endforeach
            </select>
        </div>
        <button type="submit" class="inline-flex w-fit items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">筛选</button>
        @if($status !== '')
            <a href="{{ route('admin.publication-batches.index') }}" class="inline-flex w-fit items-center px-2 py-2 text-sm text-gray-500 hover:text-gray-700">清除筛选</a>
        @endif
    </form>

    <section class="mt-6 overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">批次</th>
                        <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">状态</th>
                        <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">项目数</th>
                        <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">创建人</th>
                        <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">创建时间</th>
                        <th class="px-5 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-500">操作</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse($batches as $batch)
                        @php($batchStatus = $batch->status?->value ?? (string) $batch->status)
                        <tr class="hover:bg-gray-50">
                            <td class="whitespace-nowrap px-5 py-4 text-sm font-semibold text-gray-900">#{{ $batch->id }}</td>
                            <td class="whitespace-nowrap px-5 py-4 text-sm text-gray-700">{{ $statusLabels[$batchStatus] ?? $batchStatus }}</td>
                            <td class="whitespace-nowrap px-5 py-4 text-sm text-gray-700">{{ $batch->items_count }}</td>
                            <td class="whitespace-nowrap px-5 py-4 text-sm text-gray-700">{{ $batch->creator?->name ?? '—' }}</td>
                            <td class="whitespace-nowrap px-5 py-4 text-sm text-gray-500">{{ $batch->created_at?->format('Y-m-d H:i') ?? '—' }}</td>
                            <td class="whitespace-nowrap px-5 py-4 text-right text-sm"><a href="{{ route('admin.publication-batches.show', ['batchId' => $batch->id]) }}" class="font-semibold text-blue-600 hover:text-blue-800">查看详情</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-5 py-10 text-center text-sm text-gray-500">当前项目暂无发布批次。</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($batches->hasPages())
            <div class="border-t border-gray-100 px-5 py-4">{{ $batches->links() }}</div>
        @endif
    </section>
</div>
@endsection
