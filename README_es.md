# Yared.AI Creador de Material — Constructor de temas y banco de preguntas con IA (Moodle 4.5)

Plugin local que crea con IA el contenido de un tema del curso, genera preguntas para el banco y arma cuestionarios estándar de Moodle con esas preguntas. Todo se abre desde el menú **Más** del curso; **no requiere ningún módulo de actividad adicional**.

## Instalación
1. Subir `local_ai_yared_course.zip` (Extensiones → Instalar plugins).
2. Entrar como administrador para actualizar la base de datos y purgar cachés.
3. **Para recortar figuras de PDFs hace falta Python 3 + PyMuPDF** en el mismo entorno donde corre PHP. Ver la sección *Requisitos de despliegue* de abajo. Sin ellos el plugin sigue funcionando, pero el PDF se analiza solo como texto y no se generan recortes. En *Plugins locales → Yared.AI* hay un bloque **Estado de la conversión de PDF** que indica si el entorno es Docker o un servidor normal y si PyMuPDF está listo.

## Requisitos de despliegue (para soporte)
Antes de entregar un ambiente (QA, producción, otro cliente), revisar esta lista. **PyMuPDF no viene con Moodle ni con el plugin: se instala en cada ambiente.**

| Requisito | Para qué | Si falta |
| --- | --- | --- |
| Moodle 4.5+ y PHP con `proc_open` habilitado (no listado en `disable_functions`) | Ejecutar el convertidor de PDF | Los PDF se analizan solo como texto |
| **Python 3 + PyMuPDF**, accesibles para el usuario del servidor web | Convertir cada página del PDF en imagen y recortar figuras | Aviso «El servidor no pudo convertir el PDF», sin recortes |
| Salida HTTPS hacia el proveedor de IA (Gemini/OpenAI/Grok…) y `googleapis.com` (YouTube) | Generar contenido, preguntas y buscar videos | No se genera nada; ver *Probar la conexión* |
| Tiempo de espera ≥ 600 s en nginx/Apache/PHP-FPM (`proxy_read_timeout`, `fastcgi_read_timeout`, `max_execution_time`) | Generaciones largas | Cortes a mitad de la generación |
| Cron de Moodle activo | Tarea que borra importaciones abandonadas (cada 6 h) | Se acumulan archivos temporales |
| Si un proveedor de IA está en una IP privada (Ollama, etc.): agregar host y puerto en *Seguridad → Seguridad HTTP* de Moodle | Moodle bloquea redes privadas por defecto | El respaldo falla por conexión |

### Moodle en un servidor normal (sin contenedor)
Ubuntu/Debian 24 no permite `pip install` global, por eso se usa un entorno virtual:

```bash
sudo apt install -y python3-venv
sudo python3 -m venv /opt/pymupdf
sudo /opt/pymupdf/bin/pip install PyMuPDF
# Comprobar con el usuario de PHP (debe imprimir una versión):
sudo -u www-data /opt/pymupdf/bin/python -c "import pymupdf; print(pymupdf.__version__)"
```
Luego, en *Administración → Plugins locales → Yared.AI*, poner `/opt/pymupdf/bin/python` en **Python para convertir PDFs**. En servidores Windows: instalar Python 3 y ejecutar `py -m pip install PyMuPDF`; indicar la ruta de `python.exe` en ese mismo ajuste si PHP no la encuentra.

### Moodle en Docker
El Python que cuenta es el **del contenedor de Moodle**, no el del servidor anfitrión. Hay que agregarlo a la **imagen** (Dockerfile) para que no se pierda al recrear el contenedor:

```dockerfile
RUN apt-get update && apt-get install -y python3-venv \
 && python3 -m venv /opt/pymupdf && /opt/pymupdf/bin/pip install PyMuPDF
```
Reconstruir y recrear el contenedor, y comprobar:

```bash
docker exec -u www-data <contenedor> /opt/pymupdf/bin/python -c "import pymupdf; print(pymupdf.__version__)"
```
Después poner `/opt/pymupdf/bin/python` en **Python para convertir PDFs**. Si el contenedor se instaló sin reconstruir la imagen (por ejemplo, con `docker exec ... pip install`), la instalación se perderá al recrearlo.

### Cómo saber si el ambiente está listo
- **Al instalar o actualizar el plugin**, si no se detecta PyMuPDF aparece un aviso amarillo con el enlace a estos pasos.
- **Administración → Informes → Estado** (comprobaciones del sistema): la fila *PyMuPDF para convertir PDFs* queda en verde o en advertencia; sirve para revisar cada ambiente sin abrir el plugin.
- **Estado de la conversión de PDF** (en los ajustes del plugin): debe decir «Listo» con las versiones de Python y PyMuPDF. Muestra también si detectó Docker o servidor normal y las instrucciones que corresponden.
- Si un docente ve el aviso de conversión, el final trae un código: `pymupdf_not_installed` (falta PyMuPDF), `python` (no hay Python 3), `timeout` (servidor saturado), `open_failed` (PDF dañado). Los administradores ven además el detalle y el ambiente (`env=docker` o `env=host`).

## Novedades 0.24.0 — Imagen de referencia (Wikimedia Commons)
En la pantalla «Revisar antes de guardar» (importación de PDF y generador) cada pregunta tiene el panel **Imagen de referencia (opcional)**: se buscan imágenes libres en Wikimedia Commons (la IA deduce qué buscar si se deja vacío), el docente elige una miniatura (se muestran autor y licencia) y **Procesar y reformular** adjunta la imagen y reformula la pregunta para que dependa de ella. El crédito se agrega a la retroalimentación al guardar. Ajuste de administrador: `imagesearch`. **Requisito:** salida HTTPS del servidor Moodle a `commons.wikimedia.org` y `upload.wikimedia.org`.

## Configuración (administrador)
*Administración del sitio → Extensiones → Plugins locales → Yared.AI Creador de Material*

### Proveedores de IA con respaldo automático
Hay **seis espacios**: el principal y cinco respaldos opcionales. Motores incluidos: Gemini, OpenAI, Grok, DeepSeek, Qwen (DashScope), Kimi (Moonshot), Mistral, Groq, OpenRouter, Zhipu GLM y "Otro compatible con OpenAI" (verifique el nombre del modelo vigente en cada proveedor). Cada uno tiene su proveedor, clave API, modelo, URL base y modo JSON. El plugin usa el principal y, si falla por cuota, conexión, clave o modelo retirado, prueba los respaldos 1 a 5 en orden. El último proveedor que funcionó se recuerda para el resto del lote.

- **Principal**: Gemini (por defecto `gemini-3.5-flash-lite`, recomendado para leer PDFs e imágenes), OpenAI, Grok u otro compatible con OpenAI.
- **Respaldo 1 a 5**: opcionales. Se activan con la casilla **Activar este proveedor**.
- Cada proveedor tiene su botón **Probar la conexión**; el principal también prueba YouTube. Probar consume una solicitud de la cuota.
- La temperatura, el tiempo de espera y las preguntas por solicitud son generales para los tres. Para PDFs e imágenes se recomiendan 600 s de espera.
- Si un modelo dejó de existir (error 404), cámbielo por uno vigente. Puede consultar los modelos disponibles de Gemini en `https://generativelanguage.googleapis.com/v1beta/models?key=TU_API_KEY` y usar los que incluyan `generateContent`.
- Ejemplos de respaldo: Gemini Lite, Groq/Cerebras (compatibles con OpenAI), DeepSeek (`https://api.deepseek.com/v1` + `deepseek-chat`), Ollama local (`http://IP:11434/v1`) u OpenRouter.
- La tabla de resultados del lote muestra qué proveedor se usó y una advertencia si hubo que cambiar de proveedor durante el tema.

- **Edad de cada nivel**: edad que se envía a la IA para Inicial, Primaria, Secundaria y Universitario.
- **Clave de la API de YouTube Data v3**: necesaria para buscar videos reales. Sin clave se crea un marcador oculto.

## Uso (docente)
Curso → menú **Más**:
- **Yared.AI Creador de Material**: pide dónde agregar los temas (una sección nueva por cada tema o una sección existente), la cantidad (1 a 10), la lista de temas con su dificultad/edad y fecha, y qué componentes crear: video, banco de preguntas, práctica calificada y trabajo de investigación. Luego crea todos los temas en un solo lote y muestra el avance en vivo.
- **Yared.AI Creador de Pregunta**: elige una de las tres formas: **Clásico**, **Matemáticas con LaTeX** o **Desde PDF o imagen**. Las dos primeras generan preguntas y las guardan en el banco del curso. Al enviar el formulario muestra una pantalla de espera con los pasos y el tiempo transcurrido; no cierre ni recargue la ventana. La cuarta tarjeta, **Crear cuestionario**, abre el asistente de cuestionarios.
- **Desde PDF o imagen**: sube PDFs o fotos con varios ejercicios. La IA los separa y transcribe cada enunciado. Si hay Python/PyMuPDF, cada página del PDF se convierte en imagen y también se recorta la figura; si no, el PDF se procesa solo como texto. Antes de guardar puedes revisar, editar y ajustar cada recorte. Si el PDF no se separa bien, sube una imagen por pregunta (hasta 10).
- **Yared.AI Creador de Cuestionario**: elige uno o varios bancos (muestra cuántas preguntas tiene cada uno), el nombre, la descripción, la cantidad (10 por defecto) y la sección. Las subsecciones de Formato-Mariategui aparecen indentadas con ↳ debajo de su sección principal, para que se pueda colocar el cuestionario dentro de un tema. Crea un **cuestionario estándar de Moodle** con 2 intentos, calificación más alta, 30 minutos, todas las preguntas en una página, 20 puntos y preguntas aleatorias distintas en cada intento, repartidas entre los bancos. Después se edita con el editor normal de cuestionarios; no crea un módulo de actividad nuevo.

Cada tema creado incluye: encabezado con 🚀 Tema y 🚀 Fecha · 🎯 Lo que vas a aprender (resumen y objetivos) · 🎥 Video de Apoyo · 📖 Material Didáctico (PDF) · 📝 Práctica Calificada (cuestionario calificado sobre 20, amarrado al banco de preguntas del tema) · 📝 Práctica de Extensión (tarea de investigación con texto en línea y archivos PDF, PPTX, DOC o DOCX).

El ítem "Yared.AI Creador de Pregunta" de la sección es un **enlace** al banco del curso, filtrado por la categoría de ese tema. La práctica calificada se crea **oculta** para que el docente la revise; el trabajo de investigación queda como tarea visible dentro de la sección. El video y el PDF se crean ocultos solo si no se pudieron generar; si el PDF completo falla, se crea un PDF de respaldo con el resumen, los objetivos y las ideas clave (nunca un archivo en blanco). La sección nueva también se crea oculta para revisarla.

## Matemáticas, LaTeX e imágenes
En **Yared.AI Creador de Pregunta**:
- Elige la tarjeta **Matemáticas con LaTeX** para que la IA escriba las fórmulas con `\( ... \)` (en línea) y `\[ ... \]` (destacadas). El filtro **MathJax** o **TeX** debe estar activo en Moodle para que se vean (*Administración del sitio → Extensiones → Filtros*); si no lo está, las preguntas se guardan igual y se muestra una advertencia.
- El campo **Instrucciones adicionales** permite pedir, por ejemplo, identidades trigonométricas, ejercicios de ángulos, nivel de dificultad o al menos dos pasos algebraicos. La IA debe seguir devolviendo el mismo JSON. Este campo y las opciones de imagen están disponibles en **Matemáticas con LaTeX**; en **Clásico** no se muestran.
- Elige el **Curso de matemática** (Razonamiento Matemático, Aritmética, Álgebra, Geometría, Trigonometría, Estadística y Probabilidad, Cálculo, Física u otro). El curso ajusta el estilo de los ejercicios y muestra **temas sugeridos** que se añaden o quitan con un clic (también puedes escribir los tuyos).
- Marca **Incluir figuras** (activo por defecto): la IA **describe** la figura (no dibuja ni inventa URLs) y el plugin la **dibuja como imagen SVG** con las medidas y letras exactas del enunciado. Soporta triángulos (por ángulos, lados o catetos, con marcas de lados iguales), cuadriláteros, círculos (puntos, radios, cuerdas, ángulo central), segmentos con longitudes, rectas paralelas con secante y plano cartesiano (puntos, polígono, rectas y parábolas). El dibujo es esquemático (no está a escala). La respuesta correcta aún no se verifica con código: revisa las preguntas con figura antes de publicarlas.
- En **opciones avanzadas** puedes indicar **URLs de imágenes propias** (una por línea, en orden), que reemplazan la figura de esa pregunta.
- El plugin descarga la imagen, comprueba que sea PNG, JPG, GIF o WebP (máximo 2 MB y dimensiones razonables) y la guarda como **archivo de la pregunta** en el banco. Las alternativas también aceptan imagen.
- **Base64:** Moodle elimina las imágenes `data:` del HTML por seguridad, así que no se insertan como base64 en el texto. El campo de URLs acepta `data:image/png;base64,...` como entrada y el plugin la convierte en archivo del banco. Así la pregunta funciona al exportar, importar y hacer copias de seguridad.
- Por seguridad, solo se descargan URLs públicas por HTTP/HTTPS en los puertos 80/443; se bloquean localhost, redes privadas, redirecciones y archivos que no sean imágenes. Si una URL falla, la pregunta se guarda sin esa imagen y se muestra una advertencia.

## Importar preguntas desde PDF o imágenes
En **Yared.AI Creador de Pregunta**, pulse **Crear preguntas desde PDF o imagen**.
- Hasta 10 archivos: PDFs de hasta 15 páginas y 12 MB, o imágenes PNG, JPG, GIF o WebP de hasta 10 MB. El lote se limita a 20 ejercicios.
- Se usan el proveedor principal y los respaldos configurados. Si el PDF no se separa bien, sube **una imagen por pregunta** (hasta 10): cada imagen se recorta y genera una pregunta. También puedes subir una sola imagen de la página completa para que la IA separe los ejercicios.
- Si el servidor tiene Python y PyMuPDF, cada página del PDF se convierte en imagen y se recorta igual que una foto. Si no, el PDF se envía tal cual: se conservan el texto y las alternativas, pero no se generan recortes y se muestra una advertencia.
- En las imágenes se recorta la figura detectada o, si no hay figura, el ejercicio completo (opcional).
- Antes de guardar se muestra una pantalla de revisión: puedes editar el enunciado, las alternativas, la respuesta correcta, la retroalimentación y el texto alternativo de la imagen, además de excluir preguntas del lote.
- Elige una categoría existente o crea una nueva. Las imágenes se guardan como **archivos de la pregunta**, no como base64.
- Esta versión guarda las preguntas en el banco; no crea la práctica calificada ni el cuestionario.

## API externa
Las funciones `local_ai_yared_course_create_course`, `_create_topic`, `_list_courses`, `_list_topics` y `_update_syllabus` están registradas en `db/services.php`. La generación usa el mismo conjunto de proveedor principal y respaldos; la app no necesita cambiar nada.

## Notas
- El proceso normalmente tarda unos segundos con un modelo flash-lite; puede llegar a varios minutos si el proveedor está lento o debe probar respaldos. El tiempo de espera recomendado es 600 s. Si usa nginx, suba `proxy_read_timeout`/`fastcgi_read_timeout` a 600 s. La página muestra el avance paso a paso.
- En **Yared.AI Creador de Pregunta**, la pantalla de espera se queda abierta hasta que el servidor termina. No cierre ni recargue la ventana; si el navegador o el proxy cortan la conexión, la generación puede quedar incompleta.
- Los textos son generados por IA: revíselos antes de publicar.
- Los bloques usan estilos en línea; si el docente los edita con el editor, algunos estilos pueden simplificarse.

### Creador de Material: opciones nuevas (0.13.0)
- **¿Dónde agregarlo?** Tarjetas para elegir; por defecto *En una sección existente* (primera sección vacía).
- **Crear PDF de la clase**: solo se genera el PDF si se marca.
- **Práctica Calificada**: todas las preguntas en una sola página (Nueva página: Nunca).
- **Crear Foro de discusión**: la IA escribe una pregunta según la edad; se publica como primera discusión. Calificación del foro completo: Puntuación, máximo 20 pts.

### Proceso en segundo plano (0.14.0)
- **Yared.AI Creador de Material** ya no se ejecuta dentro de la ventana: al pulsar *Crear* se guarda un trabajo (tabla `local_ai_yared_course_job`) y una **tarea ad hoc** de Moodle lo ejecuta con el cron, **con el usuario del docente** (sus permisos y sus borradores). Cada docente solo ve y cancela sus propios trabajos (los administradores pueden verlos todos).
- El docente puede cerrar la ventana o cambiar de pestaña; el avance se retoma en `job.php` (barra, pasos, botón **Cancelar proceso**, resumen final) desde el aviso o desde *Procesos recientes* del formulario.
- Un docente ejecuta **un trabajo a la vez**; docentes distintos pueden trabajar en paralelo según los procesos de cron disponibles.
- **Requisito:** el cron de Moodle debe ejecutarse cada minuto (en Docker, un contenedor o servicio de cron). Si no corre, el trabajo se marca con error tras 60 min en cola. Un trabajo sin avance por 30 min se marca con error. Los trabajos con más de 30 días se eliminan solos.
- Al cancelar, lo ya creado se mantiene en el curso.

### Índice de temas en secciones existentes (0.14.1)
Cuando los temas se agregan a una **sección existente**, el plugin crea (y mantiene actualizado) al inicio de la sección un recuadro **"Temas de esta sección"** con un enlace por tema (título y fecha) que salta directo a él. Se reconstruye a partir de los encabezados de tema de la sección, así que también incluye los temas creados antes y se corrige si se borran o reordenan. Con una sección nueva por tema no se crea. Moodle 4.5 no tiene subsecciones; en Moodle 5.0 o con el formato Flexsections se podría plegar cada tema (pendiente).

### Temas como subsecciones en acordeón o mosaico con Formato-Mariategui (0.16.3)
Si el curso usa el formato **Formato-Mariategui** (`format_flextiles`) y se elige una sección existente, cada tema se crea **siempre** como **subsección hija** de la sección elegida. El formulario ya no tiene una casilla para desactivarlo: en su lugar hay un **select obligatorio** con dos opciones:
- **Acordeón (predeterminado):** el tema y sus actividades se abren dentro de la sección elegida, sin abrir otra página (`displaymode=accordion`).
- **Mosaico:** se muestra una tarjeta de la subsección y el tema abre en su propia página (`displaymode=mosaic`).
- La lista **Sección del curso** muestra la jerarquía: las subsecciones aparecen indentadas con `↳` y el nombre de su sección principal, por ejemplo `2 · ···↳ tema01 (en Section_01)`.
- El enlace **Ir a la sección** del informe apunta a la sección padre en acordeón y a la propia subsección en mosaico.
- La sección **General** (número 0) no admite subsecciones: en ese caso los temas se agregan al final con el índice.
- Si el formato no puede colocar la subsección dentro de la sección elegida, la app elimina la sección huérfana y usa el índice de respaldo.
- Si Formato-Mariategui no está instalado o el curso usa otro formato, se mantiene el comportamiento anterior (Flexsections/Moodle 5 con casilla para plegar, o índice de enlaces).

### Temas como subsecciones plegadas (0.15.0)
Al agregar temas a una **sección existente**, el plugin detecta qué se puede usar:
- **Formato Flexsections** (`format_flexsections`) en el curso, en Moodle 4.x o 5.x: cada tema se crea como subsección hija de la sección elegida, con el nombre "Título — fecha" y **plegada** (el padre solo muestra el título como enlace y el tema abre en su propia página).
- **Moodle 5.0+** con la actividad *Subsección* (mod_subsection) y un formato que la admita: cada tema se crea como una subsección.
- **Moodle 4.x sin Flexsections** (o con Flexsections instalado pero el curso en otro formato): el formulario avisa qué instalar o cambiar, y los temas se agregan como antes con el índice de enlaces.
Se puede desmarcar *Crear cada tema como subsección plegada* en el formulario. Si la subsección falla, el tema se crea igual al final de la sección elegida.

## Versión 0.17.0 — API para el CRM y protección multiusuario

### Protección para muchos docentes (ajustes en *Protección para muchos docentes a la vez*)
- **Salto automático de proveedor compartido**: si un proveedor o una clave falla por cuota (429), conexión, servidor (5xx) o clave/modelo inválidos, se omite unos segundos **para todos los usuarios** y se usa el siguiente. Si todos fallan, igual se prueban.
- **Varias claves por proveedor**: en *Clave API* se pueden poner varias separadas por comas; se reparten en rotación y, si una falla, se usa la siguiente.
- **Tope de concurrencia**: máximo de solicitudes de IA simultáneas por proveedor y clave (4 por defecto); las demás esperan turno.
- **Un solo proceso activo por docente** con bloqueo (antes la comprobación y la creación no eran atómicas).
- Guardado de temas en una transacción; carpetas temporales de PDF únicas por solicitud (`make_request_directory`).
- Estado compartido en la caché `aiguard` (se crea al actualizar el plugin) y en el *lock factory* de Moodle. En clúster con varios servidores configure locks en base de datos o Redis.

### API de construcción de cursos (CRM)
Servicio web **Yared.AI CRM Docentes** (`local_ai_yared_course_crm`, solo usuarios autorizados):
- `local_ai_yared_course_build_course`: encola la construcción (clona la plantilla sin usuarios, crea secciones, crea los temas como subsecciones y matricula al docente). Responde de inmediato con `buildid`. El mismo `crm_ref` nunca crea dos cursos.
- `local_ai_yared_course_get_build_status`: estado (`queued`, `running`, `done`, `partial`, `failed`), progreso y resultado por sección/tema.
- `local_ai_yared_course_list_templates`: plantillas permitidas.
- Con `generate_ai=false` solo se crean las subsecciones con encabezado; con `true` cada tema se genera con IA **con los permisos del docente**.
- Webhook firmado (HMAC-SHA256 sobre `timestamp.cuerpo`; cabeceras `X-Timestamp`, `X-Signature`) a la URL configurada en los ajustes.

Puesta en marcha: ajustes del plugin → *Cursos plantilla permitidos*; ejecutar `php local/ai_yared_course/cli/setup_crm_api.php --categories=ID[,ID] --ip=IP_DEL_CRM --create-token` (usar `--dry-run` primero).

### Cambios que pueden afectar a integraciones existentes
- El servicio **Yared.AI Creador de Material** pasa a *solo usuarios autorizados*. Al actualizar se autoriza automáticamente a los usuarios que ya tenían token de ese servicio.

## Versión 0.18.0 — Reporte de uso y errores
*Administración del sitio → Extensiones → Plugins locales → Yared.AI → Reporte de uso* (también enlazado desde los ajustes). Requiere la capability `local/ai_yared_course:viewreport` (los administradores la tienen siempre; se puede dar a coordinación).
- **Resumen**: temas, docentes, preguntas, tokens; uso por docente (cursos, edad promedio), por nivel y resultado por IA.
- **Últimos eventos**: los últimos 100 (temas, cuestionarios, preguntas con IA, importaciones PDF/imagen y cursos desde plantilla) con docente, **código del curso**, tema, edad/nivel, IA, estado, qué se creó, duración. Filtros por periodo, docente, curso, IA y tipo.
- **Errores de IA**: solo se registra cuando una IA **falla** (nunca las llamadas exitosas), con causa (cuota, clave, modelo, servidor, tiempo de espera, conexión, respuesta vacía, contenido bloqueado, sin soporte de imágenes), HTTP y mensaje limpio de claves. Los fallos repetidos de la misma IA/clave y causa en 5 minutos se agrupan en una fila con contador. Una tabla de salud marca como **candidata a reemplazo** la IA o clave con N fallos en 24 h (5 por defecto) o sin éxito después de su último fallo.
- **Exportar** CSV o Excel de la pestaña y los filtros activos (hasta 50 000 filas).
- Retención configurable (30 días por defecto, máximo 365) y tope de 50 000 filas; el borrado lo hace la tarea programada existente. No se guardan prompts, respuestas de la IA ni claves.
- Si el registro falla, nunca detiene el trabajo del docente.

## 0.18.1 – Importador de preguntas (PDF / imágenes)

- LaTeX solo donde hay notación matemática real; texto, palabras y alternativas de teoría salen limpias (`latex_cleaner`).
- Soporta hasta 8 alternativas (A–H); si el modelo devuelve menos de las impresas, la pregunta queda marcada "revisar".
- Material teórico: nunca se recorta ni se genera imagen; solo se interpreta. Solo las figuras reales se recortan.
- Ejercicios sin alternativas (p. ej. "Lee el párrafo y halla las esdrújulas") conservan el párrafo y se reorganizan en selección múltiple, verdadero/falso, respuesta corta o ensayo.
- Nuevos tipos: selección múltiple, respuesta corta, ensayo.
- Nuevos campos del formulario: "Tipo de material" (auto/teoría/matemática) y "Ejercicios sin alternativas".
- Pendiente: relacionar columnas (match). No probado en un Moodle real; solo lint y prueba offline del normalizador.

## 0.19.0 – Importador en dos fases

- Se elimina "Tipo de material" y "Ejercicios sin alternativas" del formulario: la IA decide sola.
- La imagen se decide por pregunta: si el enunciado se refiere a una figura (foto, mapa, dibujo) se adjunta el recorte, sea teoría o matemática. En la revisión se puede agregar, quitar o ajustar por pregunta.
- Fase 1: detecta preguntas, transcribe (con el párrafo) y propone un formato recomendado y los viables.
- Fase 2: por pregunta se elige formato (opción múltiple, selección múltiple, verdadero/falso, elegir la palabra perdida `gapselect`, arrastrar y soltar sobre texto `ddwtos`, respuesta corta, ensayo) y se reformula con IA enviando el texto y la imagen; vista previa y aprobación antes de guardar. Botones "Otra variante", "Con otro modelo" (evita el proveedor usado) y "Restaurar original". Aplicar formato en lote a las seleccionadas.
- Respuesta corta y ensayo se muestran y se guardan al final, marcadas "revisión manual".
- Idioma de alternativas y retroalimentación: Español Latino (predeterminado) o Inglés. El enunciado conserva el idioma del documento.
- No hay cambios de esquema. Probado solo con lint y pruebas offline; falta QA en Moodle real (sobre todo `gapselect`/`ddwtos`).

## 0.19.1

- Corrige el JS de la pantalla de revisión (una variable de etiquetas sin definir provocaba un SyntaxError y ningún botón respondía).
- Ejercicios matemáticos vuelven a recibir por defecto el recorte del ejercicio completo; los de solo texto siguen sin imagen salvo que se refieran a una figura.
- La vista previa se abre en una ventana modal, con "Otra variante" y "Con otro modelo".

## 0.19.2

- Reformular / Otra variante / Con otro modelo / cambiar de formato actualizan solo la tarjeta de la pregunta, sin moverla ni desplazar la página. La tarjeta muestra un preloading ("Reformulando, espere…").
- Se procesan hasta 3 reformulaciones simultáneas por docente (cliente y servidor); el resto queda "En cola". Si ya hay una vista previa abierta, la tarjeta terminada se marca como "Lista".
- Respuesta corta y ensayo se guardan al final del banco (aviso en la tarjeta), no se reordenan en pantalla.
- Nuevo CSS para la pantalla de revisión y para el modal de vista previa.

## 0.23.0
- **Modo Clásico con todos los formatos:** opción múltiple, selección múltiple, verdadero/falso, elegir la palabra perdida, arrastrar y soltar sobre el texto y **Mixto** (esos cinco). **Respuesta corta y ensayo** solo se generan si el docente los elige; exigen revisión manual y se guardan al final del banco. Matemáticas mantiene opción múltiple, verdadero/falso y mixto (por las figuras).
- **Revisión antes de guardar:** ahora ofrece, como el importador de PDF, el formato (lista), **Reformular**, **Otra variante**, **Con otro modelo** (solo con más de un proveedor) y **Restaurar la sugerida**, con la leyenda de qué hace cada botón. La **vista previa** (ventana) incluye el selector de formato y esos botones, y avisa que respuesta corta y ensayo requieren revisión manual.
- «Otra pregunta» de 0.22.0 se sustituye por esos botones.
- Sin cambios de base de datos.

## 0.22.0
- **Revisar antes de guardar** (activo por defecto) en el generador: las preguntas no se guardan al generarlas, sino que se abre una pantalla de revisión donde el docente edita el enunciado, las alternativas, la respuesta correcta y la retroalimentación, ve la vista previa (con LaTeX y figura), pide **Otra pregunta** (o **Con otro modelo** si hay más de un proveedor) en lugar de una que no le guste, **vuelve a la anterior**, quita la figura y elige cuáles guardar. Se reutiliza la pantalla del importador; el borrador dura 24 horas.
- Con la casilla desmarcada se guarda directo, como antes.
- Sin cambios de base de datos.

## 0.21.0
- Matemáticas: selector de **curso** con temas sugeridos (clic para añadir o quitar) y guía de estilo por curso para la IA.
- **Figuras dibujadas por el plugin (SVG)** a partir de una descripción estructurada de la IA: triángulos, cuadriláteros, círculos, segmentos, rectas paralelas con secante y plano cartesiano. La IA ya no propone URLs de imágenes.
- Las URLs propias pasan a opciones avanzadas y reemplazan la figura de esa pregunta.
- Sin cambios de base de datos.

## 0.20.0

- Respuesta corta y ensayo ya no se generan automáticamente: lo que no trae alternativas se reescribe como opción múltiple (por defecto) o verdadero/falso. Si la IA devuelve una abierta, una segunda pasada la cierra (máx. 15 por lote). Lo que no se puede cerrar (dibujar, construir) queda desmarcado en "Guardar" con la etiqueta "No evaluable automáticamente".
- Reglas de distractores para preguntas de grupos ("escribe 3 países"): ningún elemento correcto repetido en todas las opciones.
- Insignia "Reformulada: cambia lo que evalúa" y "Ver original" en la vista previa.
- El recorte que el docente mueve se envía con cada reformulación, se guarda en el borrador y la IA lo usa para formular la pregunta.
- Leyenda plegable y ayuda en cada botón; "Con otro modelo" solo aparece si hay 2 o más proveedores configurados.
- "Restaurar" vuelve a la versión que la IA propuso al analizar.
