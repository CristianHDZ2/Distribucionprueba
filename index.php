<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sistema de Inventario</title>
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
        
        /* Estilos de las tarjetas */
        .card-dashboard {
            transition: transform 0.2s ease;
            border: none;
            box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
            border-left: 4px solid;
            height: 100%;
        }
        
        .card-dashboard:hover {
            transform: translateY(-2px);
            box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15);
        }
        
        .border-left-primary { border-left-color: #007bff !important; }
        .border-left-success { border-left-color: #28a745 !important; }
        .border-left-info { border-left-color: #17a2b8 !important; }
        .border-left-warning { border-left-color: #ffc107 !important; }
        
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
            
            /* Ajustar tarjetas en tablets */
            .col-xl-3 {
                flex: 0 0 50%;
                max-width: 50%;
            }
        }
        
        /* Móviles */
        @media (max-width: 767.98px) {
            .main-content {
                padding: 0.5rem;
            }
            
            .col-xl-3,
            .col-md-6 {
                flex: 0 0 100%;
                max-width: 100%;
                margin-bottom: 1rem;
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
            
            /* Tablas responsivas */
            .table-responsive {
                font-size: 0.875rem;
            }
            
            .table th,
            .table td {
                padding: 0.5rem 0.25rem;
                font-size: 0.875rem;
                white-space: nowrap;
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
            
            .fs-2 {
                font-size: 1.5rem !important;
            }
            
            .table th,
            .table td {
                padding: 0.25rem;
                font-size: 0.8rem;
            }
            
            .badge {
                font-size: 0.7rem;
            }
            
            /* Ajustar la descripción de productos en móviles pequeños */
            .table td:nth-child(2) {
                max-width: 120px;
                overflow: hidden;
                text-overflow: ellipsis;
                white-space: nowrap;
            }
        }
        
        /* Pantallas muy grandes */
        @media (min-width: 1400px) {
            .main-content {
                padding: 1.5rem;
            }
            
            .container-fluid {
                max-width: 1400px;
            }
        }
        
        /* Asegurar que el contenido siempre sea visible */
        .content-wrapper {
            width: 100%;
            max-width: 100%;
            overflow-x: auto;
        }
        
        /* Mejorar la experiencia de scroll en tablas */
        .table-responsive {
            border-radius: 0.375rem;
            box-shadow: 0 0 0 1px rgba(0, 0, 0, 0.05);
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
        
        /* Asegurar que los iconos se vean bien en todos los tamaños */
        .bi {
            vertical-align: middle;
        }
        
        /* Mejorar la legibilidad en pantallas pequeñas */
        @media (max-width: 767.98px) {
            .text-xs {
                font-size: 0.7rem;
            }
            
            .font-weight-bold {
                font-weight: 600;
            }
            
            .text-uppercase {
                letter-spacing: 0.5px;
            }
        }
        
        /* Asegurar que las alertas se vean bien en todos los tamaños */
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
    </style>
</head>
<body>
    <div class="app-container">
        <!-- Header móvil -->
        <div class="mobile-header">
            <div class="d-flex">
                <h5><i class="bi bi-box-seam"></i> Sistema de Inventario</h5>
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
                        <a class="nav-link active" href="index.php">
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
                    <h1 class="h2 mb-0">Dashboard</h1>
                </div>

                <?php
                require_once 'config/database.php';
                
                $database = new Database();
                $db = $database->getConnection();

                // Obtener estadísticas básicas
                $stmt_productos = $db->prepare("SELECT COUNT(*) as total FROM productos");
                $stmt_productos->execute();
                $total_productos = $stmt_productos->fetch()['total'];

                $stmt_existencia = $db->prepare("SELECT SUM(existencia) as total FROM productos");
                $stmt_existencia->execute();
                $total_existencia = $stmt_existencia->fetch()['total'] ?? 0;

                $stmt_ingresos = $db->prepare("SELECT COUNT(*) as total FROM ingresos WHERE DATE(fecha_creacion) = CURDATE()");
                $stmt_ingresos->execute();
                $ingresos_hoy = $stmt_ingresos->fetch()['total'];

                $stmt_distribuciones = $db->prepare("SELECT COUNT(*) as total FROM distribuciones WHERE estado = 'activo'");
                $stmt_distribuciones->execute();
                $distribuciones_activas = $stmt_distribuciones->fetch()['total'];
                ?>

                <div class="row g-3 mb-4">
                    <div class="col-xl-3 col-md-6">
                        <div class="card border-left-primary card-dashboard animate-in">
                            <div class="card-body">
                                <div class="row align-items-center">
                                    <div class="col">
                                        <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">
                                            Total Productos
                                        </div>
                                        <div class="h5 mb-0 font-weight-bold">
                                            <?php echo number_format($total_productos); ?>
                                        </div>
                                    </div>
                                    <div class="col-auto">
                                        <i class="bi bi-box fs-2 text-primary"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-3 col-md-6">
                        <div class="card border-left-success card-dashboard animate-in">
                            <div class="card-body">
                                <div class="row align-items-center">
                                    <div class="col">
                                        <div class="text-xs font-weight-bold text-success text-uppercase mb-1">
                                            Existencia Total
                                        </div>
                                        <div class="h5 mb-0 font-weight-bold">
                                            <?php echo number_format($total_existencia); ?>
                                        </div>
                                    </div>
                                    <div class="col-auto">
                                        <i class="bi bi-clipboard-data fs-2 text-success"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-3 col-md-6">
                        <div class="card border-left-info card-dashboard animate-in">
                            <div class="card-body">
                                <div class="row align-items-center">
                                    <div class="col">
                                        <div class="text-xs font-weight-bold text-info text-uppercase mb-1">
                                            Ingresos Hoy
                                        </div>
                                        <div class="h5 mb-0 font-weight-bold">
                                            <?php echo number_format($ingresos_hoy); ?>
                                        </div>
                                    </div>
                                    <div class="col-auto">
                                        <i class="bi bi-arrow-down-circle fs-2 text-info"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-3 col-md-6">
                        <div class="card border-left-warning card-dashboard animate-in">
                            <div class="card-body">
                                <div class="row align-items-center">
                                    <div class="col">
                                        <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">
                                            Distribuciones Activas
                                        </div>
                                        <div class="h5 mb-0 font-weight-bold">
                                            <?php echo number_format($distribuciones_activas); ?>
                                        </div>
                                    </div>
                                    <div class="col-auto">
                                        <i class="bi bi-arrow-up-circle fs-2 text-warning"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Productos con bajo stock -->
                <div class="row">
                    <div class="col-12">
                        <div class="card animate-in">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <i class="bi bi-exclamation-triangle text-warning"></i>
                                    Productos con Bajo Stock (menos de 10 unidades)
                                </h5>
                            </div>
                            <div class="card-body">
                                <?php
                                $stmt_bajo_stock = $db->prepare("SELECT * FROM productos WHERE existencia < 10 ORDER BY existencia ASC LIMIT 20");
                                $stmt_bajo_stock->execute();
                                $productos_bajo_stock = $stmt_bajo_stock->fetchAll();
                                
                                if (count($productos_bajo_stock) > 0): ?>
                                    <div class="table-responsive">
                                        <table class="table table-striped table-hover mb-0">
                                            <thead class="table-dark">
                                                <tr>
                                                    <th>Proveedor</th>
                                                    <th>Descripción</th>
                                                    <th class="text-center">Existencia</th>
                                                    <th class="text-end">Precio Venta</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($productos_bajo_stock as $producto): ?>
                                                    <tr>
                                                        <td>
                                                            <span class="fw-medium"><?php echo htmlspecialchars($producto['proveedor']); ?></span>
                                                        </td>
                                                        <td>
                                                            <span title="<?php echo htmlspecialchars($producto['descripcion']); ?>">
                                                                <?php echo htmlspecialchars($producto['descripcion']); ?>
                                                            </span>
                                                        </td>
                                                        <td class="text-center">
                                                            <span class="badge fs-6 <?php echo $producto['existencia'] == 0 ? 'bg-danger' : 'bg-warning text-dark'; ?>">
                                                                <?php echo number_format($producto['existencia']); ?>
                                                            </span>
                                                        </td>
                                                        <td class="text-end fw-medium">
                                                            $<?php echo number_format($producto['precio_venta'], 2); ?>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    
                                    <?php if (count($productos_bajo_stock) == 20): ?>
                                        <div class="mt-3">
                                            <small class="text-muted">
                                                <i class="bi bi-info-circle"></i>
                                                Mostrando los primeros 20 productos. 
                                                <a href="inventario.php?stock=bajo_stock" class="text-decoration-none">Ver todos</a>
                                            </small>
                                        </div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <div class="alert alert-success mb-0">
                                        <i class="bi bi-check-circle"></i> 
                                        <strong>¡Excelente!</strong> Todos los productos tienen stock suficiente.
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Sistema de sidebar responsivo mejorado
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
                this.animateCards();
            }
            
            bindEvents() {
                // Toggle del menú móvil
                if (this.mobileToggle) {
                    this.mobileToggle.addEventListener('click', () => this.toggleSidebar());
                }
                
                // Botón cerrar
                if (this.sidebarClose) {
                    this.sidebarClose.addEventListener('click', () => this.closeSidebar());
                }
                
                // Overlay
                if (this.overlay) {
                    this.overlay.addEventListener('click', () => this.closeSidebar());
                }
                
                // Enlaces del sidebar
                const sidebarLinks = this.sidebar?.querySelectorAll('.nav-link');
                sidebarLinks?.forEach(link => {
                    link.addEventListener('click', () => {
                        if (this.isMobile) {
                            this.closeSidebar();
                        }
                    });
                });
                
                // Resize de ventana
                window.addEventListener('resize', () => this.handleResize());
                
                // Tecla Escape
                document.addEventListener('keydown', (e) => {
                    if (e.key === 'Escape' && this.isOpen) {
                        this.closeSidebar();
                    }
                });
                
                // Prevenir scroll del body cuando el sidebar está abierto
                document.addEventListener('touchmove', (e) => {
                    if (this.isOpen && this.isMobile) {
                        e.preventDefault();
                    }
                }, { passive: false });
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
                
                // Actualizar el icono del toggle
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
                
                // Restaurar el icono del toggle
                const icon = this.mobileToggle?.querySelector('i');
                if (icon) {
                    icon.className = 'bi bi-list';
                }
            }
            
            handleResize() {
                const wasMobile = this.isMobile;
                this.isMobile = window.innerWidth < 992;
                
                // Si cambió de móvil a desktop, cerrar sidebar
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
            
            animateCards() {
                // Animar tarjetas del dashboard
                const cards = document.querySelectorAll('.card-dashboard');
                cards.forEach((card, index) => {
                    card.style.setProperty('--delay', `${index * 0.1}s`);
                    card.style.animationDelay = `${index * 0.1}s`;
                });
            }
        }
        
        // Inicializar cuando el DOM esté listo
        document.addEventListener('DOMContentLoaded', () => {
            new ResponsiveSidebar();
            
            // Mejorar la experiencia de usuario
            enhanceUserExperience();
            
            // Configurar tooltips si es necesario
            initTooltips();
        });
        
        // Funciones auxiliares
        function enhanceUserExperience() {
            // Agregar smooth scroll
            document.documentElement.style.scrollBehavior = 'smooth';
            
            // Mejorar el foco en elementos interactivos
            const interactiveElements = document.querySelectorAll('button, a, input, select, textarea');
            interactiveElements.forEach(element => {
                element.addEventListener('focus', function() {
                    this.style.outline = '2px solid #007bff';
                    this.style.outlineOffset = '2px';
                });
                
                element.addEventListener('blur', function() {
                    this.style.outline = '';
                    this.style.outlineOffset = '';
                });
            });
            
            // Agregar indicador de carga para elementos que tardan
            const tables = document.querySelectorAll('.table-responsive');
            tables.forEach(table => {
                if (table.scrollWidth > table.clientWidth) {
                    table.style.position = 'relative';
                    
                    const scrollIndicator = document.createElement('div');
                    scrollIndicator.innerHTML = '<i class="bi bi-arrow-left-right"></i> Desliza para ver más';
                    scrollIndicator.style.cssText = `
                        position: absolute;
                        bottom: 5px;
                        right: 5px;
                        font-size: 0.75rem;
                        color: #6c757d;
                        background: rgba(255,255,255,0.9);
                        padding: 0.25rem 0.5rem;
                        border-radius: 0.25rem;
                        pointer-events: none;
                    `;
                    table.appendChild(scrollIndicator);
                    
                    // Ocultar el indicador después de hacer scroll
                    table.addEventListener('scroll', () => {
                        scrollIndicator.style.opacity = '0';
                        setTimeout(() => scrollIndicator.remove(), 300);
                    }, { once: true });
                }
            });
        }
        
        function initTooltips() {
            // Inicializar tooltips de Bootstrap si están disponibles
            if (typeof bootstrap !== 'undefined' && bootstrap.Tooltip) {
                const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
                tooltipTriggerList.map(function (tooltipTriggerEl) {
                    return new bootstrap.Tooltip(tooltipTriggerEl);
                });
            }
        }
        
        // Función para recargar datos sin recargar la página (opcional)
        function refreshDashboard() {
            // Esta función se puede usar para actualizar los datos del dashboard
            // sin recargar toda la página
            console.log('Actualizando dashboard...');
            
            // Agregar un indicador de carga
            const cards = document.querySelectorAll('.card-dashboard');
            cards.forEach(card => {
                card.style.opacity = '0.7';
                card.style.pointerEvents = 'none';
            });
            
            // Simular actualización (en una implementación real, aquí iría una llamada AJAX)
            setTimeout(() => {
                cards.forEach(card => {
                    card.style.opacity = '';
                    card.style.pointerEvents = '';
                });
                console.log('Dashboard actualizado');
            }, 1000);
        }
        
        // Manejar errores de JavaScript
        window.addEventListener('error', function(e) {
            console.error('Error en el dashboard:', e.error);
        });
        
        // Optimización de rendimiento
        let resizeTimeout;
        window.addEventListener('resize', function() {
            clearTimeout(resizeTimeout);
            resizeTimeout = setTimeout(function() {
                // Recalcular elementos que dependen del tamaño de la ventana
                const tables = document.querySelectorAll('.table-responsive');
                tables.forEach(table => {
                    if (window.innerWidth < 768) {
                        table.style.fontSize = '0.875rem';
                    } else {
                        table.style.fontSize = '';
                    }
                });
            }, 250);
        });
    </script>
</body>
</html>