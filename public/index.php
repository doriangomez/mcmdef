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
    <form id="dashboardFilters" class="dashboard-filters" autocomplete="off" style="display:none;">
      <select name="vista" id="filterVista"><option value="ejecutivo" selected>Dashboard ejecutivo</option><option value="operativo">Dashboard operativo</option></select>
      <select name="periodo" id="filterPeriodo"></select>
      <input type="date" name="fecha_desde" id="filterFechaDesde" readonly>
      <input type="date" name="fecha_hasta" id="filterFechaHasta" readonly>
      <select name="uen[]" id="filterUens" multiple></select>
      <select name="regional" id="filterRegional"><option value="">Todas las regionales</option></select>
      <select name="canal" id="filterCanal"><option value="">Todos los canales</option></select>
      <select name="empleado_ventas" id="filterEmpleado"><option value="">Todos los asesores</option></select>
      <select name="cliente" id="filterCliente"><option value="">Todos los clientes</option></select>
      <input type="checkbox" name="comparar_anterior" value="1" id="filterComparar">
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
  var uensEndpointUrl = <?= json_encode(app_url('api/uens/')) ?>;
  var form = document.getElementById('dashboardFilters');
  var clearButton = document.getElementById('dashboardClear');
  var updatedAtEl = document.getElementById('dashboardUpdatedAt');
  var kpiGrid = document.getElementById('kpiGrid');
  var comparisonBox = document.getElementById('comparisonBox');
  var fallbackNotice = document.getElementById('dashboardFallbackNotice');
  var selectAllUensBtn = document.getElementById('selectAllUens');
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
    if (charts[key]) charts[key].updateOptions(options, false, true, true);
    else { charts[key] = new ApexCharts(document.getElementById(id), options); charts[key].render(); }
  }

  function hydrateSelect(selectId, values, selected) {
    var el = document.getElementById(selectId);
    if (!el) return;
    if (el.multiple) {
      el.innerHTML = (values || []).map(function (v) { return '<option value="' + v + '">' + v + '</option>'; }).join('');
      var selectedValues = Array.isArray(selected) ? selected : [];
      if (!selectedValues.length && values && values.length) selectedValues = values.slice();
      Array.prototype.forEach.call(el.options, function (opt) { opt.selected = selectedValues.indexOf(opt.value) !== -1; });
      return;
    }
    var ph = el.getAttribute('data-placeholder');
    var html = ['<option value="">' + ph + '</option>'];
    (values || []).forEach(function (v) { html.push('<option value="' + v + '">' + v + '</option>'); });
    el.innerHTML = html.join('');
    el.value = selected || '';
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
      tooltip: { shared: true }
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
      tooltip: { shared: true }
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
    var el = document.getElementById('filterPeriodo');
    if (!el) return;
    var prev = selected || el.value;
    el.innerHTML = '';
    (options || []).forEach(function (value) {
      var option = document.createElement('option');
      option.value = value;
      option.textContent = value;
      if (value === prev) option.selected = true;
      el.appendChild(option);
    });
  }

  function loadUensByPeriod(periodo, selected) {
    var el = document.getElementById('filterUens');
    if (!periodo) {
      hydrateSelect('filterUens', [], []);
      return Promise.resolve([]);
    }

    return fetch(uensEndpointUrl + '?periodo=' + encodeURIComponent(periodo), { headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.json(); })
      .then(function (payload) {
        var uens = (payload && payload.uens) || [];
        var selectedUens = Array.isArray(selected) && selected.length ? selected : uens;
        hydrateSelect('filterUens', uens, selectedUens);
        if (uens.length === 0) {
          el.innerHTML = '';
          el.required = false;
        } else {
          el.required = false;
        }
        return uens;
      });
  }

  function buildDashboardQuery() {
    var params = new URLSearchParams();
    var vistaEl = document.getElementById('filterVista');
    var periodoEl = document.getElementById('filterPeriodo');
    var fechaDesdeEl = document.getElementById('filterFechaDesde');
    var fechaHastaEl = document.getElementById('filterFechaHasta');
    var regionalEl = document.getElementById('filterRegional');
    var canalEl = document.getElementById('filterCanal');
    var empleadoEl = document.getElementById('filterEmpleado');
    var clienteEl = document.getElementById('filterCliente');
    var compararEl = document.getElementById('filterComparar');
    var uenEl = document.getElementById('filterUens');

    if (vistaEl && vistaEl.value) params.set('vista', vistaEl.value);
    if (periodoEl && periodoEl.value) params.set('periodo', periodoEl.value);
    if (fechaDesdeEl && fechaDesdeEl.value) params.set('fecha_desde', fechaDesdeEl.value);
    if (fechaHastaEl && fechaHastaEl.value) params.set('fecha_hasta', fechaHastaEl.value);
    if (regionalEl && regionalEl.value) params.set('regional', regionalEl.value);
    if (canalEl && canalEl.value) params.set('canal', canalEl.value);
    if (empleadoEl && empleadoEl.value) params.set('empleado_ventas', empleadoEl.value);
    if (clienteEl && clienteEl.value) params.set('cliente', clienteEl.value);
    if (compararEl && compararEl.checked) params.set('comparar_anterior', '1');

    if (uenEl && uenEl.options) {
      Array.prototype.forEach.call(uenEl.options, function (opt) {
        if (opt.selected && opt.value) params.append('uen[]', opt.value);
      });
    }

    return params.toString();
  }

  function safelyHydrateFilters(payload) {
    if (!payload || !payload.filter_options || !payload.meta || !payload.meta.selected_filters) return;

    var selected = payload.meta.selected_filters || {};
    var options = payload.filter_options || {};
    hydrateSelect('filterRegional', options.regional, selected.regional);
    hydrateSelect('filterCanal', options.canal, selected.canal);
    hydrateSelect('filterEmpleado', options.empleado_ventas, selected.empleado_ventas);
    hydrateSelect('filterCliente', options.cliente, selected.cliente);
    hydratePeriod(options.periodo || [], selected.periodo || '');
    document.getElementById('filterPeriodo').value = selected.periodo || '';
    loadUensByPeriod(selected.periodo || '', selected.uen || []);
    document.getElementById('filterFechaDesde').value = selected.fecha_desde || options.fecha_desde || '';
    document.getElementById('filterFechaHasta').value = selected.fecha_hasta || options.fecha_hasta || '';
    document.getElementById('filterComparar').checked = !!selected.comparar_anterior;
    document.getElementById('filterVista').value = selected.vista || 'ejecutivo';
  }

  function requestData() {
    var query = buildDashboardQuery();
    var url = query ? endpointUrl + '?' + query : endpointUrl;
    fetch(url, { headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        console.log('Dashboard data:', data);

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
        renderKpis(data.kpis);
        renderCharts(data.charts);
        updatedAtEl.textContent = 'Actualizado: ' + ((data.meta && data.meta.generated_at_human) || '--');

        if (data.comparison) {
          comparisonBox.innerHTML = 'Comparación periodo anterior (' + data.comparison.periodo_anterior.desde + ' a ' + data.comparison.periodo_anterior.hasta + '): ' +
            'Cartera ' + decimal.format(data.comparison.variacion_cartera_pct || 0) + '% | ' +
            'Mora ' + decimal.format(data.comparison.variacion_mora_pct || 0) + '% | ' +
            'Exposición crítica ' + decimal.format(data.comparison.variacion_exposicion_pct || 0) + '%';
        } else {
          comparisonBox.textContent = '';
        }
      })
      .catch(function () {
        kpiGrid.innerHTML = '<article class="kpi-premium-card"><p class="kpi-premium-label">Error al cargar</p><p class="kpi-premium-subtext">No fue posible actualizar el dashboard. Intenta de nuevo.</p></article>';
        comparisonBox.textContent = '';
        fallbackNotice.textContent = '';
        updatedAtEl.textContent = 'Error de actualización';
      });
  }

  form.addEventListener('submit', function (e) { e.preventDefault(); requestData(); });
  form.addEventListener('change', function (e) {
    if (e.target && e.target.id === 'filterPeriodo') {
      loadUensByPeriod(e.target.value).then(function () { requestData(); });
      return;
    }
    requestData();
  });
  if (selectAllUensBtn) {
    selectAllUensBtn.addEventListener('click', function () {
      var el = document.getElementById('filterUens');
      Array.prototype.forEach.call(el.options, function (opt) { opt.selected = true; });
      requestData();
    });
  }
  if (clearButton) {
    clearButton.addEventListener('click', function () {
      form.reset();
      loadUensByPeriod(document.getElementById('filterPeriodo').value).then(function () {
        Array.prototype.forEach.call(document.getElementById('filterUens').options, function (opt) { opt.selected = true; });
        requestData();
      });
    });
  }
  requestData();
})();
</script>
<?php
$content = ob_get_clean();
render_layout('Dashboard estratégico', $content);
