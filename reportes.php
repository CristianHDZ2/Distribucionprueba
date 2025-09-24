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
$stmt_proveedores = $db->prepare("SELECT DISTINCT proveedor FROM productos WHERE proveedor IS NOT NULL AND proveedor != '' ORDER BY proveedor");
$stmt_proveedores->execute();
$proveedores = $stmt_proveedores->fetchAll();

// **FUNCIÓN CORREGIDA PARA OBTENER REPORTE DE INGRESOS**
function obtenerReporteIngresos($db, $fecha_desde, $fecha_hasta, $proveedor_filter = '') {
    $where_conditions = ["i.fecha_ingreso BETWEEN ? AND ?"];
    $params = [$fecha_desde, $fecha_hasta];
    
    if (!empty($proveedor_filter)) {
        $where_conditions[] = "i.proveedor = ?";
        $params[] = $proveedor_filter;
    }
    
    $where_clause = "WHERE " . implode(" AND ", $where_conditions);
    
    // **CORRECCIÓN 1: Resumen de ingresos - Consulta simplificada y correcta**
    $stmt_resumen = $db->prepare("SELECT 
        COUNT(DISTINCT i.id) as total_ingresos,
        COUNT(DISTINCT i.proveedor) as total_proveedores,
        COALESCE(SUM(i.total_factura), 0) as total_monto
    FROM ingresos i
    $where_clause");
    $stmt_resumen->execute($params);
    $resumen_ingresos = $stmt_resumen->fetch();
    
    // **CORRECCIÓN 2: Total de productos ingresados - Consulta separada para evitar duplicados**
    $stmt_productos_ingresados = $db->prepare("SELECT 
        COALESCE(SUM(di.cantidad), 0) as total_productos_ingresados
    FROM detalle_ingresos di
    INNER JOIN ingresos i ON di.ingreso_id = i.id
    $where_clause");
    $stmt_productos_ingresados->execute($params);
    $productos_data = $stmt_productos_ingresados->fetch();
    $resumen_ingresos['total_productos_ingresados'] = $productos_data['total_productos_ingresados'];
    
    // **CORRECCIÓN 3: Ingresos por proveedor - Consulta corregida**
    $stmt_por_proveedor = $db->prepare("SELECT 
        i.proveedor,
        COUNT(DISTINCT i.id) as total_ingresos,
        COALESCE(SUM(i.total_factura), 0) as total_monto,
        COALESCE(AVG(i.total_factura), 0) as promedio_factura
    FROM ingresos i
    $where_clause
    GROUP BY i.proveedor
    ORDER BY total_monto DESC");
    $stmt_por_proveedor->execute($params);
    $ingresos_por_proveedor = $stmt_por_proveedor->fetchAll();
    
    // **CORRECCIÓN 4: Agregar cantidad de productos por proveedor**
    foreach ($ingresos_por_proveedor as $index => $proveedor_data) {
        $stmt_productos_prov = $db->prepare("SELECT 
            COALESCE(SUM(di.cantidad), 0) as total_productos
        FROM detalle_ingresos di
        INNER JOIN ingresos i ON di.ingreso_id = i.id
        $where_clause AND i.proveedor = ?");
        $params_prov = array_merge($params, [$proveedor_data['proveedor']]);
        $stmt_productos_prov->execute($params_prov);
        $prod_data = $stmt_productos_prov->fetch();
        $ingresos_por_proveedor[$index]['total_productos'] = $prod_data['total_productos'];
    }
    
    // **CORRECCIÓN 5: Ingresos por fecha - Consulta simplificada**
    $stmt_por_fecha = $db->prepare("SELECT 
        DATE(i.fecha_ingreso) as fecha,
        COUNT(DISTINCT i.id) as total_ingresos,
        COALESCE(SUM(i.total_factura), 0) as total_monto
    FROM ingresos i
    $where_clause
    GROUP BY DATE(i.fecha_ingreso)
    ORDER BY DATE(i.fecha_ingreso) DESC");
    $stmt_por_fecha->execute($params);
    $ingresos_por_fecha = $stmt_por_fecha->fetchAll();
    
    // **CORRECCIÓN 6: Top productos ingresados - Consulta corregida**
    $stmt_top_productos = $db->prepare("SELECT 
        p.descripcion,
        p.proveedor,
        COALESCE(SUM(di.cantidad), 0) as total_cantidad,
        COALESCE(SUM(di.subtotal), 0) as total_costo,
        COALESCE(AVG(di.precio_compra), 0) as precio_promedio
    FROM detalle_ingresos di
    INNER JOIN ingresos i ON di.ingreso_id = i.id
    INNER JOIN productos p ON di.producto_id = p.id
    $where_clause
    GROUP BY p.id, p.descripcion, p.proveedor
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

// **FUNCIÓN CORREGIDA PARA OBTENER REPORTE DE SALIDAS**
function obtenerReporteSalidas($db, $fecha_desde, $fecha_hasta, $proveedor_filter = '') {
    $where_conditions = ["td.fecha_tabla BETWEEN ? AND ?", "td.estado = 'activo'"];
    $params = [$fecha_desde, $fecha_hasta];
    
    // **CORRECCIÓN 7: Filtro por proveedor en salidas debe ser a través del JOIN con productos**
    $proveedor_join = "";
    if (!empty($proveedor_filter)) {
        $proveedor_join = "INNER JOIN detalle_tablas_distribucion dtd_filter ON td.id = dtd_filter.tabla_id 
                           INNER JOIN productos p_filter ON dtd_filter.producto_id = p_filter.id";
        $where_conditions[] = "p_filter.proveedor = ?";
        $params[] = $proveedor_filter;
    }
    
    $where_clause = "WHERE " . implode(" AND ", $where_conditions);
    
    // **CORRECCIÓN 8: Resumen de salidas - Consulta corregida**
    $stmt_resumen = $db->prepare("SELECT 
        COUNT(DISTINCT td.id) as total_tablas,
        COUNT(DISTINCT td.distribucion_id) as total_distribuciones,
        COALESCE(SUM(td.total_tabla), 0) as total_monto
    FROM tablas_distribucion td
    $proveedor_join
    $where_clause");
    $stmt_resumen->execute($params);
    $resumen_salidas = $stmt_resumen->fetch();
    
    // **CORRECCIÓN 9: Total de productos distribuidos - Consulta separada**
    $stmt_productos_distribuidos = $db->prepare("SELECT 
        COALESCE(SUM(dtd.cantidad), 0) as total_productos_distribuidos
    FROM detalle_tablas_distribucion dtd
    INNER JOIN tablas_distribucion td ON dtd.tabla_id = td.id
    " . (!empty($proveedor_filter) ? "INNER JOIN productos p ON dtd.producto_id = p.id" : "") . "
    WHERE td.fecha_tabla BETWEEN ? AND ? AND td.estado = 'activo'
    " . (!empty($proveedor_filter) ? "AND p.proveedor = ?" : ""));
    
    $params_productos = [$fecha_desde, $fecha_hasta];
    if (!empty($proveedor_filter)) {
        $params_productos[] = $proveedor_filter;
    }
    
    $stmt_productos_distribuidos->execute($params_productos);
    $productos_data = $stmt_productos_distribuidos->fetch();
    $resumen_salidas['total_productos_distribuidos'] = $productos_data['total_productos_distribuidos'];
    
    // **CORRECCIÓN 10: Salidas por proveedor - Consulta completamente corregida**
    $stmt_por_proveedor = $db->prepare("SELECT 
        p.proveedor,
        COUNT(DISTINCT td.id) as total_tablas,
        COALESCE(SUM(dtd.subtotal), 0) as total_monto,
        COALESCE(SUM(dtd.cantidad), 0) as total_productos,
        COALESCE(AVG(dtd.precio_venta), 0) as precio_promedio
    FROM tablas_distribucion td
    INNER JOIN detalle_tablas_distribucion dtd ON td.id = dtd.tabla_id
    INNER JOIN productos p ON dtd.producto_id = p.id
    WHERE td.fecha_tabla BETWEEN ? AND ? AND td.estado = 'activo'
    " . (!empty($proveedor_filter) ? "AND p.proveedor = ?" : "") . "
    GROUP BY p.proveedor
    ORDER BY total_monto DESC");
    
    $stmt_por_proveedor->execute($params_productos);
    $salidas_por_proveedor = $stmt_por_proveedor->fetchAll();
    // **CORRECCIÓN 11: Salidas por fecha - Consulta corregida**
    $stmt_por_fecha = $db->prepare("SELECT 
        DATE(td.fecha_tabla) as fecha,
        COUNT(DISTINCT td.id) as total_tablas,
        COALESCE(SUM(td.total_tabla), 0) as total_monto
    FROM tablas_distribucion td
    " . (!empty($proveedor_filter) ? 
        "INNER JOIN detalle_tablas_distribucion dtd ON td.id = dtd.tabla_id 
         INNER JOIN productos p ON dtd.producto_id = p.id" : "") . "
    WHERE td.fecha_tabla BETWEEN ? AND ? AND td.estado = 'activo'
    " . (!empty($proveedor_filter) ? "AND p.proveedor = ?" : "") . "
    GROUP BY DATE(td.fecha_tabla)
    ORDER BY DATE(td.fecha_tabla) DESC");
    $stmt_por_fecha->execute($params_productos);
    $salidas_por_fecha = $stmt_por_fecha->fetchAll();
    
    // **CORRECCIÓN 12: Top productos distribuidos - Consulta corregida**
    $stmt_top_productos = $db->prepare("SELECT 
        p.descripcion,
        p.proveedor,
        COALESCE(SUM(dtd.cantidad), 0) as total_cantidad,
        COALESCE(SUM(dtd.subtotal), 0) as total_venta,
        COALESCE(AVG(dtd.precio_venta), 0) as precio_promedio
    FROM detalle_tablas_distribucion dtd
    INNER JOIN tablas_distribucion td ON dtd.tabla_id = td.id
    INNER JOIN productos p ON dtd.producto_id = p.id
    WHERE td.fecha_tabla BETWEEN ? AND ? AND td.estado = 'activo'
    " . (!empty($proveedor_filter) ? "AND p.proveedor = ?" : "") . "
    GROUP BY p.id, p.descripcion, p.proveedor
    ORDER BY total_cantidad DESC
    LIMIT 10");
    $stmt_top_productos->execute($params_productos);
    $top_productos_distribuidos = $stmt_top_productos->fetchAll();
    
    return [
        'resumen' => $resumen_salidas,
        'por_proveedor' => $salidas_por_proveedor,
        'por_fecha' => $salidas_por_fecha,
        'top_productos' => $top_productos_distribuidos
    ];
}

// **OBTENER DATOS SEGÚN EL TIPO DE REPORTE - CORREGIDO**
$reporte_ingresos = obtenerReporteIngresos($db, $fecha_desde, $fecha_hasta, $proveedor_filter);
$reporte_salidas = obtenerReporteSalidas($db, $fecha_desde, $fecha_hasta, $proveedor_filter);

// **CORRECCIÓN 13: Calcular análisis comparativo - Valores seguros**
$total_ingresos_monto = $reporte_ingresos['resumen']['total_monto'] ?: 0;
$total_salidas_monto = $reporte_salidas['resumen']['total_monto'] ?: 0;
$diferencia_monto = $total_salidas_monto - $total_ingresos_monto;

$total_ingresos_productos = $reporte_ingresos['resumen']['total_productos_ingresados'] ?: 0;
$total_salidas_productos = $reporte_salidas['resumen']['total_productos_distribuidos'] ?: 0;
$diferencia_productos = $total_salidas_productos - $total_ingresos_productos;

// **CORRECCIÓN 14: Análisis por proveedor corregido**
$proveedores_comparacion = [];

// Primero obtener todos los proveedores únicos de ambos reportes
$proveedores_unicos = [];
foreach ($reporte_ingresos['por_proveedor'] as $ingreso) {
    $proveedores_unicos[$ingreso['proveedor']] = true;
}
foreach ($reporte_salidas['por_proveedor'] as $salida) {
    $proveedores_unicos[$salida['proveedor']] = true;
}

// Crear comparación por proveedor
foreach (array_keys($proveedores_unicos) as $proveedor) {
    // Buscar datos de ingresos
    $datos_ingreso = null;
    foreach ($reporte_ingresos['por_proveedor'] as $ingreso) {
        if ($ingreso['proveedor'] == $proveedor) {
            $datos_ingreso = $ingreso;
            break;
        }
    }
    
    // Buscar datos de salidas
    $datos_salida = null;
    foreach ($reporte_salidas['por_proveedor'] as $salida) {
        if ($salida['proveedor'] == $proveedor) {
            $datos_salida = $salida;
            break;
        }
    }
    
    $proveedores_comparacion[$proveedor] = [
        'ingresos' => $datos_ingreso ? $datos_ingreso['total_monto'] : 0,
        'salidas' => $datos_salida ? $datos_salida['total_monto'] : 0,
        'productos_ingresados' => $datos_ingreso ? $datos_ingreso['total_productos'] : 0,
        'productos_distribuidos' => $datos_salida ? $datos_salida['total_productos'] : 0
    ];
    
    $proveedores_comparacion[$proveedor]['diferencia'] = 
        $proveedores_comparacion[$proveedor]['salidas'] - $proveedores_comparacion[$proveedor]['ingresos'];
}
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
        
        /* **CORRECCIÓN 15: Estilos para indicadores visuales mejorados** */
        .metric-positive {
            color: #28a745 !important;
            font-weight: bold;
        }
        .metric-negative {
            color: #dc3545 !important;
            font-weight: bold;
        }
        .metric-neutral {
            color: #6c757d !important;
        }
        .summary-box {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 1.5rem;
            margin-bottom: 1rem;
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <!-- **SIDEBAR CORREGIDO** -->
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
                            <a class="nav-link" href="ingresos.php">
                                <i class="bi bi-plus-circle"></i> Ingresos
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="distribuciones.php">
                                <i class="bi bi-truck"></i> Distribuciones
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link active" href="reportes.php">
                                <i class="bi bi-bar-chart"></i> Reportes
                            </a>
                        </li>
                    </ul>
                </div>
            </nav>

            <!-- **CONTENIDO PRINCIPAL CORREGIDO** -->
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 main-content">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">📊 Reportes del Sistema</h1>
                    <div class="btn-toolbar mb-2 mb-md-0 no-print">
                        <button type="button" class="btn btn-outline-primary" onclick="window.print()">
                            <i class="bi bi-printer"></i> Imprimir
                        </button>
                    </div>
                </div>

                <!-- **CORRECCIÓN 16: Filtros mejorados** -->
                <div class="card mb-4 no-print">
                    <div class="card-header">
                        <h5><i class="bi bi-funnel"></i> Filtros de Búsqueda</h5>
                    </div>
                    <div class="card-body">
                        <form method="GET" action="reportes.php" class="row g-3">
                            <div class="col-md-3">
                                <label for="fecha_desde" class="form-label">Fecha Desde:</label>
                                <input type="date" id="fecha_desde" name="fecha_desde" class="form-control" 
                                       value="<?php echo htmlspecialchars($fecha_desde); ?>">
                            </div>
                            <div class="col-md-3">
                                <label for="fecha_hasta" class="form-label">Fecha Hasta:</label>
                                <input type="date" id="fecha_hasta" name="fecha_hasta" class="form-control" 
                                       value="<?php echo htmlspecialchars($fecha_hasta); ?>">
                            </div>
                            <div class="col-md-4">
                                <label for="proveedor_filter" class="form-label">Filtrar por Proveedor:</label>
                                <select id="proveedor_filter" name="proveedor_filter" class="form-select">
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
                                <button type="submit" class="btn btn-primary w-100">
                                    <i class="bi bi-search"></i> Filtrar
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
                <!-- **CORRECCIÓN 17: Resumen general corregido** -->
                <div class="summary-box">
                    <h4 class="mb-3">📈 Resumen Ejecutivo</h4>
                    <div class="row">
                        <div class="col-md-6">
                            <h6 class="text-success">📥 Ingresos</h6>
                            <ul class="list-unstyled">
                                <li><strong>Total Ingresos:</strong> <?php echo $reporte_ingresos['resumen']['total_ingresos'] ?: 0; ?></li>
                                <li><strong>Monto Total:</strong> <span class="metric-positive">$<?php echo number_format($total_ingresos_monto, 2); ?></span></li>
                                <li><strong>Productos Ingresados:</strong> <?php echo number_format($total_ingresos_productos); ?></li>
                                <li><strong>Proveedores Activos:</strong> <?php echo $reporte_ingresos['resumen']['total_proveedores'] ?: 0; ?></li>
                            </ul>
                        </div>
                        <div class="col-md-6">
                            <h6 class="text-danger">📤 Salidas</h6>
                            <ul class="list-unstyled">
                                <li><strong>Total Tablas:</strong> <?php echo $reporte_salidas['resumen']['total_tablas'] ?: 0; ?></li>
                                <li><strong>Monto Total:</strong> <span class="metric-negative">$<?php echo number_format($total_salidas_monto, 2); ?></span></li>
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
                                <h4 class="<?php echo $diferencia_monto >= 0 ? 'metric-positive' : 'metric-negative'; ?>">
                                    <?php echo $diferencia_monto >= 0 ? '+' : ''; ?>$<?php echo number_format($diferencia_monto, 2); ?>
                                </h4>
                                <small>
                                    <?php 
                                    if ($total_ingresos_monto > 0) {
                                        $porcentaje = (($diferencia_monto / $total_ingresos_monto) * 100);
                                        echo number_format($porcentaje, 2) . "% " . ($porcentaje >= 0 ? "de ganancia" : "de pérdida");
                                    } else {
                                        echo "No hay ingresos para comparar";
                                    }
                                    ?>
                                </small>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="alert <?php echo $diferencia_productos >= 0 ? 'alert-info' : 'alert-warning'; ?>">
                                <h6>📦 Diferencia en Productos</h6>
                                <h4 class="<?php echo $diferencia_productos >= 0 ? 'text-info' : 'text-warning'; ?>">
                                    <?php echo $diferencia_productos >= 0 ? '+' : ''; ?><?php echo number_format($diferencia_productos); ?>
                                </h4>
                                <small>
                                    <?php 
                                    if ($total_ingresos_productos > 0) {
                                        $porcentaje_prod = (($diferencia_productos / $total_ingresos_productos) * 100);
                                        echo number_format($porcentaje_prod, 1) . "% de rotación";
                                    } else {
                                        echo "No hay productos ingresados para comparar";
                                    }
                                    ?>
                                </small>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- **CORRECCIÓN 18: Comparación por proveedores corregida** -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5><i class="bi bi-graph-up"></i> Análisis Comparativo por Proveedores</h5>
                    </div>
                    <div class="card-body">
                        <?php if (count($proveedores_comparacion) > 0): ?>
                            <div class="table-responsive">
                                <table class="table table-sm table-striped">
                                    <thead class="table-dark">
                                        <tr>
                                            <th>Proveedor</th>
                                            <th class="text-center">Ingresos</th>
                                            <th class="text-center">Salidas</th>
                                            <th class="text-center">Diferencia</th>
                                            <th class="text-center">Prod. Ingresados</th>
                                            <th class="text-center">Prod. Distribuidos</th>
                                            <th class="text-center">% Rendimiento</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($proveedores_comparacion as $proveedor => $datos): ?>
                                            <tr>
                                                <td class="fw-bold"><?php echo htmlspecialchars($proveedor); ?></td>
                                                <td class="text-center text-success">$<?php echo number_format($datos['ingresos'], 2); ?></td>
                                                <td class="text-center text-danger">$<?php echo number_format($datos['salidas'], 2); ?></td>
                                                <td class="text-center fw-bold <?php echo $datos['diferencia'] >= 0 ? 'text-success' : 'text-danger'; ?>">
                                                    <?php echo $datos['diferencia'] >= 0 ? '+' : ''; ?>$<?php echo number_format($datos['diferencia'], 2); ?>
                                                </td>
                                                <td class="text-center"><?php echo number_format($datos['productos_ingresados']); ?></td>
                                                <td class="text-center"><?php echo number_format($datos['productos_distribuidos']); ?></td>
                                                <td class="text-center">
                                                    <?php 
                                                    if ($datos['ingresos'] > 0) {
                                                        $rendimiento = (($datos['diferencia'] / $datos['ingresos']) * 100);
                                                        echo '<span class="badge ' . ($rendimiento >= 0 ? 'bg-success' : 'bg-danger') . '">';
                                                        echo number_format($rendimiento, 1) . '%';
                                                        echo '</span>';
                                                    } else {
                                                        echo '<span class="badge bg-secondary">N/A</span>';
                                                    }
                                                    ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                    <tfoot class="table-light">
                                        <tr>
                                            <th>TOTALES</th>
                                            <th class="text-center text-success">$<?php echo number_format($total_ingresos_monto, 2); ?></th>
                                            <th class="text-center text-danger">$<?php echo number_format($total_salidas_monto, 2); ?></th>
                                            <th class="text-center fw-bold <?php echo $diferencia_monto >= 0 ? 'text-success' : 'text-danger'; ?>">
                                                <?php echo $diferencia_monto >= 0 ? '+' : ''; ?>$<?php echo number_format($diferencia_monto, 2); ?>
                                            </th>
                                            <th class="text-center"><?php echo number_format($total_ingresos_productos); ?></th>
                                            <th class="text-center"><?php echo number_format($total_salidas_productos); ?></th>
                                            <th class="text-center">
                                                <?php 
                                                if ($total_ingresos_monto > 0) {
                                                    $rendimiento_total = (($diferencia_monto / $total_ingresos_monto) * 100);
                                                    echo '<span class="badge ' . ($rendimiento_total >= 0 ? 'bg-success' : 'bg-danger') . ' fs-6">';
                                                    echo number_format($rendimiento_total, 1) . '%';
                                                    echo '</span>';
                                                } else {
                                                    echo '<span class="badge bg-secondary">N/A</span>';
                                                }
                                                ?>
                                            </th>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-info">
                                <i class="bi bi-info-circle"></i> No hay datos disponibles para el rango de fechas seleccionado.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- **CORRECCIÓN 19: Reportes detallados por separado** -->
                <div class="row">
                    <!-- Reporte de Ingresos -->
                    <div class="col-md-6 mb-4">
                        <div class="card h-100">
                            <div class="card-header bg-success text-white">
                                <h5><i class="bi bi-arrow-down-circle"></i> Detalle de Ingresos</h5>
                            </div>
                            <div class="card-body">
                                <?php if (count($reporte_ingresos['por_proveedor']) > 0): ?>
                                    <div class="table-responsive">
                                        <table class="table table-sm">
                                            <thead>
                                                <tr>
                                                    <th>Proveedor</th>
                                                    <th class="text-end">Monto</th>
                                                    <th class="text-end">Productos</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($reporte_ingresos['por_proveedor'] as $ingreso): ?>
                                                    <tr>
                                                        <td><?php echo htmlspecialchars($ingreso['proveedor']); ?></td>
                                                        <td class="text-end">$<?php echo number_format($ingreso['total_monto'], 2); ?></td>
                                                        <td class="text-end"><?php echo number_format($ingreso['total_productos']); ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php else: ?>
                                    <p class="text-muted">No hay ingresos en el período seleccionado.</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Reporte de Salidas -->
                    <div class="col-md-6 mb-4">
                        <div class="card h-100">
                            <div class="card-header bg-danger text-white">
                                <h5><i class="bi bi-arrow-up-circle"></i> Detalle de Salidas</h5>
                            </div>
                            <div class="card-body">
                                <?php if (count($reporte_salidas['por_proveedor']) > 0): ?>
                                    <div class="table-responsive">
                                        <table class="table table-sm">
                                            <thead>
                                                <tr>
                                                    <th>Proveedor</th>
                                                    <th class="text-end">Monto</th>
                                                    <th class="text-end">Productos</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($reporte_salidas['por_proveedor'] as $salida): ?>
                                                    <tr>
                                                        <td><?php echo htmlspecialchars($salida['proveedor']); ?></td>
                                                        <td class="text-end">$<?php echo number_format($salida['total_monto'], 2); ?></td>
                                                        <td class="text-end"><?php echo number_format($salida['total_productos']); ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php else: ?>
                                    <p class="text-muted">No hay salidas en el período seleccionado.</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- **CORRECCIÓN 20: Top productos** -->
                <div class="row">
                    <div class="col-md-6 mb-4">
                        <div class="card">
                            <div class="card-header">
                                <h6><i class="bi bi-trophy"></i> Top Productos Ingresados</h6>
                            </div>
                            <div class="card-body">
                                <?php if (count($reporte_ingresos['top_productos']) > 0): ?>
                                    <div class="table-responsive">
                                        <table class="table table-sm">
                                            <tbody>
                                                <?php foreach ($reporte_ingresos['top_productos'] as $index => $producto): ?>
                                                    <tr>
                                                        <td>
                                                            <span class="badge bg-primary"><?php echo $index + 1; ?></span>
                                                            <?php echo htmlspecialchars($producto['descripcion']); ?>
                                                            <small class="text-muted d-block"><?php echo htmlspecialchars($producto['proveedor']); ?></small>
                                                        </td>
                                                        <td class="text-end">
                                                            <strong><?php echo number_format($producto['total_cantidad']); ?></strong>
                                                            <small class="text-muted d-block">unidades</small>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php else: ?>
                                    <p class="text-muted">No hay datos disponibles.</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6 mb-4">
                        <div class="card">
                            <div class="card-header">
                                <h6><i class="bi bi-star"></i> Top Productos Distribuidos</h6>
                            </div>
                            <div class="card-body">
                                <?php if (count($reporte_salidas['top_productos']) > 0): ?>
                                    <div class="table-responsive">
                                        <table class="table table-sm">
                                            <tbody>
                                                <?php foreach ($reporte_salidas['top_productos'] as $index => $producto): ?>
                                                    <tr>
                                                        <td>
                                                            <span class="badge bg-warning"><?php echo $index + 1; ?></span>
                                                            <?php echo htmlspecialchars($producto['descripcion']); ?>
                                                            <small class="text-muted d-block"><?php echo htmlspecialchars($producto['proveedor']); ?></small>
                                                        </td>
                                                        <td class="text-end">
                                                            <strong><?php echo number_format($producto['total_cantidad']); ?></strong>
                                                            <small class="text-muted d-block">unidades</small>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php else: ?>
                                    <p class="text-muted">No hay datos disponibles.</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // **CORRECCIÓN 21: Script para validación de fechas**
        document.addEventListener('DOMContentLoaded', function() {
            const fechaDesde = document.getElementById('fecha_desde');
            const fechaHasta = document.getElementById('fecha_hasta');
            
            fechaDesde.addEventListener('change', function() {
                if (fechaHasta.value && fechaDesde.value > fechaHasta.value) {
                    alert('La fecha desde no puede ser mayor que la fecha hasta');
                    fechaDesde.value = '';
                }
            });
            
            fechaHasta.addEventListener('change', function() {
                if (fechaDesde.value && fechaHasta.value < fechaDesde.value) {
                    alert('La fecha hasta no puede ser menor que la fecha desde');
                    fechaHasta.value = '';
                }
            });
        });
    </script>
</body>
</html>