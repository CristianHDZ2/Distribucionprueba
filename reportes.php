<?php
require_once 'config/database.php';

$database = new Database();
$db = $database->getConnection();

// Obtener parámetros de filtros
$fecha_desde = isset($_GET['fecha_desde']) ? $_GET['fecha_desde'] : date('Y-m-01');
$fecha_hasta = isset($_GET['fecha_hasta']) ? $_GET['fecha_hasta'] : date('Y-m-d');
$proveedor_filter = isset($_GET['proveedor_filter']) ? $_GET['proveedor_filter'] : '';
$tipo_reporte = isset($_GET['tipo_reporte']) ? $_GET['tipo_reporte'] : 'resumen';

// Obtener lista de proveedores
$stmt_proveedores = $db->prepare("SELECT DISTINCT proveedor FROM productos ORDER BY proveedor");
$stmt_proveedores->execute();
$proveedores = $stmt_proveedores->fetchAll();

// Función para obtener reporte de ingresos
function obtenerReporteIngresos($db, $fecha_desde, $fecha_hasta, $proveedor_filter = '') {
    $where_conditions = ["i.fecha_ingreso BETWEEN ? AND ?"];
    $params = [$fecha_desde, $fecha_hasta];
    
    if (!empty($proveedor_filter)) {
        $where_conditions[] = "i.proveedor = ?";
        $params[] = $proveedor_filter;
    }
    
    $where_clause = "WHERE " . implode(" AND ", $where_conditions);
    
    // Resumen de ingresos
    $stmt_resumen = $db->prepare("SELECT 
        COUNT(DISTINCT i.id) as total_ingresos,
        COUNT(DISTINCT i.proveedor) as total_proveedores,
        SUM(i.total_factura) as total_monto,
        SUM(di.cantidad) as total_productos_ingresados
    FROM ingresos i
    LEFT JOIN detalle_ingresos di ON i.id = di.ingreso_id
    $where_clause");
    $stmt_resumen->execute($params);
    $resumen_ingresos = $stmt_resumen->fetch();
    
    // Ingresos por proveedor
    $stmt_por_proveedor = $db->prepare("SELECT 
        i.proveedor,
        COUNT(DISTINCT i.id) as total_ingresos,
        SUM(i.total_factura) as total_monto,
        SUM(di.cantidad) as total_productos,
        AVG(i.total_factura) as promedio_factura
    FROM ingresos i
    LEFT JOIN detalle_ingresos di ON i.id = di.ingreso_id
    $where_clause
    GROUP BY i.proveedor
    ORDER BY total_monto DESC");
    $stmt_por_proveedor->execute($params);
    $ingresos_por_proveedor = $stmt_por_proveedor->fetchAll();
    
    // Ingresos por fecha
    $stmt_por_fecha = $db->prepare("SELECT 
        DATE(i.fecha_ingreso) as fecha,
        COUNT(DISTINCT i.id) as total_ingresos,
        SUM(i.total_factura) as total_monto,
        SUM(di.cantidad) as total_productos
    FROM ingresos i
    LEFT JOIN detalle_ingresos di ON i.id = di.ingreso_id
    $where_clause
    GROUP BY DATE(i.fecha_ingreso)
    ORDER BY DATE(i.fecha_ingreso) DESC");
    $stmt_por_fecha->execute($params);
    $ingresos_por_fecha = $stmt_por_fecha->fetchAll();
    
    // Top productos ingresados
    $stmt_top_productos = $db->prepare("SELECT 
        p.descripcion,
        p.proveedor,
        SUM(di.cantidad) as total_cantidad,
        SUM(di.subtotal) as total_costo,
        AVG(di.precio_compra) as precio_promedio
    FROM detalle_ingresos di
    INNER JOIN ingresos i ON di.ingreso_id = i.id
    INNER JOIN productos p ON di.producto_id = p.id
    $where_clause
    GROUP BY p.id
    ORDER BY total_cantidad DESC
    LIMIT 10");
    $stmt_top_productos->execute($params);
    $top_productos_ingresados = $stmt_top_productos->fetchAll();
    
    return [
        'resumen' => $resumen_ingresos,
        'por_proveedor' => $ingresos_por_proveedor,
        'por_fecha' => $ingresos_por_fecha,
        'top_productos' => $top_productos_ingresados
    ];
}

// Función para obtener reporte de salidas
function obtenerReporteSalidas($db, $fecha_desde, $fecha_hasta, $proveedor_filter = '') {
    $where_conditions = ["td.fecha_tabla BETWEEN ? AND ?", "td.estado = 'activo'"];
    $params = [$fecha_desde, $fecha_hasta];
    
    if (!empty($proveedor_filter)) {
        $where_conditions[] = "p.proveedor = ?";
        $params[] = $proveedor_filter;
    }
    
    $where_clause = "WHERE " . implode(" AND ", $where_conditions);
    
    // Resumen de salidas
    $stmt_resumen = $db->prepare("SELECT 
        COUNT(DISTINCT td.id) as total_tablas,
        COUNT(DISTINCT td.distribucion_id) as total_distribuciones,
        SUM(td.total_tabla) as total_monto,
        SUM(dtd.cantidad) as total_productos_distribuidos
    FROM tablas_distribucion td
    LEFT JOIN detalle_tablas_distribucion dtd ON td.id = dtd.tabla_id
    LEFT JOIN productos p ON dtd.producto_id = p.id
    $where_clause");
    $stmt_resumen->execute($params);
    $resumen_salidas = $stmt_resumen->fetch();
    
    // Salidas por proveedor
    $stmt_por_proveedor = $db->prepare("SELECT 
        p.proveedor,
        COUNT(DISTINCT td.id) as total_tablas,
        SUM(td.total_tabla) as total_monto,
        SUM(dtd.cantidad) as total_productos,
        AVG(dtd.precio_venta) as precio_promedio
    FROM tablas_distribucion td
    INNER JOIN detalle_tablas_distribucion dtd ON td.id = dtd.tabla_id
    INNER JOIN productos p ON dtd.producto_id = p.id
    $where_clause
    GROUP BY p.proveedor
    ORDER BY total_monto DESC");
    $stmt_por_proveedor->execute($params);
    $salidas_por_proveedor = $stmt_por_proveedor->fetchAll();
    
    // Salidas por fecha
    $stmt_por_fecha = $db->prepare("SELECT 
        DATE(td.fecha_tabla) as fecha,
        COUNT(DISTINCT td.id) as total_tablas,
        SUM(td.total_tabla) as total_monto,
        SUM(dtd.cantidad) as total_productos
    FROM tablas_distribucion td
    LEFT JOIN detalle_tablas_distribucion dtd ON td.id = dtd.tabla_id
    LEFT JOIN productos p ON dtd.producto_id = p.id
    $where_clause
    GROUP BY DATE(td.fecha_tabla)
    ORDER BY DATE(td.fecha_tabla) DESC");
    $stmt_por_fecha->execute($params);
    $salidas_por_fecha = $stmt_por_fecha->fetchAll();
    
    // Top productos distribuidos
    $stmt_top_productos = $db->prepare("SELECT 
        p.descripcion,
        p.proveedor,
        SUM(dtd.cantidad) as total_cantidad,
        SUM(dtd.subtotal) as total_venta,
        AVG(dtd.precio_venta) as precio_promedio
    FROM detalle_tablas_distribucion dtd
    INNER JOIN tablas_distribucion td ON dtd.tabla_id = td.id
    INNER JOIN productos p ON dtd.producto_id = p.id
    $where_clause
    GROUP BY p.id
    ORDER BY total_cantidad DESC
    LIMIT 10");
    $stmt_top_productos->execute($params);
    $top_productos_distribuidos = $stmt_top_productos->fetchAll();
    
    return [
        'resumen' => $resumen_salidas,
        'por_proveedor' => $salidas_por_proveedor,
        'por_fecha' => $salidas_por_fecha,
        'top_productos' => $top_productos_distribuidos
    ];
}

// Obtener datos según el tipo de reporte
$reporte_ingresos = obtenerReporteIngresos($db, $fecha_desde, $fecha_hasta, $proveedor_filter);
$reporte_salidas = obtenerReporteSalidas($db, $fecha_desde, $fecha_hasta, $proveedor_filter);

// Calcular análisis comparativo
$total_ingresos_monto = $reporte_ingresos['resumen']['total_monto'] ?: 0;
$total_salidas_monto = $reporte_salidas['resumen']['total_monto'] ?: 0;
$diferencia_monto = $total_salidas_monto - $total_ingresos_monto;

$total_ingresos_productos = $reporte_ingresos['resumen']['total_productos_ingresados'] ?: 0;
$total_salidas_productos = $reporte_salidas['resumen']['total_productos_distribuidos'] ?: 0;
$diferencia_productos = $total_salidas_productos - $total_ingresos_productos;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reportes - Sistema de Inventario</title>
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
        .report-card {
            transition: transform 0.2s;
        }
        .report-card:hover {
            transform: translateY(-2px);
        }
        .stat-card {
            border-left: 4px solid;
        }
        .stat-card.ingreso {
            border-left-color: #28a745;
        }
        .stat-card.salida {
            border-left-color: #dc3545;
        }
        .stat-card.diferencia {
            border-left-color: #007bff;
        }
        .table-sm td, .table-sm th {
            padding: 0.5rem;
        }
        @media print {
            .sidebar, .no-print {
                display: none !important;
            }
            .main-content {
                margin-left: 0 !important;
            }
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <nav class="col-md-3 col-lg-2 d-md-block sidebar collapse no-print">
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
                            <a class="nav-link active" href="reportes.php">
                                <i class="bi bi-file-earmark-bar-graph"></i> Reportes
                            </a>
                        </li>
                    </ul>
                </div>
            </nav>

            <!-- Main content -->
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 main-content">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">Reportes de Inventario</h1>
                    <div class="no-print">
                        <button type="button" class="btn btn-outline-primary" onclick="window.print()">
                            <i class="bi bi-printer"></i> Imprimir Reporte
                        </button>
                    </div>
                </div>

                <!-- Filtros -->
                <div class="card mb-4 no-print">
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
                                <button type="submit" class="btn btn-primary me-2">
                                    <i class="bi bi-search"></i> Generar Reporte
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Información del reporte -->
                <div class="card mb-4">
                    <div class="card-body">
                        <h5 class="card-title">Reporte del <?php echo date('d/m/Y', strtotime($fecha_desde)); ?> al <?php echo date('d/m/Y', strtotime($fecha_hasta)); ?></h5>
                        <?php if (!empty($proveedor_filter)): ?>
                            <p class="mb-0"><strong>Proveedor:</strong> <?php echo htmlspecialchars($proveedor_filter); ?></p>
                        <?php endif; ?>
                        <small class="text-muted">Generado el <?php echo date('d/m/Y H:i'); ?></small>
                    </div>
                </div>

                <!-- Resumen ejecutivo -->
                <div class="row mb-4">
                    <div class="col-md-4">
                        <div class="card stat-card ingreso h-100">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h6 class="card-title text-success">Total Ingresos</h6>
                                        <h4 class="text-success">$<?php echo number_format($total_ingresos_monto, 2); ?></h4>
                                        <small class="text-muted"><?php echo number_format($total_ingresos_productos); ?> productos</small>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="bi bi-arrow-down-circle fs-1 text-success"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card stat-card salida h-100">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h6 class="card-title text-danger">Total Salidas</h6>
                                        <h4 class="text-danger">$<?php echo number_format($total_salidas_monto, 2); ?></h4>
                                        <small class="text-muted"><?php echo number_format($total_salidas_productos); ?> productos</small>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="bi bi-arrow-up-circle fs-1 text-danger"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card stat-card diferencia h-100">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h6 class="card-title text-primary">Diferencia (Ganancia)</h6>
                                        <h4 class="<?php echo $diferencia_monto >= 0 ? 'text-success' : 'text-danger'; ?>">
                                            $<?php echo number_format($diferencia_monto, 2); ?>
                                        </h4>
                                        <small class="text-muted">
                                            <?php echo $diferencia_productos >= 0 ? '+' : ''; ?><?php echo number_format($diferencia_productos); ?> productos
                                        </small>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="bi bi-graph-up fs-1 text-primary"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Pestañas de reportes -->
                <ul class="nav nav-tabs no-print" id="reporteTabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="ingresos-tab" data-bs-toggle="tab" data-bs-target="#ingresos" type="button" role="tab">
                            <i class="bi bi-arrow-down-circle"></i> Reporte de Ingresos
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="salidas-tab" data-bs-toggle="tab" data-bs-target="#salidas" type="button" role="tab">
                            <i class="bi bi-arrow-up-circle"></i> Reporte de Salidas
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="comparativo-tab" data-bs-toggle="tab" data-bs-target="#comparativo" type="button" role="tab">
                            <i class="bi bi-bar-chart"></i> Análisis Comparativo
                        </button>
                    </li>
                </ul>

                <div class="tab-content" id="reporteTabContent">
                    <!-- Reporte de Ingresos -->
                    <div class="tab-pane fade show active" id="ingresos" role="tabpanel">
                        <div class="row mt-4">
                            <!-- Resumen de ingresos -->
                            <div class="col-md-12 mb-4">
                                <div class="card">
                                    <div class="card-header">
                                        <h6 class="mb-0">📊 Resumen de Ingresos</h6>
                                    </div>
                                    <div class="card-body">
                                        <div class="row">
                                            <div class="col-md-3 text-center">
                                                <h4 class="text-success"><?php echo $reporte_ingresos['resumen']['total_ingresos'] ?: 0; ?></h4>
                                                <small>Total Ingresos</small>
                                            </div>
                                            <div class="col-md-3 text-center">
                                                <h4 class="text-info"><?php echo $reporte_ingresos['resumen']['total_proveedores'] ?: 0; ?></h4>
                                                <small>Proveedores</small>
                                            </div>
                                            <div class="col-md-3 text-center">
                                                <h4 class="text-primary">$<?php echo number_format($reporte_ingresos['resumen']['total_monto'] ?: 0, 2); ?></h4>
                                                <small>Monto Total</small>
                                            </div>
                                            <div class="col-md-3 text-center">
                                                <h4 class="text-warning"><?php echo number_format($reporte_ingresos['resumen']['total_productos_ingresados'] ?: 0); ?></h4>
                                                <small>Productos Ingresados</small>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <!-- Ingresos por proveedor -->
                            <div class="col-md-6 mb-4">
                                <div class="card">
                                    <div class="card-header">
                                        <h6 class="mb-0">🏢 Ingresos por Proveedor</h6>
                                    </div>
                                    <div class="card-body">
                                        <?php if (count($reporte_ingresos['por_proveedor']) > 0): ?>
                                            <div class="table-responsive">
                                                <table class="table table-sm">
                                                    <thead>
                                                        <tr>
                                                            <th>Proveedor</th>
                                                            <th>Ingresos</th>
                                                            <th>Monto</th>
                                                            <th>Productos</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php foreach ($reporte_ingresos['por_proveedor'] as $proveedor): ?>
                                                            <tr>
                                                                <td><?php echo htmlspecialchars($proveedor['proveedor']); ?></td>
                                                                <td><?php echo $proveedor['total_ingresos']; ?></td>
                                                                <td class="fw-bold">$<?php echo number_format($proveedor['total_monto'], 2); ?></td>
                                                                <td><?php echo number_format($proveedor['total_productos']); ?></td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                    <tfoot class="table-light">
                                                        <tr>
                                                            <th>TOTAL</th>
                                                            <th><?php echo array_sum(array_column($reporte_ingresos['por_proveedor'], 'total_ingresos')); ?></th>
                                                            <th class="fw-bold">$<?php echo number_format(array_sum(array_column($reporte_ingresos['por_proveedor'], 'total_monto')), 2); ?></th>
                                                            <th><?php echo number_format(array_sum(array_column($reporte_ingresos['por_proveedor'], 'total_productos'))); ?></th>
                                                        </tr>
                                                    </tfoot>
                                                </table>
                                            </div>
                                        <?php else: ?>
                                            <div class="alert alert-info">No hay ingresos en el período seleccionado.</div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>

                            <!-- Top productos ingresados -->
                            <div class="col-md-6 mb-4">
                                <div class="card">
                                    <div class="card-header">
                                        <h6 class="mb-0">🥇 Top Productos Ingresados</h6>
                                    </div>
                                    <div class="card-body">
                                        <?php if (count($reporte_ingresos['top_productos']) > 0): ?>
                                            <div class="table-responsive">
                                                <table class="table table-sm">
                                                    <thead>
                                                        <tr>
                                                            <th>#</th>
                                                            <th>Producto</th>
                                                            <th>Cantidad</th>
                                                            <th>Costo Total</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php foreach ($reporte_ingresos['top_productos'] as $index => $producto): ?>
                                                            <tr>
                                                                <td><?php echo $index + 1; ?></td>
                                                                <td>
                                                                    <strong><?php echo htmlspecialchars($producto['descripcion']); ?></strong><br>
                                                                    <small class="text-muted"><?php echo htmlspecialchars($producto['proveedor']); ?></small>
                                                                </td>
                                                                <td><?php echo number_format($producto['total_cantidad']); ?></td>
                                                                <td class="fw-bold">$<?php echo number_format($producto['total_costo'], 2); ?></td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        <?php else: ?>
                                            <div class="alert alert-info">No hay productos ingresados en el período seleccionado.</div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>

                            <!-- Ingresos por fecha -->
                            <div class="col-md-12">
                                <div class="card">
                                    <div class="card-header">
                                        <h6 class="mb-0">📅 Ingresos por Fecha</h6>
                                    </div>
                                    <div class="card-body">
                                        <?php if (count($reporte_ingresos['por_fecha']) > 0): ?>
                                            <div class="table-responsive">
                                                <table class="table table-sm table-striped">
                                                    <thead>
                                                        <tr>
                                                            <th>Fecha</th>
                                                            <th>Ingresos</th>
                                                            <th>Monto Total</th>
                                                            <th>Productos</th>
                                                            <th>Promedio por Ingreso</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php foreach ($reporte_ingresos['por_fecha'] as $fecha): ?>
                                                            <tr>
                                                                <td><?php echo date('d/m/Y', strtotime($fecha['fecha'])); ?></td>
                                                                <td><?php echo $fecha['total_ingresos']; ?></td>
                                                                <td class="fw-bold">$<?php echo number_format($fecha['total_monto'], 2); ?></td>
                                                                <td><?php echo number_format($fecha['total_productos']); ?></td>
                                                                <td>$<?php echo number_format($fecha['total_monto'] / max(1, $fecha['total_ingresos']), 2); ?></td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                    <tfoot class="table-light">
                                                        <tr>
                                                            <th>TOTAL</th>
                                                            <th><?php echo array_sum(array_column($reporte_ingresos['por_fecha'], 'total_ingresos')); ?></th>
                                                            <th class="fw-bold">$<?php echo number_format(array_sum(array_column($reporte_ingresos['por_fecha'], 'total_monto')), 2); ?></th>
                                                            <th><?php echo number_format(array_sum(array_column($reporte_ingresos['por_fecha'], 'total_productos'))); ?></th>
                                                            <th>-</th>
                                                        </tr>
                                                    </tfoot>
                                                </table>
                                            </div>
                                        <?php else: ?>
                                            <div class="alert alert-info">No hay ingresos en el período seleccionado.</div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Reporte de Salidas -->
                    <div class="tab-pane fade" id="salidas" role="tabpanel">
                        <div class="row mt-4">
                            <!-- Resumen de salidas -->
                            <div class="col-md-12 mb-4">
                                <div class="card">
                                    <div class="card-header">
                                        <h6 class="mb-0">📊 Resumen de Salidas</h6>
                                    </div>
                                    <div class="card-body">
                                        <div class="row">
                                            <div class="col-md-3 text-center">
                                                <h4 class="text-danger"><?php echo $reporte_salidas['resumen']['total_tablas'] ?: 0; ?></h4>
                                                <small>Total Tablas</small>
                                            </div>
                                            <div class="col-md-3 text-center">
                                                <h4 class="text-info"><?php echo $reporte_salidas['resumen']['total_distribuciones'] ?: 0; ?></h4>
                                                <small>Distribuciones</small>
                                            </div>
                                            <div class="col-md-3 text-center">
                                                <h4 class="text-primary">$<?php echo number_format($reporte_salidas['resumen']['total_monto'] ?: 0, 2); ?></h4>
                                                <small>Monto Total</small>
                                            </div>
                                            <div class="col-md-3 text-center">
                                                <h4 class="text-warning"><?php echo number_format($reporte_salidas['resumen']['total_productos_distribuidos'] ?: 0); ?></h4>
                                                <small>Productos Distribuidos</small>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Salidas por proveedor -->
                            <div class="col-md-6 mb-4">
                                <div class="card">
                                    <div class="card-header">
                                        <h6 class="mb-0">🏢 Salidas por Proveedor</h6>
                                    </div>
                                    <div class="card-body">
                                        <?php if (count($reporte_salidas['por_proveedor']) > 0): ?>
                                            <div class="table-responsive">
                                                <table class="table table-sm">
                                                    <thead>
                                                        <tr>
                                                            <th>Proveedor</th>
                                                            <th>Tablas</th>
                                                            <th>Monto</th>
                                                            <th>Productos</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php foreach ($reporte_salidas['por_proveedor'] as $proveedor): ?>
                                                            <tr>
                                                                <td><?php echo htmlspecialchars($proveedor['proveedor']); ?></td>
                                                                <td><?php echo $proveedor['total_tablas']; ?></td>
                                                                <td class="fw-bold">$<?php echo number_format($proveedor['total_monto'], 2); ?></td>
                                                                <td><?php echo number_format($proveedor['total_productos']); ?></td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                    <tfoot class="table-light">
                                                        <tr>
                                                            <th>TOTAL</th>
                                                            <th><?php echo array_sum(array_column($reporte_salidas['por_proveedor'], 'total_tablas')); ?></th>
                                                            <th class="fw-bold">$<?php echo number_format(array_sum(array_column($reporte_salidas['por_proveedor'], 'total_monto')), 2); ?></th>
                                                            <th><?php echo number_format(array_sum(array_column($reporte_salidas['por_proveedor'], 'total_productos'))); ?></th>
                                                        </tr>
                                                    </tfoot>
                                                </table>
                                            </div>
                                        <?php else: ?>
                                            <div class="alert alert-info">No hay salidas en el período seleccionado.</div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>

                            <!-- Top productos distribuidos -->
                            <div class="col-md-6 mb-4">
                                <div class="card">
                                    <div class="card-header">
                                        <h6 class="mb-0">🥇 Top Productos Distribuidos</h6>
                                    </div>
                                    <div class="card-body">
                                        <?php if (count($reporte_salidas['top_productos']) > 0): ?>
                                            <div class="table-responsive">
                                                <table class="table table-sm">
                                                    <thead>
                                                        <tr>
                                                            <th>#</th>
                                                            <th>Producto</th>
                                                            <th>Cantidad</th>
                                                            <th>Venta Total</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php foreach ($reporte_salidas['top_productos'] as $index => $producto): ?>
                                                            <tr>
                                                                <td><?php echo $index + 1; ?></td>
                                                                <td>
                                                                    <strong><?php echo htmlspecialchars($producto['descripcion']); ?></strong><br>
                                                                    <small class="text-muted"><?php echo htmlspecialchars($producto['proveedor']); ?></small>
                                                                </td>
                                                                <td><?php echo number_format($producto['total_cantidad']); ?></td>
                                                                <td class="fw-bold">$<?php echo number_format($producto['total_venta'], 2); ?></td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        <?php else: ?>
                                            <div class="alert alert-info">No hay productos distribuidos en el período seleccionado.</div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>

                            <!-- Salidas por fecha -->
                            <div class="col-md-12">
                                <div class="card">
                                    <div class="card-header">
                                        <h6 class="mb-0">📅 Salidas por Fecha</h6>
                                    </div>
                                    <div class="card-body">
                                        <?php if (count($reporte_salidas['por_fecha']) > 0): ?>
                                            <div class="table-responsive">
                                                <table class="table table-sm table-striped">
                                                    <thead>
                                                        <tr>
                                                            <th>Fecha</th>
                                                            <th>Tablas</th>
                                                            <th>Monto Total</th>
                                                            <th>Productos</th>
                                                            <th>Promedio por Tabla</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php foreach ($reporte_salidas['por_fecha'] as $fecha): ?>
                                                            <tr>
                                                                <td><?php echo date('d/m/Y', strtotime($fecha['fecha'])); ?></td>
                                                                <td><?php echo $fecha['total_tablas']; ?></td>
                                                                <td class="fw-bold">$<?php echo number_format($fecha['total_monto'], 2); ?></td>
                                                                <td><?php echo number_format($fecha['total_productos']); ?></td>
                                                                <td>$<?php echo number_format($fecha['total_monto'] / max(1, $fecha['total_tablas']), 2); ?></td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                    <tfoot class="table-light">
                                                        <tr>
                                                            <th>TOTAL</th>
                                                            <th><?php echo array_sum(array_column($reporte_salidas['por_fecha'], 'total_tablas')); ?></th>
                                                            <th class="fw-bold">$<?php echo number_format(array_sum(array_column($reporte_salidas['por_fecha'], 'total_monto')), 2); ?></th>
                                                            <th><?php echo number_format(array_sum(array_column($reporte_salidas['por_fecha'], 'total_productos'))); ?></th>
                                                            <th>-</th>
                                                        </tr>
                                                    </tfoot>
                                                </table>
                                            </div>
                                        <?php else: ?>
                                            <div class="alert alert-info">No hay salidas en el período seleccionado.</div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <!-- Análisis Comparativo -->
                    <div class="tab-pane fade" id="comparativo" role="tabpanel">
                        <div class="row mt-4">
                            <!-- Comparación general -->
                            <div class="col-md-12 mb-4">
                                <div class="card">
                                    <div class="card-header">
                                        <h6 class="mb-0">📈 Análisis Comparativo de Ingresos vs Salidas</h6>
                                    </div>
                                    <div class="card-body">
                                        <div class="row">
                                            <div class="col-md-6">
                                                <h6 class="text-success">📥 Ingresos</h6>
                                                <ul class="list-unstyled">
                                                    <li><strong>Total Ingresos:</strong> <?php echo $reporte_ingresos['resumen']['total_ingresos'] ?: 0; ?></li>
                                                    <li><strong>Monto Total:</strong> $<?php echo number_format($total_ingresos_monto, 2); ?></li>
                                                    <li><strong>Productos Ingresados:</strong> <?php echo number_format($total_ingresos_productos); ?></li>
                                                    <li><strong>Proveedores Activos:</strong> <?php echo $reporte_ingresos['resumen']['total_proveedores'] ?: 0; ?></li>
                                                </ul>
                                            </div>
                                            <div class="col-md-6">
                                                <h6 class="text-danger">📤 Salidas</h6>
                                                <ul class="list-unstyled">
                                                    <li><strong>Total Tablas:</strong> <?php echo $reporte_salidas['resumen']['total_tablas'] ?: 0; ?></li>
                                                    <li><strong>Monto Total:</strong> $<?php echo number_format($total_salidas_monto, 2); ?></li>
                                                    <li><strong>Productos Distribuidos:</strong> <?php echo number_format($total_salidas_productos); ?></li>
                                                    <li><strong>Distribuciones:</strong> <?php echo $reporte_salidas['resumen']['total_distribuciones'] ?: 0; ?></li>
                                                </ul>
                                            </div>
                                        </div>
                                        
                                        <hr>
                                        
                                        <div class="row">
                                            <div class="col-md-6">
                                                <div class="alert <?php echo $diferencia_monto >= 0 ? 'alert-success' : 'alert-danger'; ?>">
                                                    <h6>💰 Diferencia Monetaria</h6>
                                                    <h4><?php echo $diferencia_monto >= 0 ? '+' : ''; ?>$<?php echo number_format($diferencia_monto, 2); ?></h4>
                                                    <small>
                                                        <?php 
                                                        if ($total_ingresos_monto > 0) {
                                                            $porcentaje = (($diferencia_monto / $total_ingresos_monto) * 100);
                                                            echo number_format($porcentaje, 2) . "% " . ($porcentaje >= 0 ? "ganancia" : "pérdida");
                                                        }
                                                        ?>
                                                    </small>
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="alert <?php echo $diferencia_productos >= 0 ? 'alert-success' : 'alert-warning'; ?>">
                                                    <h6>📦 Diferencia en Productos</h6>
                                                    <h4><?php echo $diferencia_productos >= 0 ? '+' : ''; ?><?php echo number_format($diferencia_productos); ?></h4>
                                                    <small>
                                                        <?php 
                                                        if ($total_ingresos_productos > 0) {
                                                            $porcentaje_prod = (($diferencia_productos / $total_ingresos_productos) * 100);
                                                            echo number_format($porcentaje_prod, 2) . "% " . ($porcentaje_prod >= 0 ? "más salidas" : "menos salidas");
                                                        }
                                                        ?>
                                                    </small>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Comparación por proveedor -->
                            <div class="col-md-12 mb-4">
                                <div class="card">
                                    <div class="card-header">
                                        <h6 class="mb-0">🏢 Comparación por Proveedor</h6>
                                    </div>
                                    <div class="card-body">
                                        <?php
                                        // Combinar datos de ingresos y salidas por proveedor
                                        $proveedores_comparacion = [];
                                        
                                        // Agregar ingresos
                                        foreach ($reporte_ingresos['por_proveedor'] as $ingreso) {
                                            $proveedor = $ingreso['proveedor'];
                                            $proveedores_comparacion[$proveedor]['ingresos'] = $ingreso['total_monto'];
                                            $proveedores_comparacion[$proveedor]['productos_ingresados'] = $ingreso['total_productos'];
                                        }
                                        
                                        // Agregar salidas
                                        foreach ($reporte_salidas['por_proveedor'] as $salida) {
                                            $proveedor = $salida['proveedor'];
                                            $proveedores_comparacion[$proveedor]['salidas'] = $salida['total_monto'];
                                            $proveedores_comparacion[$proveedor]['productos_distribuidos'] = $salida['total_productos'];
                                        }
                                        
                                        // Completar datos faltantes
                                        foreach ($proveedores_comparacion as $proveedor => $datos) {
                                            $proveedores_comparacion[$proveedor]['ingresos'] = $datos['ingresos'] ?? 0;
                                            $proveedores_comparacion[$proveedor]['salidas'] = $datos['salidas'] ?? 0;
                                            $proveedores_comparacion[$proveedor]['productos_ingresados'] = $datos['productos_ingresados'] ?? 0;
                                            $proveedores_comparacion[$proveedor]['productos_distribuidos'] = $datos['productos_distribuidos'] ?? 0;
                                            $proveedores_comparacion[$proveedor]['diferencia'] = $datos['salidas'] - $datos['ingresos'];
                                        }
                                        ?>
                                        
                                        <?php if (count($proveedores_comparacion) > 0): ?>
                                            <div class="table-responsive">
                                                <table class="table table-sm table-striped">
                                                    <thead>
                                                        <tr>
                                                            <th>Proveedor</th>
                                                            <th>Ingresos</th>
                                                            <th>Salidas</th>
                                                            <th>Diferencia</th>
                                                            <th>Prod. Ingresados</th>
                                                            <th>Prod. Distribuidos</th>
                                                            <th>% Rendimiento</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php foreach ($proveedores_comparacion as $proveedor => $datos): ?>
                                                            <tr>
                                                                <td><?php echo htmlspecialchars($proveedor); ?></td>
                                                                <td class="text-success">$<?php echo number_format($datos['ingresos'], 2); ?></td>
                                                                <td class="text-danger">$<?php echo number_format($datos['salidas'], 2); ?></td>
                                                                <td class="fw-bold <?php echo $datos['diferencia'] >= 0 ? 'text-success' : 'text-danger'; ?>">
                                                                    <?php echo $datos['diferencia'] >= 0 ? '+' : ''; ?>$<?php echo number_format($datos['diferencia'], 2); ?>
                                                                </td>
                                                                <td><?php echo number_format($datos['productos_ingresados']); ?></td>
                                                                <td><?php echo number_format($datos['productos_distribuidos']); ?></td>
                                                                <td>
                                                                    <?php 
                                                                    if ($datos['ingresos'] > 0) {
                                                                        $rendimiento = (($datos['diferencia'] / $datos['ingresos']) * 100);
                                                                        echo '<span class="badge ' . ($rendimiento >= 0 ? 'bg-success' : 'bg-danger') . '">';
                                                                        echo number_format($rendimiento, 1) . '%';
                                                                        echo '</span>';
                                                                    } else {
                                                                        echo '-';
                                                                    }
                                                                    ?>
                                                                </td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                    <tfoot class="table-light">
                                                        <tr>
                                                            <th>TOTAL</th>
                                                            <th class="text-success">$<?php echo number_format($total_ingresos_monto, 2); ?></th>
                                                            <th class="text-danger">$<?php echo number_format($total_salidas_monto, 2); ?></th>
                                                            <th class="fw-bold <?php echo $diferencia_monto >= 0 ? 'text-success' : 'text-danger'; ?>">
                                                                <?php echo $diferencia_monto >= 0 ? '+' : ''; ?>$<?php echo number_format($diferencia_monto, 2); ?>
                                                            </th>
                                                            <th><?php echo number_format($total_ingresos_productos); ?></th>
                                                            <th><?php echo number_format($total_salidas_productos); ?></th>
                                                            <th>
                                                                <?php 
                                                                if ($total_ingresos_monto > 0) {
                                                                    $rendimiento_total = (($diferencia_monto / $total_ingresos_monto) * 100);
                                                                    echo '<span class="badge ' . ($rendimiento_total >= 0 ? 'bg-success' : 'bg-danger') . '">';
                                                                    echo number_format($rendimiento_total, 1) . '%';
                                                                    echo '</span>';
                                                                } else {
                                                                    echo '-';
                                                                }
                                                                ?>
                                                            </th>
                                                        </tr>
                                                    </tfoot>
                                                </table>
                                            </div>
                                        <?php else: ?>
                                            <div class="alert alert-info">No hay datos para comparar en el período seleccionado.</div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>

                            <!-- Métricas adicionales -->
                            <div class="col-md-12">
                                <div class="card">
                                    <div class="card-header">
                                        <h6 class="mb-0">📊 Métricas Adicionales</h6>
                                    </div>
                                    <div class="card-body">
                                        <div class="row">
                                            <div class="col-md-3">
                                                <div class="text-center">
                                                    <h5 class="text-primary">
                                                        $<?php 
                                                        $promedio_ingreso = $reporte_ingresos['resumen']['total_ingresos'] > 0 
                                                            ? $total_ingresos_monto / $reporte_ingresos['resumen']['total_ingresos'] 
                                                            : 0;
                                                        echo number_format($promedio_ingreso, 2);
                                                        ?>
                                                    </h5>
                                                    <small>Promedio por Ingreso</small>
                                                </div>
                                            </div>
                                            <div class="col-md-3">
                                                <div class="text-center">
                                                    <h5 class="text-info">
                                                        $<?php 
                                                        $promedio_tabla = $reporte_salidas['resumen']['total_tablas'] > 0 
                                                            ? $total_salidas_monto / $reporte_salidas['resumen']['total_tablas'] 
                                                            : 0;
                                                        echo number_format($promedio_tabla, 2);
                                                        ?>
                                                    </h5>
                                                    <small>Promedio por Tabla</small>
                                                </div>
                                            </div>
                                            <div class="col-md-3">
                                                <div class="text-center">
                                                    <h5 class="text-success">
                                                        <?php 
                                                        $rotacion = $total_ingresos_productos > 0 
                                                            ? ($total_salidas_productos / $total_ingresos_productos) * 100 
                                                            : 0;
                                                        echo number_format($rotacion, 1) . '%';
                                                        ?>
                                                    </h5>
                                                    <small>Rotación de Productos</small>
                                                </div>
                                            </div>
                                            <div class="col-md-3">
                                                <div class="text-center">
                                                    <h5 class="text-warning">
                                                        <?php 
                                                        $margen = $total_ingresos_monto > 0 
                                                            ? (($diferencia_monto / $total_ingresos_monto) * 100) 
                                                            : 0;
                                                        echo number_format($margen, 1) . '%';
                                                        ?>
                                                    </h5>
                                                    <small>Margen de Ganancia</small>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Establecer fecha por defecto (primer día del mes actual)
        document.addEventListener('DOMContentLoaded', function() {
            const fechaDesde = document.getElementById('fecha_desde');
            const fechaHasta = document.getElementById('fecha_hasta');
            
            // Si no hay valores, establecer defaults
            if (!fechaDesde.value) {
                const hoy = new Date();
                const primerDia = new Date(hoy.getFullYear(), hoy.getMonth(), 1);
                fechaDesde.value = primerDia.toISOString().split('T')[0];
            }
            
            if (!fechaHasta.value) {
                fechaHasta.value = new Date().toISOString().split('T')[0];
            }
        });

        // Actualizar fecha hasta cuando cambia fecha desde
        document.getElementById('fecha_desde').addEventListener('change', function() {
            const fechaDesde = this.value;
            const fechaHasta = document.getElementById('fecha_hasta');
            
            if (fechaHasta.value < fechaDesde) {
                fechaHasta.value = fechaDesde;
            }
            fechaHasta.min = fechaDesde;
        });
    </script>
</body>
</html>