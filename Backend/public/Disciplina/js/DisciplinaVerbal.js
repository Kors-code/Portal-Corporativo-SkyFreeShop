document.addEventListener("DOMContentLoaded", async () => {
    try {
    const response = await fetch('/api/firmas');
    const data = await response.json();

    if (data.empleado) {
      const imgEmpleado = document.getElementById('imagenFirmaEmpleado');
      const inputEmpleado = document.getElementById('firmaEmpleadoBase64');
      imgEmpleado.src = data.empleado;
      imgEmpleado.style.display = 'block';
      inputEmpleado.value = data.empleado;
    }

    if (data.jefe) {
      const imgJefe = document.getElementById('imagenFirmaJefe');
      const inputJefe = document.getElementById('firmaJefeBase64');
      imgJefe.src = data.jefe;
      imgJefe.style.display = 'block';
      inputJefe.value = data.jefe;
    }

    if (data.proceso) {
      const searchInput = document.getElementById('searchMotivo');
      searchInput.value = data.proceso;
    }

  } catch (error) {
    console.error('Error obteniendo firmas desde sesión:', error);
  }
    /* ---------- Contador de caracteres ---------- */
  (function(){
    const textarea = document.getElementById('detalle');
    const contador = document.getElementById('contador');
    if (!textarea) return;
    function actualizarContador() {
      const max = parseInt(textarea.getAttribute('maxlength') || 480);
      const longitud = textarea.value.length;
      contador.textContent = `${longitud} / ${max}`;
      contador.style.color = longitud >= max ? 'rgba(255,80,80,0.95)' : 'var(--muted)';
    }
    textarea.addEventListener('input', actualizarContador);
    actualizarContador();
  })();
  
  /* ---------- Firma canvas (touch + mouse) ---------- */
  
  (function(){
    const modal = document.getElementById('modalFirma');
    const canvas = document.getElementById('canvasFirma');
    if (!canvas) return;
    const ctx = canvas.getContext('2d');
    const abrirFirmaEmpleado = document.getElementById('abrirFirmaEmpleado');
    const abrirFirmaJefe = document.getElementById('abrirFirmaJefe');
    const guardarFirma = document.getElementById('guardarFirma');
    const limpiarFirma = document.getElementById('limpiarFirma');
    const cerrarFirma = document.getElementById('cerrarFirma');
    let tipoFirma = null;
    let drawing = false;

    function initCanvas(){
      ctx.fillStyle = '#fff'; ctx.fillRect(0,0,canvas.width,canvas.height);
      ctx.strokeStyle = '#000'; ctx.lineWidth = 2; ctx.lineCap = 'round';
    }
    function openModal(tipo){
      tipoFirma = tipo;
      document.getElementById('tituloModalFirma').textContent = tipo === 'empleado' ? 'Firma Empleado' : 'Firma Responsable';
      initCanvas();
      modal.style.display = 'flex';
    }
    function closeModal(){ modal.style.display = 'none'; }

    function getPos(e){
      const rect = canvas.getBoundingClientRect();
      let clientX, clientY;
      if (e.touches && e.touches.length){ clientX = e.touches[0].clientX; clientY = e.touches[0].clientY; }
      else { clientX = e.clientX; clientY = e.clientY; }
      return { x: clientX - rect.left, y: clientY - rect.top };
    }

    canvas.addEventListener('mousedown', e => { drawing=true; const p=getPos(e); ctx.beginPath(); ctx.moveTo(p.x,p.y); });
    canvas.addEventListener('mousemove', e => { if(!drawing) return; const p=getPos(e); ctx.lineTo(p.x,p.y); ctx.stroke(); });
    window.addEventListener('mouseup', ()=> drawing=false);
    canvas.addEventListener('touchstart', e => { e.preventDefault(); drawing=true; const p=getPos(e); ctx.beginPath(); ctx.moveTo(p.x,p.y); }, {passive:false});
    canvas.addEventListener('touchmove', e => { e.preventDefault(); if(!drawing) return; const p=getPos(e); ctx.lineTo(p.x,p.y); ctx.stroke(); }, {passive:false});
    window.addEventListener('touchend', ()=> drawing=false);

    abrirFirmaEmpleado?.addEventListener('click', ()=> openModal('empleado'));
    abrirFirmaJefe?.addEventListener('click', ()=> openModal('jefe'));
    limpiarFirma?.addEventListener('click', initCanvas);
    cerrarFirma?.addEventListener('click', closeModal);

    guardarFirma?.addEventListener('click', ()=>{
      const dataUrl = canvas.toDataURL('image/png');
      if (tipoFirma === 'empleado'){
        document.getElementById('imagenFirmaEmpleado').src = dataUrl;
        document.getElementById('imagenFirmaEmpleado').style.display = 'block';
        document.getElementById('firmaEmpleadoBase64').value = dataUrl;
      } else {
        document.getElementById('imagenFirmaJefe').src = dataUrl;
        document.getElementById('imagenFirmaJefe').style.display = 'block';
        document.getElementById('firmaJefeBase64').value = dataUrl;
      }
      closeModal();
    });
    

    document.getElementById('borrarFirmaEmpleado')?.addEventListener('click', ()=>{
      document.getElementById('imagenFirmaEmpleado').style.display='none';
      document.getElementById('firmaEmpleadoBase64').value='';
    });
    document.getElementById('borrarFirmaJefe')?.addEventListener('click', ()=>{
      document.getElementById('imagenFirmaJefe').style.display='none';
      document.getElementById('firmaJefeBase64').value='';
    });

    initCanvas();
  })();
  

  /* ---------- Buscar por cédula (debounce + loader) ---------- */
  (function(){
    const cedulaInput = document.getElementById('cedula');
    const nombreInput = document.getElementById('nombre');
    const cargoInput = document.getElementById('cargo');
    const loaderEmpleado = document.getElementById('loaderEmpleado');
    const jefeCedulaInput = document.getElementById('jefe_cedula');
    const jefeNombreInput = document.getElementById('jefe');
    const jefeCargoInput = document.getElementById('cargo_jefe');
    const loaderJefe = document.getElementById('loaderJefe');

    function debounce(fn, delay){
      let timer;
      return (...args) => { clearTimeout(timer); timer = setTimeout(()=> fn(...args), delay); };
    }

    async function fetchEmpleado(cedula, loaderEl){
      if(!cedula) return null;
      loaderEl.innerHTML = '<span class="spinner" aria-hidden="true"></span>';
      try{
        const res = await fetch(`/buscar-empleado/${encodeURIComponent(cedula)}`);
        if(!res.ok) return null;
        const data = await res.json();
        return data;
      } catch(e){
        console.error(e);
        return null;
      } finally {
        loaderEl.innerHTML = '';
      }
    }

    const handleEmpleado = debounce(async () => {
      const ced = cedulaInput.value.trim();
      if(!ced){ nombreInput.value=''; cargoInput.value=''; return; }
      const data = await fetchEmpleado(ced, loaderEmpleado);
      if(data && data.success){
        nombreInput.value = data.nombre || '';
        cargoInput.value = data.cargo || '';
      } else {
        nombreInput.value = '';
        cargoInput.value = '';
      }
    }, 550);

    const handleJefe = debounce(async () => {
      const ced = jefeCedulaInput.value.trim();
      if(!ced){ jefeNombreInput.value=''; jefeCargoInput.value=''; return; }
      const data = await fetchEmpleado(ced, loaderJefe);
      if(data && data.success){
        jefeNombreInput.value = data.nombre || '';
        jefeCargoInput.value = data.cargo || '';
      } else {
        jefeNombreInput.value = '';
        jefeCargoInput.value = '';
      }
    }, 550);

    cedulaInput?.addEventListener('input', handleEmpleado);
    cedulaInput?.addEventListener('blur', handleEmpleado);
    jefeCedulaInput?.addEventListener('input', handleJefe);
    jefeCedulaInput?.addEventListener('blur', handleJefe);
  })();

  /* ---------- Alertas temporizadas ---------- */
  const alert = document.getElementById("alert");
  if (alert) setTimeout(() => alert.style.display = "none", 7000);

  /* ---------- Lista de motivos (select con filtro) ---------- */
  const motivos = [
    {codigo: 101, descripcion: "Tardanzas (llegadas tarde, tiempos de descanso sin justificación válida)"},
    {codigo: 102, descripcion: "No cumplimiento del Presupuesto Mínimo"},
    {codigo: 103, descripcion: 'Frecuencia de errores "Número de Pasaporte"'},
    {codigo: 104, descripcion: "Frecuencia en anulación de factura por error del cajero"},
    {codigo: 105, descripcion: 'Frecuencia de errores "Error en Boarding Pass"'},
    {codigo: 106, descripcion: "Frecuencia de errores de descuadres en caja por menor valor facturado"},
    {codigo: 107, descripcion: "Frecuencia de errores de descuadres en caja por mayor valor facturado"},
    {codigo: 108, descripcion: "Uso inapropiado del código de vestuario en el lugar de trabajo"},
    {codigo: 109, descripcion: "Incumplimiento en áreas de responsabilidad (precio, surtido, visual y limpieza)"},
    {codigo: 110, descripcion: "Utilizar dispositivos electrónicos u otros objetos de uso personal en el lugar de trabajo"},
    {codigo: 111, descripcion: "Recibir dineros u obsequios de cualquier tipo por parte de los clientes en las instalaciones de la compañía"},
    {codigo: 112, descripcion: "Avería de mercancía por mal manejo"},
    {codigo: 113, descripcion: "No respetar los procedimientos de seguridad interna"},
    {codigo: 114, descripcion: "Consumo de alimentos en áreas diferentes a las autorizadas por la compañía"},
    {codigo: 115, descripcion: "No entregar las tarjetas débito o crédito a los clientes"},
    {codigo: 116, descripcion: "Guardar mercancía en la tienda posterior a su facturación (excepto cancelación de vuelo)"},
    {codigo: 117, descripcion: "No diligenciar correctamente los formatos aprobados para los procesos"},
    {codigo: 118, descripcion: "No cumplir con el orden y aseo en zonas comunes de la compañía"},
    {codigo: 119, descripcion: "Saltarse los conductos regulares para la comunicación o solución de conflictos"},
    {codigo: 120, descripcion: "No cumplir con la marcación de la jornada laboral en el sistema"},
    {codigo: 121, descripcion: "Cambiar la planimetría (merchandising) sin autorización"},
    {codigo: 122, descripcion: "No realizar los traslados de productos en el sistema"},
    {codigo: 123, descripcion: "Cambiar de turno u horario laboral sin autorización del Jefe Inmediato"},
    {codigo: 124, descripcion: "Acumulación de eventos"},
    {codigo: 125, descripcion: "Error en asignación de tienda en la venta"},
    {codigo: 201, descripcion: "Realizar ventas u otras actividades económicas sin autorización"},
    {codigo: 301, descripcion: "Fumar al interior de las instalaciones o en sus alrededores"},
    {codigo: 401, descripcion: "Falsificación de documentos e información"},
    { codigo: 201, descripcion: "Realizar ventas u otras actividades económicas con fines no relacionados con el trabajo (rifas, donativos, ventas por catálogos, etc.) sin previa autorización e incurriendo en pérdidas de tiempo o afectación del relacionamiento laboral" },
    { codigo: 202, descripcion: "Inadecuado uso de los activos y/o material de la empresa (impresora, celular, corporativo, etc.)" },
    { codigo: 203, descripcion: "Incumplir las normas y procedimientos del Sistema de Gestión de Seguridad y Salud en el Trabajo" },
    { codigo: 204, descripcion: "No facturar los productos de obsequio que se encuentren registrados en el sistema" },
    { codigo: 205, descripcion: "Deficiente desempeño en sus tareas y actividades laborales" },
    { codigo: 206, descripcion: "Incumplimiento en sus tareas y responsabilidades laborales" },
    { codigo: 207, descripcion: "No abordaje de clientes" },
    { codigo: 208, descripcion: "No aprovechar los tiempos muertos en actividades propias de la labor" },
    { codigo: 209, descripcion: "Marcar en el huellero sin estar listo para el inicio de su labor" },
    { codigo: 210, descripcion: "No marcar correctamente el tiempo de break (calentar los alimentos y posteriormente marcar)" },
    { codigo: 211, descripcion: "No suministrar información de las restricciones aduaneras respecto a la cantidad de productos a ingresar al país de destino para vuelos directos o con conexión" },
    { codigo: 212, descripcion: "Asignarse ventas en el sistema y/o tomar clientes que no le corresponden" },
    { codigo: 213, descripcion: "Facturar un producto y no entregarlo al cliente" },
    { codigo: 214, descripcion: "No revisar el estado de un producto frente al cliente al momento de entregarlo" },
    { codigo: 215, descripcion: "No informar los reclamos sobre los productos a su jefe inmediato" },
    { codigo: 216, descripcion: "No asistir a clases de inglés y no cumplir con las tareas, salvo personal con certificación requerida en el perfil del cargo" },
    { codigo: 217, descripcion: "No realizar el cierre de caja en turno a la medianoche" },
    { codigo: 218, descripcion: "No cumplir con las métricas de desempeño asignadas" },
    
    { codigo: 301, descripcion: "Fumar al interior de las instalaciones o en sus alrededores" },
    { codigo: 302, descripcion: "Ausencia y/o falta del lugar de trabajo sin autorización o notificación" },
    { codigo: 303, descripcion: "Actitud irrespetuosa y uso de lenguaje obsceno en el lugar de trabajo con partes interesadas (compañeros, clientes, proveedores, autoridades, etc.)" },
    { codigo: 304, descripcion: "No saludar al personal del aeropuerto, compañeros, líderes y demás personal interno y externo a la compañía" },
    { codigo: 305, descripcion: "Acceder, compartir y/o no proteger información sensible o confidencial de manera intencional sin estar autorizado" },
    { codigo: 306, descripcion: "Incumplir las normas y procedimientos del operador aeroportuario" },
    { codigo: 307, descripcion: "Incumplir las directrices sobre servicio al cliente, brindar mala atención, no atender oportunamente solicitudes o negar deliberadamente el servicio" },
    
    { codigo: 401, descripcion: "Falsificación de documentos e información" },
    { codigo: 402, descripcion: "Ingerir bebidas alcohólicas en el lugar de trabajo sin autorización" },
    { codigo: 403, descripcion: "Asistir al trabajo bajo el efecto de bebidas alcohólicas y/o alucinógenas" },
    { codigo: 404, descripcion: "Destrucción, sustracción o apropiación de propiedad de DFP, clientes o empleados y/o ser cómplice" },
    { codigo: 405, descripcion: "Divulgar, comunicar o transferir códigos de acceso de sistemas electrónicos o de comunicación a personas no autorizadas" },
    { codigo: 406, descripcion: "Agresión física a partes interesadas (compañeros, clientes, proveedores, autoridades, etc.)" },
    { codigo: 407, descripcion: "Uso, posesión, consumo o distribución de sustancias alucinógenas dentro de las instalaciones de la compañía" },
    { codigo: 408, descripcion: "Incurrir en hostigamiento sexual, acoso laboral y/o mobbing" },
    { codigo: 409, descripcion: "Aceptar dineros, obsequios o prebendas para favorecer a terceros" },
    { codigo: 410, descripcion: "Cambiar los precios de los productos de manera intencional sin autorización para propósitos personales o de terceros" },
    { codigo: 411, descripcion: "Actuar de manera poco ética y transparente o no declarar situaciones de conflicto de interés incumpliendo las políticas de la compañía" }

  ];

  const dropdown = document.getElementById("motivosDropdown");
  const searchInput = document.getElementById("searchMotivo");
  const motivoInput = document.getElementById("motivo");

  function mostrarMotivos(lista) {
    dropdown.innerHTML = "";
    lista.forEach(m => {
      const div = document.createElement("div");
      div.textContent = `${m.codigo} - ${m.descripcion}`;
      div.onclick = () => seleccionarMotivo(m);
      dropdown.appendChild(div);
    });
    dropdown.style.display = lista.length ? "block" : "none";
  }

  function seleccionarMotivo(m) {
    searchInput.value = `${m.codigo} - ${m.descripcion}`;
    motivoInput.value = m.codigo;
    dropdown.style.display = "none";
  }

  function filtrarMotivos() {
    const texto = searchInput.value.toLowerCase();
    const filtrados = motivos.filter(m =>
      m.descripcion.toLowerCase().includes(texto) ||
      m.codigo.toString().includes(texto)
    );
    mostrarMotivos(filtrados);
  }

  mostrarMotivos(motivos);

  document.addEventListener("click", (e) => {
    if (!e.target.closest(".select-search")) dropdown.style.display = "none";
  });

  searchInput?.addEventListener("input", filtrarMotivos);

  /* ---------- Sesiones y flash ---------- */
  const flash = document.getElementById('flash-success');
  if (flash) setTimeout(() => flash.remove(), 7000);

  const dl = document.getElementById('downloadLink');
  if (dl) setTimeout(() => { try { dl.click(); } catch { window.open(dl.href, '_blank'); } }, 600);

  const err = document.getElementById('flash-errors');
  if (err) setTimeout(() => err.remove(), 10000);

  const firmaEmpleado = window.firmaEmpleadoSession || null;
  const firmaJefe = window.firmaJefeSession || null;
  const Proceso = window.ProcesoSession || null;

  if (firmaEmpleado) {
    const imgEmp = document.getElementById('imagenFirmaEmpleado');
    imgEmp.src = firmaEmpleado;
    imgEmp.style.display = 'block';
    document.getElementById('firmaEmpleadoBase64').value = firmaEmpleado;
  }
  if (firmaJefe) {
    const imgJefe = document.getElementById('imagenFirmaJefe');
    imgJefe.src = firmaJefe;
    imgJefe.style.display = 'block';
    document.getElementById('firmaJefeBase64').value = firmaJefe;
  }
  if (Proceso) searchInput.value = Proceso;

});


