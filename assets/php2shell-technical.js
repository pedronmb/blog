(() => {
    'use strict';
    const explorer = document.getElementById('memory-explorer');
    if (!explorer || !explorer.querySelector('[data-stage]')) return;
    const routes = {
        bucket: [
            ['Empieza la inserción', '_zend_hash_str_add_or_update_i()', 'nNumUsed = N', 'Bucket nuevo fuera del rango ocupado', 'active', 'La HashTable tiene un extremo ocupado conocido. El nuevo slot todavía no está incluido en ese rango. El modelo omite las ramas de actualización de claves existentes.'],
            ['Se publica el nuevo extremo', 'Incremento de contadores', 'nNumUsed = N + 1', 'key / zval todavía incompletos', 'stale', 'El contador avanza antes de que la asignación de la clave y el resto de la inicialización terminen. La región recorrible ya incluye el nuevo Bucket.'],
            ['Falla la asignación de la clave', 'zend_string_init() → OOM', 'El contador sigue en N + 1', 'Bailout antes de completar el Bucket', 'stale', 'En la ruta vulnerable, el salto no local puede omitir la inicialización posterior y la restauración. El fallo de asignación no necesita ocurrir en la memoria que después interpretará la limpieza.'],
            ['mod_php ejecuta el cierre', 'php_request_shutdown()', 'La tabla conserva el extremo adelantado', 'Destrucción de las superglobales', 'stale', 'Este recorrido corresponde a mod_php: se ejecuta el cierre incluso tras un startup fallido. FPM termina el hijo en esa situación y corta este consumidor concreto.'],
            ['Se interpreta el slot inconsistente', 'Destrucción de la HashTable', 'Metadatos de un Bucket incompleto', 'Una marca de tipo puede resultar inválida', 'stale', 'El destructor puede alcanzar datos sin una representación válida para esa entrada. El resultado depende de los bytes, del allocator y de las ramas de destrucción; no todo OOM produce el mismo efecto.'],
            ['La explotación exige una cadena adicional', 'Consumidor de estado inválido', 'Corrupción de memoria posible', 'Caída o flujo de control alterado', 'stale', 'La RCE demostrada requiere controlar condiciones de memoria y completar una cadena. Este recorrido explica la ventana del invariante; no representa un exploit ejecutándose.']
        ],
        session: [
            ['Empieza el parseo de la petición A', 'php_hash_environment()', 'PG(modules_activated) = 0', 'RINIT ordinario aún pendiente', 'active', 'El parser puede invocar callbacks de sesiones antes de que termine la activación normal de los módulos. Esta secuencia representa el recorrido temprano de mod_php.'],
            ['El callback crea estado temprano', 'php_session_rfc1867_callback()', 'PS(id) / PS(http_session_vars)', 'Referencias al heap de A', 'active', 'El seguimiento de subidas puede publicar un identificador de sesión y datos de sesión pertenecientes a la petición actual. Por ahora sus direcciones son válidas.'],
            ['Una interrupción evita el cierre esperado', 'Error durante el parseo', 'La marca de activación sigue en cero', 'Callback final o restauración omitidos', 'stale', 'El recorrido normal de cierre del multipart no se completa. La marca de módulos activados todavía no permite ejecutar el RSHUTDOWN ordinario de sesiones.'],
            ['Se libera el heap de A', 'Cierre de petición en mod_php', 'Globals aún referidos a A', 'Almacenamiento liberado; worker vivo', 'stale', 'La vida del contenedor global y la vida de su destino no coinciden. Liberar el heap no retira automáticamente todas las referencias que otros subsistemas conservaron.'],
            ['B llega al mismo contexto persistente', 'Entrada temprana de sesiones', 'Referencia vieja antes de su reset', 'Heap liberado o reutilizado', 'stale', 'Una operación de la petición posterior puede alcanzar el estado antes de reinicializarlo. La identidad del worker es parte de esta cadena; compartir dominio no basta.'],
            ['El consumidor confía en el estado obsoleto', 'Lectura, decodificación o destrucción', 'Contrato de vida incumplido', 'Use after free', 'stale', 'Las variantes pueden divulgar memoria o producir corrupción. La ruta FPM validada necesita otro productor después de un startup exitoso; no hereda sin cambios este recorrido temprano.']
        ],
        soft: [
            ['La operación pide memoria', 'Allocator corregido', 'Estado de una mutación en curso', 'Consumo por debajo del límite suave', 'active', 'Se conserva el contrato de las funciones que necesitan una asignación para completar trabajo nativo. No se elimina el control de recursos.'],
            ['Se alcanza el límite suave', 'zend_mm_check_limit()', 'Se detecta la condición OOM', 'Todavía existe margen hasta el límite duro', 'active', 'La comprobación puede intentar recuperar memoria cuando corresponde. El límite suave y el duro tienen funciones diferentes.'],
            ['El error queda pendiente', 'EG(delayed_oom) / EG(vm_interrupt)', 'OOM registrado para atención diferida', 'Sin bailout arbitrario en esta asignación', 'safe', 'La política nueva registra el fallo lógico y solicita atención del ejecutor. Evita saltar desde el punto donde el objeto puede estar a medio construir.'],
            ['Se completa el tramo nativo', 'Inicialización o restauración', 'Invariante vuelve a ser consistente', 'Dentro del margen del límite duro', 'safe', 'El margen permite llegar al punto seguro sin abandonar la mutación en un estado publicado incompleto. No garantiza éxito de cualquier asignación física del sistema.'],
            ['El ejecutor atiende el OOM', 'Punto seguro de la VM', 'Error pendiente tratado', 'No se usa el estado parcial del modelo anterior', 'safe', 'La atención se difiere a un lugar compatible con el contrato del ejecutor. Este recorrido es una explicación de la política, no una lista completa de checks de la VM.'],
            ['Se mantiene el límite de recursos', 'Cierre consistente', 'Estado coherente para el recorrido posterior', 'El umbral duro sigue disponible', 'safe', 'El arreglo cambia cuándo se interrumpe, en lugar de permitir consumo ilimitado. Los reinicios de globals de la otra familia también necesitan su corrección.']
        ],
        hard: [
            ['Se evalúa una asignación', 'Allocator corregido', 'Límites suave y duro definidos', 'No hay margen suficiente', 'active', 'La operación no puede continuar dentro de la frontera de consumo que mantiene el runtime.'],
            ['La asignación superaría el umbral duro', 'zend_mm_hard_limit_error()', 'No se entrega estado parcial a la limpieza', 'Se elige terminar el proceso', 'stale', 'El camino duro preserva una frontera de disponibilidad sin intentar el cierre ordinario sobre una operación incompleta.'],
            ['El proceso sale inmediatamente', '_exit(137)', 'El worker deja de ejecutar', 'Sin reutilizar este proceso para otra petición', 'safe', 'La llamada evita la limpieza ordinaria del runtime en esta ruta. Un código 137 observado no prueba por sí solo que el kernel haya enviado SIGKILL.'],
            ['El servicio debe observar la terminación', 'Supervisor o master según despliegue', 'Falla la petición en curso', 'Reemplazo del worker según la SAPI', 'safe', 'Terminar el proceso tiene un coste de disponibilidad. La corrección del motor y la política de supervisión resuelven responsabilidades distintas.']
        ]
    };
    const previous = explorer.querySelector('[data-previous]');
    const next = explorer.querySelector('[data-next]');
    let route = 'bucket';
    let step = 0;
    function render() {
        const steps = routes[route];
        const [title, stage, reference, memory, status, explanation] = steps[step];
        explorer.querySelector('[data-step-title]').textContent = title;
        explorer.querySelector('[data-stage]').textContent = stage;
        explorer.querySelector('[data-reference]').textContent = reference;
        explorer.querySelector('[data-memory]').textContent = memory;
        explorer.querySelector('[data-reference-cell]').dataset.state = status;
        explorer.querySelector('[data-step-explanation]').textContent = explanation;
        explorer.querySelector('[data-step-count]').textContent = `Paso ${step + 1} de ${steps.length}`;
        previous.disabled = step === 0;
        const last = step === steps.length - 1;
        next.textContent = last ? 'Volver al inicio ↺' : 'Siguiente paso →';
        next.setAttribute('aria-label', last ? 'Volver al primer paso' : 'Mostrar el siguiente paso');
    }
    explorer.querySelectorAll('input[name="memory-mode"]').forEach(input => {
        input.addEventListener('change', () => { route = input.value; step = 0; render(); });
    });
    previous.addEventListener('click', () => { step = Math.max(0, step - 1); render(); });
    next.addEventListener('click', () => { step = (step + 1) % routes[route].length; render(); });
    explorer.querySelectorAll('[data-controls]').forEach(control => { control.hidden = false; });
    render();
})();
