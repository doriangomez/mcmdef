<?php
require_once __DIR__ . '/../app/middlewares/require_auth.php';
require_once __DIR__ . '/../app/views/layout.php';

ob_start();
?>
<section class="card dashboard-hero strategic-hero">
  <div class="hero-main">
    <p class="hero-kicker">MCM | Inteligencia financiera</p>
    <h2 class="hero-title">Centro Ejecutivo de Inteligencia de Cartera</h2>
    <p class="hero-copy">Visión integral de exposición, mora y concentración basada en el Excel SAP cargado.</p>
  </div>
  <div class="hero-controls">
    <form id="dashboardFilters" class="dashboard-filters" autocomplete="off">
      <label class="filter-field"><span>Periodo</span><select name="periodo" id="filterPeriodo" data-placeholder="Todos los periodos"><option value="">Todos los periodos</option></select></label>
      <label class="filter-field"><span>Regional</span><select name="regional" id="filterRegional" data-placeholder="Todas las regionales"><option value="">Todas las regionales</option></select></label>
      <label class="filter-field"><span>Canal</span><select name="canal" id="filterCanal" data-placeholder="Todos los canales"><option value="">Todos los canales</option></select></label>
      <div class="filter-actions">
        <button type="submit" class="btn">Aplicar</button>
        <button type="button" class="btn btn-secondary" id="dashboardClear">Limpiar</button>
      </div>
    </form>
    <div class="hero-meta"><span class="dashboard-updated-at" id="dashboardUpdatedAt">Sin actualizar</span></div>
  </div>
</section>

<section class="kpi-premium-grid" id="kpiGrid"></section>

<section class="dashboard-panels-grid">
  <article class="card chart-card"><div class="card-header"><h3>Distribución por Aging</h3></div><div id="agingChart" class="chart-area chart-area-sm"></div></article>
  <article class="card chart-card"><div class="card-header"><h3>Tendencia de Exposición por Periodo</h3></div><div id="trendChart" class="chart-area chart-area-sm"></div></article>
  <article class="card chart-card"><div class="card-header"><h3>Top 10 Clientes por Saldo</h3></div><div id="topClientsChart" class="chart-area chart-area-sm"></div></article>
  <article class="card chart-card"><div class="card-header"><h3>Concentración por Regional</h3></div><div id="regionalChart" class="chart-area chart-area-sm"></div></article>
  <article class="card chart-card"><div class="card-header"><h3>Concentración por Canal</h3></div><div id="canalChart" class="chart-area chart-area-sm"></div></article>
  <article class="card chart-card chart-card-wide"><div class="card-header"><h3>Heatmap de Riesgo (Regional vs Bucket)</h3></div><div id="heatmapChart" class="chart-area chart-area-medium"></div></article>
  <article class="card chart-card chart-card-main"><div class="card-header"><h3>Score General de Cartera</h3></div><div id="scoreChart" class="chart-area chart-area-sm"></div></article>
</section>

<script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
<script>
(function () {
  var endpointUrl = <?= json_encode(app_url('api/dashboard-metrics/')) ?>;
  var form = document.getElementById('dashboardFilters');
  var clearButton = document.getElementById('dashboardClear');
  var updatedAtEl = document.getElementById('dashboardUpdatedAt');
  var kpiGrid = document.getElementById('kpiGrid');
  var charts = {};

  var currency = new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 });
  var decimal = new Intl.NumberFormat('es-CO', { minimumFractionDigits: 1, maximumFractionDigits: 1 });

  function formatPeriod(period) { return period || ''; }
  function f(v, unit) {
    var n = Number(v || 0);
    if (unit === 'currency') return currency.format(n);
    if (unit === 'percent') return decimal.format(n) + '%';
    if (unit === 'days') return decimal.format(n) + ' días';
    return decimal.format(n);
  }

  function noDataOptions(height) {
    return {
      chart: { height: height, toolbar: { show: false }, fontFamily: 'Inter, sans-serif' },
      noData: { text: 'No hay datos para los filtros seleccionados.' },
      dataLabels: { enabled: false },
      tooltip: { theme: 'light' }
    };
  }

  function upsert(key, id, options) {
    if (charts[key]) charts[key].updateOptions(options, false, true, true);
    else { charts[key] = new ApexCharts(document.getElementById(id), options); charts[key].render(); }
  }

  function hydrateSelect(selectId, values, selected) {
    var el = document.getElementById(selectId);
    var ph = el.getAttribute('data-placeholder');
    var html = ['<option value="">' + ph + '</option>'];
    (values || []).forEach(function (v) { html.push('<option value="' + v + '">' + (selectId === 'filterPeriodo' ? formatPeriod(v) : v) + '</option>'); });
    el.innerHTML = html.join('');
    el.value = selected || '';
  }

  function renderKpis(kpis) {
    kpiGrid.innerHTML = (kpis || []).map(function (kpi) {
      return '<article class="kpi-premium-card" title="' + (kpi.tooltip || '') + '"><div class="kpi-premium-head"><p class="kpi-premium-label">' + kpi.title + '</p><span class="kpi-premium-icon"><i class="' + (kpi.icon || 'fa-solid fa-chart-line') + '"></i></span></div><p class="kpi-premium-value">' + f(kpi.value, kpi.unit) + '</p><div class="kpi-premium-foot"><span class="kpi-premium-subtext">' + (kpi.tooltip || '') + '</span></div></article>';
    }).join('');
  }

  function renderCharts(data) {
    var aging = data.aging || [];
    upsert('aging', 'agingChart', Object.assign(noDataOptions(280), {
      chart: { type: 'bar', height: 280, stacked: true, toolbar: { show: false } },
      series: [{ name: 'Saldo', data: aging.map(function (r) { return r.value; }) }],
      plotOptions: { bar: { horizontal: true } },
      xaxis: { categories: aging.map(function (r) { return r.bucket; }) },
      tooltip: { y: { formatter: function (v, ctx) { var r = aging[ctx.dataPointIndex] || {}; return currency.format(v) + ' | ' + decimal.format(r.pct || 0) + '% | Prom. ' + (r.avg_days || 0) + ' días'; } } }
    }));

    var trend = data.trend || [];
    upsert('trend', 'trendChart', Object.assign(noDataOptions(280), {
      chart: { type: 'line', height: 280, toolbar: { show: false } },
      series: [{ name: 'Saldo pendiente', data: trend.map(function (r) { return r.saldo; }) }],
      xaxis: { categories: trend.map(function (r) { return formatPeriod(r.periodo); }) },
      tooltip: { y: { formatter: function (v, ctx) { var r = trend[ctx.dataPointIndex] || {}; return currency.format(v) + ' | Variación: ' + decimal.format(r.variation_pct || 0) + '%'; } } }
    }));

    var top = data.top_clients || [];
    upsert('top', 'topClientsChart', Object.assign(noDataOptions(280), {
      chart: { type: 'bar', height: 280 },
      series: [{ name: 'Saldo', data: top.map(function (r) { return r.saldo; }) }],
      plotOptions: { bar: { horizontal: false } },
      xaxis: { categories: top.map(function (r) { return r.cliente; }) },
      tooltip: { y: { formatter: function (v, ctx) { var r = top[ctx.dataPointIndex] || {}; return currency.format(v) + ' (' + decimal.format(r.pct || 0) + '%)'; } } }
    }));

    var regional = data.regional || [];
    upsert('regional', 'regionalChart', Object.assign(noDataOptions(280), {
      chart: { type: 'bar', height: 280 },
      series: [{ name: 'Saldo', data: regional.map(function (r) { return r.saldo; }) }],
      xaxis: { categories: regional.map(function (r) { return r.regional; }) },
      tooltip: { y: { formatter: function (v, ctx) { var r = regional[ctx.dataPointIndex] || {}; return currency.format(v) + ' (' + decimal.format(r.pct || 0) + '%)'; } } }
    }));

    var canal = data.canal || [];
    upsert('canal', 'canalChart', Object.assign(noDataOptions(280), {
      chart: { type: 'donut', height: 280 },
      labels: canal.map(function (r) { return r.canal; }),
      series: canal.map(function (r) { return r.saldo; }),
      tooltip: { y: { formatter: function (v, ctx) { var r = canal[ctx.seriesIndex] || {}; return currency.format(v) + ' (' + decimal.format(r.pct || 0) + '%)'; } } }
    }));

    upsert('heatmap', 'heatmapChart', Object.assign(noDataOptions(320), {
      chart: { type: 'heatmap', height: 320 },
      series: (data.heatmap && data.heatmap.series) || [],
      plotOptions: { heatmap: { colorScale: { ranges: [
        { from: 0, to: 1, color: '#16A34A', name: 'Bajo' },
        { from: 1, to: 10000000, color: '#EAB308', name: 'Medio' },
        { from: 10000000, to: 50000000, color: '#EF4444', name: 'Alto' },
        { from: 50000000, to: 999999999999, color: '#7F1D1D', name: 'Crítico' }
      ] } } },
      tooltip: { custom: function (opts) { var p = opts.w.config.series[opts.seriesIndex].data[opts.dataPointIndex]; return '<div class="apex-tooltip">Regional: ' + opts.w.config.series[opts.seriesIndex].name + '<br>Tramo: ' + p.x + '<br>Saldo: ' + currency.format(p.y || 0) + '<br>% en regional: ' + decimal.format(p.pct_regional || 0) + '%</div>'; } }
    }));

    var score = (data.score && data.score.value) || 0;
    upsert('score', 'scoreChart', Object.assign(noDataOptions(300), {
      chart: { type: 'radialBar', height: 300 },
      series: [score],
      labels: ['Score General'],
      plotOptions: { radialBar: { dataLabels: { value: { formatter: function (v) { return decimal.format(v); } } } } },
      tooltip: { enabled: true, y: { formatter: function () { return (data.score && data.score.tooltip) || ''; } } }
    }));
  }

  function requestData() {
    var query = new URLSearchParams(new FormData(form)).toString();
    fetch(endpointUrl + (query ? '?' + query : ''), { headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.json(); })
      .then(function (payload) {
        hydrateSelect('filterPeriodo', payload.filter_options.periodo, payload.meta.selected_filters.periodo);
        hydrateSelect('filterRegional', payload.filter_options.regional, payload.meta.selected_filters.regional);
        hydrateSelect('filterCanal', payload.filter_options.canal, payload.meta.selected_filters.canal);
        renderKpis(payload.kpis || []);
        renderCharts(payload.charts || {});
        updatedAtEl.textContent = 'Actualizado: ' + (payload.meta.generated_at_human || '--');
      });
  }

  form.addEventListener('submit', function (e) { e.preventDefault(); requestData(); });
  form.addEventListener('change', requestData);
  clearButton.addEventListener('click', function () { form.reset(); requestData(); });
  requestData();
})();
</script>
<?php
$content = ob_get_clean();
render_layout('Dashboard estratégico', $content);
