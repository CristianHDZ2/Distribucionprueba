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
            case 'crear':
                try {
                    $stmt = $db->prepare("INSERT INTO productos (proveedor, descripcion, precio_venta) VALUES (?, ?, ?)");
                    $stmt->execute([
                        $_POST['proveedor'],
                        $_POST['descripcion'],
                        $_POST['precio_venta']
                    ]);
                    $mensaje = "Producto creado exitosamente.";
                    $tipo_mensaje = "success";
                } catch (Exception $e) {
                    $mensaje = "Error al crear el producto: " . $e->getMessage();
                    $tipo_mensaje = "danger";
                }
                break;

            case 'editar':
                try {
                    $stmt = $db->prepare("UPDATE productos SET proveedor = ?, descripcion = ?, precio_venta = ? WHERE id = ?");
                    $stmt->execute([
                        $_POST['proveedor'],
                        $_POST['descripcion'],
                        $_POST['precio_venta'],
                        $_POST['id']
                    ]);
                    $mensaje = "Producto actualizado exitosamente.";
                    $tipo_mensaje = "success";
                } catch (Exception $e) {
                    $mensaje = "Error al actualizar el producto: " . $e->getMessage();
                    $tipo_mensaje = "danger";
                }
                break;

            case 'eliminar':
                try {
                    // Verificar si el producto tiene existencia
                    $stmt_check = $db->prepare("SELECT existencia FROM productos WHERE id = ?");
                    $stmt_check->execute([$_POST['id']]);
                    $producto = $stmt_check->fetch();
                    
                    if ($producto && $producto['existencia'] > 0) {
                        $mensaje = "No se puede eliminar el producto porque tiene existencia en inventario.";
                        $tipo_mensaje = "warning";
                    } else {
                        $stmt = $db->prepare("DELETE FROM productos WHERE id = ?");
                        $stmt->execute([$_POST['id']]);
                        $mensaje = "Producto eliminado exitosamente.";
                        $tipo_mensaje = "success";
                    }
                } catch (Exception $e) {
                    $mensaje = "Error al eliminar el producto: " . $e->getMessage();
                    $tipo_mensaje = "danger";
                }
                break;
        }
    }
}

// Obtener productos con paginación
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 10;
$offset = ($page - 1) * $limit;

$search = isset($_GET['search']) ? $_GET['search'] : '';
$proveedor_filter = isset($_GET['proveedor']) ? $_GET['proveedor'] : '';

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

$where_clause = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";

// Contar total de productos
$count_query = "SELECT COUNT(*) as total FROM productos $where_clause";
$stmt_count = $db->prepare($count_query);
$stmt_count->execute($params);
$total_productos = $stmt_count->fetch()['total'];
$total_pages = ceil($total_productos / $limit);

// Obtener productos
$query = "SELECT * FROM productos $where_clause ORDER BY fecha_creacion DESC LIMIT $limit OFFSET $offset";
$stmt = $db->prepare($query);
$stmt->execute($params);
$productos = $stmt->fetchAll();

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
    <title>Gestión de Productos - Sistema de Inventario</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        /* Reset y configuración base */
        * {
            box-sizing: border-box;
        }
        
        body {
            margin: 0;
            padding: 0;
            overflow-x: hidden;
        }
        
        /* Configuración del contenedor principal */
        .app-container {
            display: flex;
            min-height: 100vh;
            width: 100%;
        }
        
        /* Sidebar - Desktop */
        .sidebar {
            background-color: #343a40;
            width: 250px;
            min-height: 100vh;
            position: fixed;
            top: 0;
            left: 0;
            z-index: 1000;
            overflow-y: auto;
            transition: transform 0.3s ease;
        }
        
        .sidebar .nav-link {
            color: #adb5bd;
            padding: 0.75rem 1rem;
            border-radius: 0.375rem;
            margin: 0.125rem 0.5rem;
            display: flex;
            align-items: center;
        }
        
        .sidebar .nav-link:hover {
            color: #fff;
            background-color: #495057;
        }
        
        .sidebar .nav-link.active {
            color: #fff;
            background-color: #0d6efd;
        }
        
        .sidebar .nav-link i {
            width: 20px;
            margin-right: 8px;
            text-align: center;
        }
        
        /* Contenido principal - Desktop */
        .main-content {
            flex: 1;
            margin-left: 250px;
            min-height: 100vh;
            width: calc(100% - 250px);
            padding: 1rem;
            background-color: #f8f9fa;
        }
        
        /* Header móvil */
        .mobile-header {
            display: none;
            background-color: #343a40;
            color: white;
            padding: 1rem;
            position: sticky;
            top: 0;
            z-index: 1001;
            width: 100%;
        }
        
        .mobile-header .d-flex {
            justify-content: space-between;
            align-items: center;
        }
        
        .mobile-header h5 {
            margin: 0;
            color: white;
        }
        
        .mobile-toggle {
            background: none;
            border: 1px solid #adb5bd;
            color: #adb5bd;
            padding: 0.5rem;
            border-radius: 0.25rem;
            cursor: pointer;
        }
        
        .mobile-toggle:hover {
            color: white;
            border-color: white;
        }
        
        /* Overlay para móviles */
        .sidebar-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            z-index: 999;
        }
        
        /* Botón cerrar en sidebar móvil */
        .sidebar-close {
            display: none;
            position: absolute;
            top: 1rem;
            right: 1rem;
            background: none;
            border: none;
            color: #adb5bd;
            font-size: 1.5rem;
            cursor: pointer;
        }
        
        .sidebar-close:hover {
            color: white;
        }
        
        /* Estilos de tablas y acciones */
        .table-actions {
            white-space: nowrap;
        }
        
        .btn-sm {
            padding: 0.25rem 0.5rem;
            font-size: 0.875rem;
        }
        
        /* Card responsive */
        .card {
            border: none;
            box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
            margin-bottom: 1rem;
        }
        
        /* Responsive Design */
        
        /* Tablets y pantallas medianas */
        @media (max-width: 991.98px) {
            .sidebar {
                transform: translateX(-100%);
            }
            
            .sidebar.show {
                transform: translateX(0);
            }
            
            .sidebar-close {
                display: block;
            }
            
            .main-content {
                margin-left: 0;
                width: 100%;
                padding: 0.75rem;
            }
            
            .mobile-header {
                display: block;
            }
            
            .sidebar-overlay.show {
                display: block;
            }
        }
        
        /* Móviles */
        @media (max-width: 767.98px) {
            .main-content {
                padding: 0.5rem;
            }
            
            .h2 {
                font-size: 1.5rem;
            }
            
            .card-body {
                padding: 0.75rem;
            }
            
            .d-flex.justify-content-between {
                flex-direction: column;
                align-items: flex-start !important;
                gap: 0.5rem;
            }
            
            .border-bottom {
                padding-bottom: 1rem;
                margin-bottom: 1rem;
            }
            
            /* Formularios responsivos */
            .row.g-3 .col-md-4,
            .row.g-3 .col-md-3 {
                margin-bottom: 1rem;
            }
            
            /* Tablas responsivas */
            .table-responsive {
                font-size: 0.875rem;
                border-radius: 0.375rem;
                overflow-x: auto;
            }
            
            .table th,
            .table td {
                padding: 0.5rem 0.25rem;
                font-size: 0.875rem;
                vertical-align: middle;
            }
            
            /* Ocultar columnas menos importantes en móviles */
            .table th:nth-child(1),
            .table td:nth-child(1) {
                display: none;
            }
            
            .table th:nth-child(6),
            .table td:nth-child(6) {
                display: none;
            }
            
            /* Botones de acción más pequeños */
            .table-actions .btn {
                padding: 0.125rem 0.25rem;
                font-size: 0.75rem;
                margin: 0.125rem;
            }
            
            /* Modal más pequeño en móviles */
            .modal-dialog {
                margin: 0.5rem;
                max-width: calc(100% - 1rem);
            }
            
            .modal-body {
                padding: 1rem;
            }
        }
        
        /* Móviles pequeños */
        @media (max-width: 575.98px) {
            .main-content {
                padding: 0.25rem;
            }
            
            .mobile-header {
                padding: 0.75rem;
            }
            
            .card-body {
                padding: 0.5rem;
            }
            
            .h5,
            .card-title {
                font-size: 1rem;
            }
            
            .table th,
            .table td {
                padding: 0.25rem;
                font-size: 0.8rem;
            }
            
            .badge {
                font-size: 0.7rem;
            }
            
            /* Botones del header */
            .d-flex.justify-content-between .btn {
                width: 100%;
                margin-top: 0.5rem;
            }
            
            /* Filtros en columna */
            .row.g-3 > div {
                flex: 0 0 100%;
                max-width: 100%;
                margin-bottom: 0.5rem;
            }
            
            /* Paginación más compacta */
            .pagination {
                font-size: 0.875rem;
            }
            
            .page-link {
                padding: 0.375rem 0.5rem;
            }
            
            /* Modal pantalla completa en móviles pequeños */
            .modal-dialog {
                margin: 0;
                max-width: 100%;
                height: 100vh;
            }
            
            .modal-content {
                height: 100vh;
                border-radius: 0;
            }
            
            .modal-header {
                padding: 0.75rem;
            }
            
            .modal-body {
                padding: 0.75rem;
                overflow-y: auto;
            }
            
            .modal-footer {
                padding: 0.75rem;
            }
        }
        
        /* Pantallas muy grandes */
        @media (min-width: 1400px) {
            .main-content {
                padding: 1.5rem;
            }
        }
        
        /* Asegurar que el contenido siempre sea visible */
        .content-wrapper {
            width: 100%;
            max-width: 100%;
            overflow-x: auto;
        }
        
        /* Animaciones */
        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .animate-in {
            animation: slideIn 0.3s ease-out;
        }
        
        /* Mejorar la experiencia de los formularios */
        .form-control:focus,
        .form-select:focus {
            border-color: #86b7fe;
            box-shadow: 0 0 0 0.25rem rgba(13, 110, 253, 0.25);
        }
        
        /* Mejorar los badges de existencia */
        .badge {
            font-weight: 500;
            padding: 0.375em 0.75em;
        }
        
        /* Hover effects para botones de acción */
        .btn-outline-primary:hover,
        .btn-outline-danger:hover {
            transform: translateY(-1px);
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        /* Asegurar que las alertas se vean bien */
        .alert {
            border-radius: 0.375rem;
            border: none;
            margin-bottom: 1rem;
        }
        
        @media (max-width: 575.98px) {
            .alert {
                font-size: 0.875rem;
                padding: 0.75rem;
            }
        }
        
        /* Mejorar la apariencia de los inputs en móviles */
        @media (max-width: 767.98px) {
            .form-control,
            .form-select {
                font-size: 16px; /* Previene zoom en iOS */
            }
        }
        
        /* Indicador de scroll en tablas */
        .table-responsive {
            position: relative;
        }
        
        .table-responsive::after {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            bottom: 0;
            width: 20px;
            background: linear-gradient(to left, rgba(248,249,250,1) 0%, rgba(248,249,250,0) 100%);
            pointer-events: none;
            opacity: 0;
            transition: opacity 0.3s ease;
        }
        
        .table-responsive:hover::after {
            opacity: 1;
        }
        
        @media (max-width: 767.98px) {
            .table-responsive::after {
                display: none;
            }
        }
    </style>
</head>
<body>
    <div class="app-container">
        <!-- Header móvil -->
        <div class="mobile-header">
            <div class="d-flex">
                <h5><i class="bi bi-box"></i> Gestión de Productos</h5>
                <button class="mobile-toggle" id="mobileToggle">
                    <i class="bi bi-list"></i>
                </button>
            </div>
        </div>
        
        <!-- Overlay para móviles -->
        <div class="sidebar-overlay" id="sidebarOverlay"></div>
        
        <!-- Sidebar -->
        <nav class="sidebar" id="sidebar">
            <button class="sidebar-close" id="sidebarClose">
                <i class="bi bi-x-lg"></i>
            </button>
            
            <div class="pt-3">
                <div class="text-center mb-4">
                    <h4 class="text-white d-none d-lg-block">
                        <i class="bi bi-box-seam"></i> Inventario
                    </h4>
                    <h5 class="text-white d-lg-none">
                        <i class="bi bi-box-seam"></i> Inventario
                    </h5>
                </div>
                
                <ul class="nav flex-column">
                    <li class="nav-item">
                        <a class="nav-link" href="index.php">
                            <i class="bi bi-house-door"></i> Dashboard
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="productos.php">
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
                        <a class="nav-link" href="reportes.php">
                            <i class="bi bi-file-earmark-bar-graph"></i> Reportes
                        </a>
                    </li>
                </ul>
            </div>
        </nav>
        
        <!-- Contenido principal -->
        <main class="main-content">
            <div class="content-wrapper">
                <div class="d-flex justify-content-between flex-wrap align-items-center mb-4 pb-3 border-bottom">
                    <h1 class="h2 mb-0">Gestión de Productos</h1>
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalProducto">
                        <i class="bi bi-plus-lg"></i> 
                        <span class="d-none d-sm-inline">Nuevo Producto</span>
                        <span class="d-sm-none">Nuevo</span>
                    </button>
                </div>

                <!-- Mensajes -->
                <?php if (!empty($mensaje)): ?>
                    <div class="alert alert-<?php echo $tipo_mensaje; ?> alert-dismissible fade show animate-in" role="alert">
                        <?php echo htmlspecialchars($mensaje); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Filtros y búsqueda -->
                <div class="card mb-4 animate-in">
                    <div class="card-header">
                        <h6 class="mb-0">
                            <i class="bi bi-funnel"></i> Filtros de Búsqueda
                        </h6>
                    </div>
                    <div class="card-body">
                        <form method="GET" class="row g-3">
                            <div class="col-md-4">
                                <label for="search" class="form-label">Buscar</label>
                                <input type="text" class="form-control" id="search" name="search" 
                                       value="<?php echo htmlspecialchars($search); ?>" 
                                       placeholder="Buscar por descripción o proveedor...">
                            </div>
                            <div class="col-md-4">
                                <label for="proveedor" class="form-label">Proveedor</label>
                                <select class="form-select" id="proveedor" name="proveedor">
                                    <option value="">Todos los proveedores</option>
                                    <?php foreach ($proveedores as $proveedor): ?>
                                        <option value="<?php echo htmlspecialchars($proveedor['proveedor']); ?>"
                                                <?php echo $proveedor_filter == $proveedor['proveedor'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($proveedor['proveedor']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4 d-flex align-items-end gap-2">
                                <button type="submit" class="btn btn-outline-primary flex-fill">
                                    <i class="bi bi-search"></i> 
                                    <span class="d-none d-sm-inline">Buscar</span>
                                </button>
                                <a href="productos.php" class="btn btn-outline-secondary flex-fill">
                                    <i class="bi bi-x-lg"></i> 
                                    <span class="d-none d-sm-inline">Limpiar</span>
                                </a>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Tabla de productos -->
                <div class="card animate-in">
                    <div class="card-header">
                        <h5 class="card-title mb-0">
                            <i class="bi bi-list-ul"></i> 
                            Lista de Productos (<?php echo number_format($total_productos); ?> total)
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php if (count($productos) > 0): ?>
                            <div class="table-responsive">
                                <table class="table table-striped table-hover mb-0">
                                    <thead class="table-dark">
                                        <tr>
                                            <th>ID</th>
                                            <th>Proveedor</th>
                                            <th>Descripción</th>
                                            <th class="text-end">Precio Venta</th>
                                            <th class="text-center">Existencia</th>
                                            <th class="d-none d-md-table-cell">Fecha Creación</th>
                                            <th class="table-actions text-center">Acciones</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($productos as $producto): ?>
                                            <tr>
                                                <td class="fw-medium"><?php echo $producto['id']; ?></td>
                                                <td>
                                                    <span class="fw-medium text-primary">
                                                        <?php echo htmlspecialchars($producto['proveedor']); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <span title="<?php echo htmlspecialchars($producto['descripcion']); ?>">
                                                        <?php echo htmlspecialchars($producto['descripcion']); ?>
                                                    </span>
                                                </td>
                                                <td class="text-end fw-medium">
                                                    $<?php echo number_format($producto['precio_venta'], 2); ?>
                                                </td>
                                                <td class="text-center">
                                                    <span class="badge fs-6 <?php echo $producto['existencia'] == 0 ? 'bg-danger' : ($producto['existencia'] < 10 ? 'bg-warning text-dark' : 'bg-success'); ?>">
                                                        <?php echo number_format($producto['existencia']); ?>
                                                    </span>
                                                </td>
                                                <td class="d-none d-md-table-cell text-muted">
                                                    <small><?php echo date('d/m/Y H:i', strtotime($producto['fecha_creacion'])); ?></small>
                                                </td>
                                                <td class="table-actions text-center">
                                                    <div class="btn-group" role="group">
                                                        <button type="button" class="btn btn-sm btn-outline-primary" 
                                                                onclick="editarProducto(<?php echo htmlspecialchars(json_encode($producto)); ?>)"
                                                                title="Editar producto">
                                                            <i class="bi bi-pencil"></i>
                                                            <span class="d-none d-lg-inline"> Editar</span>
                                                        </button>
                                                        <button type="button" class="btn btn-sm btn-outline-danger" 
                                                                onclick="eliminarProducto(<?php echo $producto['id']; ?>, '<?php echo htmlspecialchars($producto['descripcion']); ?>')"
                                                                title="Eliminar producto">
                                                            <i class="bi bi-trash"></i>
                                                            <span class="d-none d-lg-inline"> Eliminar</span>
                                                        </button>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                            <!-- Paginación -->
                            <?php if ($total_pages > 1): ?>
                                <nav aria-label="Navegación de productos" class="mt-3">
                                    <ul class="pagination justify-content-center mb-0">
                                        <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                            <a class="page-link" href="?page=<?php echo $page - 1; ?>&search=<?php echo urlencode($search); ?>&proveedor=<?php echo urlencode($proveedor_filter); ?>">
                                                <i class="bi bi-chevron-left"></i>
                                                <span class="d-none d-sm-inline"> Anterior</span>
                                            </a>
                                        </li>
                                        
                                        <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                                            <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                                <a class="page-link" href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&proveedor=<?php echo urlencode($proveedor_filter); ?>"><?php echo $i; ?></a>
                                            </li>
                                        <?php endfor; ?>
                                        
                                        <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                                            <a class="page-link" href="?page=<?php echo $page + 1; ?>&search=<?php echo urlencode($search); ?>&proveedor=<?php echo urlencode($proveedor_filter); ?>">
                                                <span class="d-none d-sm-inline">Siguiente </span>
                                                <i class="bi bi-chevron-right"></i>
                                            </a>
                                        </li>
                                    </ul>
                                </nav>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="text-center py-5">
                                <i class="bi bi-inbox display-1 text-muted"></i>
                                <h4 class="mt-3">No hay productos</h4>
                                <p class="text-muted">No se encontraron productos con los criterios especificados.</p>
                                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalProducto">
                                    <i class="bi bi-plus-lg"></i> Crear Primer Producto
                                </button>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Modal para crear/editar producto -->
    <div class="modal fade" id="modalProducto" tabindex="-1" aria-labelledby="modalProductoLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalProductoLabel">
                        <i class="bi bi-plus-circle"></i> Nuevo Producto
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="formProducto" method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="accion" id="accion" value="crear">
                        <input type="hidden" name="id" id="producto_id">
                        
                        <div class="mb-3">
                            <label for="proveedor" class="form-label">Proveedor <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="proveedor_modal" name="proveedor" required 
                                   maxlength="255" placeholder="Nombre del proveedor">
                        </div>
                        
                        <div class="mb-3">
                            <label for="descripcion" class="form-label">Descripción <span class="text-danger">*</span></label>
                            <textarea class="form-control" id="descripcion_modal" name="descripcion" required 
                                      rows="3" placeholder="Descripción del producto"></textarea>
                        </div>
                        
                        <div class="mb-3">
                            <label for="precio_venta" class="form-label">Precio de Venta <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text">$</span>
                                <input type="number" class="form-control" id="precio_venta_modal" name="precio_venta" 
                                       required min="0" step="0.01" placeholder="0.00">
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            <i class="bi bi-x-circle"></i> Cancelar
                        </button>
                        <button type="submit" class="btn btn-primary" id="btnGuardar">
                            <i class="bi bi-save"></i> 
                            <span id="btnTexto">Guardar Producto</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal de confirmación para eliminar -->
    <div class="modal fade" id="modalEliminar" tabindex="-1" aria-labelledby="modalEliminarLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalEliminarLabel">
                        <i class="bi bi-exclamation-triangle text-danger"></i> Confirmar Eliminación
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-warning">
                        <i class="bi bi-exclamation-triangle"></i>
                        <strong>¿Está seguro que desea eliminar el producto?</strong>
                    </div>
                    <p class="mb-2"><strong>Producto:</strong> <span id="descripcion_eliminar"></span></p>
                    <p class="text-danger mb-0">
                        <i class="bi bi-info-circle"></i>
                        Esta acción no se puede deshacer.
                    </p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-x-circle"></i> Cancelar
                    </button>
                    <form method="POST" style="display: inline;">
                        <input type="hidden" name="accion" value="eliminar">
                        <input type="hidden" name="id" id="id_eliminar">
                        <button type="submit" class="btn btn-danger">
                            <i class="bi bi-trash"></i> Sí, Eliminar
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Sistema de sidebar responsivo (reutilizado)
        class ResponsiveSidebar {
            constructor() {
                this.sidebar = document.getElementById('sidebar');
                this.overlay = document.getElementById('sidebarOverlay');
                this.mobileToggle = document.getElementById('mobileToggle');
                this.sidebarClose = document.getElementById('sidebarClose');
                this.isOpen = false;
                this.isMobile = window.innerWidth < 992;
                
                this.init();
            }
            
            init() {
                this.bindEvents();
                this.checkScreenSize();
            }
            
            bindEvents() {
                if (this.mobileToggle) {
                    this.mobileToggle.addEventListener('click', () => this.toggleSidebar());
                }
                
                if (this.sidebarClose) {
                    this.sidebarClose.addEventListener('click', () => this.closeSidebar());
                }
                
                if (this.overlay) {
                    this.overlay.addEventListener('click', () => this.closeSidebar());
                }
                
                const sidebarLinks = this.sidebar?.querySelectorAll('.nav-link');
                sidebarLinks?.forEach(link => {
                    link.addEventListener('click', () => {
                        if (this.isMobile) {
                            this.closeSidebar();
                        }
                    });
                });
                
                window.addEventListener('resize', () => this.handleResize());
                
                document.addEventListener('keydown', (e) => {
                    if (e.key === 'Escape' && this.isOpen) {
                        this.closeSidebar();
                    }
                });
            }
            
            toggleSidebar() {
                if (this.isOpen) {
                    this.closeSidebar();
                } else {
                    this.openSidebar();
                }
            }
            
            openSidebar() {
                this.sidebar?.classList.add('show');
                this.overlay?.classList.add('show');
                document.body.style.overflow = 'hidden';
                this.isOpen = true;
                
                const icon = this.mobileToggle?.querySelector('i');
                if (icon) {
                    icon.className = 'bi bi-x-lg';
                }
            }
            
            closeSidebar() {
                this.sidebar?.classList.remove('show');
                this.overlay?.classList.remove('show');
                document.body.style.overflow = '';
                this.isOpen = false;
                
                const icon = this.mobileToggle?.querySelector('i');
                if (icon) {
                    icon.className = 'bi bi-list';
                }
            }
            
            handleResize() {
                const wasMobile = this.isMobile;
                this.isMobile = window.innerWidth < 992;
                
                if (wasMobile && !this.isMobile) {
                    this.closeSidebar();
                }
                
                this.checkScreenSize();
            }
            
            checkScreenSize() {
                if (!this.isMobile && this.isOpen) {
                    this.closeSidebar();
                }
            }
        }

        // Funciones específicas para gestión de productos
        function editarProducto(producto) {
            document.getElementById('modalProductoLabel').innerHTML = '<i class="bi bi-pencil-square"></i> Editar Producto';
            document.getElementById('accion').value = 'editar';
            document.getElementById('producto_id').value = producto.id;
            document.getElementById('proveedor_modal').value = producto.proveedor;
            document.getElementById('descripcion_modal').value = producto.descripcion;
            document.getElementById('precio_venta_modal').value = producto.precio_venta;
            document.getElementById('btnTexto').textContent = 'Actualizar Producto';
            
            const modal = new bootstrap.Modal(document.getElementById('modalProducto'));
            modal.show();
        }

        function eliminarProducto(id, descripcion) {
            document.getElementById('id_eliminar').value = id;
            document.getElementById('descripcion_eliminar').textContent = descripcion;
            
            const modal = new bootstrap.Modal(document.getElementById('modalEliminar'));
            modal.show();
        }

        // Limpiar formulario al cerrar modal
        document.getElementById('modalProducto').addEventListener('hidden.bs.modal', function () {
            document.getElementById('formProducto').reset();
            document.getElementById('modalProductoLabel').innerHTML = '<i class="bi bi-plus-circle"></i> Nuevo Producto';
            document.getElementById('accion').value = 'crear';
            document.getElementById('producto_id').value = '';
            document.getElementById('btnTexto').textContent = 'Guardar Producto';
            
            // Limpiar estilos de validación
            const inputs = this.querySelectorAll('.form-control, .form-select');
            inputs.forEach(input => {
                input.classList.remove('is-valid', 'is-invalid');
            });
        });

        // Validación del formulario
        document.getElementById('formProducto').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const proveedor = document.getElementById('proveedor_modal').value.trim();
            const descripcion = document.getElementById('descripcion_modal').value.trim();
            const precio = parseFloat(document.getElementById('precio_venta_modal').value);
            
            let isValid = true;
            
            // Validar proveedor
            const proveedorInput = document.getElementById('proveedor_modal');
            if (!proveedor) {
                proveedorInput.classList.add('is-invalid');
                isValid = false;
            } else {
                proveedorInput.classList.remove('is-invalid');
                proveedorInput.classList.add('is-valid');
            }
            
            // Validar descripción
            const descripcionInput = document.getElementById('descripcion_modal');
            if (!descripcion) {
                descripcionInput.classList.add('is-invalid');
                isValid = false;
            } else {
                descripcionInput.classList.remove('is-invalid');
                descripcionInput.classList.add('is-valid');
            }
            
            // Validar precio
            const precioInput = document.getElementById('precio_venta_modal');
            if (!precio || precio <= 0) {
                precioInput.classList.add('is-invalid');
                isValid = false;
            } else {
                precioInput.classList.remove('is-invalid');
                precioInput.classList.add('is-valid');
            }
            
            if (!isValid) {
                // Mostrar alerta de validación
                const existingAlert = document.querySelector('.modal-body .alert-danger');
                if (existingAlert) {
                    existingAlert.remove();
                }
                
                const alert = document.createElement('div');
                alert.className = 'alert alert-danger';
                alert.innerHTML = '<i class="bi bi-exclamation-triangle"></i> Por favor, complete todos los campos correctamente.';
                
                const modalBody = document.querySelector('#modalProducto .modal-body');
                modalBody.insertBefore(alert, modalBody.firstChild);
                
                return false;
            }
            
            // Si todo está válido, enviar el formulario
            const btnGuardar = document.getElementById('btnGuardar');
            const originalText = btnGuardar.innerHTML;
            btnGuardar.innerHTML = '<span class="spinner-border spinner-border-sm" role="status"></span> Guardando...';
            btnGuardar.disabled = true;
            
            // Enviar formulario
            this.submit();
        });

        // Mejorar la experiencia de búsqueda
        function setupSearch() {
            const searchInput = document.getElementById('search');
            const proveedorSelect = document.getElementById('proveedor');
            
            let searchTimeout;
            
            // Búsqueda en tiempo real (opcional)
            searchInput?.addEventListener('input', function() {
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(() => {
                    // Aquí se podría implementar búsqueda AJAX
                    // Por ahora mantenemos el comportamiento actual
                }, 500);
            });
            
            // Autocompletar proveedores
            const proveedores = <?php echo json_encode(array_column($proveedores, 'proveedor')); ?>;
            
            searchInput?.addEventListener('input', function() {
                const value = this.value.toLowerCase();
                const suggestions = proveedores.filter(p => 
                    p.toLowerCase().includes(value)
                );
                
                // Aquí se podría mostrar sugerencias
            });
        }

        // Funciones de utilidad
        function showToast(message, type = 'success') {
            // Crear toast notification
            const toastContainer = document.getElementById('toastContainer') || createToastContainer();
            
            const toast = document.createElement('div');
            toast.className = `toast align-items-center text-white bg-${type} border-0`;
            toast.setAttribute('role', 'alert');
            toast.innerHTML = `
                <div class="d-flex">
                    <div class="toast-body">
                        <i class="bi bi-${type === 'success' ? 'check-circle' : 'exclamation-triangle'}"></i>
                        ${message}
                    </div>
                    <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
                </div>
            `;
            
            toastContainer.appendChild(toast);
            
            const bsToast = new bootstrap.Toast(toast);
            bsToast.show();
            
            // Limpiar después de que se oculte
            toast.addEventListener('hidden.bs.toast', () => {
                toast.remove();
            });
        }
        
        function createToastContainer() {
            const container = document.createElement('div');
            container.id = 'toastContainer';
            container.className = 'toast-container position-fixed top-0 end-0 p-3';
            container.style.zIndex = '1055';
            document.body.appendChild(container);
            return container;
        }

        // Mejorar la experiencia de la tabla
        function enhanceTable() {
            const tableContainer = document.querySelector('.table-responsive');
            if (!tableContainer) return;
            
            // Agregar indicador de scroll en móviles
            if (window.innerWidth < 768) {
                const table = tableContainer.querySelector('table');
                const tableWidth = table.scrollWidth;
                const containerWidth = tableContainer.clientWidth;
                
                if (tableWidth > containerWidth) {
                    const indicator = document.createElement('div');
                    indicator.className = 'text-center text-muted mt-2';
                    indicator.innerHTML = '<small><i class="bi bi-arrow-left-right"></i> Desliza para ver más columnas</small>';
                    tableContainer.appendChild(indicator);
                    
                    // Ocultar indicador después del primer scroll
                    tableContainer.addEventListener('scroll', () => {
                        indicator.style.opacity = '0.5';
                    }, { once: true });
                }
            }
            
            // Mejorar el hover en filas de tabla
            const rows = document.querySelectorAll('tbody tr');
            rows.forEach(row => {
                row.addEventListener('mouseenter', function() {
                    this.style.backgroundColor = '#f8f9fa';
                });
                
                row.addEventListener('mouseleave', function() {
                    this.style.backgroundColor = '';
                });
            });
        }

        // Funciones de accesibilidad
        function enhanceAccessibility() {
            // Mejorar el foco en elementos interactivos
            const buttons = document.querySelectorAll('button, .btn');
            buttons.forEach(button => {
                button.addEventListener('focus', function() {
                    this.style.outline = '2px solid #007bff';
                    this.style.outlineOffset = '2px';
                });
                
                button.addEventListener('blur', function() {
                    this.style.outline = '';
                    this.style.outlineOffset = '';
                });
            });
            
            // Añadir teclas de acceso rápido
            document.addEventListener('keydown', function(e) {
                // Ctrl/Cmd + N para nuevo producto
                if ((e.ctrlKey || e.metaKey) && e.key === 'n') {
                    e.preventDefault();
                    const newBtn = document.querySelector('[data-bs-target="#modalProducto"]');
                    if (newBtn) newBtn.click();
                }
                
                // Escape para cerrar modales
                if (e.key === 'Escape') {
                    const openModals = document.querySelectorAll('.modal.show');
                    openModals.forEach(modal => {
                        const modalInstance = bootstrap.Modal.getInstance(modal);
                        if (modalInstance) modalInstance.hide();
                    });
                }
            });
        }

        // Inicialización principal
        document.addEventListener('DOMContentLoaded', function() {
            // Inicializar sidebar responsivo
            new ResponsiveSidebar();
            
            // Configurar funciones adicionales
            setupSearch();
            enhanceTable();
            enhanceAccessibility();
            
            // Animar elementos al cargar
            const animatedElements = document.querySelectorAll('.animate-in');
            animatedElements.forEach((element, index) => {
                element.style.animationDelay = `${index * 0.1}s`;
            });
            
            // Configurar tooltips
            const tooltipTriggerList = [].slice.call(document.querySelectorAll('[title]'));
            tooltipTriggerList.map(function (tooltipTriggerEl) {
                return new bootstrap.Tooltip(tooltipTriggerEl);
            });
            
            // Mostrar mensaje de bienvenida si es la primera vez
            const hasVisited = localStorage.getItem('productos_visited');
            if (!hasVisited && <?php echo count($productos); ?> === 0) {
                setTimeout(() => {
                    showToast('¡Bienvenido! Comience creando su primer producto.', 'info');
                    localStorage.setItem('productos_visited', 'true');
                }, 1000);
            }
        });

        // Manejar errores de red
        window.addEventListener('online', () => {
            showToast('Conexión restaurada', 'success');
        });

        window.addEventListener('offline', () => {
            showToast('Sin conexión a internet', 'warning');
        });

        // Prevenir pérdida de datos en formularios
        window.addEventListener('beforeunload', function(e) {
            const form = document.getElementById('formProducto');
            const modal = document.getElementById('modalProducto');
            
            if (modal.classList.contains('show') && form.querySelector('.is-valid')) {
                e.preventDefault();
                e.returnValue = '¿Está seguro que desea salir? Los cambios no guardados se perderán.';
            }
        });

        // Optimización de rendimiento
        let scrollTimeout;
        window.addEventListener('scroll', function() {
            clearTimeout(scrollTimeout);
            scrollTimeout = setTimeout(function() {
                // Lazy loading de imágenes si las hubiera
                // Optimizaciones adicionales
            }, 100);
        }, { passive: true });
    </script>
</body>
</html>