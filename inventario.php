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
            case 'editar_existencia':
                try {
                    $producto_id = $_POST['producto_id'];
                    $nueva_existencia = $_POST['nueva_existencia'];
                    $motivo = $_POST['motivo'];
                    
                    // Validar que la nueva existencia sea un número válido
                    if (!is_numeric($nueva_existencia) || $nueva_existencia < 0) {
                        throw new Exception('La existencia debe ser un número válido mayor o igual a 0.');
                    }
                    
                    // Obtener la existencia actual
                    $stmt_actual = $db->prepare("SELECT existencia, descripcion FROM productos WHERE id = ?");
                    $stmt_actual->execute([$producto_id]);
                    $producto_actual = $stmt_actual->fetch();
                    
                    if (!$producto_actual) {
                        throw new Exception('Producto no encontrado.');
                    }
                    
                    $existencia_anterior = $producto_actual['existencia'];
                    $diferencia = $nueva_existencia - $existencia_anterior;
                    
                    // Actualizar la existencia
                    $stmt_update = $db->prepare("UPDATE productos SET existencia = ?, fecha_actualizacion = CURRENT_TIMESTAMP WHERE id = ?");
                    $stmt_update->execute([$nueva_existencia, $producto_id]);
                    
                    // Registrar el ajuste en la tabla de ingresos como un ajuste manual (opcional)
                    if ($diferencia != 0) {
                        $tipo_ajuste = $diferencia > 0 ? 'Ajuste de Inventario (+)' : 'Ajuste de Inventario (-)';
                        $numero_factura = 'AJUSTE-' . date('YmdHis');
                        
                        $stmt_ingreso = $db->prepare("INSERT INTO ingresos (proveedor, numero_factura, fecha_ingreso, total_factura) VALUES (?, ?, ?, ?)");
                        $stmt_ingreso->execute([
                            'AJUSTE MANUAL',
                            $numero_factura,
                            date('Y-m-d'),
                            0
                        ]);
                        
                        $ingreso_id = $db->lastInsertId();
                        
                        // Solo registrar si es un incremento (no registramos decrementos como ingresos negativos)
                        if ($diferencia > 0) {
                            $stmt_detalle = $db->prepare("INSERT INTO detalle_ingresos (ingreso_id, producto_id, cantidad, precio_compra, subtotal) VALUES (?, ?, ?, ?, ?)");
                            $stmt_detalle->execute([
                                $ingreso_id,
                                $producto_id,
                                $diferencia,
                                0,
                                0
                            ]);
                        }
                    }
                    
                    $mensaje = "Existencia actualizada exitosamente. " . 
                               "Anterior: {$existencia_anterior}, Nueva: {$nueva_existencia}" . 
                               ($motivo ? " (Motivo: {$motivo})" : "");
                    $tipo_mensaje = "success";
                    
                } catch (Exception $e) {
                    $mensaje = "Error al actualizar la existencia: " . $e->getMessage();
                    $tipo_mensaje = "danger";
                }
                break;
        }
    }
}

// Obtener inventario con paginación
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 15;
$offset = ($page - 1) * $limit;

$search = isset($_GET['search']) ? $_GET['search'] : '';
$proveedor_filter = isset($_GET['proveedor']) ? $_GET['proveedor'] : '';
$stock_filter = isset($_GET['stock']) ? $_GET['stock'] : '';

// Construir consulta con filtros
$where_conditions = [];
$params = [];

if (!empty($search)) {
    $where_conditions[] = "(descripcion LIKE ? OR proveedor LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if (!empty($proveedor_filter)) {
    $where_conditions[] = "proveedor = ?";
    $params[] = $proveedor_filter;
}

if (!empty($stock_filter)) {
    switch ($stock_filter) {
        case 'sin_stock':
            $where_conditions[] = "existencia = 0";
            break;
        case 'bajo_stock':
            $where_conditions[] = "existencia > 0 AND existencia < 10";
            break;
        case 'stock_normal':
            $where_conditions[] = "existencia >= 10";
            break;
    }
}

$where_clause = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";

// Contar total de productos
$count_query = "SELECT COUNT(*) as total FROM productos $where_clause";
$stmt_count = $db->prepare($count_query);
$stmt_count->execute($params);
$total_productos = $stmt_count->fetch()['total'];
$total_pages = ceil($total_productos / $limit);

// Obtener productos con información adicional
$query = "SELECT 
            p.*,
            COALESCE(SUM(di.cantidad), 0) as total_ingresos,
            COALESCE(SUM(dtd.cantidad), 0) as total_salidas,
            (p.existencia * p.precio_venta) as valor_inventario
          FROM productos p
          LEFT JOIN detalle_ingresos di ON p.id = di.producto_id
          LEFT JOIN detalle_tablas_distribucion dtd ON p.id = dtd.producto_id
          $where_clause
          GROUP BY p.id
          ORDER BY p.proveedor, p.descripcion
          LIMIT $limit OFFSET $offset";

$stmt = $db->prepare($query);
$stmt->execute($params);
$productos = $stmt->fetchAll();

// Obtener estadísticas generales
$stmt_stats = $db->prepare("SELECT 
    COUNT(*) as total_productos,
    SUM(existencia) as total_existencia,
    SUM(existencia * precio_venta) as valor_total_inventario,
    COUNT(CASE WHEN existencia = 0 THEN 1 END) as productos_sin_stock,
    COUNT(CASE WHEN existencia > 0 AND existencia < 10 THEN 1 END) as productos_bajo_stock
FROM productos");
$stmt_stats->execute();
$stats = $stmt_stats->fetch();

// Obtener lista de proveedores para el filtro
$stmt_proveedores = $db->prepare("SELECT DISTINCT proveedor FROM productos ORDER BY proveedor");
$stmt_proveedores->execute();
$proveedores = $stmt_proveedores->fetchAll();
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventario - Sistema de Inventario</title>
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
        .stats-card {
            transition: transform 0.2s;
        }
        .stats-card:hover {
            transform: translateY(-2px);
        }
        .table-sm td {
            padding: 0.5rem 0.75rem;
        }
        .existencia-editable {
            cursor: pointer;
            border: 1px dashed transparent;
            padding: 2px 5px;
            border-radius: 3px;
        }
        .existencia-editable:hover {
            border-color: #007bff;
            background-color: #f8f9fa;
        }
        .existencia-input {
            width: 80px;
            text-align: center;
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
                            <a class="nav-link active" href="inventario.php">
                                <i class="bi bi-clipboard-data"></i> Inventario
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="ingresos.php">
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
                    <h1 class="h2">Control de Inventario</h1>
                    <a href="ingresos.php" class="btn btn-success">
                        <i class="bi bi-plus-lg"></i> Registrar Ingreso
                    </a>
                </div>

                <!-- Mensajes -->
                <?php if (!empty($mensaje)): ?>
                    <div class="alert alert-<?php echo $tipo_mensaje; ?> alert-dismissible fade show" role="alert">
                        <?php echo htmlspecialchars($mensaje); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Estadísticas del inventario -->
                <div class="row mb-4">
                    <div class="col-xl-3 col-md-6 mb-3">
                        <div class="card stats-card h-100">
                            <div class="card-body">
                                <div class="row no-gutters align-items-center">
                                    <div class="col mr-2">
                                        <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">
                                            Total Productos
                                        </div>
                                        <div class="h5 mb-0 font-weight-bold text-gray-800">
                                            <?php echo number_format($stats['total_productos']); ?>
                                        </div>
                                    </div>
                                    <div class="col-auto">
                                        <i class="bi bi-box fs-2 text-primary"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-3 col-md-6 mb-3">
                        <div class="card stats-card h-100">
                            <div class="card-body">
                                <div class="row no-gutters align-items-center">
                                    <div class="col mr-2">
                                        <div class="text-xs font-weight-bold text-success text-uppercase mb-1">
                                            Existencia Total
                                        </div>
                                        <div class="h5 mb-0 font-weight-bold text-gray-800">
                                            <?php echo number_format($stats['total_existencia']); ?>
                                        </div>
                                    </div>
                                    <div class="col-auto">
                                        <i class="bi bi-clipboard-data fs-2 text-success"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-3 col-md-6 mb-3">
                        <div class="card stats-card h-100">
                            <div class="card-body">
                                <div class="row no-gutters align-items-center">
                                    <div class="col mr-2">
                                        <div class="text-xs font-weight-bold text-info text-uppercase mb-1">
                                            Valor Inventario
                                        </div>
                                        <div class="h5 mb-0 font-weight-bold text-gray-800">
                                            $<?php echo number_format($stats['valor_total_inventario'], 2); ?>
                                        </div>
                                    </div>
                                    <div class="col-auto">
                                        <i class="bi bi-currency-dollar fs-2 text-info"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-3 col-md-6 mb-3">
                        <div class="card stats-card h-100">
                            <div class="card-body">
                                <div class="row no-gutters align-items-center">
                                    <div class="col mr-2">
                                        <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">
                                            Alertas Stock
                                        </div>
                                        <div class="h5 mb-0 font-weight-bold text-gray-800">
                                            <?php echo $stats['productos_sin_stock'] + $stats['productos_bajo_stock']; ?>
                                        </div>
                                        <small class="text-muted">
                                            <?php echo $stats['productos_sin_stock']; ?> sin stock | 
                                            <?php echo $stats['productos_bajo_stock']; ?> bajo stock
                                        </small>
                                    </div>
                                    <div class="col-auto">
                                        <i class="bi bi-exclamation-triangle fs-2 text-warning"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Filtros y búsqueda -->
                <div class="card mb-4">
                    <div class="card-body">
                        <form method="GET" class="row g-3">
                            <div class="col-md-3">
                                <label for="search" class="form-label">Buscar</label>
                                <input type="text" class="form-control" id="search" name="search" 
                                       value="<?php echo htmlspecialchars($search); ?>" 
                                       placeholder="Descripción o proveedor...">
                            </div>
                            <div class="col-md-3">
                                <label for="proveedor" class="form-label">Proveedor</label>
                                <select class="form-select" id="proveedor" name="proveedor">
                                    <option value="">Todos</option>
                                    <?php foreach ($proveedores as $proveedor): ?>
                                        <option value="<?php echo htmlspecialchars($proveedor['proveedor']); ?>"
                                                <?php echo $proveedor_filter == $proveedor['proveedor'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($proveedor['proveedor']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label for="stock" class="form-label">Estado Stock</label>
                                <select class="form-select" id="stock" name="stock">
                                    <option value="">Todos</option>
                                    <option value="sin_stock" <?php echo $stock_filter == 'sin_stock' ? 'selected' : ''; ?>>Sin Stock</option>
                                    <option value="bajo_stock" <?php echo $stock_filter == 'bajo_stock' ? 'selected' : ''; ?>>Bajo Stock (&lt;10)</option>
                                    <option value="stock_normal" <?php echo $stock_filter == 'stock_normal' ? 'selected' : ''; ?>>Stock Normal (≥10)</option>
                                </select>
                            </div>
                            <div class="col-md-3 d-flex align-items-end">
                                <button type="submit" class="btn btn-outline-primary me-2">
                                    <i class="bi bi-search"></i> Filtrar
                                </button>
                                <a href="inventario.php" class="btn btn-outline-secondary">
                                    <i class="bi bi-x-lg"></i> Limpiar
                                </a>
                            </div>
                        </form>
                    </div>
                </div>
                <!-- Tabla de inventario -->
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="card-title mb-0">Inventario Actual (<?php echo $total_productos; ?> productos)</h5>
                        <small class="text-muted">
                            <i class="bi bi-info-circle"></i> Haga clic en la existencia para editarla
                        </small>
                    </div>
                    <div class="card-body">
                        <?php if (count($productos) > 0): ?>
                            <div class="table-responsive">
                                <table class="table table-striped table-hover table-sm">
                                    <thead class="table-dark">
                                        <tr>
                                            <th>Proveedor</th>
                                            <th>Descripción</th>
                                            <th>Precio Venta</th>
                                            <th>Existencia</th>
                                            <th>Total Ingresos</th>
                                            <th>Total Salidas</th>
                                            <th>Valor Inventario</th>
                                            <th>Estado</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($productos as $producto): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($producto['proveedor']); ?></td>
                                                <td><?php echo htmlspecialchars($producto['descripcion']); ?></td>
                                                <td>$<?php echo number_format($producto['precio_venta'], 2); ?></td>
                                                <td>
                                                    <span class="existencia-editable badge <?php 
                                                        echo $producto['existencia'] == 0 ? 'bg-danger' : 
                                                             ($producto['existencia'] < 10 ? 'bg-warning text-dark' : 'bg-success'); 
                                                    ?>" 
                                                    data-producto-id="<?php echo $producto['id']; ?>"
                                                    data-existencia-actual="<?php echo $producto['existencia']; ?>"
                                                    data-descripcion="<?php echo htmlspecialchars($producto['descripcion']); ?>"
                                                    title="Clic para editar">
                                                        <?php echo number_format($producto['existencia']); ?>
                                                        <i class="bi bi-pencil-square ms-1"></i>
                                                    </span>
                                                </td>
                                                <td class="text-success">
                                                    <i class="bi bi-arrow-down"></i> <?php echo number_format($producto['total_ingresos']); ?>
                                                </td>
                                                <td class="text-danger">
                                                    <i class="bi bi-arrow-up"></i> <?php echo number_format($producto['total_salidas']); ?>
                                                </td>
                                                <td class="fw-bold">$<?php echo number_format($producto['valor_inventario'], 2); ?></td>
                                                <td>
                                                    <?php if ($producto['existencia'] == 0): ?>
                                                        <span class="badge bg-danger">Sin Stock</span>
                                                    <?php elseif ($producto['existencia'] < 10): ?>
                                                        <span class="badge bg-warning text-dark">Bajo Stock</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-success">Normal</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                    <tfoot class="table-light">
                                        <tr>
                                            <th colspan="6" class="text-end">Total en esta página:</th>
                                            <th>$<?php echo number_format(array_sum(array_column($productos, 'valor_inventario')), 2); ?></th>
                                            <th></th>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>

                            <!-- Paginación -->
                            <?php if ($total_pages > 1): ?>
                                <nav aria-label="Navegación de inventario">
                                    <ul class="pagination justify-content-center">
                                        <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                            <a class="page-link" href="?page=<?php echo $page - 1; ?>&search=<?php echo urlencode($search); ?>&proveedor=<?php echo urlencode($proveedor_filter); ?>&stock=<?php echo urlencode($stock_filter); ?>">Anterior</a>
                                        </li>
                                        
                                        <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                                            <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                                <a class="page-link" href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&proveedor=<?php echo urlencode($proveedor_filter); ?>&stock=<?php echo urlencode($stock_filter); ?>"><?php echo $i; ?></a>
                                            </li>
                                        <?php endfor; ?>
                                        
                                        <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                                            <a class="page-link" href="?page=<?php echo $page + 1; ?>&search=<?php echo urlencode($search); ?>&proveedor=<?php echo urlencode($proveedor_filter); ?>&stock=<?php echo urlencode($stock_filter); ?>">Siguiente</a>
                                        </li>
                                    </ul>
                                </nav>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="alert alert-info">
                                <i class="bi bi-info-circle"></i> No se encontraron productos con los criterios especificados.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Resumen por proveedor -->
                <?php if (!empty($proveedor_filter)): ?>
                    <?php
                    $stmt_resumen = $db->prepare("SELECT 
                        COUNT(*) as total_productos,
                        SUM(existencia) as total_existencia,
                        SUM(existencia * precio_venta) as valor_total
                    FROM productos WHERE proveedor = ?");
                    $stmt_resumen->execute([$proveedor_filter]);
                    $resumen = $stmt_resumen->fetch();
                    ?>
                    <div class="card mt-4">
                        <div class="card-header">
                            <h5 class="card-title mb-0">Resumen: <?php echo htmlspecialchars($proveedor_filter); ?></h5>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="text-center">
                                        <h4 class="text-primary"><?php echo $resumen['total_productos']; ?></h4>
                                        <small class="text-muted">Productos</small>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="text-center">
                                        <h4 class="text-success"><?php echo number_format($resumen['total_existencia']); ?></h4>
                                        <small class="text-muted">Unidades en Stock</small>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="text-center">
                                        <h4 class="text-info">$<?php echo number_format($resumen['valor_total'], 2); ?></h4>
                                        <small class="text-muted">Valor Total</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </main>
        </div>
    </div>

    <!-- Modal para editar existencia -->
    <div class="modal fade" id="modalEditarExistencia" tabindex="-1" aria-labelledby="modalEditarExistenciaLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalEditarExistenciaLabel">Editar Existencia</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="formEditarExistencia" method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="accion" value="editar_existencia">
                        <input type="hidden" name="producto_id" id="producto_id_editar">
                        
                        <div class="mb-3">
                            <label class="form-label"><strong>Producto:</strong></label>
                            <p id="descripcion_producto" class="text-muted"></p>
                        </div>
                        
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label class="form-label">Existencia Actual:</label>
                                <div class="form-control-plaintext fw-bold" id="existencia_actual"></div>
                            </div>
                            <div class="col-md-6">
                                <label for="nueva_existencia" class="form-label">Nueva Existencia <span class="text-danger">*</span></label>
                                <input type="number" class="form-control" id="nueva_existencia" name="nueva_existencia" 
                                       required min="0" step="1" placeholder="0">
                            </div>
                        </div>
                        
                        <div id="diferencia_info" class="mb-3" style="display: none;">
                            <div class="alert alert-info">
                                <strong>Diferencia:</strong> <span id="diferencia_texto"></span>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="motivo" class="form-label">Motivo del Ajuste (Opcional)</label>
                            <textarea class="form-control" id="motivo" name="motivo" rows="3" 
                                      placeholder="Ej: Conteo físico, producto dañado, ajuste por inventario, etc."></textarea>
                        </div>
                        
                        <div class="alert alert-warning">
                            <i class="bi bi-exclamation-triangle"></i>
                            <strong>Importante:</strong> Este cambio se registrará como un ajuste de inventario y afectará las estadísticas del sistema.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary" id="btnGuardarExistencia">
                            <i class="bi bi-save"></i> Actualizar Existencia
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal de confirmación para cambios grandes -->
    <div class="modal fade" id="modalConfirmarCambio" tabindex="-1" aria-labelledby="modalConfirmarCambioLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalConfirmarCambioLabel">Confirmar Cambio Importante</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-warning">
                        <i class="bi bi-exclamation-triangle"></i>
                        <strong>¡Atención!</strong> Va a realizar un cambio significativo en la existencia.
                    </div>
                    <p id="confirmacion_texto"></p>
                    <p><strong>¿Está seguro que desea continuar?</strong></p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-warning" id="btnConfirmarCambio">
                        <i class="bi bi-check-circle"></i> Sí, Confirmar Cambio
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Variables globales
        let currentProductId = null;
        let currentExistencia = null;
        let pendingSubmission = false;

        // Manejar clic en existencia editable
        document.querySelectorAll('.existencia-editable').forEach(element => {
            element.addEventListener('click', function() {
                const productoId = this.getAttribute('data-producto-id');
                const existenciaActual = this.getAttribute('data-existencia-actual');
                const descripcion = this.getAttribute('data-descripcion');
                
                // Llenar el modal
                document.getElementById('producto_id_editar').value = productoId;
                document.getElementById('existencia_actual').textContent = existenciaActual + ' unidades';
                document.getElementById('descripcion_producto').textContent = descripcion;
                document.getElementById('nueva_existencia').value = existenciaActual;
                document.getElementById('motivo').value = '';
                
                // Limpiar diferencia
                document.getElementById('diferencia_info').style.display = 'none';
                
                // Guardar valores actuales
                currentProductId = productoId;
                currentExistencia = parseInt(existenciaActual);
                
                // Mostrar modal
                const modal = new bootstrap.Modal(document.getElementById('modalEditarExistencia'));
                modal.show();
                
                // Enfocar el campo de nueva existencia
                setTimeout(() => {
                    document.getElementById('nueva_existencia').focus();
                    document.getElementById('nueva_existencia').select();
                }, 500);
            });
        });

        // Calcular diferencia al cambiar la nueva existencia
        document.getElementById('nueva_existencia').addEventListener('input', function() {
            const nuevaExistencia = parseInt(this.value) || 0;
            const diferencia = nuevaExistencia - currentExistencia;
            
            if (diferencia !== 0) {
                const diferenciaInfo = document.getElementById('diferencia_info');
                const diferenciaTexto = document.getElementById('diferencia_texto');
                
                if (diferencia > 0) {
                    diferenciaTexto.innerHTML = `<span class="text-success">+${diferencia} unidades (Incremento)</span>`;
                } else {
                    diferenciaTexto.innerHTML = `<span class="text-danger">${diferencia} unidades (Reducción)</span>`;
                }
                
                diferenciaInfo.style.display = 'block';
            } else {
                document.getElementById('diferencia_info').style.display = 'none';
            }
        });

        // Validación y envío del formulario
        document.getElementById('formEditarExistencia').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const nuevaExistencia = parseInt(document.getElementById('nueva_existencia').value) || 0;
            const diferencia = Math.abs(nuevaExistencia - currentExistencia);
            const porcentajeCambio = currentExistencia > 0 ? (diferencia / currentExistencia) * 100 : 100;
            
            // Si el cambio es significativo (más del 50% o diferencia mayor a 100 unidades), pedir confirmación
            if ((porcentajeCambio > 50 || diferencia > 100) && !pendingSubmission) {
                const descripcion = document.getElementById('descripcion_producto').textContent;
                const confirmacionTexto = document.getElementById('confirmacion_texto');
                
                confirmacionTexto.innerHTML = `
                    <strong>Producto:</strong> ${descripcion}<br>
                    <strong>Existencia actual:</strong> ${currentExistencia} unidades<br>
                    <strong>Nueva existencia:</strong> ${nuevaExistencia} unidades<br>
                    <strong>Diferencia:</strong> ${nuevaExistencia - currentExistencia} unidades (${porcentajeCambio.toFixed(1)}% de cambio)
                `;
                
                // Ocultar el modal actual y mostrar confirmación
                const modalEditarExistencia = bootstrap.Modal.getInstance(document.getElementById('modalEditarExistencia'));
                modalEditarExistencia.hide();
                
                const modalConfirmar = new bootstrap.Modal(document.getElementById('modalConfirmarCambio'));
                modalConfirmar.show();
                
                return false;
            }
            
            // Validaciones básicas
            if (nuevaExistencia < 0) {
                alert('La existencia no puede ser negativa.');
                return false;
            }
            
            if (isNaN(nuevaExistencia)) {
                alert('Por favor, ingrese un número válido.');
                return false;
            }
            
            // Si llegamos aquí, proceder con el envío
            this.submit();
        });

        // Manejar confirmación de cambio grande
        document.getElementById('btnConfirmarCambio').addEventListener('click', function() {
            pendingSubmission = true;
            
            // Ocultar modal de confirmación
            const modalConfirmar = bootstrap.Modal.getInstance(document.getElementById('modalConfirmarCambio'));
            modalConfirmar.hide();
            
            // Enviar el formulario
            document.getElementById('formEditarExistencia').submit();
        });

        // Restablecer estado cuando se oculta el modal de confirmación
        document.getElementById('modalConfirmarCambio').addEventListener('hidden.bs.modal', function() {
            if (!pendingSubmission) {
                // Volver a mostrar el modal de edición si no se confirmó
                const modalEditarExistencia = new bootstrap.Modal(document.getElementById('modalEditarExistencia'));
                modalEditarExistencia.show();
            }
        });

        // Limpiar formulario al cerrar modal
        document.getElementById('modalEditarExistencia').addEventListener('hidden.bs.modal', function() {
            if (!pendingSubmission) {
                document.getElementById('formEditarExistencia').reset();
                document.getElementById('diferencia_info').style.display = 'none';
                currentProductId = null;
                currentExistencia = null;
            }
            pendingSubmission = false;
        });

        // Atajos de teclado en el modal
        document.getElementById('modalEditarExistencia').addEventListener('keydown', function(e) {
            // Enter para enviar (solo si no es en textarea)
            if (e.key === 'Enter' && e.target.tagName !== 'TEXTAREA') {
                e.preventDefault();
                document.getElementById('formEditarExistencia').dispatchEvent(new Event('submit'));
            }
            
            // Escape para cerrar
            if (e.key === 'Escape') {
                const modal = bootstrap.Modal.getInstance(this);
                modal.hide();
            }
        });

        // Agregar tooltip a las existencias editables
        document.querySelectorAll('.existencia-editable').forEach(element => {
            element.setAttribute('data-bs-toggle', 'tooltip');
            element.setAttribute('data-bs-placement', 'top');
            element.setAttribute('title', 'Clic para editar la existencia');
        });

        // Inicializar tooltips
        const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
        const tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl);
        });

        // Manejar actualización visual después de editar
        document.addEventListener('DOMContentLoaded', function() {
            // Si hay un mensaje de éxito, destacar brevemente las existencias editables
            const alertSuccess = document.querySelector('.alert-success');
            if (alertSuccess && alertSuccess.textContent.includes('Existencia actualizada')) {
                document.querySelectorAll('.existencia-editable').forEach(element => {
                    element.style.animation = 'pulse 2s ease-in-out';
                });
            }
        });

        // Agregar estilos de animación dinámicamente
        const style = document.createElement('style');
        style.textContent = `
            @keyframes pulse {
                0% { transform: scale(1); }
                50% { transform: scale(1.05); box-shadow: 0 0 10px rgba(0,123,255,0.5); }
                100% { transform: scale(1); }
            }
        `;
        document.head.appendChild(style);
    </script>
</body>
</html>