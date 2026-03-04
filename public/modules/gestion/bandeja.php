<?php
require_once __DIR__ . '/../../../app/config/db.php';
require_once __DIR__ . '/../../../app/middlewares/require_auth.php';
require_once __DIR__ . '/../../../app/middlewares/require_role.php';
require_once __DIR__ . '/../../../app/views/layout.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../../../app/services/PortfolioScope.php';

require_role(['admin', 'analista']);

$user = current_user();
$isAdmin = portfolio_is_admin($user);
$responsables = gestion_get_responsables($pdo);

ob_start(); ?>
<h1>Gestión - Centro operativo</h1>
<div class="kpi-grid" id="gestionKpiGrid">
  <div class="kpi-card"><p class="kpi-label">Total cartera</p><p class="kpi-value" data-kpi="cartera_total">$0</p></div>
  <div class="kpi-card"><p class="kpi-label">Documentos en mora</p><p class="kpi-value" data-kpi="docs_mora">0</p></div>
  <div class="kpi-card"><p class="kpi-label">Clientes con mora</p><p class="kpi-value" data-kpi="clientes_mora">0</p></div>
  <div class="kpi-card"><p class="kpi-label">Promesas pendientes</p><p class="kpi-value" data-kpi="pendientes">0</p></div>
  <div class="kpi-card"><p class="kpi-label">Promesas incumplidas</p><p class="kpi-value" data-kpi="incumplidas">0</p></div>
  <div class="kpi-card"><p class="kpi-label">Recuperado hoy / semana / mes</p><p class="kpi-value" data-kpi="recuperado">$0 / $0 / $0</p></div>
</div>

<div class="card">
  <div class="row">
    <?php if ($isAdmin): ?>
    <select id="fResponsable">
      <option value="0">Todos los gestores</option>
      <?php foreach ($responsables as $r): ?>
        <option value="<?= (int)$r['id'] ?>"><?= htmlspecialchars((string)$r['nombre']) ?></option>
      <?php endforeach; ?>
    </select>
    <?php endif; ?>
    <input id="fRegional" placeholder="Regional">
    <input id="fCanal" placeholder="Canal">
    <select id="fMora">
      <option value="">Rango mora</option>
      <option value="0-30">0-30</option><option value="31-60">31-60</option><option value="61-90">61-90</option><option value="+90">+90</option>
    </select>
    <select id="fEstadoGestion">
      <option value="">Estado gestión</option><option value="sin_gestion">Sin gestión</option><option value="con_gestion">Con gestión</option>
    </select>
    <select id="fEstadoCompromiso">
      <option value="">Estado compromiso</option><option value="pendiente">Pendiente</option><option value="cumplido">Cumplido</option><option value="incumplido">Incumplido</option>
    </select>
    <input id="fQ" placeholder="Cliente, NIT, cuenta, documento">
    <button class="btn" id="btnFiltrar" type="button">Filtrar</button>
  </div>
</div>

<div class="card">
  <div class="row">
    <button class="btn btn-secondary" id="btnAsignar" type="button">Asignar gestor</button>
    <button class="btn btn-secondary" id="btnCampania" type="button">Marcar campaña</button>
    <button class="btn btn-secondary" id="btnExportSel" type="button">Exportar selección CSV</button>
    <button class="btn btn-secondary" type="button" disabled title="Próximamente">Enviar recordatorio</button>
  </div>
  <table class="table" id="tablaGestion">
    <thead><tr><th><input type="checkbox" id="checkAll"></th><th>Cliente</th><th>Documento/Factura</th><th>Valor / Saldo</th><th>Días mora</th><th>Última gestión</th><th>Estado</th><th>Acciones</th><th>CTA</th></tr></thead>
    <tbody></tbody>
  </table>
</div>

<div id="drawer" class="gestion-drawer">
  <div class="gestion-drawer-content">
    <div class="card-header"><h3>Gestionar caso</h3><button class="btn btn-secondary btn-sm" id="closeDrawer" type="button">Cerrar</button></div>
    <div id="drawerResumen" class="client-grid"></div>
    <div class="card">
      <div class="row"><select id="tipoGestion"><option value="">Tipo gestión</option><option>Llamada</option><option>WhatsApp</option><option>Correo</option><option>Visita</option><option>Otro</option></select></div>
      <div class="row"><textarea id="obsGestion" placeholder="Observación (obligatoria)"></textarea></div>
      <div class="row">
        <label><input type="checkbox" id="swCompromiso"> ¿Compromiso de pago?</label>
        <input type="date" id="fechaCompromiso">
        <input type="number" step="0.01" id="valorCompromiso" placeholder="Valor compromiso">
        <select id="estadoCompromiso"><option value="pendiente">Pendiente</option><option value="cumplido">Cumplido</option><option value="incumplido">Incumplido</option></select>
      </div>
      <div class="row"><button class="btn" id="guardarGestion" type="button">Guardar gestión</button><button class="btn" id="guardarSiguiente" type="button">Guardar y siguiente</button></div>
    </div>
    <div class="card"><h4>Timeline</h4><ul id="timelineGestion" class="alert-list"></ul></div>
  </div>
</div>

<style>
.gestion-drawer{position:fixed;top:0;right:-720px;width:680px;max-width:100%;height:100%;background:#fff;z-index:60;box-shadow:-8px 0 18px rgba(0,0,0,.15);transition:right .2s;padding:16px;overflow:auto}
.gestion-drawer.open{right:0}
.copy-btn{cursor:pointer;color:#2563eb}
</style>

<script>
(function(){
  const state={rows:[],selected:new Set(),current:null};
  const tbody=document.querySelector('#tablaGestion tbody');
  const drawer=document.getElementById('drawer');
  const resumen=document.getElementById('drawerResumen');
  const timeline=document.getElementById('timelineGestion');

  function money(v){return new Intl.NumberFormat('es-CO',{style:'currency',currency:'COP',maximumFractionDigits:0}).format(Number(v||0));}
  function esc(v){return String(v??'').replace(/[&<>"']/g,m=>({"&":"&amp;","<":"&lt;",">":"&gt;","\"":"&quot;","'":"&#39;"}[m]));}
  function estado(row){ if(!row.ultima_gestion_id) return 'Sin gestión'; if(row.estado_compromiso) return row.estado_compromiso; return 'Gestionado'; }

  async function loadKpis(){
    const p=new URLSearchParams();
    const fr=document.getElementById('fResponsable'); if(fr) p.set('responsable_id',fr.value);
    const r=await fetch('<?= app_url('api/gestion/dashboard.php') ?>?'+p.toString()); const data=await r.json(); if(!data.ok) return;
    document.querySelector('[data-kpi="cartera_total"]').textContent=money(data.kpis.cartera_total||0);
    document.querySelector('[data-kpi="docs_mora"]').textContent=data.kpis.docs_mora||0;
    document.querySelector('[data-kpi="clientes_mora"]').textContent=data.kpis.clientes_mora||0;
    document.querySelector('[data-kpi="pendientes"]').textContent=data.kpis.pendientes||0;
    document.querySelector('[data-kpi="incumplidas"]').textContent=data.kpis.incumplidas||0;
    document.querySelector('[data-kpi="recuperado"]').textContent=`${money(data.kpis.recuperado_hoy||0)} / ${money(data.kpis.recuperado_semana||0)} / ${money(data.kpis.recuperado_mes||0)}`;
  }

  async function loadRows(){
    const p=new URLSearchParams();
    ['fRegional','fCanal','fMora','fEstadoGestion','fEstadoCompromiso','fQ'].forEach(id=>{const el=document.getElementById(id); if(el&&el.value) p.set(id.replace('f','').toLowerCase(),el.value);});
    const fr=document.getElementById('fResponsable'); if(fr&&fr.value!=='0') p.set('responsable_id',fr.value);
    if(p.has('q')===false && document.getElementById('fQ')?.value){p.set('q',document.getElementById('fQ').value)}
    if(p.get('mora')){p.set('mora_rango',p.get('mora')); p.delete('mora');}
    if(p.get('estadogestion')){p.set('estado_gestion',p.get('estadogestion')); p.delete('estadogestion');}
    if(p.get('estadocompromiso')){p.set('estado_compromiso',p.get('estadocompromiso')); p.delete('estadocompromiso');}
    const r=await fetch('<?= app_url('api/gestion/listado.php') ?>?'+p.toString()); const data=await r.json();
    state.rows=data.rows||[]; renderTable();
  }

  function renderTable(){
    tbody.innerHTML=state.rows.map((row,idx)=>`<tr>
      <td><input type="checkbox" data-id="${row.id}" ${state.selected.has(String(row.id))?'checked':''}></td>
      <td><strong>${esc(row.cliente)}</strong><br><small>${esc(row.cuenta||'')}</small></td>
      <td>${esc(row.nro_documento)}</td>
      <td>${money(row.saldo_pendiente)}</td>
      <td>${Number(row.dias_vencido||0)}</td>
      <td>${esc(row.fecha_ultima_gestion||'-')}</td>
      <td>${esc(estado(row))}</td>
      <td><span class="copy-btn" data-phone="${esc(row.telefono||'')}">Copiar teléfono</span></td>
      <td><button class="btn btn-sm" data-idx="${idx}">Gestionar</button></td>
    </tr>`).join('');
  }

  function openDrawer(row){ state.current=row; drawer.classList.add('open');
    resumen.innerHTML=`<p><strong>Cliente</strong><br>${esc(row.cliente)} · ${esc(row.cuenta||'-')}</p><p><strong>NIT</strong><br>${esc(row.nit||'-')}</p><p><strong>Dirección</strong><br>${esc(row.direccion||'-')}</p><p><strong>Contacto</strong><br>${esc(row.telefono||'-')} · ${esc(row.canal||'-')}</p><p><strong>Regional</strong><br>${esc(row.regional||'-')}</p><p><strong>Documento</strong><br>${esc(row.nro_documento)} · ${money(row.saldo_pendiente)} · ${Number(row.dias_vencido||0)} días</p><p><strong>Responsable</strong><br>${esc(row.responsable||'Sin asignar')}</p>`;
    loadTimeline(row.id);
  }
  async function loadTimeline(id){ const r=await fetch('<?= app_url('api/gestion/historial.php') ?>?documento_id='+id); const d=await r.json(); timeline.innerHTML=(d.rows||[]).map(x=>`<li><strong>${esc(x.created_at)}</strong> · ${esc(x.usuario)} · ${esc(x.tipo_gestion)}<br>${esc(x.observacion)}<br>${x.compromiso_pago?('Compromiso '+esc(x.compromiso_pago)+' '+esc(x.estado_compromiso||'')):''}</li>`).join('')||'<li>Sin gestiones</li>'; }

  async function guardar(next){
    if(!state.current) return;
    const body={documento_id:state.current.id,tipo_gestion:document.getElementById('tipoGestion').value,observacion:document.getElementById('obsGestion').value,tiene_compromiso:document.getElementById('swCompromiso').checked,fecha_compromiso:document.getElementById('fechaCompromiso').value,valor_compromiso:document.getElementById('valorCompromiso').value,estado_compromiso:document.getElementById('estadoCompromiso').value};
    const r=await fetch('<?= app_url('api/gestion/crear.php') ?>',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(body)}); const d=await r.json();
    if(!d.ok){alert(d.message||'Error al guardar');return;} await loadRows(); await loadKpis();
    if(next){const i=state.rows.findIndex(x=>String(x.id)===String(state.current.id)); if(i>=0 && state.rows[i+1]) openDrawer(state.rows[i+1]); else drawer.classList.remove('open');}
    else {openDrawer(state.current);}  }

  document.getElementById('btnFiltrar').addEventListener('click',()=>{loadRows(); loadKpis();});
  document.getElementById('closeDrawer').addEventListener('click',()=>drawer.classList.remove('open'));
  document.getElementById('guardarGestion').addEventListener('click',()=>guardar(false));
  document.getElementById('guardarSiguiente').addEventListener('click',()=>guardar(true));
  document.getElementById('checkAll').addEventListener('change',e=>{state.selected=new Set(e.target.checked?state.rows.map(r=>String(r.id)):[]); renderTable();});
  tbody.addEventListener('click', async (e)=>{
    const btn=e.target.closest('button[data-idx]'); if(btn){openDrawer(state.rows[Number(btn.dataset.idx)]);}
    const cp=e.target.closest('.copy-btn'); if(cp){navigator.clipboard?.writeText(cp.dataset.phone||'');}
  });
  tbody.addEventListener('change',e=>{ if(e.target.matches('input[type=checkbox][data-id]')){const id=e.target.dataset.id; if(e.target.checked) state.selected.add(id); else state.selected.delete(id);} });

  document.getElementById('btnExportSel').addEventListener('click',()=>{const ids=[...state.selected]; if(!ids.length) return alert('Seleccione filas'); const rows=state.rows.filter(r=>ids.includes(String(r.id))); let csv='cliente,documento,saldo,dias_mora\n'; rows.forEach(r=>{csv+=`"${(r.cliente||'').replace(/"/g,'""')}","${r.nro_documento}",${r.saldo_pendiente},${r.dias_vencido}\n`;}); const a=document.createElement('a'); a.href=URL.createObjectURL(new Blob([csv],{type:'text/csv'})); a.download='gestion_seleccion.csv'; a.click(); });
  document.getElementById('btnCampania').addEventListener('click',()=>alert('Marcación de campaña disponible próximamente.'));
  document.getElementById('btnAsignar').addEventListener('click', async ()=>{
    const ids=[...state.selected]; if(!ids.length) return alert('Seleccione filas');
    const resp=prompt('ID del gestor responsable'); if(!resp) return;
    const clienteIds=[...new Set(state.rows.filter(r=>ids.includes(String(r.id))).map(r=>r.cliente_id))];
    const r=await fetch('<?= app_url('api/gestion/asignacion.php') ?>',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({responsable_usuario_id:Number(resp),cliente_ids:clienteIds})});
    const d=await r.json(); if(!d.ok){alert(d.message||'No se pudo asignar'); return;} alert('Asignación realizada'); loadRows();
  });

  loadRows(); loadKpis();
})();
</script>
<?php
$content = ob_get_clean();
render_layout('Gestión', $content);
