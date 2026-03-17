<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Log Viewer — {{ $config['app_name'] }}</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <style>
        [x-cloak] { display: none !important; }
        .sidebar-width { width: 260px; min-width: 260px; }
        .log-row:hover { background-color: #f8fafc; }
        .log-row { cursor: pointer; border-bottom: 1px solid #f1f5f9; }
        .badge { display: inline-flex; align-items: center; padding: 2px 7px; border-radius: 4px; font-size: 11px; font-weight: 600; letter-spacing: 0.03em; white-space: nowrap; }
        .badge-debug    { background: #f3f4f6; color: #6b7280; }
        .badge-info     { background: #dbeafe; color: #1d4ed8; }
        .badge-notice   { background: #e0f2fe; color: #0369a1; }
        .badge-warning  { background: #fef3c7; color: #b45309; }
        .badge-error    { background: #fee2e2; color: #dc2626; }
        .badge-critical { background: #fca5a5; color: #7f1d1d; }
        .badge-alert    { background: #fde8d8; color: #9a3412; }
        .badge-emergency{ background: #f3e8ff; color: #6b21a8; }
        .source-job     { border: 1px solid #9ca3af; color: #374151; background: transparent; }
        .source-request { border: 1px solid #93c5fd; color: #1d4ed8; background: transparent; }
        .source-command { border: 1px solid #c4b5fd; color: #6d28d9; background: transparent; }
        .source-system  { border: 1px solid #86efac; color: #15803d; background: transparent; }
        .flow-step-controller { background: #dbeafe; color: #1e40af; }
        .flow-step-service    { background: #d1fae5; color: #065f46; }
        .flow-step-job        { background: #f3f4f6; color: #374151; }
        .flow-step-observer   { background: #fef9c3; color: #854d0e; }
        .flow-step-middleware  { background: #ede9fe; color: #5b21b6; }
        .flow-step-model      { background: #fce7f3; color: #9d174d; }
        .flow-step-event      { background: #ffedd5; color: #9a3412; }
        .flow-step-command    { background: #cffafe; color: #0e7490; }
        .flow-step-seeder     { background: #fef3c7; color: #92400e; }
        .flow-step-migration  { background: #e0e7ff; color: #3730a3; }
        .flow-step-database   { background: #fee2e2; color: #991b1b; font-weight: 600; border: 1px solid #fecaca; }
        .flow-step-other      { background: #f1f5f9; color: #475569; }
        .chart-container { height: 120px; }
        ::-webkit-scrollbar { width: 5px; height: 5px; }
        ::-webkit-scrollbar-track { background: #f1f5f9; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 3px; }
        .font-mono-small { font-family: 'Menlo', 'Monaco', 'Courier New', monospace; font-size: 11.5px; }
    </style>
</head>
<body class="bg-gray-50 text-gray-800 antialiased h-screen overflow-hidden" x-data="logViewer()" x-init="init()">

    {{-- ================================================================ --}}
    {{-- HEADER                                                           --}}
    {{-- ================================================================ --}}
    <header class="bg-white border-b border-gray-200 h-12 flex items-center px-4 gap-4 z-20 relative shadow-sm">
        <div class="flex items-center gap-2 font-semibold text-gray-900 text-sm">
            <svg class="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                      d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414A1 1 0 0119 9.586V19a2 2 0 01-2 2z"/>
            </svg>
            <span class="text-gray-900 font-bold">Log Viewer</span>
            <span class="text-gray-400 font-normal">—</span>
            <span class="text-gray-500 font-normal">{{ $config['app_name'] }}</span>
        </div>

        <div class="ml-auto flex items-center gap-3 text-xs text-gray-500">
            <span x-show="selectedFile" x-text="selectedFile ? selectedFile.name : ''" class="font-mono-small bg-gray-100 px-2 py-1 rounded"></span>
            <span x-show="selectedFile" class="text-gray-400" x-text="selectedFile ? selectedFile.size_human : ''"></span>
            <span class="text-gray-300">|</span>
            <span>Timezone: <strong class="text-gray-700">{{ $config['app_timezone'] }}</strong></span>
        </div>
    </header>

    {{-- ================================================================ --}}
    {{-- MAIN LAYOUT                                                      --}}
    {{-- ================================================================ --}}
    <div class="flex" style="height: calc(100vh - 48px);">

        {{-- ============================================================ --}}
        {{-- SIDEBAR                                                      --}}
        {{-- ============================================================ --}}
        <aside class="sidebar-width bg-white border-r border-gray-200 flex flex-col overflow-hidden shrink-0">
            <div class="px-3 py-2 border-b border-gray-100">
                <div class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2 flex items-center justify-between">
                    <span>Log Files</span>
                    <button @click="loadFiles()" class="text-gray-400 hover:text-blue-500 transition" title="Refresh">
                        <svg class="w-3.5 h-3.5" :class="loadingFiles ? 'animate-spin' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                  d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                        </svg>
                    </button>
                </div>
                <input type="text" x-model="fileSearch" placeholder="Search files…"
                       class="w-full text-xs border border-gray-200 rounded px-2 py-1.5 focus:outline-none focus:ring-1 focus:ring-blue-400 bg-gray-50"/>
            </div>

            <div class="flex-1 overflow-y-auto">
                <template x-if="loadingFiles">
                    <div class="flex items-center justify-center py-8 text-gray-400 text-xs">Loading…</div>
                </template>

                <template x-if="!loadingFiles && filteredFiles.length === 0">
                    <div class="flex flex-col items-center justify-center py-10 text-gray-400 text-xs gap-1">
                        <svg class="w-8 h-8 opacity-40" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                                  d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"/>
                        </svg>
                        <span>No log files found</span>
                    </div>
                </template>

                <template x-for="file in filteredFiles" :key="file.path">
                    <div class="group px-3 py-2.5 cursor-pointer border-b border-gray-50 hover:bg-blue-50 transition"
                         :class="selectedFile && selectedFile.path === file.path ? 'bg-blue-50 border-l-2 border-l-blue-500' : ''"
                         @click="selectFile(file)">
                        <div class="flex items-start justify-between gap-1">
                            <div class="flex-1 min-w-0">
                                <div class="text-xs font-medium text-gray-800 truncate" x-text="file.name"></div>
                                <div class="flex items-center gap-2 mt-0.5">
                                    <span class="text-xs text-gray-400" x-text="file.size_human"></span>
                                    <span class="text-gray-200">·</span>
                                    <span class="text-xs text-gray-400" x-text="file.modified_human"></span>
                                </div>
                            </div>
                            <div class="flex items-center gap-1 opacity-0 group-hover:opacity-100 transition shrink-0">
                                <a :href="baseUrl + '/api/download?file=' + encodeURIComponent(file.path)"
                                   @click.stop
                                   class="p-1 text-gray-400 hover:text-blue-500 transition" title="Download">
                                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                              d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                                    </svg>
                                </a>
                                <button @click.stop="confirmDelete(file)"
                                        class="p-1 text-gray-400 hover:text-red-500 transition" title="Delete">
                                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                              d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                    </svg>
                                </button>
                            </div>
                        </div>
                    </div>
                </template>
            </div>
        </aside>

        {{-- ============================================================ --}}
        {{-- MAIN CONTENT                                                 --}}
        {{-- ============================================================ --}}
        <main class="flex-1 flex flex-col overflow-hidden bg-white">

            {{-- -------------------------------------------------------- --}}
            {{-- FILTERS BAR                                              --}}
            {{-- -------------------------------------------------------- --}}
            <div class="border-b border-gray-200 px-4 py-2.5 flex flex-wrap items-center gap-2 shrink-0 bg-white">
                {{-- Search --}}
                <div class="relative flex-1 min-w-[180px] max-w-xs">
                    <svg class="absolute left-2.5 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0"/>
                    </svg>
                    <input type="text" x-model="filters.search" @input.debounce.400ms="applyFilters()"
                           placeholder="Search messages…"
                           class="w-full pl-8 pr-3 py-1.5 text-xs border border-gray-200 rounded-md focus:outline-none focus:ring-1 focus:ring-blue-400 bg-white"/>
                </div>

                {{-- Level --}}
                <select x-model="filters.level" @change="applyFilters()"
                        class="text-xs border border-gray-200 rounded-md px-2 py-1.5 bg-white focus:outline-none focus:ring-1 focus:ring-blue-400 text-gray-700">
                    <option value="">Level All</option>
                    <option value="DEBUG">DEBUG</option>
                    <option value="INFO">INFO</option>
                    <option value="NOTICE">NOTICE</option>
                    <option value="WARNING">WARNING</option>
                    <option value="ERROR">ERROR</option>
                    <option value="CRITICAL">CRITICAL</option>
                    <option value="ALERT">ALERT</option>
                    <option value="EMERGENCY">EMERGENCY</option>
                </select>

                {{-- Type --}}
                <select x-model="filters.source_type" @change="applyFilters()"
                        class="text-xs border border-gray-200 rounded-md px-2 py-1.5 bg-white focus:outline-none focus:ring-1 focus:ring-blue-400 text-gray-700">
                    <option value="">Type All</option>
                    <option value="JOB">JOB</option>
                    <option value="REQUEST">REQUEST</option>
                    <option value="COMMAND">COMMAND</option>
                    <option value="SYSTEM">SYSTEM</option>
                </select>

                {{-- Environment --}}
                <input type="text" x-model="filters.environment" @input.debounce.400ms="applyFilters()"
                       placeholder="Environment…"
                       class="text-xs border border-gray-200 rounded-md px-2 py-1.5 w-28 bg-white focus:outline-none focus:ring-1 focus:ring-blue-400 text-gray-700"/>

                {{-- Time Range --}}
                <select x-model="filters.time_range" @change="applyTimeRange()"
                        class="text-xs border border-gray-200 rounded-md px-2 py-1.5 bg-white focus:outline-none focus:ring-1 focus:ring-blue-400 text-gray-700">
                    <option value="">All time</option>
                    <option value="1h">Last hour</option>
                    <option value="24h">Last 24h</option>
                    <option value="7d">Last 7 days</option>
                    <option value="custom">Custom…</option>
                </select>

                {{-- Custom date range --}}
                <template x-if="filters.time_range === 'custom'">
                    <div class="flex items-center gap-1">
                        <input type="datetime-local" x-model="filters.date_from" @change="applyFilters()"
                               class="text-xs border border-gray-200 rounded px-2 py-1 bg-white focus:outline-none focus:ring-1 focus:ring-blue-400"/>
                        <span class="text-gray-400 text-xs">to</span>
                        <input type="datetime-local" x-model="filters.date_to" @change="applyFilters()"
                               class="text-xs border border-gray-200 rounded px-2 py-1 bg-white focus:outline-none focus:ring-1 focus:ring-blue-400"/>
                    </div>
                </template>

                <button @click="clearFilters()"
                        class="ml-auto text-xs text-gray-400 hover:text-gray-700 transition px-2 py-1.5 rounded hover:bg-gray-100">
                    Clear
                </button>
            </div>

            {{-- -------------------------------------------------------- --}}
            {{-- CHART                                                    --}}
            {{-- -------------------------------------------------------- --}}
            <div class="border-b border-gray-100 px-4 pt-3 pb-2 shrink-0 bg-white" style="height: 150px;">
                <div class="relative h-full">
                    <template x-if="!selectedFile">
                        <div class="flex items-center justify-center h-full text-gray-400 text-xs">
                            Select a log file to view activity
                        </div>
                    </template>
                    <template x-if="selectedFile && loadingChart">
                        <div class="flex items-center justify-center h-full text-gray-400 text-xs">Loading chart…</div>
                    </template>
                    <canvas id="activityChart" style="display: block;" x-show="selectedFile && !loadingChart"></canvas>
                </div>
            </div>

            {{-- -------------------------------------------------------- --}}
            {{-- TABLE                                                    --}}
            {{-- -------------------------------------------------------- --}}
            <div class="flex-1 overflow-auto">
                <table class="w-full text-xs" style="border-collapse: collapse;">
                    <thead class="sticky top-0 bg-gray-50 z-10">
                        <tr class="border-b border-gray-200">
                            <th class="px-4 py-2.5 text-left font-semibold text-gray-500 whitespace-nowrap w-52">
                                <span class="inline-flex items-center gap-1.5">
                                    Timestamp
                                    <span class="badge badge-info" style="font-size:9px; padding: 1px 4px;">UTC</span>
                                    <span class="text-gray-400 font-normal">LOCAL</span>
                                </span>
                            </th>
                            <th class="px-3 py-2.5 text-left font-semibold text-gray-500 w-52">Entrypoint</th>
                            <th class="px-3 py-2.5 text-left font-semibold text-gray-500 w-24">Level</th>
                            <th class="px-3 py-2.5 text-left font-semibold text-gray-500">Message</th>
                            <th class="px-4 py-2.5 text-right font-semibold text-gray-500 w-28">
                                <span x-show="total > 0" x-text="total.toLocaleString() + ' rows'"></span>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        {{-- Empty / loading states --}}
                        <template x-if="loading">
                            <tr>
                                <td colspan="5" class="py-16 text-center text-gray-400">
                                    <div class="flex flex-col items-center gap-2">
                                        <svg class="w-5 h-5 animate-spin" fill="none" viewBox="0 0 24 24">
                                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/>
                                        </svg>
                                        <span class="text-xs">Loading entries…</span>
                                    </div>
                                </td>
                            </tr>
                        </template>

                        <template x-if="!loading && !selectedFile">
                            <tr>
                                <td colspan="5" class="py-16 text-center text-gray-400 text-xs">
                                    <div class="flex flex-col items-center gap-2">
                                        <svg class="w-10 h-10 opacity-30" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                                                  d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414A1 1 0 0119 9.586V19a2 2 0 01-2 2z"/>
                                        </svg>
                                        Select a log file from the sidebar
                                    </div>
                                </td>
                            </tr>
                        </template>

                        <template x-if="!loading && selectedFile && entries.length === 0">
                            <tr>
                                <td colspan="5" class="py-16 text-center text-gray-400 text-xs">
                                    No log entries match your filters.
                                </td>
                            </tr>
                        </template>

                        <template x-for="entry in entries" :key="entry.id">
                            <tr class="log-row" @click="openEntry(entry)">
                                {{-- Timestamp --}}
                                <td class="px-4 py-2 align-top w-52">
                                    <div class="flex items-center gap-1">
                                        <span class="font-mono-small text-gray-700" x-text="formatTimestamp(entry.timestamp)"></span>
                                        <span class="badge badge-info" style="font-size:9px; padding: 1px 4px;">UTC</span>
                                    </div>
                                    <div class="font-mono-small text-gray-400 mt-0.5" x-text="entry.timestamp_local"></div>
                                </td>

                                {{-- Entrypoint --}}
                                <td class="px-3 py-2 align-top w-52">
                                    <div class="flex items-center gap-1.5 flex-wrap">
                                        <span class="badge"
                                              :class="sourceClass(entry.source_type)"
                                              x-text="entry.source_type"></span>
                                        <span class="text-gray-600 truncate max-w-[140px]" x-text="entry.entrypoint" :title="entry.entrypoint"></span>
                                    </div>
                                </td>

                                {{-- Level --}}
                                <td class="px-3 py-2 align-top w-24">
                                    <span class="badge" :class="levelClass(entry.level)" x-text="entry.level"></span>
                                </td>

                                {{-- Message --}}
                                <td class="px-3 py-2 align-top">
                                    <span class="text-gray-700 line-clamp-2 leading-snug" x-text="entry.message"></span>
                                </td>

                                {{-- Env --}}
                                <td class="px-4 py-2 align-top text-right">
                                    <span class="text-gray-400 text-xs" x-text="entry.environment"></span>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>

            {{-- -------------------------------------------------------- --}}
            {{-- PAGINATION                                               --}}
            {{-- -------------------------------------------------------- --}}
            <div class="border-t border-gray-200 px-4 py-2 flex items-center justify-between bg-white shrink-0" x-show="lastPage > 1 || total > 0">
                <div class="text-xs text-gray-500">
                    Page <strong x-text="currentPage"></strong> of <strong x-text="lastPage"></strong>
                    <span class="ml-2 text-gray-400" x-text="'(' + total.toLocaleString() + ' total)'"></span>
                </div>

                <div class="flex items-center gap-1">
                    <button @click="goToPage(1)" :disabled="currentPage === 1"
                            class="px-2 py-1 text-xs rounded border border-gray-200 hover:bg-gray-50 disabled:opacity-40 disabled:cursor-not-allowed">
                        «
                    </button>
                    <button @click="goToPage(currentPage - 1)" :disabled="currentPage === 1"
                            class="px-2 py-1 text-xs rounded border border-gray-200 hover:bg-gray-50 disabled:opacity-40 disabled:cursor-not-allowed">
                        ‹ Prev
                    </button>

                    <template x-for="p in paginationPages" :key="p">
                        <button @click="p !== '…' && goToPage(p)"
                                :class="p === currentPage ? 'bg-blue-600 text-white border-blue-600' : 'border-gray-200 hover:bg-gray-50'"
                                :disabled="p === '…'"
                                class="px-2.5 py-1 text-xs rounded border min-w-[30px]"
                                x-text="p"></button>
                    </template>

                    <button @click="goToPage(currentPage + 1)" :disabled="currentPage === lastPage"
                            class="px-2 py-1 text-xs rounded border border-gray-200 hover:bg-gray-50 disabled:opacity-40 disabled:cursor-not-allowed">
                        Next ›
                    </button>
                    <button @click="goToPage(lastPage)" :disabled="currentPage === lastPage"
                            class="px-2 py-1 text-xs rounded border border-gray-200 hover:bg-gray-50 disabled:opacity-40 disabled:cursor-not-allowed">
                        »
                    </button>
                </div>
            </div>
        </main>
    </div>

    {{-- ================================================================ --}}
    {{-- LOG DETAIL MODAL                                                 --}}
    {{-- ================================================================ --}}
    <div x-cloak x-show="showModal"
         class="fixed inset-0 z-50 flex items-start justify-center pt-10 px-4 pb-4"
         @keydown.escape.window="closeModal()">

        {{-- Backdrop --}}
        <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" @click="closeModal()"></div>

        {{-- Panel --}}
        <div class="relative bg-white rounded-xl shadow-2xl w-full max-w-4xl max-h-[85vh] flex flex-col overflow-hidden z-10"
             x-show="showModal"
             x-transition:enter="transition ease-out duration-200"
             x-transition:enter-start="opacity-0 scale-95"
             x-transition:enter-end="opacity-100 scale-100"
             x-transition:leave="transition ease-in duration-150"
             x-transition:leave-start="opacity-100 scale-100"
             x-transition:leave-end="opacity-0 scale-95">

            {{-- Modal header --}}
            <div class="flex items-center justify-between px-5 py-3.5 border-b border-gray-100 shrink-0">
                <div class="flex items-center gap-3">
                    <template x-if="selectedEntry">
                        <span class="badge text-sm" :class="levelClass(selectedEntry.level)" x-text="selectedEntry.level"></span>
                    </template>
                    <template x-if="selectedEntry">
                        <span class="badge" :class="sourceClass(selectedEntry.source_type)" x-text="selectedEntry.source_type"></span>
                    </template>
                    <span class="text-xs text-gray-400 font-mono-small" x-text="selectedEntry ? formatTimestamp(selectedEntry.timestamp) + ' UTC' : ''"></span>
                </div>
                <button @click="closeModal()" class="text-gray-400 hover:text-gray-700 transition p-1 rounded hover:bg-gray-100">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>

            {{-- Modal body --}}
            <div class="flex-1 overflow-y-auto px-5 py-4 space-y-5">
                <template x-if="loadingEntry">
                    <div class="flex items-center justify-center py-12 text-gray-400 text-sm">Loading…</div>
                </template>

                <template x-if="!loadingEntry && selectedEntry">
                    <div class="space-y-5">

                        {{-- Message --}}
                        <div>
                            <h3 class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-2">Message</h3>
                            <div class="bg-gray-50 rounded-lg px-4 py-3 text-sm text-gray-800 leading-relaxed whitespace-pre-wrap font-mono-small break-words"
                                 x-text="selectedEntry.message"></div>
                        </div>

                        {{-- Meta row --}}
                        <div class="flex flex-wrap gap-3 text-xs">
                            <div class="flex items-center gap-1.5 bg-gray-50 rounded px-3 py-1.5">
                                <span class="text-gray-400">Environment:</span>
                                <span class="font-semibold text-gray-700" x-text="selectedEntry.environment"></span>
                            </div>
                            <div class="flex items-center gap-1.5 bg-gray-50 rounded px-3 py-1.5">
                                <span class="text-gray-400">Entrypoint:</span>
                                <span class="font-semibold text-gray-700" x-text="selectedEntry.entrypoint"></span>
                            </div>
                            <div class="flex items-center gap-1.5 bg-gray-50 rounded px-3 py-1.5">
                                <span class="text-gray-400">UTC:</span>
                                <span class="font-mono-small text-gray-700" x-text="selectedEntry.timestamp"></span>
                            </div>
                            <div class="flex items-center gap-1.5 bg-gray-50 rounded px-3 py-1.5">
                                <span class="text-gray-400">Local:</span>
                                <span class="font-mono-small text-gray-700" x-text="selectedEntry.timestamp_local"></span>
                            </div>
                        </div>

                        {{-- Context --}}
                        <div x-show="selectedEntry.context && Object.keys(selectedEntry.context).length > 0">
                            <div class="flex items-center justify-between cursor-pointer mb-2"
                                 @click="showContext = !showContext">
                                <h3 class="text-xs font-semibold text-gray-400 uppercase tracking-wider">Context</h3>
                                <svg class="w-4 h-4 text-gray-400 transition-transform" :class="showContext ? 'rotate-180' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                                </svg>
                            </div>
                            <div x-show="showContext" x-collapse>
                                <pre class="bg-gray-900 text-green-400 rounded-lg px-4 py-3 text-xs overflow-x-auto leading-relaxed font-mono-small"
                                     x-text="JSON.stringify(selectedEntry.context, null, 2)"></pre>
                            </div>
                        </div>

                        {{-- SQL Info Banner (shown for DB exceptions) --}}
                        <template x-if="selectedEntry.sql_info && selectedEntry.sql_info.sqlstate">
                            <div class="rounded-lg border border-red-200 bg-red-50 p-4 space-y-3">
                                <div class="flex items-center gap-2 flex-wrap">
                                    <svg class="w-5 h-5 text-red-500 shrink-0" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                                        <ellipse cx="12" cy="6" rx="8" ry="3"/>
                                        <path d="M4 6v6c0 1.657 3.582 3 8 3s8-1.343 8-3V6"/>
                                        <path d="M4 12v6c0 1.657 3.582 3 8 3s8-1.343 8-3v-6"/>
                                    </svg>
                                    <span class="text-sm font-semibold text-red-700" x-text="selectedEntry.sql_info.exception_type || 'Database Error'"></span>
                                    <span class="text-xs font-mono bg-red-100 text-red-800 px-1.5 py-0.5 rounded" x-text="'SQLSTATE[' + selectedEntry.sql_info.sqlstate + ']'"></span>
                                </div>
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-2 text-xs">
                                    <template x-if="selectedEntry.sql_info.table">
                                        <div class="flex gap-2">
                                            <span class="text-gray-500 shrink-0 font-medium">Table:</span>
                                            <code class="font-mono text-gray-800" x-text="'`' + selectedEntry.sql_info.table + '`'"></code>
                                        </div>
                                    </template>
                                    <template x-if="selectedEntry.sql_info.model">
                                        <div class="flex gap-2">
                                            <span class="text-gray-500 shrink-0 font-medium">Model:</span>
                                            <code class="font-mono text-pink-700 font-semibold" x-text="selectedEntry.sql_info.model"></code>
                                        </div>
                                    </template>
                                    <template x-if="selectedEntry.sql_info.column">
                                        <div class="flex gap-2">
                                            <span class="text-red-600 shrink-0 font-semibold">Column:</span>
                                            <code class="font-mono text-red-700 font-semibold" x-text="'`' + selectedEntry.sql_info.column + '`'"></code>
                                        </div>
                                    </template>
                                    <template x-if="selectedEntry.sql_info.operation">
                                        <div class="flex gap-2">
                                            <span class="text-gray-500 shrink-0 font-medium">Operation:</span>
                                            <code class="font-mono text-gray-800" x-text="selectedEntry.sql_info.operation"></code>
                                        </div>
                                    </template>
                                </div>
                                <template x-if="selectedEntry.sql_info.sql">
                                    <div>
                                        <div class="text-xs text-gray-500 font-medium mb-1">SQL Query:</div>
                                        <pre class="text-xs bg-white border border-red-100 rounded-md p-3 overflow-x-auto text-gray-700 whitespace-pre-wrap break-words leading-relaxed" x-text="selectedEntry.sql_info.sql"></pre>
                                    </div>
                                </template>
                            </div>
                        </template>

                        {{-- Call Flow --}}
                        <div x-show="selectedEntry.callFlow && selectedEntry.callFlow.length > 0">
                            <h3 class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-3">Call Flow</h3>
                            <div class="flex flex-wrap items-start gap-2">
                                <template x-for="(step, idx) in selectedEntry.callFlow" :key="step.order">
                                    <div class="flex items-center gap-2 shrink-0">
                                        <div class="flex flex-col items-center">
                                            <span class="badge text-xs px-2.5 py-1 rounded-md text-center whitespace-nowrap"
                                                  :class="'flow-step-' + step.type"
                                                  x-text="step.label"></span>
                                            <span class="text-gray-400 mt-0.5 text-center whitespace-nowrap" style="font-size:10px;"
                                                  :title="step.file ? step.file + ':' + step.line : ''"
                                                  x-text="step.file ? (step.file.split(/[\\/]/).pop() + ':' + step.line) : (step.sql_info && step.sql_info.model ? step.sql_info.model : '')"></span>
                                        </div>
                                        <template x-if="idx < selectedEntry.callFlow.length - 1">
                                            <svg class="w-4 h-4 text-gray-300 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                                            </svg>
                                        </template>
                                    </div>
                                </template>
                            </div>
                        </div>

                        {{-- Stack Trace --}}
                        <div x-show="selectedEntry.stack_trace && selectedEntry.stack_trace.length > 0">
                            <div class="flex items-center justify-between cursor-pointer mb-2"
                                 @click="showStackTrace = !showStackTrace">
                                <h3 class="text-xs font-semibold text-gray-400 uppercase tracking-wider flex items-center gap-2">
                                    Stack Trace
                                    <span class="text-gray-300 font-normal normal-case" x-text="'(' + (selectedEntry.stack_trace ? selectedEntry.stack_trace.length : 0) + ' frames)'"></span>
                                </h3>
                                <svg class="w-4 h-4 text-gray-400 transition-transform" :class="showStackTrace ? 'rotate-180' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                                </svg>
                            </div>
                            <div x-show="showStackTrace" x-collapse>
                                <div class="bg-gray-900 rounded-lg overflow-hidden">
                                    <template x-for="(frame, idx) in selectedEntry.stack_trace" :key="idx">
                                        <div class="px-4 py-2 border-b border-gray-700 last:border-0 hover:bg-gray-800 transition">
                                            <div class="flex items-start gap-3">
                                                <span class="text-gray-500 font-mono-small text-xs shrink-0 mt-0.5"
                                                      x-text="'#' + frame.frame"></span>
                                                <div class="flex-1 min-w-0">
                                                    <div class="text-green-400 font-mono-small text-xs"
                                                         x-text="(frame.class ? frame.class + '->' : '') + frame.function + '()'"></div>
                                                    <div class="text-gray-500 font-mono-small text-xs mt-0.5 truncate"
                                                         x-text="frame.file + (frame.line ? ':' + frame.line : '')"></div>
                                                </div>
                                            </div>
                                        </div>
                                    </template>
                                </div>
                            </div>
                        </div>

                        {{-- Raw Line --}}
                        <div>
                            <div class="flex items-center justify-between cursor-pointer mb-2"
                                 @click="showRaw = !showRaw">
                                <h3 class="text-xs font-semibold text-gray-400 uppercase tracking-wider">Raw Line</h3>
                                <svg class="w-4 h-4 text-gray-400 transition-transform" :class="showRaw ? 'rotate-180' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                                </svg>
                            </div>
                            <div x-show="showRaw" x-collapse>
                                <pre class="bg-gray-900 text-gray-400 rounded-lg px-4 py-3 text-xs overflow-x-auto font-mono-small whitespace-pre-wrap break-all"
                                     x-text="selectedEntry.raw_line"></pre>
                            </div>
                        </div>
                    </div>
                </template>
            </div>
        </div>
    </div>

    {{-- ================================================================ --}}
    {{-- DELETE CONFIRM DIALOG                                           --}}
    {{-- ================================================================ --}}
    <div x-cloak x-show="showDeleteConfirm" class="fixed inset-0 z-50 flex items-center justify-center px-4">
        <div class="absolute inset-0 bg-black/40" @click="showDeleteConfirm = false"></div>
        <div class="relative bg-white rounded-xl shadow-xl w-full max-w-sm p-6 z-10">
            <h3 class="text-base font-semibold text-gray-900 mb-2">Delete Log File?</h3>
            <p class="text-sm text-gray-600 mb-4">
                Are you sure you want to delete <strong x-text="fileToDelete ? fileToDelete.name : ''"></strong>?
                This action cannot be undone.
            </p>
            <div class="flex items-center justify-end gap-3">
                <button @click="showDeleteConfirm = false"
                        class="px-4 py-2 text-sm rounded-lg border border-gray-200 hover:bg-gray-50 transition">
                    Cancel
                </button>
                <button @click="deleteFile()"
                        class="px-4 py-2 text-sm rounded-lg bg-red-600 text-white hover:bg-red-700 transition">
                    Delete
                </button>
            </div>
        </div>
    </div>

    {{-- ================================================================ --}}
    {{-- ERROR TOAST                                                      --}}
    {{-- ================================================================ --}}
    <div x-cloak x-show="errorMsg"
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0 translate-y-2"
         x-transition:enter-end="opacity-100 translate-y-0"
         class="fixed bottom-4 right-4 z-50 bg-red-600 text-white px-4 py-3 rounded-lg shadow-lg text-sm max-w-sm">
        <div class="flex items-center gap-2">
            <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                      d="M12 9v2m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            <span x-text="errorMsg"></span>
        </div>
    </div>

    {{-- ================================================================ --}}
    {{-- ALPINE.JS COMPONENT                                              --}}
    {{-- ================================================================ --}}
    <script>
    function logViewer() {
        return {
            // ---- API base ----
            baseUrl: '{{ rtrim(url(config("log-viewer.route_prefix", "log-viewer")), "/") }}',

            // ---- State ----
            files: [],
            loadingFiles: false,
            fileSearch: '',

            selectedFile: null,
            entries: [],
            total: 0,
            currentPage: 1,
            lastPage: 1,
            loading: false,

            // ---- Filters ----
            filters: {
                search: '',
                level: '',
                source_type: '',
                environment: '',
                time_range: '',
                date_from: '',
                date_to: '',
            },

            // ---- Chart ----
            loadingChart: false,
            chartInstance: null,

            // ---- Modal ----
            selectedEntry: null,
            showModal: false,
            loadingEntry: false,
            showContext: true,
            showStackTrace: true,
            showRaw: false,

            // ---- Delete ----
            showDeleteConfirm: false,
            fileToDelete: null,

            // ---- Error ----
            errorMsg: '',
            errorTimer: null,

            // ================================================================
            // INIT
            // ================================================================
            init() {
                this.loadFiles();
            },

            // ================================================================
            // FILES
            // ================================================================
            async loadFiles() {
                this.loadingFiles = true;
                try {
                    const res  = await fetch(this.baseUrl + '/api/files');
                    const json = await res.json();
                    if (json.success) {
                        this.files = json.data;
                        if (this.files.length > 0 && !this.selectedFile) {
                            this.selectFile(this.files[0]);
                        }
                    } else {
                        this.showError(json.message || 'Failed to load files');
                    }
                } catch (e) {
                    this.showError('Network error: ' + e.message);
                } finally {
                    this.loadingFiles = false;
                }
            },

            selectFile(file) {
                this.selectedFile = file;
                this.currentPage  = 1;
                this.entries      = [];
                this.total        = 0;
                this.loadEntries();
                this.loadChart();
            },

            get filteredFiles() {
                if (!this.fileSearch.trim()) return this.files;
                const q = this.fileSearch.toLowerCase();
                return this.files.filter(f => f.name.toLowerCase().includes(q));
            },

            // ================================================================
            // ENTRIES
            // ================================================================
            async loadEntries() {
                if (!this.selectedFile) return;

                this.loading = true;
                try {
                    const params = new URLSearchParams({
                        file:     this.selectedFile.path,
                        page:     this.currentPage,
                        per_page: {{ $config['per_page'] }},
                        ...this.activeFilters(),
                    });

                    const res  = await fetch(this.baseUrl + '/api/entries?' + params.toString());
                    const json = await res.json();

                    if (json.success) {
                        this.entries     = json.data;
                        this.total       = json.meta.total;
                        this.currentPage = json.meta.current_page;
                        this.lastPage    = json.meta.last_page;
                    } else {
                        this.showError(json.message || 'Failed to load entries');
                    }
                } catch (e) {
                    this.showError('Network error: ' + e.message);
                } finally {
                    this.loading = false;
                }
            },

            activeFilters() {
                const f = {};
                if (this.filters.search)      f.search      = this.filters.search;
                if (this.filters.level)       f.level       = this.filters.level;
                if (this.filters.source_type) f.source_type = this.filters.source_type;
                if (this.filters.environment) f.environment = this.filters.environment;
                if (this.filters.date_from)   f.date_from   = this.filters.date_from;
                if (this.filters.date_to)     f.date_to     = this.filters.date_to;
                return f;
            },

            applyFilters() {
                this.currentPage = 1;
                this.loadEntries();
            },

            applyTimeRange() {
                const now  = new Date();
                const fmt  = d => d.toISOString().slice(0, 16);

                if (this.filters.time_range === '1h') {
                    this.filters.date_from = fmt(new Date(now - 3600000));
                    this.filters.date_to   = fmt(now);
                } else if (this.filters.time_range === '24h') {
                    this.filters.date_from = fmt(new Date(now - 86400000));
                    this.filters.date_to   = fmt(now);
                } else if (this.filters.time_range === '7d') {
                    this.filters.date_from = fmt(new Date(now - 604800000));
                    this.filters.date_to   = fmt(now);
                } else if (this.filters.time_range === '' || this.filters.time_range === 'custom') {
                    if (this.filters.time_range !== 'custom') {
                        this.filters.date_from = '';
                        this.filters.date_to   = '';
                    }
                }

                if (this.filters.time_range !== 'custom') {
                    this.applyFilters();
                }
            },

            clearFilters() {
                this.filters = {
                    search: '', level: '', source_type: '',
                    environment: '', time_range: '',
                    date_from: '', date_to: '',
                };
                this.applyFilters();
            },

            // ================================================================
            // PAGINATION
            // ================================================================
            goToPage(page) {
                if (page < 1 || page > this.lastPage || page === this.currentPage) return;
                this.currentPage = page;
                this.loadEntries();
            },

            get paginationPages() {
                const pages = [];
                const cur   = this.currentPage;
                const last  = this.lastPage;

                if (last <= 7) {
                    for (let i = 1; i <= last; i++) pages.push(i);
                    return pages;
                }

                pages.push(1);
                if (cur > 3)  pages.push('…');

                for (let i = Math.max(2, cur - 1); i <= Math.min(last - 1, cur + 1); i++) {
                    pages.push(i);
                }

                if (cur < last - 2) pages.push('…');
                pages.push(last);

                return pages;
            },

            // ================================================================
            // CHART
            // ================================================================
            async loadChart() {
                if (!this.selectedFile) return;
                this.loadingChart = true;

                try {
                    const params = new URLSearchParams({ file: this.selectedFile.path });
                    const res    = await fetch(this.baseUrl + '/api/chart?' + params.toString());
                    const json   = await res.json();

                    if (json.success) {
                        this.$nextTick(() => this.renderChart(json.data));
                    }
                } catch (e) {
                    this.showError('Chart error: ' + e.message);
                } finally {
                    this.loadingChart = false;
                }
            },

            renderChart(data) {
                const canvas = document.getElementById('activityChart');
                if (!canvas) return;

                if (this.chartInstance) {
                    this.chartInstance.destroy();
                    this.chartInstance = null;
                }

                if (!data || !data.labels || data.labels.length === 0) return;

                this.chartInstance = new Chart(canvas, {
                    type: 'line',
                    data: {
                        labels:   data.labels,
                        datasets: data.datasets.map(ds => ({
                            ...ds,
                            borderWidth:  1.5,
                            pointRadius:  ds.pointRadius ?? 2,
                            tension:      ds.tension     ?? 0.3,
                            fill:         false,
                        })),
                    },
                    options: {
                        responsive:          true,
                        maintainAspectRatio: false,
                        animation:           { duration: 300 },
                        interaction:         { mode: 'index', intersect: false },
                        plugins: {
                            legend: {
                                display:  true,
                                position: 'top',
                                labels:   {
                                    boxWidth:  10,
                                    font:      { size: 10 },
                                    padding:   10,
                                },
                            },
                            tooltip: {
                                bodyFont:  { size: 11 },
                                titleFont: { size: 11 },
                            },
                        },
                        scales: {
                            x: {
                                ticks: {
                                    font:       { size: 10 },
                                    color:      '#9ca3af',
                                    maxTicksLimit: 20,
                                },
                                grid: { color: '#f1f5f9' },
                            },
                            y: {
                                beginAtZero: true,
                                ticks: {
                                    font:       { size: 10 },
                                    color:      '#9ca3af',
                                    stepSize:   1,
                                    precision:  0,
                                },
                                grid: { color: '#f1f5f9' },
                            },
                        },
                    },
                });
            },

            // ================================================================
            // ENTRY DETAIL
            // ================================================================
            async openEntry(entry) {
                this.selectedEntry  = { ...entry, callFlow: [] };
                this.showModal      = true;
                this.loadingEntry   = true;
                this.showContext    = true;
                this.showStackTrace = true;
                this.showRaw        = false;

                try {
                    const params = new URLSearchParams({ file: this.selectedFile ? this.selectedFile.path : '' });
                    const res    = await fetch(`${this.baseUrl}/api/entries/${entry.id}?${params.toString()}`);
                    const json   = await res.json();

                    if (json.success) {
                        this.selectedEntry = json.data;
                    } else {
                        this.showError(json.message || 'Failed to load entry');
                    }
                } catch (e) {
                    this.showError('Network error: ' + e.message);
                } finally {
                    this.loadingEntry = false;
                }
            },

            closeModal() {
                this.showModal     = false;
                this.selectedEntry = null;
            },

            // ================================================================
            // DELETE
            // ================================================================
            confirmDelete(file) {
                this.fileToDelete      = file;
                this.showDeleteConfirm = true;
            },

            async deleteFile() {
                if (!this.fileToDelete) return;
                this.showDeleteConfirm = false;

                try {
                    const res  = await fetch(this.baseUrl + '/api/file', {
                        method:  'DELETE',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                        },
                        body: JSON.stringify({ file: this.fileToDelete.path }),
                    });
                    const json = await res.json();

                    if (json.success) {
                        if (this.selectedFile && this.selectedFile.path === this.fileToDelete.path) {
                            this.selectedFile = null;
                            this.entries      = [];
                            this.total        = 0;
                        }
                        this.fileToDelete = null;
                        await this.loadFiles();
                    } else {
                        this.showError(json.message || 'Failed to delete file');
                    }
                } catch (e) {
                    this.showError('Network error: ' + e.message);
                }
            },

            // ================================================================
            // HELPERS
            // ================================================================
            levelClass(level) {
                const map = {
                    DEBUG:     'badge-debug',
                    INFO:      'badge-info',
                    NOTICE:    'badge-notice',
                    WARNING:   'badge-warning',
                    ERROR:     'badge-error',
                    CRITICAL:  'badge-critical',
                    ALERT:     'badge-alert',
                    EMERGENCY: 'badge-emergency',
                };
                return map[(level || '').toUpperCase()] || 'badge-debug';
            },

            sourceClass(type) {
                const map = {
                    JOB:     'source-job',
                    REQUEST: 'source-request',
                    COMMAND: 'source-command',
                    SYSTEM:  'source-system',
                };
                return map[(type || '').toUpperCase()] || 'source-system';
            },

            formatTimestamp(ts) {
                if (!ts) return '';
                // Show as "YYYY-MM-DD HH:MM:SS.mmm" without timezone info
                const s = ts.replace('T', ' ').replace(/\+.*$/, '').replace('Z', '');
                return s;
            },

            formatFileSize(bytes) {
                if (bytes >= 1073741824) return (bytes / 1073741824).toFixed(2) + ' GB';
                if (bytes >= 1048576)    return (bytes / 1048576).toFixed(2)    + ' MB';
                if (bytes >= 1024)       return (bytes / 1024).toFixed(2)       + ' KB';
                return bytes + ' B';
            },

            formatRelativeTime(dateStr) {
                const diff = Math.floor((Date.now() - new Date(dateStr).getTime()) / 1000);
                if (diff < 60)    return 'just now';
                if (diff < 3600)  return Math.floor(diff / 60)   + 'm ago';
                if (diff < 86400) return Math.floor(diff / 3600)  + 'h ago';
                return Math.floor(diff / 86400) + 'd ago';
            },

            showError(msg) {
                this.errorMsg = msg;
                if (this.errorTimer) clearTimeout(this.errorTimer);
                this.errorTimer = setTimeout(() => { this.errorMsg = ''; }, 5000);
            },
        };
    }
    </script>
</body>
</html>
