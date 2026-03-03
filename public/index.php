<?php
require_once __DIR__ . '/../app/middlewares/require_auth.php';
require_once __DIR__ . '/../app/views/layout.php';

ob_start();
?>
<section class="card dashboard-hero strategic-hero">
  <div class="hero-main">
    <p class="hero-kicker">MCM | Inteligencia financiera</p>
    <h2 class="hero-title">Panel Estratégico de Riesgo de Cartera</h2>
    <p class="hero-copy">
      Visualiza exposición, mora, concentración y dispersión territorial con lectura ejecutiva de riesgo.
    </p>
  </div>
  <div class="hero-controls">
    <form id="dashboardFilters" class="dashboard-filters" autocomplete="off">
      <label class="filter-field">
        <span>Periodo</span>
        <select name="periodo" id="filterPeriodo" data-placeholder="Todos los periodos">
          <option value="">Todos los periodos</option>
        </select>
      </label>
      <label class="filter-field">
        <span>Regional</span>
        <select name="regional" id="filterRegional" data-placeholder="Todas las regionales">
          <option value="">Todas las regionales</option>
        </select>
      </label>
      <label class="filter-field">
        <span>Canal</span>
        <select name="canal" id="filterCanal" data-placeholder="Todos los canales">
          <option value="">Todos los canales</option>
        </select>
      </label>
      <label class="filter-field">
        <span>UEN</span>
        <select name="uen" id="filterUen" data-placeholder="Todas las UEN">
          <option value="">Todas las UEN</option>
        </select>
      </label>
      <div class="filter-actions">
        <button type="submit" class="btn">Aplicar</button>
        <button type="button" class="btn btn-secondary" id="dashboardClear">Limpiar</button>
        <button type="button" class="btn btn-secondary" id="dashboardRefresh">
          <i class="fa-solid fa-rotate"></i>
          <span>Actualizar</span>
        </button>
      </div>
    </form>
    <div class="hero-meta">
      <span class="risk-chip risk-chip-neutral" id="riskLevelChip">Nivel de riesgo: --</span>
      <span class="dashboard-updated-at" id="dashboardUpdatedAt">Sin actualizar</span>
    </div>
  </div>
</section>

<section class="card risk-intel-strip">
  <div class="risk-intel-head">
    <h3>Radar de inteligencia financiera</h3>
    <p id="riskNarrative">Analizando exposición y focos de riesgo...</p>
  </div>
  <div class="risk-intel-metrics">
    <div>
      <span>Índice de mora</span>
      <strong id="riskOverdueRatio">--</strong>
    </div>
    <div>
      <span>Concentración Top 3</span>
      <strong id="riskTop3">--</strong>
    </div>
    <div>
      <span>Bucket dominante</span>
      <strong id="riskBucket">--</strong>
    </div>
  </div>
</section>

<section class="kpi-premium-grid" id="kpiGrid">
  <?php for ($index = 0; $index < 6; $index++): ?>
    <article class="kpi-premium-card is-loading">
      <div class="kpi-premium-head">
        <p class="kpi-premium-label skeleton-line">Indicador</p>
        <span class="kpi-premium-icon"><i class="fa-solid fa-chart-line"></i></span>
      </div>
      <p class="kpi-premium-value skeleton-line">--</p>
      <div class="kpi-premium-foot">
        <span class="kpi-premium-trend skeleton-line"><i class="fa-solid fa-minus"></i> 0%</span>
        <span class="kpi-premium-subtext skeleton-line">vs periodo previo</span>
      </div>
    </article>
  <?php endfor; ?>
</section>

<section class="dashboard-panels-grid">
  <article class="card chart-card chart-card-main">
    <div class="card-header">
      <h3>Tendencia de exposición (6 meses)</h3>
      <span class="chart-caption">Evolución total vs cartera en mora</span>
    </div>
    <div class="chart-shell is-loading" id="trendShell">
      <div class="chart-skeleton"></div>
      <div id="trendChart" class="chart-area chart-area-main"></div>
    </div>
  </article>

  <article class="card chart-card">
    <div class="card-header">
      <h3>Distribución por mora (Aging)</h3>
      <span class="chart-caption">Composición de saldos por tramo</span>
    </div>
    <div class="chart-shell is-loading" id="agingShell">
      <div class="chart-skeleton"></div>
      <div id="agingChart" class="chart-area chart-area-sm"></div>
    </div>
  </article>

  <article class="card chart-card">
    <div class="card-header">
      <h3>Pareto de concentración clientes</h3>
      <span class="chart-caption">Impacto acumulado de principales deudores</span>
    </div>
    <div class="chart-shell is-loading" id="paretoShell">
      <div class="chart-skeleton"></div>
      <div id="paretoChart" class="chart-area chart-area-sm"></div>
    </div>
  </article>

  <article class="card chart-card chart-card-wide">
    <div class="card-header">
      <h3>Heatmap regional / canal</h3>
      <span class="chart-caption">Concentración territorial y comercial</span>
    </div>
    <div class="chart-shell is-loading" id="heatmapShell">
      <div class="chart-skeleton"></div>
      <div id="heatmapChart" class="chart-area chart-area-medium"></div>
    </div>
  </article>

  <article class="card chart-card">
    <div class="card-header">
      <h3>Top 10 exposición</h3>
      <span class="chart-caption">Mayor saldo total por cliente</span>
    </div>
    <div class="chart-shell is-loading" id="topExposureShell">
      <div class="chart-skeleton"></div>
      <div id="topExposureChart" class="chart-area chart-area-sm"></div>
    </div>
    <div class="mini-table-wrap">
      <table class="mini-table">
        <thead>
          <tr><th>Cliente</th><th>Exposición</th></tr>
        </thead>
        <tbody id="topExposureBody"></tbody>
      </table>
    </div>
  </article>

  <article class="card chart-card">
    <div class="card-header">
      <h3>Top 10 mora</h3>
      <span class="chart-caption">Mayor saldo vencido por cliente</span>
    </div>
    <div class="chart-shell is-loading" id="topMoraShell">
      <div class="chart-skeleton"></div>
      <div id="topMoraChart" class="chart-area chart-area-sm"></div>
    </div>
    <div class="mini-table-wrap">
      <table class="mini-table">
        <thead>
          <tr><th>Cliente</th><th>Mora</th><th>Días prom.</th></tr>
        </thead>
        <tbody id="topMoraBody"></tbody>
      </table>
    </div>
  </article>
</section>

<script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
<script>
  (function () {
    var endpointUrl = <?= json_encode(app_url('api/dashboard-metrics/')) ?>;
    var filtersForm = document.getElementById('dashboardFilters');
    var clearButton = document.getElementById('dashboardClear');
    var refreshButton = document.getElementById('dashboardRefresh');
    var updatedAtEl = document.getElementById('dashboardUpdatedAt');
    var riskLevelChip = document.getElementById('riskLevelChip');
    var riskNarrative = document.getElementById('riskNarrative');
    var riskOverdueRatio = document.getElementById('riskOverdueRatio');
    var riskTop3 = document.getElementById('riskTop3');
    var riskBucket = document.getElementById('riskBucket');
    var topExposureBody = document.getElementById('topExposureBody');
    var topMoraBody = document.getElementById('topMoraBody');
    var kpiCards = Array.prototype.slice.call(document.querySelectorAll('.kpi-premium-card'));
    var chartShells = {
      trend: document.getElementById('trendShell'),
      aging: document.getElementById('agingShell'),
      pareto: document.getElementById('paretoShell'),
      heatmap: document.getElementById('heatmapShell'),
      topExposure: document.getElementById('topExposureShell'),
      topMora: document.getElementById('topMoraShell')
    };

    var charts = {};
    var activeRequest = null;
    var isHydratingFilters = false;
    var debounceTimer = null;
    var autoRefreshTimer = null;

    var currencyFormatter = new Intl.NumberFormat('es-CO', {
      style: 'currency',
      currency: 'COP',
      maximumFractionDigits: 0
    });
    var numberFormatter = new Intl.NumberFormat('es-CO', {
      maximumFractionDigits: 0
    });
    var decimalFormatter = new Intl.NumberFormat('es-CO', {
      minimumFractionDigits: 1,
      maximumFractionDigits: 1
    });

    function escapeHtml(value) {
      return String(value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
    }

    function formatPeriod(period) {
      var match = /^(\d{4})-(\d{2})$/.exec(String(period || ''));
      if (!match) {
        return String(period || '');
      }
      var year = match[1];
      var monthIndex = Number(match[2]) - 1;
      var monthNames = ['Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'];
      if (monthIndex < 0 || monthIndex > 11) {
        return String(period);
      }
      return monthNames[monthIndex] + ' ' + year;
    }

    function formatValue(value, unit) {
      if (unit === 'currency') {
        return currencyFormatter.format(Number(value || 0));
      }
      if (unit === 'percent') {
        return decimalFormatter.format(Number(value || 0)) + '%';
      }
      if (unit === 'days') {
        return decimalFormatter.format(Number(value || 0)) + ' días';
      }
      return numberFormatter.format(Number(value || 0));
    }

    function formatVariation(value) {
      var safe = Number(value || 0);
      return (safe >= 0 ? '+' : '') + decimalFormatter.format(safe) + '%';
    }

    function renderTableSkeleton(target, columns) {
      var rows = [];
      for (var i = 0; i < 4; i++) {
        var cells = [];
        for (var c = 0; c < columns; c++) {
          cells.push('<td><span class="skeleton-line skeleton-inline"></span></td>');
        }
        rows.push('<tr class="loading-row">' + cells.join('') + '</tr>');
      }
      target.innerHTML = rows.join('');
    }

    function setLoadingState(isLoading) {
      kpiCards.forEach(function (card) {
        card.classList.toggle('is-loading', isLoading);
      });
      Object.keys(chartShells).forEach(function (key) {
        if (chartShells[key]) {
          chartShells[key].classList.toggle('is-loading', isLoading);
        }
      });
      if (isLoading) {
        renderTableSkeleton(topExposureBody, 2);
        renderTableSkeleton(topMoraBody, 3);
      }
    }

    function updateSelectOptions(selectEl, options, selectedValue) {
      var placeholder = selectEl.getAttribute('data-placeholder') || 'Todos';
      var values = Array.isArray(options) ? options.slice() : [];

      if (selectedValue && values.indexOf(selectedValue) === -1) {
        values.unshift(selectedValue);
      }

      var html = ['<option value="">' + escapeHtml(placeholder) + '</option>'];
      values.forEach(function (optionValue) {
        var label = selectEl.name === 'periodo' ? formatPeriod(optionValue) : optionValue;
        html.push('<option value="' + escapeHtml(optionValue) + '">' + escapeHtml(label) + '</option>');
      });
      selectEl.innerHTML = html.join('');
      selectEl.value = selectedValue || '';
    }

    function hydrateFilters(filterOptions, selectedFilters) {
      isHydratingFilters = true;
      updateSelectOptions(document.getElementById('filterPeriodo'), filterOptions.periodo || [], selectedFilters.periodo || '');
      updateSelectOptions(document.getElementById('filterRegional'), filterOptions.regional || [], selectedFilters.regional || '');
      updateSelectOptions(document.getElementById('filterCanal'), filterOptions.canal || [], selectedFilters.canal || '');
      updateSelectOptions(document.getElementById('filterUen'), filterOptions.uen || [], selectedFilters.uen || '');
      isHydratingFilters = false;
    }

    function renderRiskInsight(insight) {
      var level = String(insight.risk_level || 'Controlado');
      var levelClass = 'risk-chip-neutral';
      if (level === 'Alto') {
        levelClass = 'risk-chip-high';
      } else if (level === 'Moderado') {
        levelClass = 'risk-chip-medium';
      } else if (level === 'Controlado') {
        levelClass = 'risk-chip-low';
      }

      riskLevelChip.className = 'risk-chip ' + levelClass;
      riskLevelChip.textContent = 'Nivel de riesgo: ' + level;
      riskNarrative.textContent = insight.narrative || 'Sin hallazgos relevantes para los filtros seleccionados.';
      riskOverdueRatio.textContent = decimalFormatter.format(Number(insight.overdue_ratio || 0)) + '%';
      riskTop3.textContent = decimalFormatter.format(Number(insight.top3_concentration || 0)) + '%';
      riskBucket.textContent = insight.dominant_aging_bucket || '--';
    }

    function renderKpis(kpis, meta) {
      kpiCards.forEach(function (card, index) {
        var kpi = kpis[index];
        if (!kpi) {
          return;
        }

        var trendClass = 'kpi-trend-neutral';
        var trendIcon = 'fa-solid fa-minus';
        if (kpi.direction === 'up') {
          trendClass = kpi.is_improving ? 'kpi-trend-positive' : 'kpi-trend-negative';
          trendIcon = 'fa-solid fa-arrow-trend-up';
        } else if (kpi.direction === 'down') {
          trendClass = kpi.is_improving ? 'kpi-trend-positive' : 'kpi-trend-negative';
          trendIcon = 'fa-solid fa-arrow-trend-down';
        }

        card.querySelector('.kpi-premium-label').textContent = kpi.title;
        card.querySelector('.kpi-premium-icon').innerHTML = '<i class="' + escapeHtml(kpi.icon || 'fa-solid fa-chart-line') + '"></i>';
        card.querySelector('.kpi-premium-value').textContent = formatValue(kpi.value, kpi.unit);

        var trendEl = card.querySelector('.kpi-premium-trend');
        trendEl.className = 'kpi-premium-trend ' + trendClass;
        trendEl.innerHTML = '<i class="' + trendIcon + '"></i> ' + formatVariation(kpi.variation_pct);

        var versus = meta.previous_period ? ('vs ' + formatPeriod(meta.previous_period)) : 'sin comparativo previo';
        card.querySelector('.kpi-premium-subtext').textContent = kpi.subtitle + ' · ' + versus;

        card.classList.remove('is-loading');
      });
    }

    function upsertChart(chartKey, elementId, options) {
      var target = document.getElementById(elementId);
      if (!target || typeof ApexCharts === 'undefined') {
        return;
      }

      if (charts[chartKey]) {
        charts[chartKey].updateOptions(options, false, true, true);
      } else {
        charts[chartKey] = new ApexCharts(target, options);
        charts[chartKey].render();
      }
    }

    function chartBase(height) {
      return {
        chart: {
          height: height,
          toolbar: { show: false },
          animations: { enabled: true, speed: 500, animateGradually: { enabled: true, delay: 100 } },
          fontFamily: 'Inter, sans-serif'
        },
        noData: {
          text: 'Sin datos para los filtros seleccionados.',
          align: 'center',
          verticalAlign: 'middle',
          style: { color: '#64748B', fontSize: '13px' }
        },
        legend: {
          labels: { colors: '#334155' }
        },
        grid: {
          borderColor: 'rgba(148, 163, 184, 0.22)'
        },
        dataLabels: { enabled: false },
        tooltip: { theme: 'light' }
      };
    }

    function renderCharts(chartData) {
      var trendCategories = (chartData.trend && chartData.trend.categories ? chartData.trend.categories : []).map(formatPeriod);
      upsertChart('trend', 'trendChart', Object.assign(chartBase(340), {
        chart: Object.assign(chartBase(340).chart, { type: 'area' }),
        colors: ['#1E5AA8', '#EF4444'],
        stroke: { width: [3, 3], curve: 'smooth' },
        fill: {
          type: 'gradient',
          gradient: { shadeIntensity: 0.4, opacityFrom: 0.32, opacityTo: 0.02, stops: [0, 90, 100] }
        },
        series: chartData.trend ? chartData.trend.series : [],
        xaxis: {
          categories: trendCategories,
          labels: { style: { colors: '#64748B' } }
        },
        yaxis: {
          labels: {
            formatter: function (value) { return currencyFormatter.format(value || 0); },
            style: { colors: '#64748B' }
          }
        }
      }));

      upsertChart('aging', 'agingChart', Object.assign(chartBase(300), {
        chart: Object.assign(chartBase(300).chart, { type: 'donut' }),
        colors: ['#22C55E', '#1E5AA8', '#0EA5A6', '#F59E0B', '#F97316', '#EF4444'],
        labels: chartData.aging ? chartData.aging.labels : [],
        series: chartData.aging ? chartData.aging.values : [],
        plotOptions: {
          pie: {
            donut: {
              size: '63%',
              labels: {
                show: true,
                total: {
                  show: true,
                  label: 'Exposición',
                  formatter: function (w) {
                    var sum = w.globals.seriesTotals.reduce(function (acc, current) { return acc + current; }, 0);
                    return currencyFormatter.format(sum || 0);
                  }
                }
              }
            }
          }
        }
      }));

      upsertChart('pareto', 'paretoChart', Object.assign(chartBase(300), {
        chart: Object.assign(chartBase(300).chart, { type: 'line', stacked: false }),
        series: [
          { name: 'Exposición', type: 'column', data: chartData.pareto ? chartData.pareto.exposure : [] },
          { name: 'Acumulado %', type: 'line', data: chartData.pareto ? chartData.pareto.cumulative_pct : [] }
        ],
        colors: ['#1E5AA8', '#F59E0B'],
        stroke: { width: [0, 3], curve: 'smooth' },
        xaxis: {
          categories: chartData.pareto ? chartData.pareto.categories : [],
          labels: { rotate: -35, style: { colors: '#64748B', fontSize: '11px' } }
        },
        yaxis: [
          {
            labels: {
              formatter: function (value) { return currencyFormatter.format(value || 0); },
              style: { colors: '#64748B' }
            },
            title: { text: 'Exposición' }
          },
          {
            opposite: true,
            max: 100,
            labels: {
              formatter: function (value) { return decimalFormatter.format(value || 0) + '%'; },
              style: { colors: '#64748B' }
            },
            title: { text: 'Acumulado %' }
          }
        ]
      }));

      upsertChart('heatmap', 'heatmapChart', Object.assign(chartBase(320), {
        chart: Object.assign(chartBase(320).chart, { type: 'heatmap' }),
        colors: ['#1E5AA8'],
        series: chartData.heatmap ? chartData.heatmap.series : [],
        plotOptions: {
          heatmap: {
            shadeIntensity: 0.65,
            radius: 4,
            colorScale: {
              ranges: [
                { from: 0, to: 1, color: '#E2E8F0', name: 'Bajo' },
                { from: 1, to: 10000000, color: '#1E5AA8', name: 'Alto' }
              ]
            }
          }
        },
        dataLabels: {
          enabled: true,
          formatter: function (value) {
            return value > 0 ? numberFormatter.format(value) : '';
          },
          style: { fontSize: '10px' }
        },
        xaxis: {
          labels: { style: { colors: '#64748B' } }
        }
      }));

      upsertChart('topExposure', 'topExposureChart', Object.assign(chartBase(260), {
        chart: Object.assign(chartBase(260).chart, { type: 'bar' }),
        colors: ['#1E5AA8'],
        series: [{ name: 'Exposición', data: chartData.top_exposure ? chartData.top_exposure.values : [] }],
        plotOptions: {
          bar: { horizontal: true, borderRadius: 5, distributed: false }
        },
        xaxis: {
          categories: chartData.top_exposure ? chartData.top_exposure.categories : [],
          labels: {
            formatter: function (value) { return numberFormatter.format(value || 0); },
            style: { colors: '#64748B' }
          }
        },
        yaxis: {
          labels: { style: { colors: '#475569', fontSize: '11px' } }
        }
      }));

      upsertChart('topMora', 'topMoraChart', Object.assign(chartBase(260), {
        chart: Object.assign(chartBase(260).chart, { type: 'bar' }),
        colors: ['#EF4444'],
        series: [{ name: 'Mora', data: chartData.top_mora ? chartData.top_mora.values : [] }],
        plotOptions: {
          bar: { horizontal: true, borderRadius: 5, distributed: false }
        },
        xaxis: {
          categories: chartData.top_mora ? chartData.top_mora.categories : [],
          labels: {
            formatter: function (value) { return numberFormatter.format(value || 0); },
            style: { colors: '#64748B' }
          }
        },
        yaxis: {
          labels: { style: { colors: '#475569', fontSize: '11px' } }
        }
      }));

      Object.keys(chartShells).forEach(function (key) {
        if (chartShells[key]) {
          chartShells[key].classList.remove('is-loading');
        }
      });
    }

    function renderTables(tables) {
      var exposureRows = tables.top_exposure || [];
      if (exposureRows.length === 0) {
        topExposureBody.innerHTML = '<tr><td colspan="2" class="mini-table-empty">Sin datos</td></tr>';
      } else {
        topExposureBody.innerHTML = exposureRows.map(function (row) {
          return '<tr><td>' + escapeHtml(row.cliente) + '</td><td>' + currencyFormatter.format(Number(row.valor || 0)) + '</td></tr>';
        }).join('');
      }

      var moraRows = tables.top_mora || [];
      if (moraRows.length === 0) {
        topMoraBody.innerHTML = '<tr><td colspan="3" class="mini-table-empty">Sin datos</td></tr>';
      } else {
        topMoraBody.innerHTML = moraRows.map(function (row) {
          var avgDays = decimalFormatter.format(Number(row.dias_promedio || 0));
          return '<tr><td>' + escapeHtml(row.cliente) + '</td><td>' + currencyFormatter.format(Number(row.valor || 0)) + '</td><td>' + avgDays + '</td></tr>';
        }).join('');
      }
    }

    function requestDashboardData() {
      if (activeRequest) {
        activeRequest.abort();
      }

      setLoadingState(true);
      updatedAtEl.textContent = 'Actualizando métricas...';
      var controller = new AbortController();
      activeRequest = controller;

      var params = new URLSearchParams(new FormData(filtersForm));
      var query = params.toString();
      var requestUrl = endpointUrl + (query ? ('?' + query) : '');

      fetch(requestUrl, {
        method: 'GET',
        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        signal: controller.signal
      })
      .then(function (response) {
        if (!response.ok) {
          throw new Error('No se pudo actualizar el dashboard.');
        }
        return response.json();
      })
      .then(function (payload) {
        if (!payload || payload.ok !== true) {
          throw new Error(payload && payload.message ? payload.message : 'Respuesta inválida del endpoint.');
        }
        hydrateFilters(payload.filter_options || {}, payload.meta.selected_filters || {});
        renderRiskInsight(payload.insights || {});
        renderKpis(payload.kpis || [], payload.meta || {});
        renderCharts(payload.charts || {});
        renderTables(payload.tables || {});

        var periodInfo = payload.meta.current_period ? (' | Corte ' + formatPeriod(payload.meta.current_period)) : '';
        updatedAtEl.textContent = 'Actualizado: ' + (payload.meta.generated_at_human || '--') + periodInfo;
      })
      .catch(function (error) {
        if (error && error.name === 'AbortError') {
          return;
        }
        updatedAtEl.textContent = 'Error de actualización: ' + (error && error.message ? error.message : 'intente nuevamente');
      })
      .finally(function () {
        if (activeRequest === controller) {
          activeRequest = null;
          setLoadingState(false);
        }
      });
    }

    filtersForm.addEventListener('submit', function (event) {
      event.preventDefault();
      requestDashboardData();
    });

    filtersForm.addEventListener('change', function () {
      if (isHydratingFilters) {
        return;
      }
      clearTimeout(debounceTimer);
      debounceTimer = setTimeout(requestDashboardData, 200);
    });

    clearButton.addEventListener('click', function () {
      filtersForm.reset();
      requestDashboardData();
    });

    refreshButton.addEventListener('click', requestDashboardData);

    autoRefreshTimer = setInterval(requestDashboardData, 120000);
    window.addEventListener('beforeunload', function () {
      clearInterval(autoRefreshTimer);
      if (activeRequest) {
        activeRequest.abort();
      }
    });

    renderTableSkeleton(topExposureBody, 2);
    renderTableSkeleton(topMoraBody, 3);
    requestDashboardData();
  })();
</script>
<?php
$content = ob_get_clean();
render_layout('Dashboard estratégico', $content);
