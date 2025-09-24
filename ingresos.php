<?php
require_once 'config/database.php';

$database = new Database();
$db = $database->getConnection();

$mensaje = '';
$tipo_mensaje = '';

// Procesar acciones
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['accion'])) {
        switch ($_POST['accion']) {
            case 'crear_ingreso':
                try {
                    $db->beginTransaction();
                    
                    // Insertar el ingreso principal
                    $stmt_ingreso = $db->prepare("INSERT INTO ingresos (proveedor, numero_factura, fecha_ingreso) VALUES (?, ?, ?)");
                    $stmt_ingreso->execute([
                        $_POST['proveedor'],
                        $_POST['numero_factura'],
                        $_POST['fecha_ingreso']
                    ]);
                    
                    $ingreso_id = $db->lastInsertId();
                    $total_factura = 0;
                    
                    // Insertar detalles del ingreso
                    $productos = $_POST['productos'];
                    $cantidades = $_POST['cantidades'];
                    $precios_compra = $_POST['precios_compra'];
                    
                    for ($i = 0; $i < count($productos); $i++) {
                        if (!empty($cantidades[$i]) && $cantidades[$i] > 0) {
                            $subtotal = $cantidades[$i] * $precios_compra[$i];
                            $total_factura += $subtotal;
                            
                            // Insertar detalle
                            $stmt_detalle = $db->prepare("INSERT INTO detalle_ingresos (ingreso_id, producto_id, cantidad, precio_compra, subtotal) VALUES (?, ?, ?, ?, ?)");
                            $stmt_detalle->execute([
                                $ingreso_id,
                                $productos[$i],
                                $cantidades[$i],
                                $precios_compra[$i],
                                $subtotal
                            ]);
                            
                            // Actualizar existencia del producto
                            $stmt_update = $db->prepare("UPDATE productos SET existencia = existencia + ? WHERE id = ?");
                            $stmt_update->execute([$cantidades[$i], $productos[$i]]);
                        }
                    }
                    
                    // Actualizar total de la factura
                    $stmt_total = $db->prepare("UPDATE ingresos SET total_factura = ? WHERE id = ?");
                    $stmt_total->execute([$total_factura, $ingreso_id]);
                    
                    $db->commit();
                    $mensaje = "Ingreso registrado exitosamente. Total: $" . number_format($total_factura, 2);
                    $tipo_mensaje = "success";
                    
                } catch (Exception $e) {
                    $db->rollBack();
                    $mensaje = "Error al registrar el ingreso: " . $e->getMessage();
                    $tipo_mensaje = "danger";
                }
                break;
                
            case 'eliminar_ingreso':
                try {
                    $db->beginTransaction();
                    
                    // Obtener detalles del ingreso para revertir existencias
                    $stmt_detalles = $db->prepare("SELECT producto_id, cantidad FROM detalle_ingresos WHERE ingreso_id = ?");
                    $stmt_detalles->execute([$_POST['ingreso_id']]);
                    $detalles = $stmt_detalles->fetchAll();
                    
                    // Revertir existencias
                    foreach ($detalles as $detalle) {
                        $stmt_revertir = $db->prepare("UPDATE productos SET existencia = existencia - ? WHERE id = ?");
                        $stmt_revertir->execute([$detalle['cantidad'], $detalle['producto_id']]);
                    }
                    
                    // Eliminar ingreso (los detalles se eliminan automáticamente por CASCADE)
                    $stmt_eliminar = $db->prepare("DELETE FROM ingresos WHERE id = ?");
                    $stmt_eliminar->execute([$_POST['ingreso_id']]);
                    
                    $db->commit();
                    $mensaje = "Ingreso eliminado exitosamente.";
                    $tipo_mensaje = "success";
                    
                } catch (Exception $e) {
                    $db->rollBack();
                    $mensaje = "Error al eliminar el ingreso: " . $e->getMessage();
                    $tipo_mensaje = "danger";
                }
                break;
        }
    }
}

// Obtener proveedores únicos
$stmt_proveedores = $db->prepare("SELECT DISTINCT proveedor FROM productos ORDER BY proveedor");
$stmt_proveedores->execute();
$proveedores = $stmt_proveedores->fetchAll();

// Obtener ingresos con paginación
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 10;
$offset = ($page - 1) * $limit;

// Filtros
$fecha_desde = isset($_GET['fecha_desde']) ? $_GET['fecha_desde'] : '';
$fecha_hasta = isset($_GET['fecha_hasta']) ? $_GET['fecha_hasta'] : '';
$proveedor_filter = isset($_GET['proveedor_filter']) ? $_GET['proveedor_filter'] : '';

$where_conditions = [];
$params = [];

if (!empty($fecha_desde)) {
    $where_conditions[] = "i.fecha_ingreso >= ?";
    $params[] = $fecha_desde;
}

if (!empty($fecha_hasta)) {
    $where_conditions[] = "i.fecha_ingreso <= ?";
    $params[] = $fecha_hasta;
}

if (!empty($proveedor_filter)) {
    $where_conditions[] = "i.proveedor = ?";
    $params[] = $proveedor_filter;
}

$where_clause = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";

// Contar total de ingresos
$count_query = "SELECT COUNT(*) as total FROM ingresos i $where_clause";
$stmt_count = $db->prepare($count_query);
$stmt_count->execute($params);
$total_ingresos = $stmt_count->fetch()['total'];
$total_pages = ceil($total_ingresos / $limit);

// Obtener ingresos
$query = "SELECT i.*, 
          (SELECT COUNT(*) FROM detalle_ingresos di WHERE di.ingreso_id = i.id) as total_productos
          FROM ingresos i 
          $where_clause 
          ORDER BY i.fecha_ingreso DESC, i.fecha_creacion DESC 
          LIMIT $limit OFFSET $offset";
$stmt = $db->prepare($query);
$stmt->execute($params);
$ingresos = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Ingresos - Sistema de Inventario</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        .sidebar {
            height: 100vh;
            background-color: #343a40;
        }
        .sidebar .nav-link {
            color: #adb5bd;
        }
        .sidebar .nav-link:hover {
            color: #fff;
            background-color: #495057;
        }
        .sidebar .nav-link.active {
            color: #fff;
            background-color: #0d6efd;
        }
        .main-content {
            margin-left: 0;
        }
        @media (min-width: 768px) {
            .main-content {
                margin-left: 250px;
            }
        }
        .producto-row {
            border-bottom: 1px solid #dee2e6;
            padding: 10px 0;
        }
        .producto-row:last-child {
            border-bottom: none;
        }
        .detalle-producto {
            background-color: #f8f9fa;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 15px;
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <nav class="col-md-3 col-lg-2 d-md-block sidebar collapse">
                <div class="position-sticky pt-3">
                    <div class="text-center mb-4">
                        <h4 class="text-white">Inventario</h4>
                    </div>
                    <ul class="nav flex-column">
                        <li class="nav-item">
                            <a class="nav-link" href="index.php">
                                <i class="bi bi-house-door"></i> Dashboard
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="productos.php">
                                <i class="bi bi-box"></i> Productos
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="inventario.php">
                                <i class="bi bi-clipboard-data"></i> Inventario
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link active" href="ingresos.php">
                                <i class="bi bi-arrow-down-circle"></i> Ingresos
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="distribuciones.php">
                                <i class="bi bi-arrow-up-circle"></i> Distribuciones
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="reportes.php">
                                <i class="bi bi-file-earmark-bar-graph"></i> Reportes
                            </a>
                        </li>
                    </ul>
                </div>
            </nav>

            <!-- Main content -->
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 main-content">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">Gestión de Ingresos</h1>
                    <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#modalIngreso">
                        <i class="bi bi-plus-lg"></i> Nuevo Ingreso
                    </button>
                </div>

                <!-- Mensajes -->
                <?php if (!empty($mensaje)): ?>
                    <div class="alert alert-<?php echo $tipo_mensaje; ?> alert-dismissible fade show" role="alert">
                        <?php echo htmlspecialchars($mensaje); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Filtros -->
                <div class="card mb-4">
                    <div class="card-body">
                        <form method="GET" class="row g-3">
                            <div class="col-md-3">
                                <label for="fecha_desde" class="form-label">Fecha Desde</label>
                                <input type="date" class="form-control" id="fecha_desde" name="fecha_desde" value="<?php echo $fecha_desde; ?>">
                            </div>
                            <div class="col-md-3">
                                <label for="fecha_hasta" class="form-label">Fecha Hasta</label>
                                <input type="date" class="form-control" id="fecha_hasta" name="fecha_hasta" value="<?php echo $fecha_hasta; ?>">
                            </div>
                            <div class="col-md-3">
                                <label for="proveedor_filter" class="form-label">Proveedor</label>
                                <select class="form-select" id="proveedor_filter" name="proveedor_filter">
                                    <option value="">Todos los proveedores</option>
                                    <?php foreach ($proveedores as $proveedor): ?>
                                        <option value="<?php echo htmlspecialchars($proveedor['proveedor']); ?>"
                                                <?php echo $proveedor_filter == $proveedor['proveedor'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($proveedor['proveedor']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3 d-flex align-items-end">
                                <button type="submit" class="btn btn-outline-primary me-2">
                                    <i class="bi bi-search"></i> Filtrar
                                </button>
                                <a href="ingresos.php" class="btn btn-outline-secondary">
                                    <i class="bi bi-x-lg"></i> Limpiar
                                </a>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Lista de ingresos -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title mb-0">Lista de Ingresos (<?php echo $total_ingresos; ?> total)</h5>
                    </div>
                    <div class="card-body">
                        <?php if (count($ingresos) > 0): ?>
                            <div class="table-responsive">
                                <table class="table table-striped table-hover">
                                    <thead class="table-dark">
                                        <tr>
                                            <th>Fecha</th>
                                            <th>Proveedor</th>
                                            <th>Nº Factura</th>
                                            <th>Productos</th>
                                            <th>Total Factura</th>
                                            <th>Acciones</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($ingresos as $ingreso): ?>
                                            <tr>
                                                <td><?php echo date('d/m/Y', strtotime($ingreso['fecha_ingreso'])); ?></td>
                                                <td><?php echo htmlspecialchars($ingreso['proveedor']); ?></td>
                                                <td><?php echo htmlspecialchars($ingreso['numero_factura']); ?></td>
                                                <td>
                                                    <span class="badge bg-info"><?php echo $ingreso['total_productos']; ?> productos</span>
                                                </td>
                                                <td class="fw-bold">$<?php echo number_format($ingreso['total_factura'], 2); ?></td>
                                                <td>
                                                    <button type="button" class="btn btn-sm btn-outline-info" 
                                                            onclick="verDetalle(<?php echo $ingreso['id']; ?>)">
                                                        <i class="bi bi-eye"></i> Ver
                                                    </button>
                                                    <button type="button" class="btn btn-sm btn-outline-danger" 
                                                            onclick="eliminarIngreso(<?php echo $ingreso['id']; ?>, '<?php echo htmlspecialchars($ingreso['numero_factura']); ?>')">
                                                        <i class="bi bi-trash"></i>
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                            <!-- Paginación -->
                            <?php if ($total_pages > 1): ?>
                                <nav aria-label="Navegación de ingresos">
                                    <ul class="pagination justify-content-center">
                                        <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                            <a class="page-link" href="?page=<?php echo $page - 1; ?>&fecha_desde=<?php echo $fecha_desde; ?>&fecha_hasta=<?php echo $fecha_hasta; ?>&proveedor_filter=<?php echo urlencode($proveedor_filter); ?>">Anterior</a>
                                        </li>
                                        
                                        <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                                            <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                                <a class="page-link" href="?page=<?php echo $i; ?>&fecha_desde=<?php echo $fecha_desde; ?>&fecha_hasta=<?php echo $fecha_hasta; ?>&proveedor_filter=<?php echo urlencode($proveedor_filter); ?>"><?php echo $i; ?></a>
                                            </li>
                                        <?php endfor; ?>
                                        
                                        <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                                            <a class="page-link" href="?page=<?php echo $page + 1; ?>&fecha_desde=<?php echo $fecha_desde; ?>&fecha_hasta=<?php echo $fecha_hasta; ?>&proveedor_filter=<?php echo urlencode($proveedor_filter); ?>">Siguiente</a>
                                        </li>
                                    </ul>
                                </nav>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="alert alert-info">
                                <i class="bi bi-info-circle"></i> No se encontraron ingresos con los criterios especificados.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <!-- Modal para nuevo ingreso -->
    <div class="modal fade" id="modalIngreso" tabindex="-1" aria-labelledby="modalIngresoLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalIngresoLabel">Nuevo Ingreso de Productos</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="formIngreso" method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="accion" value="crear_ingreso">
                        
                        <div class="row mb-3">
                            <div class="col-md-4">
                                <label for="proveedor" class="form-label">Proveedor <span class="text-danger">*</span></label>
                                <select class="form-select" id="proveedor" name="proveedor" required>
                                    <option value="">Seleccionar proveedor...</option>
                                    <?php foreach ($proveedores as $proveedor): ?>
                                        <option value="<?php echo htmlspecialchars($proveedor['proveedor']); ?>">
                                            <?php echo htmlspecialchars($proveedor['proveedor']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label for="numero_factura" class="form-label">Nº Factura <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="numero_factura" name="numero_factura" required>
                            </div>
                            <div class="col-md-4">
                                <label for="fecha_ingreso" class="form-label">Fecha Ingreso <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" id="fecha_ingreso" name="fecha_ingreso" required value="<?php echo date('Y-m-d'); ?>">
                            </div>
                        </div>

                        <hr>
                        
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h6 class="mb-0">Productos del Proveedor</h6>
                            <div id="total-factura" class="h5 mb-0 text-success">Total: $0.00</div>
                        </div>
                        
                        <div id="productos-container">
                            <div class="alert alert-info">
                                <i class="bi bi-info-circle"></i> Selecciona un proveedor para ver sus productos disponibles.
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-success" id="btnGuardarIngreso" disabled>
                            <i class="bi bi-save"></i> Guardar Ingreso
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal para ver detalle -->
    <div class="modal fade" id="modalDetalle" tabindex="-1" aria-labelledby="modalDetalleLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalDetalleLabel">Detalle del Ingreso</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="detalleContent">
                    <!-- Contenido se carga dinámicamente -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal de confirmación para eliminar -->
    <div class="modal fade" id="modalEliminar" tabindex="-1" aria-labelledby="modalEliminarLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalEliminarLabel">Confirmar Eliminación</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p>¿Está seguro que desea eliminar el ingreso de la factura <strong id="factura_eliminar"></strong>?</p>
                    <p class="text-danger">Esta acción revertirá las existencias y no se puede deshacer.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <form method="POST" style="display: inline;">
                        <input type="hidden" name="accion" value="eliminar_ingreso">
                        <input type="hidden" name="ingreso_id" id="ingreso_id_eliminar">
                        <button type="submit" class="btn btn-danger">Eliminar</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Cargar productos del proveedor seleccionado
        document.getElementById('proveedor').addEventListener('change', function() {
            const proveedor = this.value;
            const container = document.getElementById('productos-container');
            const btnGuardar = document.getElementById('btnGuardarIngreso');
            
            if (!proveedor) {
                container.innerHTML = '<div class="alert alert-info"><i class="bi bi-info-circle"></i> Selecciona un proveedor para ver sus productos disponibles.</div>';
                btnGuardar.disabled = true;
                return;
            }
            
            // Hacer petición AJAX para obtener productos
            fetch(`get_productos_proveedor.php?proveedor=${encodeURIComponent(proveedor)}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.productos.length > 0) {
                        let html = '';
                        data.productos.forEach((producto, index) => {
                            html += `
                                <div class="detalle-producto">
                                    <div class="row align-items-center">
                                        <div class="col-md-5">
                                            <strong>${producto.descripcion}</strong><br>
                                            <small class="text-muted">Existencia actual: ${producto.existencia}</small>
                                            <input type="hidden" name="productos[]" value="${producto.id}">
                                        </div>
                                        <div class="col-md-2">
                                            <label class="form-label">Cantidad</label>
                                            <input type="number" class="form-control cantidad-input" name="cantidades[]" 
                                                   min="0" step="1" value="0" onchange="calcularTotal()">
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label">Precio Compra</label>
                                            <div class="input-group">
                                                <span class="input-group-text">$</span>
                                                <input type="number" class="form-control precio-input" name="precios_compra[]" 
                                                       min="0" step="0.01" value="0" onchange="calcularTotal()">
                                            </div>
                                        </div>
                                        <div class="col-md-2">
                                            <label class="form-label">Subtotal</label>
                                            <div class="fw-bold subtotal-display">$0.00</div>
                                        </div>
                                    </div>
                                </div>
                            `;
                        });
                        container.innerHTML = html;
                        btnGuardar.disabled = false;
                    } else {
                        container.innerHTML = '<div class="alert alert-warning"><i class="bi bi-exclamation-triangle"></i> No hay productos registrados para este proveedor.</div>';
                        btnGuardar.disabled = true;
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    container.innerHTML = '<div class="alert alert-danger"><i class="bi bi-exclamation-triangle"></i> Error al cargar los productos.</div>';
                    btnGuardar.disabled = true;
                });
        });

        // Calcular total
        function calcularTotal() {
            let total = 0;
            const rows = document.querySelectorAll('.detalle-producto');
            
            rows.forEach(row => {
                const cantidad = parseFloat(row.querySelector('.cantidad-input').value) || 0;
                const precio = parseFloat(row.querySelector('.precio-input').value) || 0;
                const subtotal = cantidad * precio;
                
                row.querySelector('.subtotal-display').textContent = '$' + subtotal.toFixed(2);
                total += subtotal;
            });
            
            document.getElementById('total-factura').textContent = 'Total: $' + total.toFixed(2);
        }

        // Ver detalle del ingreso
        function verDetalle(ingresoId) {
            fetch(`get_detalle_ingreso.php?id=${ingresoId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        let html = `
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <strong>Proveedor:</strong> ${data.ingreso.proveedor}<br>
                                    <strong>Nº Factura:</strong> ${data.ingreso.numero_factura}<br>
                                    <strong>Fecha:</strong> ${data.ingreso.fecha_ingreso}
                                </div>
                                <div class="col-md-6">
                                    <strong>Total Factura:</strong> $${parseFloat(data.ingreso.total_factura).toFixed(2)}<br>
                                    <strong>Fecha Registro:</strong> ${data.ingreso.fecha_creacion}
                                </div>
                            </div>
                            <hr>
                            <h6>Productos</h6>
                            <div class="table-responsive">
                                <table class="table table-sm">
                                    <thead>
                                        <tr>
                                            <th>Descripción</th>
                                            <th>Cantidad</th>
                                            <th>Precio Compra</th>
                                            <th>Subtotal</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                        `;
                        
                        data.detalles.forEach(detalle => {
                            html += `
                                <tr>
                                    <td>${detalle.descripcion}</td>
                                    <td>${detalle.cantidad}</td>
                                    <td>$${parseFloat(detalle.precio_compra).toFixed(2)}</td>
                                    <td>$${parseFloat(detalle.subtotal).toFixed(2)}</td>
                                </tr>
                            `;
                        });
                        
                        html += `
                                    </tbody>
                                </table>
                            </div>
                        `;
                        
                        document.getElementById('detalleContent').innerHTML = html;
                        const modal = new bootstrap.Modal(document.getElementById('modalDetalle'));
                        modal.show();
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error al cargar el detalle del ingreso.');
                });
        }

        // Eliminar ingreso
        function eliminarIngreso(id, factura) {
            document.getElementById('ingreso_id_eliminar').value = id;
            document.getElementById('factura_eliminar').textContent = factura;
            
            const modal = new bootstrap.Modal(document.getElementById('modalEliminar'));
            modal.show();
        }

        // Limpiar formulario al cerrar modal
        document.getElementById('modalIngreso').addEventListener('hidden.bs.modal', function () {
            document.getElementById('formIngreso').reset();
            document.getElementById('productos-container').innerHTML = '<div class="alert alert-info"><i class="bi bi-info-circle"></i> Selecciona un proveedor para ver sus productos disponibles.</div>';
            document.getElementById('btnGuardarIngreso').disabled = true;
            document.getElementById('total-factura').textContent = 'Total: $0.00';
        });

        // Validación del formulario
        document.getElementById('formIngreso').addEventListener('submit', function(e) {
            const proveedor = document.getElementById('proveedor').value;
            const numeroFactura = document.getElementById('numero_factura').value.trim();
            const fechaIngreso = document.getElementById('fecha_ingreso').value;
            
            if (!proveedor || !numeroFactura || !fechaIngreso) {
                e.preventDefault();
                alert('Por favor, complete todos los campos obligatorios.');
                return false;
            }
            
            // Verificar que al menos un producto tenga cantidad > 0
            const cantidades = document.querySelectorAll('.cantidad-input');
            let hayProductos = false;
            
            cantidades.forEach(input => {
                if (parseFloat(input.value) > 0) {
                    hayProductos = true;
                }
            });
            
            if (!hayProductos) {
                e.preventDefault();
                alert('Debe ingresar al menos un producto con cantidad mayor a 0.');
                return false;
            }
            
            // Confirmar envío
            const total = document.getElementById('total-factura').textContent;
            if (!confirm(`¿Confirmar el ingreso de productos por ${total}?`)) {
                e.preventDefault();
                return false;
            }
        });

        // Autocompletar fecha actual al abrir el modal
        document.getElementById('modalIngreso').addEventListener('show.bs.modal', function () {
            document.getElementById('fecha_ingreso').value = new Date().toISOString().split('T')[0];
        });
    </script>
</body>
</html>