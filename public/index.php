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
      <div class="dashboard-filters-row dashboard-filters-row-primary">
        <label class="filter-field" for="filtroPeriodo">
          <span>Periodo</span>
          <select id="filtroPeriodo" name="periodo">
            <option value="">Cargando periodos...</option>
          </select>
        </label>
        <label class="filter-field" for="filtroUen">
          <span>UEN</span>
          <select id="filtroUen" name="uen">
            <option value="">Cargando UEN...</option>
          </select>
        </label>
      </div>
      <div class="dashboard-filters-row dashboard-filters-row-secondary">
        <label class="filter-field" for="regional">
          <span>Regional</span>
          <select id="regional" name="regional">
            <option value="">Cargando regionales...</option>
          </select>
        </label>
        <label class="filter-field" for="filtroCanal">
          <span>Canal</span>
          <select id="filtroCanal" name="canal">
            <option value="">Cargando canales...</option>
          </select>
        </label>
        <label class="filter-field" for="filtroEmpleado">
          <span>Empleado</span>
          <select id="filtroEmpleado" name="empleado_ventas">
            <option value="">Cargando empleados...</option>
          </select>
        </label>
        <label class="filter-field" for="filtroCliente">
          <span>Cliente</span>
          <select id="filtroCliente" name="cliente">
            <option value="">Cargando clientes...</option>
          </select>
        </label>
      </div>
      <div class="dashboard-filters-row dashboard-filters-row-dates">
        <label class="filter-field filter-field-date" for="filtroFechaDesde">
          <span>Fecha desde</span>
          <input id="filtroFechaDesde" name="fecha_desde" type="date">
        </label>
        <label class="filter-field filter-field-date" for="filtroFechaHasta">
          <span>Fecha hasta</span>
          <input id="filtroFechaHasta" name="fecha_hasta" type="date">
        </label>
        <label class="filter-field filter-field-toggle" for="filtroCompararAnterior">
          <span>Comparación</span>
          <span style="display:flex;align-items:center;gap:8px;padding:8px 0;">
            <input id="filtroCompararAnterior" name="comparar_anterior" type="checkbox" value="1">
            Comparar contra periodo anterior
          </span>
        </label>
        <label class="filter-field filter-field-toggle" for="filtroCompararPersonalizado">
          <span>Comparación</span>
          <span style="display:flex;align-items:center;gap:8px;padding:8px 0;">
            <input id="filtroCompararPersonalizado" name="comparar_personalizado" type="checkbox" value="1">
            Comparar contra periodo específico
          </span>
        </label>
        <label class="filter-field" for="filtroPeriodoComparacion">
          <span>Periodo de comparación</span>
          <select id="filtroPeriodoComparacion" name="comparar_periodo" disabled>
            <option value="">Automático (periodo anterior)</option>
          </select>
        </label>
      </div>
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
  function initDashboard() {
    var endpointUrl = <?= json_encode(app_url('api/dashboard-metrics/')) ?>;
    var form = document.getElementById('filtersForm') || document.getElementById('dashboardFilters');
    var periodoSelect = document.querySelector('#filtroPeriodo');
    var regionalSelect = document.querySelector('#regional');
    var uenSelect = document.querySelector('#filtroUen');
    var updatedAtEl = document.getElementById('dashboardUpdatedAt');
    var kpiGrid = document.getElementById('kpiGrid');
    var comparisonBox = document.getElementById('comparisonBox');
    var fallbackNotice = document.getElementById('dashboardFallbackNotice');
    var charts = {};
    var reloadDebounceMs = 500;
    var reloadTimeoutId = null;
    var pendingRequestController = null;

    if (!form) return;

  var currency = new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 });
  var decimal = new Intl.NumberFormat('es-CO', { minimumFractionDigits: 1, maximumFractionDigits: 1 });

  function f(v, unit) {
    var n = Number(v || 0);
    if (unit === 'currency') return currency.format(n);
    if (unit === 'percent') return decimal.format(n) + '%';
    if (unit === 'days') return decimal.format(n) + ' días';
    return decimal.format(n);
  }

  function escapeHtml(value) {
    return String(value == null ? '' : value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
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

  function hydrateDateInput(inputId, selected, fallbackValue) {
    var el = document.getElementById(inputId);
    if (!el) return;

    var currentValue = selected || el.value || fallbackValue || '';
    el.value = currentValue;
  }

  function renderKpis(kpis, emptyMessage) {
    if (!kpis || !kpis.length) {
      kpiGrid.innerHTML = '<article class="kpi-premium-card"><p class="kpi-premium-label">Sin datos</p><p class="kpi-premium-subtext">' + (emptyMessage || 'Sin datos para los filtros seleccionados') + '</p></article>';
      return;
    }
    kpiGrid.innerHTML = kpis.map(function (kpi) {
      var foot = kpi.message || kpi.tooltip || '';
      var emptyState = Boolean(kpi.empty_state);
      var cardClasses = 'kpi-premium-card kpi-status-' + (kpi.status || 'neutral') + (emptyState ? ' kpi-premium-card-empty' : '');
      var valueHtml = emptyState ? escapeHtml(kpi.empty_value_label || 'Sin datos') : escapeHtml(f(kpi.value, kpi.unit));
      var infoIcon = emptyState && kpi.empty_tooltip
        ? '<span class="kpi-inline-info" tabindex="0" title="' + escapeHtml(kpi.empty_tooltip) + '" aria-label="' + escapeHtml(kpi.empty_tooltip) + '"><i class="fa-solid fa-circle-info"></i></span>'
        : '';
      return '<article class="' + cardClasses + '" title="' + escapeHtml(kpi.tooltip || '') + '"><div class="kpi-premium-head"><p class="kpi-premium-label">' + escapeHtml(kpi.title) + '</p><span class="kpi-premium-icon"><i class="' + escapeHtml(kpi.icon || 'fa-solid fa-chart-line') + '"></i></span></div><p class="kpi-premium-value' + (emptyState ? ' is-empty' : '') + '">' + valueHtml + '</p><div class="kpi-premium-foot"><span class="kpi-premium-subtext">' + escapeHtml(foot) + '</span>' + infoIcon + '</div></article>';
    }).join('');
  }

  function renderCharts(data) {
    var aging = data.aging || [];
    var agingNegative = data.aging_negative || { bucket: 'Saldo negativo', value: 0, pct: 0 };
    var agingCategories = aging.map(function (r) { return r.bucket; }).concat([agingNegative.bucket || 'Saldo negativo']);
    var agingSeriesValues = aging.map(function (r) { return r.value; }).concat([0]);
    var agingPctValues = aging.map(function (r) { return Math.max(0, r.pct || 0); }).concat([0]);
    var agingNegativeSeries = aging.map(function () { return 0; }).concat([agingNegative.value || 0]);
    upsert('aging', 'agingChart', Object.assign(noDataOptions(280), {
      chart: { type: 'bar', height: 280 },
      series: [
        { name: 'Saldo', data: agingSeriesValues, color: '#2563EB' },
        { name: 'Saldo negativo', data: agingNegativeSeries, color: '#F97316' },
        { name: '%', data: agingPctValues, type: 'line', color: '#0F172A' }
      ],
      xaxis: { categories: agingCategories },
      yaxis: [
        { labels: { formatter: function (v) { return currency.format(v); } } },
        { opposite: true, min: 0, forceNiceScale: true, labels: { formatter: function (v) { return decimal.format(Math.max(0, v)) + '%'; } } }
      ],
      stroke: { width: [0, 0, 3] },
      dataLabels: { enabled: false },
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
    document.getElementById('dependenciaClienteMayor').innerHTML = '<div style="padding:16px"><h4 style="margin:0 0 8px">' + escapeHtml(dep.cliente) + '</h4><p style="margin:0;font-size:22px;font-weight:700">' + decimal.format(dep.pct || 0) + '%</p><p style="margin:4px 0 0">' + currency.format(dep.saldo || 0) + '</p></div>';

    var scoreData = data.score || {};
    var score = scoreData.value || 0;
    var scoreContainer = document.getElementById('scoreChart');
    if (scoreContainer) {
      if (charts.score) {
        charts.score.destroy();
        delete charts.score;
      }
      scoreContainer.innerHTML = '<div class="score-chart-layout"><div id="scoreChartViz" class="score-chart-viz"></div><div class="score-drivers"><p class="score-drivers-title">' + (score > 80 ? 'Fortalezas principales' : 'Factores que afectan el score') + '</p><ul class="score-driver-list">' + ((scoreData.drivers || []).map(function (driver) {
        var isStrength = driver.kind === 'strength';
        return '<li class="score-driver-item ' + (isStrength ? 'is-strength' : 'is-risk') + '"><span class="score-driver-icon">' + (isStrength ? '✓' : '⚠') + '</span><span>' + escapeHtml(driver.label || '') + '</span></li>';
      }).join('') || '<li class="score-driver-item"><span>No hay factores disponibles.</span></li>') + '</ul></div></div>';
    }
    upsert('score', 'scoreChartViz', Object.assign(noDataOptions(220), {
      chart: { type: 'radialBar', height: 300 },
      series: [score],
      labels: [scoreData.label || 'Score General'],
      plotOptions: { radialBar: { dataLabels: { value: { formatter: function (v) { return decimal.format(v); } } } } },
      tooltip: { enabled: true, y: { formatter: function () { return scoreData.tooltip || ''; } } }
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

  function getSelectedOptionValue(selected) {
    if (Array.isArray(selected)) {
      return selected[0] || '';
    }

    return selected || '';
  }

  function hydrateSelectableFilter(selectId, options, selected, fallbackLabel) {
    var el = document.getElementById(selectId);
    if (!el) return;

    var currentValue = getSelectedOptionValue(selected) || el.value || '';
    var html = ['<option value="">' + fallbackLabel + '</option>'];

    (options || []).forEach(function (value) {
      html.push('<option value="' + value + '">' + value + '</option>');
    });

    el.innerHTML = html.join('');
    el.value = currentValue;

    if (el.value !== currentValue) {
      el.value = '';
    }

    el.disabled = false;
  }

  function hydrateRegional(options, selected) {
    hydrateSelectableFilter('regional', options, selected, 'Todas las regionales');
  }

  function hydrateCanal(options, selected) {
    hydrateSelectableFilter('filtroCanal', options, selected, 'Todos los canales');
  }

  function hydrateEmpleado(options, selected) {
    hydrateSelectableFilter('filtroEmpleado', options, selected, 'Todos los empleados');
  }

  function hydrateCliente(options, selected) {
    hydrateSelectableFilter('filtroCliente', options, selected, 'Todos los clientes');
  }

  function hydrateUen(options, selected) {
    hydrateSelectableFilter('filtroUen', options, selected, 'Todas las UEN');
  }

  function getFilterValue(selectId) {
    var el = document.getElementById(selectId);
    return el ? String(el.value || '').trim() : '';
  }

  function getFilters() {
    var filters = {
      periodo: getFilterValue('filtroPeriodo'),
      uen: getFilterValue('filtroUen'),
      fechaDesde: getFilterValue('filtroFechaDesde'),
      fechaHasta: getFilterValue('filtroFechaHasta'),
      compararAnterior: Boolean(document.getElementById('filtroCompararAnterior') && document.getElementById('filtroCompararAnterior').checked),
      compararPersonalizado: Boolean(document.getElementById('filtroCompararPersonalizado') && document.getElementById('filtroCompararPersonalizado').checked),
      compararPeriodo: getFilterValue('filtroPeriodoComparacion'),
      regional: getFilterValue('regional'),
      canal: getFilterValue('filtroCanal'),
      empleado: getFilterValue('filtroEmpleado'),
      cliente: getFilterValue('filtroCliente')
    };

    console.log('Filtros activos:', {
      periodo: filters.periodo,
      uen: filters.uen,
      fechaDesde: filters.fechaDesde,
      fechaHasta: filters.fechaHasta,
      compararAnterior: filters.compararAnterior,
      compararPersonalizado: filters.compararPersonalizado,
      compararPeriodo: filters.compararPeriodo,
      regional: filters.regional,
      canal: filters.canal,
      empleado: filters.empleado,
      cliente: filters.cliente
    });

    return filters;
  }

  function appendFilter(searchParams, key, value) {
    if (value) {
      searchParams.set(key, value);
    }
  }

  function buildDashboardUrl() {
    var filters = getFilters();
    var url = new URL(endpointUrl, window.location.origin);

    appendFilter(url.searchParams, 'periodo', filters.periodo);
    appendFilter(url.searchParams, 'uen', filters.uen);
    appendFilter(url.searchParams, 'fecha_desde', filters.fechaDesde);
    appendFilter(url.searchParams, 'fecha_hasta', filters.fechaHasta);
    if (filters.compararAnterior) {
      url.searchParams.set('comparar_anterior', '1');
    }
    if (filters.compararPersonalizado) {
      url.searchParams.set('comparar_personalizado', '1');
      appendFilter(url.searchParams, 'comparar_periodo', filters.compararPeriodo);
    }
    appendFilter(url.searchParams, 'regional', filters.regional);
    appendFilter(url.searchParams, 'canal', filters.canal);
    appendFilter(url.searchParams, 'empleado_ventas', filters.empleado);
    appendFilter(url.searchParams, 'cliente', filters.cliente);

    console.log('URL con filtros:', url.toString());

    return { filtros: filters, url: url.toString() };
  }

  function safelyHydrateFilters(payload) {
    if (!payload || !payload.filter_options) return;

    var selected = (payload.meta && payload.meta.selected_filters) || {};
    var options = payload.filter_options || {};

    hydratePeriod(options.periodo || [], selected.periodo || '');
    hydrateDateInput('filtroFechaDesde', selected.fecha_desde || '', options.fecha_desde || '');
    hydrateDateInput('filtroFechaHasta', selected.fecha_hasta || '', options.fecha_hasta || '');
    var compararAnterior = document.getElementById('filtroCompararAnterior');
    var compararPersonalizado = document.getElementById('filtroCompararPersonalizado');
    var periodoComparacion = document.getElementById('filtroPeriodoComparacion');
    if (compararAnterior) {
      compararAnterior.checked = Boolean(selected.comparar_anterior);
    }
    if (compararPersonalizado) {
      compararPersonalizado.checked = Boolean(selected.comparar_personalizado);
    }
    if (periodoComparacion) {
      var periodHtml = ['<option value="">Automático (periodo anterior)</option>'];
      (options.periodo || []).forEach(function (value) {
        periodHtml.push('<option value="' + value + '">' + value + '</option>');
      });
      periodoComparacion.innerHTML = periodHtml.join('');
      periodoComparacion.value = selected.comparar_periodo || '';
      periodoComparacion.disabled = !(compararPersonalizado && compararPersonalizado.checked);
    }
    hydrateRegional(options.regional || [], selected.regional || '');
    hydrateCanal(options.canal || [], selected.canal || '');
    hydrateEmpleado(options.empleado_ventas || [], selected.empleado_ventas || '');
    hydrateCliente(options.cliente || [], selected.cliente || '');
    hydrateUen(options.uen || [], selected.uen || '');
  }

  function requestData() {
    var request = buildDashboardUrl();

    if (pendingRequestController) {
      pendingRequestController.abort();
    }

    pendingRequestController = new AbortController();
    var currentRequestController = pendingRequestController;

    fetch(request.url, {
      headers: { 'Accept': 'application/json' },
      signal: currentRequestController.signal
    })
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
            var comparisonLabel = (data.comparison.periodo_anterior && data.comparison.periodo_anterior.label) || 'periodo anterior';
            comparisonBox.innerHTML = 'Comparación contra ' + comparisonLabel + ': ' +
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
      .catch(function (error) {
        if (error && error.name === 'AbortError') {
          return;
        }

        kpiGrid.innerHTML = '<article class="kpi-premium-card"><p class="kpi-premium-label">Error al cargar</p><p class="kpi-premium-subtext">No fue posible actualizar el dashboard. Intenta de nuevo.</p></article>';
        comparisonBox.textContent = '';
        fallbackNotice.textContent = '';
        updatedAtEl.textContent = 'Error de actualización';
      })
      .finally(function () {
        if (pendingRequestController === currentRequestController) {
          pendingRequestController = null;
        }
      });
  }

  function scheduleRequestData() {
    if (reloadTimeoutId) {
      clearTimeout(reloadTimeoutId);
    }

    reloadTimeoutId = window.setTimeout(function () {
      reloadTimeoutId = null;
      requestData();
    }, reloadDebounceMs);
  }

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      scheduleRequestData();
    });

    if (periodoSelect) {
      periodoSelect.addEventListener('change', function () {
        console.log('Cambio de periodo detectado:', this.value);
        scheduleRequestData();
      });
    }

    if (regionalSelect) {
      regionalSelect.addEventListener('change', function () {
        console.log('Cambio de regional detectado:', this.value);
        scheduleRequestData();
      });
    }

    if (uenSelect) {
      uenSelect.addEventListener('change', function () {
        console.log('Cambio de UEN detectado:', this.value);
        scheduleRequestData();
      });
    }

    ['filtroFechaDesde', 'filtroFechaHasta', 'filtroCanal', 'filtroEmpleado', 'filtroCliente', 'filtroCompararAnterior', 'filtroCompararPersonalizado'].forEach(function (filterId) {
      var el = document.getElementById(filterId);
      if (!el) return;
      el.addEventListener('change', function () {
        if (filterId === 'filtroCompararAnterior') {
          var personalizedEl = document.getElementById('filtroCompararPersonalizado');
          var comparisonPeriodEl = document.getElementById('filtroPeriodoComparacion');
          if (this.checked && personalizedEl) {
            personalizedEl.checked = false;
          }
          if (comparisonPeriodEl && (!personalizedEl || !personalizedEl.checked)) {
            comparisonPeriodEl.disabled = true;
          }
        }
        if (filterId === 'filtroCompararPersonalizado') {
          var previousEl = document.getElementById('filtroCompararAnterior');
          var periodEl = document.getElementById('filtroPeriodoComparacion');
          if (this.checked && previousEl) {
            previousEl.checked = false;
          }
          if (periodEl) {
            periodEl.disabled = !this.checked;
            if (this.checked && !periodEl.value) {
              periodEl.focus();
            }
          }
        }
        console.log('Cambio de filtro detectado:', filterId, this.value);
        scheduleRequestData();
      });
    });

    var comparisonPeriodSelect = document.getElementById('filtroPeriodoComparacion');
    if (comparisonPeriodSelect) {
      comparisonPeriodSelect.addEventListener('change', function () {
        scheduleRequestData();
      });
    }


    requestData();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initDashboard);
  } else {
    initDashboard();
  }
})();
</script>
<?php
$content = ob_get_clean();
render_layout('Dashboard estratégico', $content);
