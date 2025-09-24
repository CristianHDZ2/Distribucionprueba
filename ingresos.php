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
                            
                            // **NUEVO: Actualizar precio de compra más reciente en productos**
                            $stmt_update_precio = $db->prepare("UPDATE productos SET ultimo_precio_compra = ?, fecha_ultimo_precio_compra = NOW() WHERE id = ?");
                            $stmt_update_precio->execute([$precios_compra[$i], $productos[$i]]);
                            
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
        
        /* **ESTILOS NUEVOS PARA PRECIOS RECORDADOS** */
        .precio-recordado {
            background-color: #e7f3ff;
            border: 2px solid #0066cc;
            position: relative;
        }
        
        .precio-recordado::before {
            content: "💡";
            position: absolute;
            left: -25px;
            top: 50%;
            transform: translateY(-50%);
            font-size: 16px;
        }
        
        .precio-sugerido-badge {
            position: absolute;
            top: -8px;
            right: -8px;
            background: #28a745;
            color: white;
            font-size: 10px;
            padding: 2px 6px;
            border-radius: 10px;
            z-index: 10;
        }
        
        .precio-container {
            position: relative;
        }
        
        .historial-precios {
            font-size: 11px;
            color: #666;
            margin-top: 2px;
        }
        
        .precio-anterior {
            background-color: #f8f9fa;
            padding: 2px 6px;
            border-radius: 3px;
            margin-right: 5px;
            border: 1px solid #dee2e6;
        }
        
        .precio-promedio {
            color: #28a745;
            font-weight: bold;
        }
        
        .sin-historial {
            color: #dc3545;
            font-style: italic;
        }
        
        /* Animación para campos autocompletados */
        @keyframes destacarAutocomplete {
            0% { background-color: #fff3cd; }
            100% { background-color: #e7f3ff; }
        }
        
        .autocomplete-animation {
            animation: destacarAutocomplete 1s ease-out;
        }
        
        /* Tooltip personalizado */
        .tooltip-precio {
            position: relative;
            cursor: help;
        }
        
        .tooltip-precio:hover::after {
            content: attr(data-tooltip);
            position: absolute;
            bottom: 100%;
            left: 50%;
            transform: translateX(-50%);
            background: rgba(0,0,0,0.8);
            color: white;
            padding: 5px 8px;
            border-radius: 4px;
            font-size: 12px;
            white-space: nowrap;
            z-index: 1000;
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <nav class="col-md-3 col-lg-2 d-md-block sidebar collapse">
                <div class="position-sticky pt-3">
                    <h6 class="sidebar-heading d-flex justify-content-between align-items-center px-3 mt-4 mb-1 text-muted">
                        <span>SISTEMA DE INVENTARIO</span>
                    </h6>
                    <ul class="nav flex-column">
                        <li class="nav-item">
                            <a class="nav-link" href="dashboard.php">
                                <i class="bi bi-house-door"></i> Dashboard
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="productos.php">
                                <i class="bi bi-box"></i> Productos
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link active" href="ingresos.php">
                                <i class="bi bi-plus-circle"></i> Ingresos
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="distribuciones.php">
                                <i class="bi bi-truck"></i> Distribuciones
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="reportes.php">
                                <i class="bi bi-bar-chart"></i> Reportes
                            </a>
                        </li>
                    </ul>
                </div>
            </nav>

            <!-- Contenido principal -->
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 main-content">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2"><i class="bi bi-plus-circle"></i> Gestión de Ingresos</h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalNuevoIngreso">
                            <i class="bi bi-plus-lg"></i> Nuevo Ingreso
                        </button>
                    </div>
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
                                <label for="fecha_desde" class="form-label">Desde:</label>
                                <input type="date" class="form-control" id="fecha_desde" name="fecha_desde" 
                                       value="<?php echo htmlspecialchars($fecha_desde); ?>">
                            </div>
                            <div class="col-md-3">
                                <label for="fecha_hasta" class="form-label">Hasta:</label>
                                <input type="date" class="form-control" id="fecha_hasta" name="fecha_hasta" 
                                       value="<?php echo htmlspecialchars($fecha_hasta); ?>">
                            </div>
                            <div class="col-md-4">
                                <label for="proveedor_filter" class="form-label">Proveedor:</label>
                                <select class="form-select" id="proveedor_filter" name="proveedor_filter">
                                    <option value="">Todos los proveedores</option>
                                    <?php foreach ($proveedores as $proveedor): ?>
                                        <option value="<?php echo htmlspecialchars($proveedor['proveedor']); ?>"
                                                <?php echo ($proveedor_filter == $proveedor['proveedor']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($proveedor['proveedor']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2 d-flex align-items-end">
                                <button type="submit" class="btn btn-outline-primary w-100">
                                    <i class="bi bi-funnel"></i> Filtrar
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Lista de ingresos -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title mb-0">
                            <i class="bi bi-list-ul"></i> Lista de Ingresos
                            <span class="badge bg-primary"><?php echo $total_ingresos; ?> registros</span>
                        </h5>
                    </div>
                    <div class="card-body p-0">
                        <?php if (count($ingresos) > 0): ?>
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead class="table-dark">
                                        <tr>
                                            <th>Fecha</th>
                                            <th>Proveedor</th>
                                            <th>Factura</th>
                                            <th>Productos</th>
                                            <th>Total</th>
                                            <th>Acciones</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($ingresos as $ingreso): ?>
                                            <tr>
                                                <td>
                                                    <i class="bi bi-calendar3"></i>
                                                    <?php echo date('d/m/Y', strtotime($ingreso['fecha_ingreso'])); ?>
                                                </td>
                                                <td>
                                                    <strong><?php echo htmlspecialchars($ingreso['proveedor']); ?></strong>
                                                </td>
                                                <td>
                                                    <code><?php echo htmlspecialchars($ingreso['numero_factura']); ?></code>
                                                </td>
                                                <td>
                                                    <span class="badge bg-info"><?php echo $ingreso['total_productos']; ?> productos</span>
                                                </td>
                                                <td>
                                                    <strong class="text-success">$<?php echo number_format($ingreso['total_factura'], 2); ?></strong>
                                                </td>
                                                <td>
                                                    <div class="btn-group btn-group-sm">
                                                        <button type="button" class="btn btn-outline-info" 
                                                                onclick="verDetalleIngreso(<?php echo $ingreso['id']; ?>)"
                                                                title="Ver detalle">
                                                            <i class="bi bi-eye"></i>
                                                        </button>
                                                        <button type="button" class="btn btn-outline-danger" 
                                                                onclick="eliminarIngreso(<?php echo $ingreso['id']; ?>)"
                                                                title="Eliminar ingreso">
                                                            <i class="bi bi-trash"></i>
                                                        </button>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-5">
                                <i class="bi bi-inbox display-1 text-muted"></i>
                                <h5 class="text-muted mt-3">No hay ingresos registrados</h5>
                                <p class="text-muted">Comienza registrando tu primer ingreso</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Paginación -->
                <?php if ($total_pages > 1): ?>
                    <nav class="mt-4">
                        <ul class="pagination justify-content-center">
                            <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                <a class="page-link" href="?page=<?php echo max(1, $page - 1); ?>&fecha_desde=<?php echo urlencode($fecha_desde); ?>&fecha_hasta=<?php echo urlencode($fecha_hasta); ?>&proveedor_filter=<?php echo urlencode($proveedor_filter); ?>">Anterior</a>
                            </li>
                            
                            <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                                <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                    <a class="page-link" href="?page=<?php echo $i; ?>&fecha_desde=<?php echo urlencode($fecha_desde); ?>&fecha_hasta=<?php echo urlencode($fecha_hasta); ?>&proveedor_filter=<?php echo urlencode($proveedor_filter); ?>"><?php echo $i; ?></a>
                                </li>
                            <?php endfor; ?>
                            
                            <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                                <a class="page-link" href="?page=<?php echo min($total_pages, $page + 1); ?>&fecha_desde=<?php echo urlencode($fecha_desde); ?>&fecha_hasta=<?php echo urlencode($fecha_hasta); ?>&proveedor_filter=<?php echo urlencode($proveedor_filter); ?>">Siguiente</a>
                            </li>
                        </ul>
                    </nav>
                <?php endif; ?>

            </main>
        </div>
    </div>

    <!-- **MODAL NUEVO INGRESO CON PRECIOS RECORDADOS** -->
    <div class="modal fade" id="modalNuevoIngreso" tabindex="-1" aria-labelledby="modalNuevoIngresoLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalNuevoIngresoLabel">
                        <i class="bi bi-plus-lg"></i> Nuevo Ingreso
                        <small class="text-muted">- Con precios recordados automáticamente</small>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="formNuevoIngreso">
                    <input type="hidden" name="accion" value="crear_ingreso">
                    <div class="modal-body">
                        <div class="row mb-3">
                            <div class="col-md-4">
                                <label for="proveedor" class="form-label">Proveedor:</label>
                                <select class="form-select" id="proveedor" name="proveedor" required onchange="cargarProductosConPrecios()">
                                    <option value="">Seleccionar proveedor</option>
                                    <?php foreach ($proveedores as $proveedor): ?>
                                        <option value="<?php echo htmlspecialchars($proveedor['proveedor']); ?>">
                                            <?php echo htmlspecialchars($proveedor['proveedor']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label for="numero_factura" class="form-label">Número de Factura:</label>
                                <input type="text" class="form-control" id="numero_factura" name="numero_factura" required>
                            </div>
                            <div class="col-md-4">
                                <label for="fecha_ingreso" class="form-label">Fecha de Ingreso:</label>
                                <input type="date" class="form-control" id="fecha_ingreso" name="fecha_ingreso" 
                                       value="<?php echo date('Y-m-d'); ?>" required>
                            </div>
                        </div>

                        <hr>

                        <div class="row mb-3">
                            <div class="col-md-8">
                                <h6><i class="bi bi-box"></i> Productos del Ingreso</h6>
                                <small class="text-muted">
                                    Los precios se autocompletarán con el último precio de compra registrado para cada producto
                                </small>
                            </div>
                            <div class="col-md-4 text-end">
                                <button type="button" class="btn btn-outline-success btn-sm" onclick="agregarProducto()">
                                    <i class="bi bi-plus-lg"></i> Agregar Producto
                                </button>
                            </div>
                        </div>

                        <div id="productos-container">
                            <!-- Los productos se cargarán dinámicamente aquí -->
                        </div>

                        <div class="row mt-3">
                            <div class="col-md-8">
                                <div class="alert alert-info">
                                    <i class="bi bi-info-circle"></i>
                                    <strong>Información:</strong> Los campos con 💡 muestran precios sugeridos basados en compras anteriores.
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="card bg-light">
                                    <div class="card-body text-center">
                                        <h6>Total Estimado:</h6>
                                        <h4 id="total-estimado" class="text-primary">$0.00</h4>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            <i class="bi bi-x-circle"></i> Cancelar
                        </button>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-lg"></i> Registrar Ingreso
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal para ver detalle -->
    <div class="modal fade" id="modalDetalleIngreso" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-eye"></i> Detalle del Ingreso
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="detalle-content">
                    <div class="text-center">
                        <div class="spinner-border" role="status"></div>
                        <p class="mt-2">Cargando...</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal eliminar -->
    <div class="modal fade" id="modalEliminar" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title text-danger">
                        <i class="bi bi-exclamation-triangle"></i> Confirmar Eliminación
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>¿Está seguro que desea eliminar este ingreso?</p>
                    <div class="alert alert-warning">
                        <strong>Advertencia:</strong> Esta acción revertirá las existencias de los productos y no se puede deshacer.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <form method="POST" style="display: inline;">
                        <input type="hidden" name="accion" value="eliminar_ingreso">
                        <input type="hidden" name="ingreso_id" id="ingreso_id_eliminar">
                        <button type="submit" class="btn btn-danger">
                            <i class="bi bi-trash"></i> Eliminar
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // **SISTEMA DE PRECIOS RECORDADOS - JAVASCRIPT COMPLETO**
        
        let contadorProductos = 0;
        let productosData = {}; // Cache de información de productos
        
        // **FUNCIÓN PRINCIPAL: Cargar productos con precios recordados**
        async function cargarProductosConPrecios() {
            const proveedor = document.getElementById('proveedor').value;
            
            if (!proveedor) {
                document.getElementById('productos-container').innerHTML = '';
                return;
            }
            
            try {
                const response = await fetch('obtener_productos_con_precios.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `proveedor=${encodeURIComponent(proveedor)}`
                });
                
                const data = await response.json();
                
                if (data.success) {
                    productosData = data.productos;
                    mostrarProductosDisponibles(data.productos);
                } else {
                    console.error('Error:', data.message);
                    document.getElementById('productos-container').innerHTML = 
                        '<div class="alert alert-danger">Error al cargar productos</div>';
                }
            } catch (error) {
                console.error('Error de conexión:', error);
                document.getElementById('productos-container').innerHTML = 
                    '<div class="alert alert-danger">Error de conexión al cargar productos</div>';
            }
        }

        // **FUNCIÓN: Mostrar productos disponibles**
        function mostrarProductosDisponibles(productos) {
            const container = document.getElementById('productos-container');
            
            if (productos.length === 0) {
                container.innerHTML = `
                    <div class="alert alert-warning">
                        <i class="bi bi-exclamation-triangle"></i>
                        No se encontraron productos para este proveedor.
                    </div>
                `;
                return;
            }
            
            let html = `
                <div class="card">
                    <div class="card-header">
                        <h6><i class="bi bi-list-check"></i> Productos Disponibles (${productos.length})</h6>
                    </div>
                    <div class="card-body">
            `;
            
            productos.forEach((producto, index) => {
                html += crearFilaProducto(producto, index);
            });
            
            html += `
                    </div>
                </div>
            `;
            
            container.innerHTML = html;
            
            // Inicializar eventos después de cargar
            inicializarEventosProductos();
        }

        // **FUNCIÓN: Crear fila de producto con precios recordados**
        function crearFilaProducto(producto, index) {
            const tieneHistorial = producto.ultimo_precio_compra && producto.ultimo_precio_compra > 0;
            const precioSugerido = tieneHistorial ? parseFloat(producto.ultimo_precio_compra) : 0;
            const fechaUltimoPrecio = producto.fecha_ultimo_precio_compra;
            
            // Generar información del historial
            let historialInfo = '';
            let tooltipInfo = '';
            let clasePrecio = 'form-control';
            
            if (tieneHistorial) {
                clasePrecio += ' precio-recordado';
                historialInfo = `
                    <div class="historial-precios">
                        <span class="precio-anterior">
                            💡 Último: ${precioSugerido.toFixed(2)}
                        </span>
                        <small class="text-muted">
                            (${new Date(fechaUltimoPrecio).toLocaleDateString('es-ES')})
                        </small>
                `;
                
                // Agregar promedio si existe
                if (producto.precio_promedio && producto.precio_promedio > 0) {
                    historialInfo += `
                        <span class="precio-promedio ms-2">
                            📊 Promedio: ${parseFloat(producto.precio_promedio).toFixed(2)}
                        </span>
                    `;
                }
                
                // Agregar cantidad de compras si existe
                if (producto.total_compras && producto.total_compras > 0) {
                    historialInfo += `
                        <small class="text-muted ms-2">
                            (${producto.total_compras} compra${producto.total_compras !== 1 ? 's' : ''})
                        </small>
                    `;
                }
                
                historialInfo += '</div>';
                
                tooltipInfo = `Último precio: ${precioSugerido.toFixed(2)} el ${new Date(fechaUltimoPrecio).toLocaleDateString('es-ES')}`;
            } else {
                historialInfo = `
                    <div class="historial-precios">
                        <span class="sin-historial">
                            ⚠️ Sin historial de precios
                        </span>
                    </div>
                `;
                tooltipInfo = 'No hay historial de precios para este producto';
            }
            
            return `
                <div class="producto-row" data-producto-id="${producto.id}">
                    <div class="row align-items-center">
                        <div class="col-md-4">
                            <label class="form-label">
                                <strong>${producto.descripcion}</strong>
                            </label>
                            <div class="text-muted small">
                                <i class="bi bi-building"></i> ${producto.proveedor}
                                <br>
                                <i class="bi bi-box"></i> Stock actual: ${producto.existencia}
                            </div>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Cantidad:</label>
                            <input type="number" 
                                   class="form-control cantidad-producto" 
                                   name="cantidades[]" 
                                   min="1" 
                                   placeholder="0"
                                   onchange="calcularSubtotal(${index}); actualizarTotal();"
                                   data-index="${index}">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Precio de Compra:</label>
                            <div class="precio-container">
                                <input type="number" 
                                       class="precio-compra ${clasePrecio}" 
                                       name="precios_compra[]" 
                                       step="0.01" 
                                       min="0.01" 
                                       placeholder="0.00"
                                       value="${tieneHistorial ? precioSugerido.toFixed(2) : ''}"
                                       onchange="calcularSubtotal(${index}); actualizarTotal();"
                                       data-index="${index}"
                                       data-tooltip="${tooltipInfo}"
                                       title="${tooltipInfo}">
                                ${tieneHistorial ? '<span class="precio-sugerido-badge">Sugerido</span>' : ''}
                            </div>
                            ${historialInfo}
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Subtotal:</label>
                            <input type="text" 
                                   class="form-control subtotal-producto" 
                                   readonly 
                                   placeholder="$0.00"
                                   data-index="${index}">
                        </div>
                        <div class="col-md-1">
                            <label class="form-label">&nbsp;</label>
                            <button type="button" 
                                    class="btn btn-outline-danger btn-sm d-block" 
                                    onclick="removerProducto(${index})"
                                    title="Remover producto">
                                <i class="bi bi-trash"></i>
                            </button>
                        </div>
                    </div>
                    <input type="hidden" name="productos[]" value="${producto.id}">
                </div>
                <hr>
            `;
        }

        // **FUNCIÓN: Inicializar eventos de productos**
        function inicializarEventosProductos() {
            // Agregar animación a campos con precios sugeridos
            document.querySelectorAll('.precio-recordado').forEach(input => {
                if (input.value && parseFloat(input.value) > 0) {
                    input.classList.add('autocomplete-animation');
                    setTimeout(() => {
                        input.classList.remove('autocomplete-animation');
                    }, 1000);
                }
            });
        }

        // **FUNCIÓN: Calcular subtotal de producto**
        function calcularSubtotal(index) {
            const cantidadInput = document.querySelector(`input[data-index="${index}"].cantidad-producto`);
            const precioInput = document.querySelector(`input[data-index="${index}"].precio-compra`);
            const subtotalInput = document.querySelector(`input[data-index="${index}"].subtotal-producto`);
            
            if (cantidadInput && precioInput && subtotalInput) {
                const cantidad = parseFloat(cantidadInput.value) || 0;
                const precio = parseFloat(precioInput.value) || 0;
                const subtotal = cantidad * precio;
                
                subtotalInput.value = subtotal > 0 ? `${subtotal.toFixed(2)}` : '$0.00';
            }
        }

        // **FUNCIÓN: Actualizar total general**
        function actualizarTotal() {
            let total = 0;
            
            document.querySelectorAll('.subtotal-producto').forEach(input => {
                const valor = input.value.replace(', '').replace(',', '');
                total += parseFloat(valor) || 0;
            });
            
            document.getElementById('total-estimado').textContent = `${total.toFixed(2)}`;
        }

        // **FUNCIÓN: Remover producto**
        function removerProducto(index) {
            const productRow = document.querySelector(`input[data-index="${index}"]`).closest('.producto-row');
            if (productRow) {
                productRow.nextElementSibling?.remove(); // Remover HR
                productRow.remove();
                actualizarTotal();
            }
        }

        // **FUNCIÓN: Agregar producto (funcionalidad adicional)**
        function agregarProducto() {
            const proveedor = document.getElementById('proveedor').value;
            
            if (!proveedor) {
                alert('Primero seleccione un proveedor');
                return;
            }
            
            // Esta función podría expandirse para agregar productos manualmente
            // Por ahora, los productos se cargan automáticamente al seleccionar proveedor
            alert('Los productos se cargan automáticamente al seleccionar el proveedor');
        }

        // **FUNCIÓN: Ver detalle de ingreso**
        async function verDetalleIngreso(ingresoId) {
            const modal = new bootstrap.Modal(document.getElementById('modalDetalleIngreso'));
            modal.show();
            
            try {
                const response = await fetch('obtener_detalle_ingreso.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `ingreso_id=${ingresoId}`
                });
                
                const data = await response.json();
                
                if (data.success) {
                    document.getElementById('detalle-content').innerHTML = generarHTMLDetalle(data.ingreso, data.detalles);
                } else {
                    document.getElementById('detalle-content').innerHTML = 
                        '<div class="alert alert-danger">Error al cargar el detalle</div>';
                }
            } catch (error) {
                document.getElementById('detalle-content').innerHTML = 
                    '<div class="alert alert-danger">Error de conexión</div>';
            }
        }

        // **FUNCIÓN: Generar HTML para detalle de ingreso**
        function generarHTMLDetalle(ingreso, detalles) {
            let html = `
                <div class="row mb-3">
                    <div class="col-md-6">
                        <h6>Información General</h6>
                        <p><strong>Proveedor:</strong> ${ingreso.proveedor}</p>
                        <p><strong>Factura:</strong> ${ingreso.numero_factura}</p>
                        <p><strong>Fecha:</strong> ${new Date(ingreso.fecha_ingreso).toLocaleDateString('es-ES')}</p>
                    </div>
                    <div class="col-md-6 text-end">
                        <h6>Total</h6>
                        <h4 class="text-success">${parseFloat(ingreso.total_factura).toFixed(2)}</h4>
                    </div>
                </div>
                <hr>
                <h6>Productos del Ingreso</h6>
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>Producto</th>
                                <th>Cantidad</th>
                                <th>Precio Compra</th>
                                <th>Subtotal</th>
                            </tr>
                        </thead>
                        <tbody>
            `;
            
            detalles.forEach(detalle => {
                html += `
                    <tr>
                        <td>
                            <strong>${detalle.descripcion}</strong><br>
                            <small class="text-muted">${detalle.proveedor}</small>
                        </td>
                        <td>${detalle.cantidad}</td>
                        <td>${parseFloat(detalle.precio_compra).toFixed(2)}</td>
                        <td>${parseFloat(detalle.subtotal).toFixed(2)}</td>
                    </tr>
                `;
            });
            
            html += `
                        </tbody>
                    </table>
                </div>
            `;
            
            return html;
        }

        // **FUNCIÓN: Eliminar ingreso**
        function eliminarIngreso(ingresoId) {
            document.getElementById('ingreso_id_eliminar').value = ingresoId;
            const modal = new bootstrap.Modal(document.getElementById('modalEliminar'));
            modal.show();
        }

        // **FUNCIÓN: Validación del formulario**
        function validarFormulario() {
            const proveedor = document.getElementById('proveedor').value;
            const numeroFactura = document.getElementById('numero_factura').value;
            const fechaIngreso = document.getElementById('fecha_ingreso').value;
            
            if (!proveedor || !numeroFactura || !fechaIngreso) {
                alert('Complete todos los campos obligatorios');
                return false;
            }
            
            // Validar que haya al menos un producto con cantidad y precio
            const cantidades = document.querySelectorAll('.cantidad-producto');
            const precios = document.querySelectorAll('.precio-compra');
            
            let tieneProductos = false;
            
            for (let i = 0; i < cantidades.length; i++) {
                const cantidad = parseFloat(cantidades[i].value) || 0;
                const precio = parseFloat(precios[i].value) || 0;
                
                if (cantidad > 0 && precio > 0) {
                    tieneProductos = true;
                    break;
                }
            }
            
            if (!tieneProductos) {
                alert('Debe agregar al menos un producto con cantidad y precio válidos');
                return false;
            }
            
            return true;
        }

        // **EVENTOS DE INICIALIZACIÓN**
        document.addEventListener('DOMContentLoaded', function() {
            // Validar formulario antes de enviar
            document.getElementById('formNuevoIngreso').addEventListener('submit', function(e) {
                if (!validarFormulario()) {
                    e.preventDefault();
                    return false;
                }
                
                // Mostrar indicador de carga
                const submitBtn = this.querySelector('button[type="submit"]');
                const originalText = submitBtn.innerHTML;
                submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Registrando...';
                submitBtn.disabled = true;
                
                // Restaurar botón después de 10 segundos (timeout de seguridad)
                setTimeout(() => {
                    submitBtn.innerHTML = originalText;
                    submitBtn.disabled = false;
                }, 10000);
            });
            
            // Limpiar formulario al cerrar modal
            document.getElementById('modalNuevoIngreso').addEventListener('hidden.bs.modal', function() {
                document.getElementById('formNuevoIngreso').reset();
                document.getElementById('productos-container').innerHTML = '';
                document.getElementById('total-estimado').textContent = '$0.00';
                contadorProductos = 0;
                productosData = {};
            });
            
            // Auto-focus en proveedor al abrir modal
            document.getElementById('modalNuevoIngreso').addEventListener('shown.bs.modal', function() {
                document.getElementById('proveedor').focus();
            });
        });

        // **FUNCIONES DE UTILIDAD**
        
        // Formatear números con separadores de miles
        function formatearNumero(numero) {
            return new Intl.NumberFormat('es-ES', {
                style: 'currency',
                currency: 'USD',
                minimumFractionDigits: 2
            }).format(numero);
        }
        
        // Validar si un precio es razonable (no muy diferente del precio anterior)
        function validarPrecioRazonable(precioNuevo, precioAnterior, tolerancia = 50) {
            if (!precioAnterior || precioAnterior <= 0) return true;
            
            const diferenciaPorcentaje = Math.abs((precioNuevo - precioAnterior) / precioAnterior) * 100;
            return diferenciaPorcentaje <= tolerancia;
        }
        
        // Mostrar advertencia si el precio es muy diferente
        function verificarPreciosRazonables() {
            document.querySelectorAll('.precio-compra').forEach((input, index) => {
                const precioNuevo = parseFloat(input.value) || 0;
                const productRow = input.closest('.producto-row');
                const productoId = productRow.getAttribute('data-producto-id');
                
                if (productosData[productoId] && precioNuevo > 0) {
                    const precioAnterior = parseFloat(productosData[productoId].ultimo_precio_compra) || 0;
                    
                    if (precioAnterior > 0 && !validarPrecioRazonable(precioNuevo, precioAnterior)) {
                        input.style.borderColor = '#dc3545';
                        input.title = `⚠️ Precio muy diferente al anterior: ${precioAnterior.toFixed(2)}`;
                    } else {
                        input.style.borderColor = '';
                        input.title = input.getAttribute('data-tooltip') || '';
                    }
                }
            });
        }
        
        // Debug: Mostrar información de productos cargados
        function debugProductos() {
            console.log('Productos cargados:', productosData);
            console.log('Total productos:', Object.keys(productosData).length);
        }
        
        console.log('✅ Sistema de precios recordados cargado correctamente');
    </script>
</body>
</html>