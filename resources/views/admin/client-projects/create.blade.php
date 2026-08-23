@extends('admin.layouts.app')

@section('content')
    <div class="mx-auto max-w-3xl px-4 sm:px-0">
        <div class="flex flex-col gap-2">
            <h1 class="text-2xl font-bold text-gray-900">创建客户项目</h1>
            <p class="text-sm leading-6 text-gray-600">创建后你会自动成为该项目的运营人员，并切换到新项目上下文。</p>
        </div>

        @if ($errors->any())
            <div class="mt-6 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-700" role="alert">
                <ul class="list-disc space-y-1 pl-5">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('admin.client-projects.store.page') }}" class="mt-6 space-y-6">
            @csrf
            <section class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <label for="client_name" class="block text-sm font-medium text-gray-700">客户名称</label>
                        <input id="client_name" name="client_name" value="{{ old('client_name') }}" required maxlength="160" class="mt-2 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    </div>
                    <div>
                        <label for="client_slug" class="block text-sm font-medium text-gray-700">客户标识（可选）</label>
                        <input id="client_slug" name="client_slug" value="{{ old('client_slug') }}" maxlength="160" pattern="[a-z0-9]+(?:-[a-z0-9]+)*" class="mt-2 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    </div>
                    <div>
                        <label for="project_name" class="block text-sm font-medium text-gray-700">项目名称</label>
                        <input id="project_name" name="project_name" value="{{ old('project_name') }}" required maxlength="160" class="mt-2 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    </div>
                    <div class="sm:col-span-2">
                        <label for="project_slug" class="block text-sm font-medium text-gray-700">项目标识（可选）</label>
                        <input id="project_slug" name="project_slug" value="{{ old('project_slug') }}" maxlength="160" pattern="[a-z0-9]+(?:-[a-z0-9]+)*" class="mt-2 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        <p class="mt-2 text-xs text-gray-500">标识只能使用小写字母、数字和连字符；留空时由名称自动生成。</p>
                    </div>
                </div>
            </section>

            <div class="flex items-center justify-end gap-3">
                <a href="{{ route('admin.dashboard') }}" class="rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-semibold text-gray-700 hover:bg-gray-50">取消</a>
                <button type="submit" class="rounded-lg bg-blue-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-blue-700">创建并进入项目</button>
            </div>
        </form>
    </div>
@endsection
