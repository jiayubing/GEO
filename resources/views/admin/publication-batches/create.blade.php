@extends('admin.layouts.app')

@section('content')
<div class="px-4 sm:px-0">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">创建发布批次</h1>
            <p class="mt-1 text-sm leading-6 text-gray-600">选择当前项目中已审核、尚未公开的文章，生成本地目标草稿。此操作不会执行发布。</p>
        </div>
        <a href="{{ route('admin.articles.index') }}" class="inline-flex w-fit items-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">返回文章</a>
    </div>

    <form method="POST" action="{{ route('admin.publication-batches.store') }}" class="mt-6 space-y-6">
        @csrf
        <input type="hidden" name="idempotency_key" value="{{ $idempotencyKey }}">

        <section class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
            <div class="flex items-center justify-between gap-4">
                <h2 class="text-lg font-semibold text-gray-900">可选文章</h2>
                <span class="text-sm text-gray-500">项目：{{ $project->name ?? $project->slug }}</span>
            </div>

            @if($articles->isEmpty())
                <p class="mt-5 rounded-md bg-gray-50 px-4 py-3 text-sm text-gray-600">当前项目没有符合条件的文章。</p>
            @else
                <div class="mt-5 divide-y divide-gray-100 rounded-lg border border-gray-200">
                    @foreach($articles as $article)
                        <label class="flex cursor-pointer items-start gap-3 p-4 hover:bg-gray-50">
                            <input type="checkbox" name="article_ids[]" value="{{ $article->id }}" @checked(in_array((string) $article->id, array_map('strval', (array) old('article_ids', [])), true)) class="mt-1 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                            <span class="min-w-0 flex-1">
                                <span class="block font-medium text-gray-900">{{ $article->title }}</span>
                                <span class="mt-1 block text-xs text-gray-500">状态：{{ $article->status }} · 审核：{{ $article->review_status }} · 目标：本地项目站点</span>
                            </span>
                        </label>
                    @endforeach
                </div>
            @endif
        </section>

        <div class="flex items-center justify-end gap-3">
            <button type="submit" class="inline-flex items-center rounded-md bg-blue-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-blue-700">保存草稿</button>
        </div>
    </form>
</div>
@endsection
