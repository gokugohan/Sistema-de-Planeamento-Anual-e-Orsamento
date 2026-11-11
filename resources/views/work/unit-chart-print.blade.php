{{-- The organization chart on a page of its own, for printing: only the heading, the legend and the chart, every branch
     open, scaled to the width of an A4 landscape sheet; the print dialog opens by itself (not with ?preview=1). --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Organization chart') }} — {{ $organization }}</title>
    @vite(['resources/css/app.css'])
    <style>
        @page { size: A4 landscape; margin: 8mm; }
        html, body { background: #fff; margin: 0; }
        body { -webkit-print-color-adjust: exact; print-color-adjust: exact; color: #1C2434; }
        .sheet { padding: 6mm; }
        .oc-print-frame { display: flex; justify-content: center; }
        .oc-box:hover { transform: none; box-shadow: none; }
        @media screen { .sheet { max-width: 297mm; margin: 0 auto; } }
        @media print { .no-print { display: none !important; } .sheet { padding: 0; } }
    </style>
</head>
<body>
    <div class="no-print flex items-center gap-3 bg-indigo-950 px-4 py-2 text-sm text-white">
        <span>{{ __('Organization chart') }}</span>
        <span class="ml-auto"></span>
        <label class="inline-flex items-center gap-2">
            <span class="text-indigo-200">{{ __('Size') }}</span>
            <select id="oc-fit" class="rounded border-0 bg-indigo-900 py-1 pl-2 pr-8 text-sm text-white">
                <option value="page">{{ __('On one page') }}</option>
                <option value="width">{{ __('Larger, over several pages') }}</option>
            </select>
        </label>
        <button type="button" onclick="window.print()" class="rounded bg-indigo-600 px-3 py-1.5 font-medium">{{ __('Print') }}</button>
    </div>
    <div class="sheet">
        <div class="mb-3 flex items-end justify-between gap-4 border-b-2 border-zinc-800 pb-2">
            <div>
                <div class="text-lg font-semibold">{{ __('Organization chart') }}</div>
                <div class="text-xs text-zinc-600">{{ $organization }}@if($abbr) ({{ $abbr }})@endif · {{ trans_choice(':n unit of the institution, with its head.|:n units of the institution, each with its head.', $count, ['n' => $count]) }}</div>
            </div>
            <div class="text-right text-[10px] text-zinc-500">{{ __('Generated') }} {{ now('Asia/Dili')->format('Y-m-d H:i') }} TLT · {{ auth()->user()->name }}</div>
        </div>
        @include('livewire.pages.work.partials.org-legend', ['bodies' => $bodies])
        <div class="oc-print-frame mt-4">
            <div id="oc-print-scale">
                @include('livewire.pages.work.partials.org-tree', ['print' => true])
            </div>
        </div>
    </div>
    @include('livewire.pages.work.partials.org-styles')
    <script>
        // the chart on one A4 landscape sheet (less the margins and the heading, at 96 dpi), or on its width over several
        // sheets; never enlarged
        (function () {
            var chart = document.getElementById('org-chart'), box = document.getElementById('oc-print-scale'), pick = document.getElementById('oc-fit');
            if (!chart || !box) return;
            var fit = function () {
                box.style.zoom = 1;
                var w = 1040 / (chart.scrollWidth + 10), h = 610 / (chart.scrollHeight + 10);
                box.style.zoom = Math.min(1, pick && pick.value === 'width' ? w : Math.min(w, h));
            };
            if (pick) pick.addEventListener('change', fit);
            fit();
            window.addEventListener('beforeprint', fit);
            if (!/[?&]preview=1/.test(location.search)) {
                window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 300); });
            }
        })();
    </script>
</body>
</html>
