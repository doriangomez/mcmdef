<?php
require_once __DIR__ . '/../app/middlewares/require_auth.php';
require_once __DIR__ . '/../app/views/layout.php';

ob_start();
?>
<section class="card dashboard-hero strategic-hero">
  <div class="hero-main">
    <p class="hero-kicker">MCM | Inteligencia financiera</p>
    <h2 class="hero-title">Centro Ejecutivo de Inteligencia de Cartera</h2>
    <p class="hero-copy">Vista separada para análisis ejecutivo y operación de cobranza.</p>
  </div>
  <div class="hero-controls">
    <form id="filtersForm" class="dashboard-filters" autocomplete="off">
      <label class="filter-field" for="filtroPeriodo">
        <span>Periodo</span>
        <select id="filtroPeriodo" name="periodo">
          <option value="">Cargando periodos...</option>
        </select>
      </label>
      <label class="filter-field" for="filtroRegional">
        <span>Regional</span>
        <select id="filtroRegional" disabled>
          <option value="">Próximamente</option>
        </select>
      </label>
      <label class="filter-field" for="filtroCanal">
        <span>Canal</span>
        <select id="filtroCanal" disabled>
          <option value="">Próximamente</option>
        </select>
      </label>
      <label class="filter-field" for="filtroEmpleado">
        <span>Empleado</span>
        <select id="filtroEmpleado" disabled>
          <option value="">Próximamente</option>
        </select>
      </label>
      <label class="filter-field" for="filtroCliente">
        <span>Cliente</span>
        <select id="filtroCliente" disabled>
          <option value="">Próximamente</option>
        </select>
      </label>
      <label class="filter-field" for="filtroUen">
        <span>UEN</span>
        <select id="filtroUen" disabled>
          <option value="">Próximamente</option>
        </select>
      </label>
    </form>
    <div class="filter-actions">
      <a class="btn" id="dashboardExport" href="<?= htmlspecialchars(app_url('api/cartera/analisis-export.php')) ?>">Descargar análisis de cartera (Excel XLSX)</a>
      <a class="btn btn-secondary" href="<?= htmlspecialchars(app_url('cartera/lista.php')) ?>">Ir a dashboard operativo (detalle)</a>
    </div>
    <div class="hero-meta"><span class="dashboard-updated-at" id="dashboardUpdatedAt">Sin actualizar</span></div>
    <div id="comparisonBox" class="kpi-premium-subtext"></div>
    <div id="dashboardFallbackNotice" class="kpi-premium-subtext"></div>
  </div>
</section>

<section class="kpi-premium-grid" id="kpiGrid"></section>

<section class="dashboard-panels-grid">
  <article class="card chart-card"><div class="card-header"><h3>Distribución por Edad de Cartera (valor y %)</h3></div><div id="agingChart" class="chart-area chart-area-sm"></div></article>
  <article class="card chart-card"><div class="card-header"><h3>Tendencia de Exposición por Periodo</h3></div><div id="trendChart" class="chart-area chart-area-sm"></div></article>
  <article class="card chart-card"><div class="card-header"><h3>Cartera vencida por UEN</h3></div><div id="moraUenChart" class="chart-area chart-area-sm"></div></article>
  <article class="card chart-card"><div class="card-header"><h3>Cartera vencida por canal</h3></div><div id="vencidaCanalChart" class="chart-area chart-area-sm"></div></article>
  <article class="card chart-card chart-card-wide"><div class="card-header"><h3>Cartera vencida por vendedor</h3></div><div id="vencidaEmpleadoChart" class="chart-area chart-area-medium"></div></article>
  <article class="card chart-card chart-card-wide"><div class="card-header"><h3>Pareto de clientes (Top 10)</h3></div><div id="paretoChart" class="chart-area chart-area-medium"></div></article>
  <article class="card chart-card"><div class="card-header"><h3>Dependencia del cliente mayor</h3></div><div id="dependenciaClienteMayor" class="chart-area chart-area-sm"></div></article>
  <article class="card chart-card"><div class="card-header"><h3>Score general de salud de cartera</h3></div><div id="scoreChart" class="chart-area chart-area-sm"></div></article>
</section>

<script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
<script>
(function () {
  var endpointUrl = <?= json_encode(app_url('api/dashboard-metrics/')) ?>;
  var form = document.getElementById('filtersForm') || document.getElementById('dashboardFilters');
  var updatedAtEl = document.getElementById('dashboardUpdatedAt');
  var kpiGrid = document.getElementById('kpiGrid');
  var comparisonBox = document.getElementById('comparisonBox');
  var fallbackNotice = document.getElementById('dashboardFallbackNotice');
  var charts = {};

  var currency = new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 });
  var decimal = new Intl.NumberFormat('es-CO', { minimumFractionDigits: 1, maximumFractionDigits: 1 });

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
      noData: { text: 'Sin datos para los filtros seleccionados' },
      dataLabels: { enabled: false },
      tooltip: { theme: 'light' }
    };
  }

  function upsert(key, id, options) {
    try {
      if (charts[key]) charts[key].updateOptions(options, false, true, true);
      else { charts[key] = new ApexCharts(document.getElementById(id), options); charts[key].render(); }
    } catch (e) {
      console.error('Error renderizando gráfico:', key, e);
      var container = document.getElementById(id);
      if (container) {
        container.innerHTML = '<div style="padding:20px;text-align:center;color:#888">Gráfico no disponible</div>';
      }
    }
  }

  function hydrateReadonlySelect(selectId) {
    var el = document.getElementById(selectId);
    if (!el) return;
    el.innerHTML = '<option value="">Próximamente</option>';
    el.disabled = true;
  }

  function renderKpis(kpis, emptyMessage) {
    if (!kpis || !kpis.length) {
      kpiGrid.innerHTML = '<article class="kpi-premium-card"><p class="kpi-premium-label">Sin datos</p><p class="kpi-premium-subtext">' + (emptyMessage || 'Sin datos para los filtros seleccionados') + '</p></article>';
      return;
    }
    kpiGrid.innerHTML = kpis.map(function (kpi) {
      var foot = kpi.message || kpi.tooltip || '';
      return '<article class="kpi-premium-card kpi-status-' + (kpi.status || 'neutral') + '" title="' + (kpi.tooltip || '') + '"><div class="kpi-premium-head"><p class="kpi-premium-label">' + kpi.title + '</p><span class="kpi-premium-icon"><i class="' + (kpi.icon || 'fa-solid fa-chart-line') + '"></i></span></div><p class="kpi-premium-value">' + f(kpi.value, kpi.unit) + '</p><div class="kpi-premium-foot"><span class="kpi-premium-subtext">' + foot + '</span></div></article>';
    }).join('');
  }

  function renderCharts(data) {
    var aging = data.aging || [];
    upsert('aging', 'agingChart', Object.assign(noDataOptions(280), {
      chart: { type: 'bar', height: 280 },
      series: [
        { name: 'Saldo', data: aging.map(function (r) { return r.value; }) },
        { name: '%', data: aging.map(function (r) { return r.pct; }) }
      ],
      xaxis: { categories: aging.map(function (r) { return r.bucket; }) },
      yaxis: [{ labels: { formatter: function (v) { return currency.format(v); } } }, { opposite: true, labels: { formatter: function (v) { return decimal.format(v) + '%'; } } }],
      tooltip: { shared: true, intersect: false }
    }));

    var trend = data.trend || [];
    upsert('trend', 'trendChart', Object.assign(noDataOptions(280), {
      chart: { type: 'line', height: 280, toolbar: { show: false } },
      series: [{ name: 'Saldo pendiente', data: trend.map(function (r) { return r.saldo; }) }],
      xaxis: { categories: trend.map(function (r) { return r.periodo; }) },
      tooltip: { y: { formatter: function (v, ctx) { var r = trend[ctx.dataPointIndex] || {}; return currency.format(v) + ' | Var.: ' + decimal.format(r.variation_pct || 0) + '%'; } } }
    }));

    var moraUen = data.mora_uen || [];
    upsert('moraUen', 'moraUenChart', Object.assign(noDataOptions(280), {
      chart: { type: 'bar', height: 280 },
      series: [{ name: 'Cartera vencida', data: moraUen.map(function (r) { return r.cartera_vencida; }) }],
      xaxis: { categories: moraUen.map(function (r) { return r.uen; }) },
      tooltip: { y: { formatter: function (v) { return currency.format(v); } } }
    }));

    var canal = data.vencida_canal || [];
    upsert('vencidaCanal', 'vencidaCanalChart', Object.assign(noDataOptions(280), {
      chart: { type: 'bar', height: 280 },
      series: [{ name: 'Cartera vencida', data: canal.map(function (r) { return r.cartera_vencida; }) }],
      xaxis: { categories: canal.map(function (r) { return r.canal; }) },
      tooltip: { y: { formatter: function (v, ctx) { var r = canal[ctx.dataPointIndex] || {}; return currency.format(v) + ' | % mora: ' + decimal.format(r.mora_pct || 0) + '%'; } } }
    }));

    var empleados = data.vencida_empleado || [];
    upsert('vencidaEmpleado', 'vencidaEmpleadoChart', Object.assign(noDataOptions(320), {
      chart: { type: 'bar', height: 320 },
      series: [{ name: 'Cartera vencida', data: empleados.map(function (r) { return r.cartera_vencida; }) }],
      plotOptions: { bar: { horizontal: true } },
      xaxis: { categories: empleados.map(function (r) { return r.empleado; }) },
      tooltip: { y: { formatter: function (v, ctx) { var r = empleados[ctx.dataPointIndex] || {}; return currency.format(v) + ' | % mora: ' + decimal.format(r.mora_pct || 0) + '%'; } } }
    }));

    var paretoRows = (data.pareto_top10 && data.pareto_top10.rows) || [];
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
      tooltip: { shared: true, intersect: false }
    }));

    var dep = data.dependencia_mayor || { cliente: 'Sin dato', pct: 0, saldo: 0 };
    document.getElementById('dependenciaClienteMayor').innerHTML = '<div style="padding:16px"><h4 style="margin:0 0 8px">' + dep.cliente + '</h4><p style="margin:0;font-size:22px;font-weight:700">' + decimal.format(dep.pct || 0) + '%</p><p style="margin:4px 0 0">' + currency.format(dep.saldo || 0) + '</p></div>';

    var score = (data.score && data.score.value) || 0;
    upsert('score', 'scoreChart', Object.assign(noDataOptions(300), {
      chart: { type: 'radialBar', height: 300 },
      series: [score],
      labels: [(data.score && data.score.label) || 'Score General'],
      plotOptions: { radialBar: { dataLabels: { value: { formatter: function (v) { return decimal.format(v); } } } } },
      tooltip: { enabled: true, y: { formatter: function () { return (data.score && data.score.tooltip) || ''; } } }
    }));
  }


  function hydratePeriod(options, selected) {
    var el = document.getElementById('filtroPeriodo');
    if (!el) return;

    var fallbackLabel = 'Todos los periodos';
    var currentValue = selected || el.value || '';
    var html = ['<option value="">' + fallbackLabel + '</option>'];

    (options || []).forEach(function (value) {
      html.push('<option value="' + value + '">' + value + '</option>');
    });

    el.innerHTML = html.join('');
    el.value = currentValue;
  }

  function getFilters() {
    var periodoEl = document.getElementById('filtroPeriodo');
    var periodo = periodoEl ? periodoEl.value : '';

    console.log('Periodo seleccionado:', periodo);

    return {
      periodo: periodo
    };
  }

  function buildDashboardUrl() {
    var filters = getFilters();
    var url = new URL(endpointUrl, window.location.origin);

    if (filters.periodo) {
      url.searchParams.set('periodo', filters.periodo);
    }

    console.log('URL con periodo:', url.toString());

    return { filtros: filters, url: url.toString() };
  }

  function safelyHydrateFilters(payload) {
    if (!payload || !payload.filter_options) return;

    var selected = (payload.meta && payload.meta.selected_filters) || {};
    var options = payload.filter_options || {};

    hydratePeriod(options.periodo || [], selected.periodo || '');
    hydrateReadonlySelect('filtroRegional');
    hydrateReadonlySelect('filtroCanal');
    hydrateReadonlySelect('filtroEmpleado');
    hydrateReadonlySelect('filtroCliente');
    hydrateReadonlySelect('filtroUen');
  }

  function requestData() {
    var request = buildDashboardUrl();

    fetch(request.url, { headers: { 'Accept': 'application/json' } })
      .then(async function (response) {
        var data = await response.json();
        console.log('Response:', data);
        try {

          if (!data) {
            kpiGrid.innerHTML = '<article class="kpi-premium-card"><p class="kpi-premium-label">Error al cargar</p><p class="kpi-premium-subtext">No fue posible actualizar el dashboard. Intenta de nuevo.</p></article>';
            comparisonBox.textContent = '';
            fallbackNotice.textContent = '';
            updatedAtEl.textContent = 'Error de actualización';
            return;
          }

          safelyHydrateFilters(data);

          if (data.meta && data.meta.degraded_to_global) {
            fallbackNotice.textContent = 'Vista global aplicada automáticamente: algunos filtros sin valores (ej. UEN) fueron ignorados para evitar un dashboard vacío.';
          } else {
            fallbackNotice.textContent = '';
          }

          document.getElementById('dashboardExport').href = <?= json_encode(app_url('api/cartera/analisis-export.php')) ?>;

          if (data && data.kpis) {
            renderKpis(data.kpis);
          }

          if (data && data.charts) {
            renderCharts(data.charts);
          }

          updatedAtEl.textContent = 'Actualizado: ' + ((data.meta && data.meta.generated_at_human) || '--');

          if (data.comparison) {
            comparisonBox.innerHTML = 'Comparación periodo anterior (' + data.comparison.periodo_anterior.desde + ' a ' + data.comparison.periodo_anterior.hasta + '): ' +
              'Cartera ' + decimal.format(data.comparison.variacion_cartera_pct || 0) + '% | ' +
              'Mora ' + decimal.format(data.comparison.variacion_mora_pct || 0) + '% | ' +
              'Exposición crítica ' + decimal.format(data.comparison.variacion_exposicion_pct || 0) + '%';
          } else {
            comparisonBox.textContent = '';
          }
        } catch(e) {
          console.error('Error renderizando dashboard:', e);
        }
      })
      .catch(function () {
        kpiGrid.innerHTML = '<article class="kpi-premium-card"><p class="kpi-premium-label">Error al cargar</p><p class="kpi-premium-subtext">No fue posible actualizar el dashboard. Intenta de nuevo.</p></article>';
        comparisonBox.textContent = '';
        fallbackNotice.textContent = '';
        updatedAtEl.textContent = 'Error de actualización';
      });
  }

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    requestData();
  });

  form.addEventListener('change', function (e) {
    if (e.target && e.target.id === 'filtroPeriodo') {
      requestData();
    }
  });

  requestData();
})();
</script>
<?php
$content = ob_get_clean();
render_layout('Dashboard estratégico', $content);
