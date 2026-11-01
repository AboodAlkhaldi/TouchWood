{{--
| The daily report's PDF (ops.md §1.11), drawn by mPDF: tables for layout (mPDF has no flexbox or grid),
| TouchWood's colours from resources/css/themes.css (navy, copper, cream), every number in a
| left-to-right span through ReportFormat. The sections in the spec's order, 1 to 10.
--}}
@php
    $w = static fn (string $key, array $replace = []): string => __('ops::daily_report.'.$key, $replace, $locale);
    $start = $rtl ? 'right' : 'left';
    $end = $rtl ? 'left' : 'right';
    $zone = $report->timezone;
@endphp
<html dir="{{ $rtl ? 'rtl' : 'ltr' }}" lang="{{ $locale }}">
<head>
<style>
    body { font-family: ibmplexsansarabic; font-size: 8.6pt; color: #0b2031; }
    table { border-collapse: collapse; }
    .header { width: 100%; background-color: #02365e; color: #ffffff; }
    .header td { padding: 10pt 12pt; vertical-align: middle; }
    .htitle { font-size: 18pt; font-weight: bold; color: #ffffff; }
    .hstore { font-size: 11pt; color: #dca071; font-weight: bold; padding-top: 2pt; }
    .hmeta { font-size: 8pt; color: #c9d9e6; line-height: 1.5; }
    .rule { height: 3pt; background-color: #864720; }
    .mark { margin-top: 6pt; background-color: #fef4e4; border: 0.8pt solid #dca071; color: #815d2c; font-weight: bold;
            text-align: center; padding: 4pt; font-size: 8.5pt; }
    .storeband { margin-top: 12pt; background-color: #e7f0f8; border-{{ $start }}: 4pt solid #02365e; padding: 7pt 10pt; }
    .sbname { font-size: 12pt; font-weight: bold; color: #02365e; }
    .sbwindow { font-size: 8pt; color: #53626b; }
    .off { font-size: 7.6pt; font-weight: bold; color: #982b24; background-color: #fdebe9; padding: 1pt 4pt; }
    .cards { width: 100%; border-collapse: separate; border-spacing: 5pt; margin: 4pt -5pt 0 -5pt; }
    .card { background-color: #ffffff; border: 0.8pt solid #dcdfe1; border-top: 2.4pt solid #02365e; padding: 7pt 9pt; vertical-align: top; }
    .card.copper { border-top-color: #864720; }
    .clabel { font-size: 7.6pt; color: #53626b; }
    .cvalue { font-size: 16pt; font-weight: bold; color: #02365e; padding-top: 3pt; }
    .mini { width: 100%; border-collapse: separate; border-spacing: 5pt; margin: 0 -5pt; }
    .mini td { background-color: #f6efe8; padding: 6pt 8pt; vertical-align: top; width: 25%; }
    .mlabel { font-size: 7.4pt; color: #53626b; }
    .mvalue { font-size: 12.5pt; font-weight: bold; color: #0b2031; padding-top: 2pt; }
    .mini td.wait { background-color: #f2f4f5; }
    .mwait { font-size: 8.4pt; color: #815d2c; font-weight: bold; padding-top: 2pt; }
    h2 { font-size: 10.5pt; color: #02365e; margin: 14pt 0 5pt 0; padding-bottom: 3pt; border-bottom: 0.8pt solid #dcdfe1; }
    h2 .num { color: #864720; }
    h3 { font-size: 8.4pt; color: #53626b; margin: 6pt 0 3pt 0; font-weight: bold; }
    .data td.subhead { font-size: 8.4pt; color: #53626b; font-weight: bold; padding: 0 0 3pt 0; border-bottom: none; }
    .grid { width: 100%; }
    .data { width: 100%; }
    /* A block never splits across pages, and keeps its heading with it (use_kwt): no heading alone at a
       page's foot. The waiting refunds alone may run long, and may split. */
    .cards, .mini, .grid, .month, .data { page-break-inside: avoid; }
    .data.long { page-break-inside: auto; }
    /* A section's heading and its first block, in one cell that never splits: the heading never stands alone. */
    table.keep { width: 100%; page-break-inside: avoid; }
    td.keep { padding: 0; }
    td.sechead { font-size: 10.5pt; font-weight: bold; color: #02365e; padding: 14pt 0 3pt 0; border-bottom: 0.8pt solid #dcdfe1; }
    td.sechead .num { color: #864720; }
    td.keep table { margin-top: 5pt; }
    .data th { background-color: #02365e; color: #ffffff; font-size: 7.6pt; font-weight: bold; padding: 4pt 6pt; text-align: {{ $start }}; }
    .data td { padding: 3.6pt 6pt; border-bottom: 0.5pt solid #dcdfe1; vertical-align: middle; }
    .data tr.alt td { background-color: #f7f8f9; }
    .data tr.total td { font-weight: bold; border-top: 0.8pt solid #02365e; border-bottom: none; background-color: #e7f0f8; }
    .data .n { text-align: {{ $end }}; white-space: nowrap; }
    .data th.n { text-align: {{ $end }}; }
    .muted { color: #87949a; }
    .small { font-size: 7.2pt; }
    .code { font-size: 6.8pt; color: #87949a; }
    .up { color: #1d6546; font-weight: bold; }
    .down { color: #982b24; font-weight: bold; }
    .empty { color: #87949a; padding: 6pt 0; }
    .note { font-size: 7.4pt; color: #815d2c; background-color: #fef4e4; padding: 4pt 6pt; margin-top: 4pt; }
    .month { width: 100%; border-collapse: separate; border-spacing: 5pt; margin: 0 -5pt; }
    .month td { background-color: #02365e; color: #ffffff; padding: 8pt 10pt; width: 33%; }
    .molabel { font-size: 7.6pt; color: #9bbeda; }
    .movalue { font-size: 15pt; font-weight: bold; color: #ffffff; padding-top: 3pt; }
</style>
</head>
<body>

{{-- The header: TouchWood's logo on its cream tile, the report, the store (or all stores), its window. --}}
<table class="header">
    <tr>
        <td width="62"><img src="{{ $logo }}" width="50" height="50"></td>
        <td>
            <div class="htitle">{{ $w('title') }}</div>
            <div class="hstore">{!! $report->allStores ? e($w('all_stores')) : $f::safe($f->text($report->stores[0]->store->name->ar, $report->stores[0]->store->name->en)) !!}@if (! $report->allStores && ! $report->stores[0]->store->isActive) <span class="off">{{ $w('switched_off') }}</span>@endif</div>
        </td>
        <td class="hmeta" style="text-align: {{ $end }};" width="210">
            @php $first = $report->stores[0]; @endphp
            {{-- Every store's report: today where it is read (the base store's zone), not the first store's. --}}
            <b style="color: #ffffff; font-size: 10pt;">{!! $report->allStores && ! $first->pastDay ? $f->date($report->madeAt, $zone) : $f->date($first->from, $first->store->timezone) !!}</b><br>
            @if ($first->pastDay)
                {{ $w('whole_day') }}<br>
            @elseif (! $report->allStores)
                {{ $w('today_until', ['time' => '']) }}{!! $f->time($first->to, $first->store->timezone) !!}<br>
            @endif
            {!! str_replace([':date', ':time'], [$f->date($report->madeAt, $zone)->toHtml(), $f->time($report->madeAt, $zone)->toHtml()], e(__('ops::daily_report.made_at', [], $locale))) !!}<br>
            {{ $w('timezone', ['zone' => '']) }}{!! \Modules\Ops\Infrastructure\Pdf\ReportFormat::ltr($zone) !!}
        </td>
    </tr>
</table>
<div class="rule"></div>
@isset($mark)
    <div class="mark">{{ $mark }}</div>
@endisset

@foreach ($report->stores as $s)
    @php
        $store = $s->store;
        $money = static fn ($amount) => $f->storeMoney($amount, $store);
        $tz = $store->timezone;
        $paymentsIn = \Shared\Domain\ValueObject\Money::zero($store->currencyCode);
        foreach ($s->money->payments as $way) { $paymentsIn = $paymentsIn->add($way->amount); }
        $refundsOut = \Shared\Domain\ValueObject\Money::zero($store->currencyCode);
        foreach ($s->money->refunds as $way) { $refundsOut = $refundsOut->add($way->amount); }
        $sum = static fn (array $byKind): int => array_sum($byKind);
    @endphp

    @if ($report->allStores)
        <div class="storeband" @if (! $loop->first) style="page-break-before: always;" @endif>
            <span class="sbname">{!! $f::safe($f->text($store->name->ar, $store->name->en)) !!}</span>@if (! $store->isActive) <span class="off">{{ $w('switched_off') }}</span>@endif
            <span class="sbwindow"> · {!! $f->date($s->from, $tz) !!} ·
                @if ($s->pastDay) {{ $w('whole_day') }} @else {{ $w('today_until', ['time' => '']) }}{!! $f->time($s->to, $tz) !!} @endif
                · {!! \Modules\Ops\Infrastructure\Pdf\ReportFormat::ltr($tz) !!}</span>
        </div>
    @endif

    {{-- The figures that matter most, first. --}}
    <table class="cards">
        <tr>
            <td class="card" width="25%"><div class="clabel">{{ $w('cards.revenue') }}</div><div class="cvalue">{!! $money($s->today->revenue) !!}</div></td>
            <td class="card" width="25%"><div class="clabel">{{ $w('cards.orders') }}</div><div class="cvalue">{!! $f->int($s->today->orders) !!}</div></td>
            <td class="card" width="25%"><div class="clabel">{{ $w('cards.average') }}</div><div class="cvalue">{!! $f->average($s->today->revenue, $s->today->orders, $store) !!}</div></td>
            <td class="card copper" width="25%"><div class="clabel">{{ $w('cards.new_customers') }}</div><div class="cvalue">{!! $f->int($s->today->newCustomers) !!}</div></td>
        </tr>
    </table>

    {{-- 1. Today against yesterday, same hours. --}}
    <table class="keep"><tr><td class="sechead"><span class="num">1</span> · {{ $w('compare.title') }}</td></tr><tr><td class="keep">
    <table class="data">
        <tr><th></th><th class="n">{{ $w('compare.today') }}</th><th class="n">{{ $w('compare.yesterday') }}</th><th class="n">{{ $w('compare.change') }}</th></tr>
        @foreach ([
            ['compare.orders', $f->int($s->today->orders), $f->int($s->yesterday->orders), $s->today->orders, $s->yesterday->orders],
            ['compare.revenue', $money($s->today->revenue), $money($s->yesterday->revenue), $s->today->revenue->minorUnits, $s->yesterday->revenue->minorUnits],
            ['compare.new_customers', $f->int($s->today->newCustomers), $f->int($s->yesterday->newCustomers), $s->today->newCustomers, $s->yesterday->newCustomers],
        ] as [$label, $today, $yesterday, $a, $b])
            <tr @if ($loop->even) class="alt" @endif>
                <td>{{ $w($label) }}</td>
                <td class="n"><b>{!! $today !!}</b></td>
                <td class="n">{!! $yesterday !!}</td>
                <td class="n"><span class="{{ $b === 0 || $a === $b ? 'muted' : ($a > $b ? 'up' : 'down') }}">{!! $f->change($a, $b) !!}</span></td>
            </tr>
        @endforeach
    </table>
    </td></tr></table>

    {{-- 2. Sales. --}}
    <table class="keep"><tr><td class="sechead"><span class="num">2</span> · {{ $w('sales.title') }}</td></tr><tr><td class="keep">
    <table class="grid"><tr>
        <td width="49%" style="vertical-align: top;">
            <table class="data">
                <tr><th>{{ $w('sales.orders') }}</th><th class="n" width="38%"></th></tr>
                <tr><td>{{ $w('sales.placed') }}</td><td class="n">{!! $f->int($s->counts->placed) !!}</td></tr>
                <tr class="alt"><td>{{ $w('sales.counted') }}</td><td class="n"><b>{!! $f->int($s->counts->counted) !!}</b></td></tr>
                <tr><td>&nbsp;&nbsp;{{ $w('sales.individuals') }}</td><td class="n">{!! $f->int($s->counts->individuals) !!}</td></tr>
                <tr class="alt"><td>&nbsp;&nbsp;{{ $w('sales.companies') }}</td><td class="n">{!! $f->int($s->counts->companies) !!}</td></tr>
                <tr><td>{{ $w('sales.cancelled') }}</td><td class="n">{!! $f->int($s->counts->cancelled) !!}</td></tr>
                <tr class="alt"><td>{{ $w('sales.rejected') }}</td><td class="n">{!! $f->int($s->counts->rejected) !!}</td></tr>
                <tr><td>{{ $w('sales.came_back') }}</td><td class="n">{!! $f->int($s->counts->cameBack) !!}</td></tr>
            </table>
        </td>
        <td width="2%"></td>
        <td width="49%" style="vertical-align: top;">
            <table class="data">
                <tr><th>{{ $w('sales.amounts') }}</th><th class="n" width="38%"></th></tr>
                <tr><td>{{ $w('sales.revenue') }}</td><td class="n"><b>{!! $money($s->today->revenue) !!}</b></td></tr>
                {{-- VAT is net of refunds too (owner, 2026-10-30), and so revenue with VAT. --}}
                <tr class="alt"><td>{{ $w('sales.vat') }}</td><td class="n">{!! $money($s->vat) !!}</td></tr>
                <tr><td>{{ $w('sales.revenue_with_vat') }}</td><td class="n">{!! $money($s->today->revenue->add($s->vat)) !!}</td></tr>
                <tr class="alt"><td>{{ $w('sales.shipping') }}</td><td class="n">{!! $money($s->shipping) !!}</td></tr>
                <tr><td>{{ $w('sales.refunds') }}</td><td class="n">{!! $money($s->refunds) !!}</td></tr>
                <tr class="alt"><td>{{ $w('sales.average') }}</td><td class="n">{!! $f->average($s->today->revenue, $s->today->orders, $store) !!}</td></tr>
                @if ($s->waitingRefunds !== [])
                    <tr class="total"><td>{{ $w('sales.if_refunds') }}</td><td class="n">{!! $money($s->today->revenue->subtract($s->waitingRefundsTotal)) !!}</td></tr>
                    <tr class="total"><td>{{ $w('sales.with_vat_if_refunds') }}</td><td class="n">{!! $money($s->today->revenue->subtract($s->waitingRefundsTotal)->add($s->vat)->subtract($s->waitingRefundsVat)) !!}</td></tr>
                @endif
            </table>
        </td>
    </tr></table>
    </td></tr></table>
    @if ($s->waitingRefunds !== [])
        <div class="note">{{ $w('sales.waiting_note') }}</div>
        <h3>{{ $w('sales.waiting_title') }}</h3>
        <table class="data long">
            <tr><th>{{ $w('sales.order') }}</th><th class="n">{{ $w('sales.estimate') }}</th><th class="n">{{ $w('sales.estimate_vat') }}</th></tr>
            @foreach ($s->waitingRefunds as $waiting)
                <tr @if ($loop->even) class="alt" @endif><td>{!! \Modules\Ops\Infrastructure\Pdf\ReportFormat::ltr($waiting->orderNumber) !!}</td><td class="n">{!! $money($waiting->estimate) !!}</td><td class="n">{!! $money($waiting->estimateVat) !!}</td></tr>
            @endforeach
        </table>
    @endif

    {{-- 3. Money received. --}}
    <table class="keep"><tr><td class="sechead"><span class="num">3</span> · {{ $w('money.title') }}</td></tr><tr><td class="keep">
    <table class="grid"><tr>
        @foreach ([['money.payments_in', $s->money->payments, $paymentsIn], ['money.refunds_out', $s->money->refunds, $refundsOut]] as [$label, $ways, $total])
            <td width="49%" style="vertical-align: top;">
                <table class="data">
                    <tr><th>{{ $w($label) }}</th><th class="n">{{ $w('money.records') }}</th><th class="n">{{ $w('money.amount') }}</th></tr>
                    @forelse ($ways as $way)
                        <tr @if ($loop->even) class="alt" @endif><td>{{ $w('money.ways.'.$way->way) }}</td><td class="n">{!! $f->int($way->records) !!}</td><td class="n">{!! $money($way->amount) !!}</td></tr>
                    @empty
                        <tr><td colspan="3" class="muted">{{ $w('none') }}</td></tr>
                    @endforelse
                    <tr class="total"><td>{{ $w('money.total') }}</td><td></td><td class="n">{!! $money($total) !!}</td></tr>
                </table>
            </td>
            @if ($loop->first) <td width="2%"></td> @endif
        @endforeach
    </tr></table>
    <table class="mini" style="margin-top: 4pt;"><tr>
        <td style="width: 50%;"><div class="mlabel">{{ $w($s->notReadThatDay ? 'money.transfers_waiting_now' : 'money.transfers_waiting') }}</div><div class="mvalue">{!! str_replace([':orders', ':amount'], [$f->int($s->transfersWaiting->orders)->toHtml(), $money($s->transfersWaiting->owed)->toHtml()], e(__('ops::daily_report.money.transfers_waiting_line', [], $locale))) !!}</div></td>
        <td class="wait" style="width: 50%;"><div class="mlabel">{{ $w('money.transfers_overdue') }}</div><div class="mwait">{{ $w('money.waits_stage7') }}</div></td>
    </tr></table>
    </td></tr></table>

    {{-- 4. Products. --}}
    <table class="keep"><tr><td class="sechead"><span class="num">4</span> · {{ $w('products.title') }}</td></tr><tr><td class="keep">
    @if ($s->topSizes === [])
        <table class="grid"><tr><td class="empty">{{ $w('products.no_sales') }}</td></tr></table>
    @else
        <table class="data">
            <tr><td colspan="4" class="subhead">{{ $w('products.top_sizes') }}</td></tr>
            <tr><th width="16">#</th><th>{{ $w('products.product') }}</th><th class="n">{{ $w('products.units') }}</th><th class="n">{{ $w('products.revenue') }}</th></tr>
            @foreach ($s->topSizes as $size)
                <tr @if ($loop->even) class="alt" @endif>
                    <td class="muted">{!! $f->int($loop->iteration) !!}</td>
                    <td>{!! $f::safe($f->text($size->productNameAr, $size->productNameEn)) !!}
                        @if ($f->text($size->sizeAr, $size->sizeEn) !== '') <span class="small muted"> — {!! $f::safe($f->text($size->sizeAr, $size->sizeEn)) !!}</span> @endif
                        <br><span class="code">{!! \Modules\Ops\Infrastructure\Pdf\ReportFormat::ltr($size->code) !!}</span></td>
                    <td class="n">{!! $f->int($size->units) !!}</td>
                    <td class="n">{!! $money($size->revenue) !!}</td>
                </tr>
            @endforeach
        </table>
    @endif
    </td></tr></table>
    {{-- Net of refunds (§1.11), so a day of refunds and no sales has its rows too. --}}
    @if ($s->categories !== [])
        <h3>{{ $w('products.by_category') }}</h3>
        <table class="data">
            <tr><th>{{ $w('products.category') }}</th><th class="n">{{ $w('products.units') }}</th><th class="n">{{ $w('products.revenue') }}</th></tr>
            @foreach ($s->categories as $category)
                <tr @if ($loop->even) class="alt" @endif>
                    <td>{!! $category->categoryId === null ? e($w('products.no_category')) : $f::safe($f->text($category->nameAr, $category->nameEn)) !!}</td>
                    <td class="n">{!! $f->int($category->units) !!}</td>
                    <td class="n">{!! $money($category->revenue) !!}</td>
                </tr>
            @endforeach
        </table>
    @endif

    {{-- 5. Customers. --}}
    <table class="keep"><tr><td class="sechead"><span class="num">5</span> · {{ $w('customers.title') }}</td></tr><tr><td class="keep">
    <table class="mini"><tr>
        <td><div class="mlabel">{{ $w('customers.new_individuals') }}</div><div class="mvalue">{!! $f->int($s->newIndividuals) !!}</div></td>
        <td><div class="mlabel">{{ $w('customers.new_companies') }}</div><div class="mvalue">{!! $f->int($s->newCompanies) !!}</div></td>
        <td><div class="mlabel">{{ $w('customers.applications_submitted') }}</div><div class="mvalue">{!! $f->int($s->applications->submitted) !!}</div></td>
        <td><div class="mlabel">{{ $w('customers.applications_approved') }}</div><div class="mvalue">{!! $f->int($s->applications->approved) !!}</div></td>
    </tr></table>
    </td></tr></table>

    {{-- 6. Stock. --}}
    <table class="keep"><tr><td class="sechead"><span class="num">6</span> · {{ $w('stock.title') }}</td></tr><tr><td class="keep">
    <table class="mini"><tr>
        <td><div class="mlabel">{{ $w('stock.became_low') }}</div><div class="mvalue">{!! $f->int(count($s->lowStock->becameLow)) !!}</div></td>
        <td><div class="mlabel">{{ $w('stock.ran_out') }}</div><div class="mvalue">{!! $f->int(count($s->lowStock->ranOut)) !!}</div></td>
        <td><div class="mlabel">{{ $w('stock.came_back') }}</div><div class="mvalue">{!! $f->int(count($s->lowStock->cameBack)) !!}</div></td>
        <td style="background-color: #e7f0f8;"><div class="mlabel">{{ $w('stock.reads') }}</div>
            <div class="small" style="padding-top: 2pt;">{{ $w('stock.reads_done') }}: <b>{!! $f->int($sum($s->sync->readsDone)) !!}</b><br>
            {{ $w('stock.reads_failed') }}: <b>{!! $f->int($sum($s->sync->readsFailed)) !!}</b><br>
            {{ $w('stock.problems') }}: <b>{!! $f->int($sum($s->sync->problemsOpened)) !!}</b></div>
            @if (! $s->sync->complete) <div class="small" style="color: #815d2c;">{{ $w('stock.not_complete') }}</div> @endif
        </td>
    </tr></table>
    </td></tr></table>

    {{-- 7. Promotions and points. --}}
    <table class="keep"><tr><td class="sechead"><span class="num">7</span> · {{ $w('promotions.title') }}</td></tr><tr><td class="keep">
    <table class="mini"><tr>
        <td><div class="mlabel">{{ $w('promotions.coupons_used') }}</div><div class="mvalue">{!! $f->int($s->coupons->uses) !!}</div></td>
        <td><div class="mlabel">{{ $w('promotions.coupon_discount') }}</div><div class="mvalue">{!! $money($s->coupons->discount) !!}</div></td>
        <td><div class="mlabel">{{ $w('promotions.points_given') }}</div><div class="mvalue">{!! $f->int($s->pointsGiven) !!}</div></td>
        <td><div class="mlabel">{{ $w('promotions.points_spent') }}</div><div class="mvalue">{!! $f->int($s->pointsSpent) !!}</div></td>
    </tr></table>
    </td></tr></table>
    @if ($s->otherPoints !== [])
        <table class="data" style="margin-top: 4pt;">
            <tr><th>{{ $w('promotions.other_points') }}</th><th class="n">{{ $w('promotions.entries') }}</th><th class="n">{{ $w('promotions.points') }}</th></tr>
            @foreach ($s->otherPoints as $kind)
                <tr @if ($loop->even) class="alt" @endif><td>{{ $w('promotions.kinds.'.$kind->kind->value) }}</td><td class="n">{!! $f->int($kind->entries) !!}</td><td class="n">{!! $f->int($kind->points) !!}</td></tr>
            @endforeach
        </table>
    @endif

    {{-- 8. Feedback. --}}
    <table class="keep"><tr><td class="sechead"><span class="num">8</span> · {{ $w('feedback.title') }}</td></tr><tr><td class="keep">
    <table class="mini"><tr>
        <td style="width: 33%;"><div class="mlabel">{{ $w('feedback.new_reviews') }}</div><div class="mvalue">{!! $f->int($s->feedback->newReviews) !!}</div></td>
        <td style="width: 33%;"><div class="mlabel">{{ $w('feedback.new_questions') }}</div><div class="mvalue">{!! $f->int($s->feedback->newQuestions) !!}</div></td>
        <td style="width: 34%;"><div class="mlabel">{{ $w('feedback.waiting_questions') }}</div><div class="mvalue">{!! $f->int($s->feedback->waitingQuestions) !!}</div></td>
    </tr></table>
    </td></tr></table>

    {{-- 9. Searches. --}}
    <table class="keep"><tr><td class="sechead"><span class="num">9</span> · {{ $w('searches.title') }}</td></tr><tr><td class="keep">
    <table class="mini"><tr>
        <td style="width: 50%;"><div class="mlabel">{{ $w('searches.searches') }}</div><div class="mvalue">{!! $f->int($s->searches) !!}</div></td>
        <td style="width: 50%;"><div class="mlabel">{{ $w('searches.no_results') }}</div><div class="mvalue">{!! $f->int($s->searchesWithNoResults) !!}</div></td>
    </tr></table>
    @if ($s->topSearches !== [])
        <table class="grid" style="margin-top: 4pt;"><tr>
            @foreach ([['searches.top', $s->topSearches, 'searches'], ['searches.top_no_results', $s->topNoResults, 'noResults']] as [$label, $rows, $field])
                <td width="49%" style="vertical-align: top;">
                    <table class="data">
                        <tr><th>{{ $w($label) }}</th><th class="n">{{ $w('searches.times') }}</th></tr>
                        @forelse ($rows as $row)
                            <tr @if ($loop->even) class="alt" @endif><td>{!! $f::safe($row->query) !!} <span class="code">{{ strtoupper($row->locale) }}</span></td><td class="n">{!! $f->int($row->{$field}) !!}</td></tr>
                        @empty
                            <tr><td colspan="2" class="muted">{{ $w('none') }}</td></tr>
                        @endforelse
                    </table>
                </td>
                @if ($loop->first) <td width="2%"></td> @endif
            @endforeach
        </tr></table>
    @else
        <div class="empty">{{ $w('searches.none') }}</div>
    @endif
    </td></tr></table>

    {{-- 10. Month to date, the last section. --}}
    <table class="keep"><tr><td class="sechead"><span class="num">10</span> · {{ $w('month.title') }} <span class="small muted">— {{ $w('month.since') }}</span></td></tr><tr><td class="keep">
    <table class="month"><tr>
        <td><div class="molabel">{{ $w('month.orders') }}</div><div class="movalue">{!! $f->int($s->monthToDate->orders) !!}</div></td>
        <td><div class="molabel">{{ $w('month.revenue') }}</div><div class="movalue">{!! $money($s->monthToDate->revenue) !!}</div></td>
        <td><div class="molabel">{{ $w('month.new_customers') }}</div><div class="movalue">{!! $f->int($s->monthToDate->newCustomers) !!}</div></td>
    </tr></table>
    </td></tr></table>
@endforeach

{{-- All stores: the totals per currency, never converted (§1.10). --}}
@if ($report->totals !== [])
    <div class="storeband" style="page-break-before: always;"><span class="sbname">{{ $w('totals.title') }}</span> <span class="sbwindow">· {{ $w('totals.note') }}</span></div>
    <table class="data" style="margin-top: 6pt;">
        <tr>
            <th></th>
            @foreach ($report->totals as $total)
                <th class="n">{!! \Modules\Ops\Infrastructure\Pdf\ReportFormat::ltr($total->currencyCode) !!} <span style="font-weight: normal;">({{ $w('totals.stores', ['count' => count($total->storeIds)]) }})</span></th>
            @endforeach
        </tr>
        @foreach ([
            ['compare.orders', static fn ($t) => $f->int($t->today->orders)],
            ['sales.revenue', static fn ($t) => $f->money($t->today->revenue, $t->currencyExponent, $locale === 'en' ? $t->symbolEn : $t->symbolAr)],
            ['sales.vat', static fn ($t) => $f->money($t->vat, $t->currencyExponent, $locale === 'en' ? $t->symbolEn : $t->symbolAr)],
            ['sales.revenue_with_vat', static fn ($t) => $f->money($t->today->revenue->add($t->vat), $t->currencyExponent, $locale === 'en' ? $t->symbolEn : $t->symbolAr)],
            ['sales.shipping', static fn ($t) => $f->money($t->shipping, $t->currencyExponent, $locale === 'en' ? $t->symbolEn : $t->symbolAr)],
            ['sales.refunds', static fn ($t) => $f->money($t->refunds, $t->currencyExponent, $locale === 'en' ? $t->symbolEn : $t->symbolAr)],
            ['totals.payments_in', static fn ($t) => $f->money($t->paymentsIn, $t->currencyExponent, $locale === 'en' ? $t->symbolEn : $t->symbolAr)],
            ['compare.new_customers', static fn ($t) => $f->int($t->today->newCustomers)],
            ['month.orders', static fn ($t) => $f->int($t->monthToDate->orders)],
            ['month.revenue', static fn ($t) => $f->money($t->monthToDate->revenue, $t->currencyExponent, $locale === 'en' ? $t->symbolEn : $t->symbolAr)],
        ] as [$label, $cell])
            <tr @if ($loop->even) class="alt" @endif>
                <td>{{ $w($label) }}@if (str_starts_with($label, 'month.')) <span class="small muted">({{ $w('month.title') }})</span>@endif</td>
                @foreach ($report->totals as $total) <td class="n">{!! $cell($total) !!}</td> @endforeach
            </tr>
        @endforeach
    </table>
@endif

</body>
</html>
