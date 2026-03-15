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
      <label class="filter-field"><span>UEN (obligatorio)</span><select name="uens[]" id="filterUens" multiple required data-placeholder="Seleccione UEN"></select></label>
      <label class="filter-field"><span>Empleado de Ventas</span><select name="empleado_ventas" id="filterEmpleado" data-placeholder="Todos los asesores"><option value="">Todos los asesores</option></select></label>
      <label class="filter-field"><span>Cliente</span><select name="cliente" id="filterCliente" data-placeholder="Todos los clientes"><option value="">Todos los clientes</option></select></label>
      <div class="filter-actions">
        <button type="submit" class="btn">Aplicar</button>
        <button type="button" class="btn btn-secondary" id="dashboardClear">Limpiar</button>
        <a class="btn" id="dashboardExport" href="<?= htmlspecialchars(app_url('api/cartera/analisis-export.php')) ?>">Descargar análisis de cartera (Excel XLSX)</a>
      </div>
    </form>
    <div class="hero-meta"><span class="dashboard-updated-at" id="dashboardUpdatedAt">Sin actualizar</span></div>
  </div>
</section>

<section class="kpi-premium-grid" id="kpiGrid"></section>

<section class="dashboard-panels-grid">
  <article class="card chart-card"><div class="card-header"><h3>Distribución por Aging</h3></div><div id="agingChart" class="chart-area chart-area-sm"></div></article>
  <article class="card chart-card"><div class="card-header"><h3>Tendencia de Exposición por Periodo</h3></div><div id="trendChart" class="chart-area chart-area-sm"></div></article>
  <article class="card chart-card"><div class="card-header"><h3>Promedio Días Vencido por Regional</h3></div><div id="avgDaysRegionalChart" class="chart-area chart-area-sm"></div></article>
  <article class="card chart-card"><div class="card-header"><h3>Promedio Días Vencido por Canal</h3></div><div id="avgDaysCanalChart" class="chart-area chart-area-sm"></div></article>
  <article class="card chart-card chart-card-wide"><div class="card-header"><h3>Promedio Días Vencido por Empleado de Ventas</h3></div><div id="avgDaysEmpleadoChart" class="chart-area chart-area-medium"></div></article>
  <article class="card chart-card chart-card-wide"><div class="card-header"><h3>Pareto Concentración Clientes (Top 10)</h3></div><div id="paretoChart" class="chart-area chart-area-medium"></div></article>
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
    if (!el) return;
    if (el.multiple) {
      el.innerHTML = (values || []).map(function (v) { return '<option value="' + v + '">' + v + '</option>'; }).join('');
      var selectedValues = Array.isArray(selected) ? selected : [];
      Array.prototype.forEach.call(el.options, function (opt) {
        opt.selected = selectedValues.indexOf(opt.value) !== -1;
      });
      return;
    }
    var ph = el.getAttribute('data-placeholder');
    var html = ['<option value="">' + ph + '</option>'];
    (values || []).forEach(function (v) { html.push('<option value="' + v + '">' + (selectId === 'filterPeriodo' ? formatPeriod(v) : v) + '</option>'); });
    el.innerHTML = html.join('');
    el.value = selected || '';
  }

  function renderKpis(kpis, emptyMessage) {
    if (!kpis || !kpis.length) {
      kpiGrid.innerHTML = '<article class="kpi-premium-card"><p class="kpi-premium-label">Sin datos</p><p class="kpi-premium-subtext">' + (emptyMessage || 'No hay datos para los filtros seleccionados.') + '</p></article>';
      return;
    }
    kpiGrid.innerHTML = kpis.map(function (kpi) {
      return '<article class="kpi-premium-card kpi-status-' + (kpi.status || 'neutral') + '" title="' + (kpi.tooltip || '') + '"><div class="kpi-premium-head"><p class="kpi-premium-label">' + kpi.title + '</p><span class="kpi-premium-icon"><i class="' + (kpi.icon || 'fa-solid fa-chart-line') + '"></i></span></div><p class="kpi-premium-value">' + f(kpi.value, kpi.unit) + '</p><div class="kpi-premium-foot"><span class="kpi-premium-subtext">' + (kpi.tooltip || '') + '</span></div></article>';
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

    var regional = data.avg_days_regional || [];
    upsert('avgRegional', 'avgDaysRegionalChart', Object.assign(noDataOptions(280), {
      chart: { type: 'bar', height: 280 },
      series: [{ name: 'Promedio días', data: regional.map(function (r) { return r.avg_dias; }) }],
      xaxis: { categories: regional.map(function (r) { return r.regional; }) },
      tooltip: { y: { formatter: function (v) { return decimal.format(v) + ' días'; } } }
    }));

    var canal = data.avg_days_canal || [];
    upsert('avgCanal', 'avgDaysCanalChart', Object.assign(noDataOptions(280), {
      chart: { type: 'bar', height: 280 },
      series: [{ name: 'Promedio días', data: canal.map(function (r) { return r.avg_dias; }) }],
      xaxis: { categories: canal.map(function (r) { return r.canal; }) },
      tooltip: { y: { formatter: function (v) { return decimal.format(v) + ' días'; } } }
    }));

    var empleados = data.avg_days_empleado || [];
    upsert('avgEmpleado', 'avgDaysEmpleadoChart', Object.assign(noDataOptions(320), {
      chart: { type: 'bar', height: 320 },
      series: [{ name: 'Promedio días', data: empleados.map(function (r) { return r.avg_dias; }) }],
      plotOptions: { bar: { horizontal: true } },
      xaxis: { categories: empleados.map(function (r) { return r.empleado; }) },
      tooltip: { y: { formatter: function (v) { return decimal.format(v) + ' días'; } } }
    }));

    var paretoRows = (data.pareto_top5 && data.pareto_top5.rows) || [];
    upsert('pareto', 'paretoChart', Object.assign(noDataOptions(320), {
      chart: { type: 'line', height: 320, stacked: false },
      series: [
        { name: 'Saldo', type: 'column', data: paretoRows.map(function (r) { return r.saldo; }) },
        { name: '% Acumulado', type: 'line', data: paretoRows.map(function (r) { return r.cum_pct; }) }
      ],
      xaxis: { categories: paretoRows.map(function (r) { return r.cliente; }) },
      yaxis: [
        { title: { text: 'Saldo' }, labels: { formatter: function (v) { return currency.format(v); } } },
        { opposite: true, max: 100, title: { text: '% acumulado' }, labels: { formatter: function (v) { return decimal.format(v) + '%'; } } }
      ],
      tooltip: { shared: true }
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
        hydrateSelect('filterEmpleado', payload.filter_options.empleado_ventas, payload.meta.selected_filters.empleado_ventas);
        hydrateSelect('filterCliente', payload.filter_options.cliente, payload.meta.selected_filters.cliente);
        hydrateSelect('filterUens', payload.filter_options.uens || [], payload.meta.selected_filters.uens || []);
        var exportQuery = new URLSearchParams(new FormData(form)).toString();
        document.getElementById('dashboardExport').href = <?= json_encode(app_url('api/cartera/analisis-export.php')) ?> + (exportQuery ? '?' + exportQuery : '');
        renderKpis(payload.kpis || [], payload.empty_message || '');
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
