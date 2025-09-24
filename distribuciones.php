<?php
require_once 'config/database.php';

$database = new Database();
$db = $database->getConnection();

// Variables para mensajes
$mensaje = '';
$tipo_mensaje = '';

// Procesar formularios
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['accion'])) {
        switch ($_POST['accion']) {
            case 'generar_distribucion':
                try {
                    $db->beginTransaction();
                    
                    $fecha_inicio = $_POST['fecha_inicio'];
                    $fecha_fin = $_POST['fecha_fin'];
                    $dias_exclusion = json_encode($_POST['dias_exclusion'] ?? []);
                    $tipo_distribucion = $_POST['tipo_distribucion'];
                    $productos_seleccionados = '';
                    
                    if ($tipo_distribucion == 'parcial' && isset($_POST['productos_seleccionados'])) {
                        $productos_parciales = [];
                        foreach ($_POST['productos_seleccionados'] as $producto_id) {
                            $cantidad = (int)($_POST['cantidades'][$producto_id] ?? 0);
                            if ($cantidad > 0) {
                                $productos_parciales[] = [
                                    'producto_id' => $producto_id,
                                    'cantidad' => $cantidad
                                ];
                            }
                        }
                        $productos_seleccionados = json_encode($productos_parciales);
                    }
                    
                    // Crear distribución
                    $stmt = $db->prepare("INSERT INTO distribuciones (fecha_inicio, fecha_fin, dias_exclusion, tipo_distribucion, productos_seleccionados, estado, fecha_creacion) VALUES (?, ?, ?, ?, ?, 'activo', NOW())");
                    $stmt->execute([$fecha_inicio, $fecha_fin, $dias_exclusion, $tipo_distribucion, $productos_seleccionados]);
                    $distribucion_id = $db->lastInsertId();
                    
                    // Generar tablas
                    $resultado = generarTablasDistribucionCorregido($db, $distribucion_id, $fecha_inicio, $fecha_fin, $dias_exclusion, $tipo_distribucion, $productos_seleccionados);
                    
                    if ($resultado['success']) {
                        $db->commit();
                        $mensaje = "✅ Distribución generada exitosamente!\n\n" . $resultado['message'];
                        $tipo_mensaje = "success";
                    } else {
                        $db->rollBack();
                        $mensaje = "❌ Error: " . $resultado['message'];
                        $tipo_mensaje = "danger";
                    }
                    
                } catch (Exception $e) {
                    $db->rollBack();
                    $mensaje = "Error al generar distribución: " . $e->getMessage();
                    $tipo_mensaje = "danger";
                }
                break;
                
            case 'eliminar_distribucion':
                try {
                    $db->beginTransaction();
                    
                    $distribucion_id = $_POST['distribucion_id'];
                    
                    // Obtener todas las tablas de la distribución
                    $stmt_tablas = $db->prepare("SELECT id FROM tablas_distribucion WHERE distribucion_id = ? AND estado = 'activo'");
                    $stmt_tablas->execute([$distribucion_id]);
                    $tablas = $stmt_tablas->fetchAll();
                    
                    foreach ($tablas as $tabla) {
                        // Revertir existencias
                        $stmt_detalles = $db->prepare("SELECT producto_id, cantidad FROM detalle_tablas_distribucion WHERE tabla_id = ?");
                        $stmt_detalles->execute([$tabla['id']]);
                        $detalles = $stmt_detalles->fetchAll();
                        
                        foreach ($detalles as $detalle) {
                            $stmt_revertir = $db->prepare("UPDATE productos SET existencia = existencia + ? WHERE id = ?");
                            $stmt_revertir->execute([$detalle['cantidad'], $detalle['producto_id']]);
                        }
                        
                        // Eliminar detalles
                        $stmt_eliminar_detalles = $db->prepare("DELETE FROM detalle_tablas_distribucion WHERE tabla_id = ?");
                        $stmt_eliminar_detalles->execute([$tabla['id']]);
                    }
                    
                    // Marcar tablas como eliminadas
                    $stmt_eliminar_tablas = $db->prepare("UPDATE tablas_distribucion SET estado = 'eliminado' WHERE distribucion_id = ?");
                    $stmt_eliminar_tablas->execute([$distribucion_id]);
                    
                    // Marcar distribución como eliminada
                    $stmt_eliminar_distribucion = $db->prepare("UPDATE distribuciones SET estado = 'eliminado' WHERE id = ?");
                    $stmt_eliminar_distribucion->execute([$distribucion_id]);
                    
                    $db->commit();
                    $mensaje = "Distribución eliminada y existencias revertidas exitosamente.";
                    $tipo_mensaje = "success";
                    
                } catch (Exception $e) {
                    $db->rollBack();
                    $mensaje = "Error al eliminar distribución: " . $e->getMessage();
                    $tipo_mensaje = "danger";
                }
                break;
        }
    }
}

// **FUNCIÓN PARA CALCULAR FECHAS VÁLIDAS**
function calcularFechasValidas($fecha_inicio, $fecha_fin, $dias_exclusion) {
    $fechas_validas = [];
    $fecha_actual = new DateTime($fecha_inicio);
    $fecha_limite = new DateTime($fecha_fin);
    
    while ($fecha_actual <= $fecha_limite) {
        $dia_semana_num = $fecha_actual->format('w');
        if (!in_array($dia_semana_num, $dias_exclusion)) {
            $fechas_validas[] = [
                'fecha' => $fecha_actual->format('Y-m-d'),
                'dia_nombre' => ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'][$dia_semana_num],
                'fecha_formato' => $fecha_actual->format('d/m/Y')
            ];
        }
        $fecha_actual->add(new DateInterval('P1D'));
    }
    
    return $fechas_validas;
}

// **NUEVA FUNCIÓN PARA OBTENER UNIDADES (NO PRODUCTOS ÚNICOS)**
function obtenerUnidadesParaDistribucion($db, $tipo_distribucion, $productos_seleccionados_json) {
    if ($tipo_distribucion == 'completo') {
        $stmt_productos = $db->prepare("SELECT id, descripcion, existencia, precio_venta FROM productos WHERE existencia > 0 ORDER BY id");
        $stmt_productos->execute();
        $productos_base = $stmt_productos->fetchAll();
        
        $productos_a_distribuir = [];
        $total_unidades = 0;
        
        foreach ($productos_base as $producto) {
            $productos_a_distribuir[] = [
                'id' => $producto['id'],
                'descripcion' => $producto['descripcion'],
                'precio_venta' => $producto['precio_venta'],
                'cantidad_total' => $producto['existencia'],
                'cantidad_restante' => $producto['existencia']
            ];
            $total_unidades += $producto['existencia']; // SUMAR TODAS LAS UNIDADES
        }
    } else {
        $productos_seleccionados = json_decode($productos_seleccionados_json, true) ?: [];
        $productos_a_distribuir = [];
        $total_unidades = 0;
        
        foreach ($productos_seleccionados as $producto_sel) {
            $stmt_producto = $db->prepare("SELECT id, descripcion, existencia, precio_venta FROM productos WHERE id = ?");
            $stmt_producto->execute([$producto_sel['producto_id']]);
            $producto = $stmt_producto->fetch();
            
            if ($producto) {
                $cantidad_distribuir = min($producto_sel['cantidad'], $producto['existencia']);
                $productos_a_distribuir[] = [
                    'id' => $producto['id'],
                    'descripcion' => $producto['descripcion'],
                    'precio_venta' => $producto['precio_venta'],
                    'cantidad_total' => $cantidad_distribuir,
                    'cantidad_restante' => $cantidad_distribuir
                ];
                $total_unidades += $cantidad_distribuir; // SUMAR UNIDADES SELECCIONADAS
            }
        }
    }
    
    return [
        'productos' => $productos_a_distribuir,
        'total_productos' => count($productos_a_distribuir), // Productos únicos diferentes
        'total_unidades' => $total_unidades // TOTAL DE UNIDADES/EXISTENCIAS
    ];
}

// **ALGORITMO PRINCIPAL CORREGIDO - BASADO EN UNIDADES TOTALES**
function generarTablasDistribucionCorregido($db, $distribucion_id, $fecha_inicio, $fecha_fin, $dias_exclusion_json, $tipo_distribucion, $productos_seleccionados_json) {
    try {
        $dias_exclusion = json_decode($dias_exclusion_json, true) ?: [];
        
        // **PASO 1: PREPARAR DATOS CORREGIDOS**
        $fechas_validas = calcularFechasValidas($fecha_inicio, $fecha_fin, $dias_exclusion);
        $unidades_info = obtenerUnidadesParaDistribucion($db, $tipo_distribucion, $productos_seleccionados_json);
        $productos_a_distribuir = $unidades_info['productos'];
        
        if (empty($productos_a_distribuir) || empty($fechas_validas)) {
            return ['success' => false, 'message' => 'No hay productos o fechas válidas para distribuir.'];
        }
        
        $total_dias = count($fechas_validas);
        $total_unidades_disponibles = $unidades_info['total_unidades']; // ESTE ES EL CLAVE
        $total_productos_unicos = $unidades_info['total_productos'];
        
        // **PASO 2: CÁLCULOS ESTRATÉGICOS CORREGIDOS**
        $minimo_tablas_por_dia = 10;
        $maximo_tablas_por_dia = 40;
        
        // CORRECCIÓN PRINCIPAL: Calcular distribución basada en UNIDADES TOTALES
        $unidades_por_dia_base = floor($total_unidades_disponibles / $total_dias);
        $unidades_sobrantes = $total_unidades_disponibles % $total_dias;
        
        // Calcular cuántas tablas podemos generar por día realísticamente
        $planificacion_diaria = [];
        for ($i = 0; $i < $total_dias; $i++) {
            $unidades_este_dia = $unidades_por_dia_base + ($i < $unidades_sobrantes ? 1 : 0);
            
            // Determinar número de tablas para este día
            if ($unidades_este_dia >= $minimo_tablas_por_dia) {
                // Puede generar el mínimo ideal
                $tablas_este_dia = min($maximo_tablas_por_dia, max($minimo_tablas_por_dia, $unidades_este_dia));
            } else {
                // Menos del mínimo, pero garantizar al menos 1 tabla por día si hay unidades
                $tablas_este_dia = max(1, min($unidades_este_dia, $total_productos_unicos));
            }
            
            $planificacion_diaria[] = [
                'unidades_objetivo' => $unidades_este_dia,
                'tablas_planificadas' => $tablas_este_dia
            ];
        }
        
        // **PASO 3: DISTRIBUCIÓN GARANTIZADA DÍA POR DÍA**
        $total_tablas_generadas = 0;
        $total_unidades_distribuidas = 0;
        $estadisticas_detalladas = [];
        $productos_agotados_completamente = 0;
        
        foreach ($fechas_validas as $index_dia => $fecha_info) {
            $fecha = $fecha_info['fecha'];
            $dia_nombre = $fecha_info['dia_nombre'];
            $plan_dia = $planificacion_diaria[$index_dia];
            
            // Filtrar productos que aún tienen existencia
            $productos_disponibles_hoy = array_filter($productos_a_distribuir, function($p) {
                return $p['cantidad_restante'] > 0;
            });
            
            if (empty($productos_disponibles_hoy)) {
                // Si ya no hay productos, crear tabla vacía simbólica
                $stmt_tabla = $db->prepare("INSERT INTO tablas_distribucion (distribucion_id, fecha_tabla, numero_tabla, total_tabla) VALUES (?, ?, ?, ?)");
                $stmt_tabla->execute([$distribucion_id, $fecha, 1, 0.00]);
                
                $estadisticas_detalladas[] = [
                    'fecha' => $fecha,
                    'dia' => $dia_nombre,
                    'tablas_creadas' => 1,
                    'unidades_distribuidas' => 0,
                    'estado' => 'Sin productos disponibles'
                ];
                continue;
            }
            
            $unidades_objetivo_dia = $plan_dia['unidades_objetivo'];
            $tablas_objetivo_dia = $plan_dia['tablas_planificadas'];
            $unidades_distribuidas_hoy = 0;
            $tablas_creadas_hoy = 0;
            
            // **GENERAR TABLAS PARA ESTE DÍA**
            for ($tabla_num = 1; $tabla_num <= $tablas_objetivo_dia; $tabla_num++) {
                // Crear tabla
                $stmt_tabla = $db->prepare("INSERT INTO tablas_distribucion (distribucion_id, fecha_tabla, numero_tabla, total_tabla) VALUES (?, ?, ?, ?)");
                $stmt_tabla->execute([$distribucion_id, $fecha, $tabla_num, 0.00]);
                $tabla_id = $db->lastInsertId();
                
                $total_tabla_actual = 0;
                $productos_en_tabla = 0;
                
                // **DISTRIBUIR PRODUCTOS EN ESTA TABLA**
                foreach ($productos_disponibles_hoy as $indice => $producto) {
                    if ($producto['cantidad_restante'] <= 0) continue;
                    
                    // Calcular cuánto asignar (al menos 1, máximo lo disponible)
                    $cantidad_a_asignar = max(1, min($producto['cantidad_restante'], 
                        ceil($unidades_objetivo_dia / $tablas_objetivo_dia)));
                    
                    if ($unidades_distribuidas_hoy + $cantidad_a_asignar > $unidades_objetivo_dia) {
                        $cantidad_a_asignar = max(0, $unidades_objetivo_dia - $unidades_distribuidas_hoy);
                    }
                    
                    if ($cantidad_a_asignar > 0) {
                        $subtotal = $cantidad_a_asignar * $producto['precio_venta'];
                        
                        // Insertar detalle
                        $stmt_detalle = $db->prepare("INSERT INTO detalle_tablas_distribucion (tabla_id, producto_id, cantidad, precio_venta, subtotal) VALUES (?, ?, ?, ?, ?)");
                        $stmt_detalle->execute([$tabla_id, $producto['id'], $cantidad_a_asignar, $producto['precio_venta'], $subtotal]);
                        
                        // Actualizar existencia en productos
                        $stmt_update = $db->prepare("UPDATE productos SET existencia = existencia - ? WHERE id = ?");
                        $stmt_update->execute([$cantidad_a_asignar, $producto['id']]);
                        
                        // Actualizar arrays de control
                        $productos_a_distribuir[$indice]['cantidad_restante'] -= $cantidad_a_asignar;
                        $productos_disponibles_hoy[$indice]['cantidad_restante'] -= $cantidad_a_asignar;
                        
                        $total_tabla_actual += $subtotal;
                        $unidades_distribuidas_hoy += $cantidad_a_asignar;
                        $productos_en_tabla++;
                        
                        if ($productos_a_distribuir[$indice]['cantidad_restante'] <= 0) {
                            $productos_agotados_completamente++;
                        }
                    }
                    
                    // Si ya alcanzamos el objetivo del día, salir
                    if ($unidades_distribuidas_hoy >= $unidades_objetivo_dia) {
                        break;
                    }
                }
                
                // Actualizar total de la tabla
                $stmt_update_tabla = $db->prepare("UPDATE tablas_distribucion SET total_tabla = ? WHERE id = ?");
                $stmt_update_tabla->execute([$total_tabla_actual, $tabla_id]);
                
                $tablas_creadas_hoy++;
                $total_tablas_generadas++;
                
                // Si ya no hay más productos o alcanzamos el objetivo, salir del bucle
                if ($unidades_distribuidas_hoy >= $unidades_objetivo_dia || empty(array_filter($productos_disponibles_hoy, function($p) { return $p['cantidad_restante'] > 0; }))) {
                    break;
                }
            }
            
            $total_unidades_distribuidas += $unidades_distribuidas_hoy;
            
            $estadisticas_detalladas[] = [
                'fecha' => $fecha,
                'dia' => $dia_nombre,
                'tablas_creadas' => $tablas_creadas_hoy,
                'unidades_distribuidas' => $unidades_distribuidas_hoy,
                'estado' => 'Completado exitosamente'
            ];
        }
        
        // **PASO 4: DISTRIBUIR REMANENTES SI EXISTEN**
        $productos_con_remanentes = array_filter($productos_a_distribuir, function($p) {
            return $p['cantidad_restante'] > 0;
        });
        
        $mensaje_remanentes = '';
        if (!empty($productos_con_remanentes)) {
            $mensaje_remanentes = distribuirRemanentes($db, $distribucion_id, $productos_con_remanentes);
        }
        
        // **GENERAR MENSAJE DE ÉXITO DETALLADO**
        $resumen_estadisticas = "\n🎯 DISTRIBUCIÓN COMPLETADA:\n";
        $resumen_estadisticas .= "• {$total_tablas_generadas} tablas generadas en {$total_dias} días\n";
        $resumen_estadisticas .= "• {$total_unidades_distribuidas} unidades distribuidas de {$total_unidades_disponibles}\n";
        $resumen_estadisticas .= "• {$productos_agotados_completamente} productos agotados completamente\n";
        $resumen_estadisticas .= "• Promedio: " . round($total_tablas_generadas / $total_dias, 1) . " tablas por día\n";
        
        if (!empty($mensaje_remanentes)) {
            $resumen_estadisticas .= "\n" . $mensaje_remanentes;
        }
        
        $resumen_estadisticas .= "\n📊 DETALLE POR DÍA:\n";
        foreach ($estadisticas_detalladas as $stat) {
            $resumen_estadisticas .= "• {$stat['fecha']} ({$stat['dia']}): {$stat['tablas_creadas']} tablas, {$stat['unidades_distribuidas']} unidades\n";
        }
        
        return [
            'success' => true,
            'message' => $resumen_estadisticas,
            'estadisticas' => [
                'total_tablas' => $total_tablas_generadas,
                'total_unidades_distribuidas' => $total_unidades_distribuidas,
                'productos_agotados' => $productos_agotados_completamente,
                'detalle_por_dia' => $estadisticas_detalladas
            ]
        ];
        
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => 'Error en la generación: ' . $e->getMessage()
        ];
    }
}

// **FUNCIÓN PARA DISTRIBUIR REMANENTES**
function distribuirRemanentes($db, $distribucion_id, $productos_remanentes) {
    // Obtener todas las tablas existentes de la distribución
    $stmt_tablas = $db->prepare("SELECT id, total_tabla FROM tablas_distribucion WHERE distribucion_id = ? AND estado = 'activo' ORDER BY fecha_tabla, numero_tabla");
    $stmt_tablas->execute([$distribucion_id]);
    $tablas_disponibles = $stmt_tablas->fetchAll();
    
    if (empty($tablas_disponibles)) {
        return "⚠️ No hay tablas disponibles para distribuir remanentes";
    }
    
    $total_unidades_remanentes = array_sum(array_column($productos_remanentes, 'cantidad_restante'));
    $unidades_distribuidas = 0;
    $tabla_index = 0;
    
    // Distribuir remanentes de manera equitativa
    foreach ($productos_remanentes as $indice => $producto) {
        $cantidad_restante = $producto['cantidad_restante'];
        
        while ($cantidad_restante > 0 && $tabla_index < count($tablas_disponibles)) {
            $tabla = $tablas_disponibles[$tabla_index];
            
            // Decidir cuánto agregar a esta tabla (máximo 3 unidades por vuelta)
            $cantidad_agregar = min(3, $cantidad_restante);
            
            if ($cantidad_agregar > 0) {
                $subtotal = $cantidad_agregar * $producto['precio_venta'];
                
                // Insertar el detalle adicional
                $stmt_detalle = $db->prepare("INSERT INTO detalle_tablas_distribucion (tabla_id, producto_id, cantidad, precio_venta, subtotal) VALUES (?, ?, ?, ?, ?)");
                $stmt_detalle->execute([$tabla['id'], $producto['id'], $cantidad_agregar, $producto['precio_venta'], $subtotal]);
                
                // Actualizar existencia
                $stmt_update = $db->prepare("UPDATE productos SET existencia = existencia - ? WHERE id = ?");
                $stmt_update->execute([$cantidad_agregar, $producto['id']]);
                
                // Actualizar total de la tabla
                $nuevo_total = $tabla['total_tabla'] + $subtotal;
                $stmt_total = $db->prepare("UPDATE tablas_distribucion SET total_tabla = ? WHERE id = ?");
                $stmt_total->execute([$nuevo_total, $tabla['id']]);
                
                $cantidad_restante -= $cantidad_agregar;
                $unidades_distribuidas += $cantidad_agregar;
                $tabla['total_tabla'] = $nuevo_total;
                
                // Actualizar el producto en nuestro array
                $productos_remanentes[$indice]['cantidad_restante'] = $cantidad_restante;
            }
            
            $tabla_index++;
            
            // Reiniciar índice si llegamos al final
            if ($tabla_index >= count($tablas_disponibles)) {
                $tabla_index = 0;
                break; // Evitar bucle infinito si no se pueden distribuir más
            }
        }
    }
    
    if ($unidades_distribuidas > 0) {
        return sprintf(
            "♻️ REMANENTES DISTRIBUIDOS:\n" .
            "• %s unidades adicionales distribuidas\n" .
            "• Distribuidas equitativamente en tablas existentes",
            number_format($unidades_distribuidas)
        );
    } else {
        return "⚠️ No se pudieron distribuir los {$total_unidades_remanentes} remanentes restantes";
    }
}
// Obtener distribuciones con paginación
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 10;
$offset = ($page - 1) * $limit;

$estado_filter = isset($_GET['estado']) ? $_GET['estado'] : 'activo';

$where_clause = "WHERE estado = '$estado_filter'";

// Contar total de distribuciones
$count_query = "SELECT COUNT(*) as total FROM distribuciones $where_clause";
$stmt_count = $db->prepare($count_query);
$stmt_count->execute();
$total_distribuciones = $stmt_count->fetch()['total'];
$total_pages = ceil($total_distribuciones / $limit);

// Obtener distribuciones
$query = "SELECT d.*, 
          (SELECT COUNT(*) FROM tablas_distribucion td WHERE td.distribucion_id = d.id AND td.estado = 'activo') as total_tablas,
          (SELECT SUM(td.total_tabla) FROM tablas_distribucion td WHERE td.distribucion_id = d.id AND td.estado = 'activo') as total_distribucion
          FROM distribuciones d 
          $where_clause 
          ORDER BY d.fecha_creacion DESC 
          LIMIT $limit OFFSET $offset";
$stmt = $db->prepare($query);
$stmt->execute();
$distribuciones = $stmt->fetchAll();

// Obtener productos con existencia para el modal
$stmt_productos = $db->prepare("SELECT id, proveedor, descripcion, existencia, precio_venta FROM productos WHERE existencia > 0 ORDER BY proveedor, descripcion");
$stmt_productos->execute();
$productos_con_existencia = $stmt_productos->fetchAll();
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Distribuciones - Sistema de Inventario</title>
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
        .distribution-card {
            border: 1px solid #dee2e6;
            border-radius: 8px;
            margin-bottom: 1rem;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .distribution-card:hover {
            box-shadow: 0 4px 8px rgba(0,0,0,0.15);
            transform: translateY(-2px);
            transition: all 0.3s ease;
        }
        .btn-group-sm > .btn, .btn-sm {
            padding: .25rem .5rem;
            font-size: .875rem;
            border-radius: .2rem;
        }
        
        /* **CORRECCIÓN PRINCIPAL: CSS PARA IMPRESIÓN** */
        @media print {
            /* Ocultar elementos no necesarios para impresión */
            .sidebar, 
            .no-print, 
            .btn, 
            .modal-header, 
            .modal-footer,
            .pagination,
            .card-footer,
            .alert,
            nav {
                display: none !important;
            }
            
            /* Ajustar el contenido principal */
            .main-content {
                margin-left: 0 !important;
                width: 100% !important;
                padding: 0 !important;
            }
            
            /* **Configurar el modal para impresión completa** */
            .modal {
                position: static !important;
                display: block !important;
                width: 100% !important;
                height: auto !important;
                overflow: visible !important;
                background: white !important;
            }
            
            .modal-dialog {
                position: static !important;
                width: 100% !important;
                max-width: none !important;
                margin: 0 !important;
                transform: none !important;
            }
            
            .modal-content {
                position: static !important;
                width: 100% !important;
                border: none !important;
                box-shadow: none !important;
                background: white !important;
            }
            
            .modal-body {
                position: static !important;
                max-height: none !important;
                overflow: visible !important;
                padding: 0 !important;
                margin: 0 !important;
            }
            
            /* **Estilos específicos para las tablas de distribución** */
            .print-tabla-distribucion {
                page-break-inside: avoid;
                margin-bottom: 20px;
                border: 1px solid #000;
                padding: 10px;
            }
            
            .print-dia-header {
                background-color: #f8f9fa !important;
                border-bottom: 2px solid #000;
                padding: 10px;
                margin-bottom: 15px;
                font-weight: bold;
                font-size: 16px;
                page-break-after: avoid;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            
            .print-tabla-individual {
                border: 1px solid #666;
                margin-bottom: 15px;
                page-break-inside: avoid;
            }
            
            .print-tabla-header {
                background-color: #e9ecef !important;
                padding: 8px;
                border-bottom: 1px solid #666;
                font-weight: bold;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            
            .print-producto-row {
                border-bottom: 1px solid #ccc;
                padding: 5px 8px;
            }
            
            .print-producto-row:last-child {
                border-bottom: none;
            }
            
            .print-total-tabla {
                background-color: #f1f3f4 !important;
                padding: 8px;
                border-top: 2px solid #000;
                font-weight: bold;
                text-align: right;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            
            /* Ajustar tamaños de fuente para impresión */
            body {
                font-size: 12px !important;
                line-height: 1.3 !important;
            }
            
            h1, h2, h3, h4, h5, h6 {
                margin-top: 0 !important;
                margin-bottom: 10px !important;
            }
            
            /* Configuración de página */
            @page {
                margin: 15mm;
                size: A4;
            }
            
            /* Forzar saltos de página apropiados */
            .print-page-break {
                page-break-before: always;
            }
            
            /* Asegurar que las tablas no se corten */
            .table {
                page-break-inside: avoid;
            }
            
            .table thead {
                page-break-after: avoid;
            }
            
            .table tbody tr {
                page-break-inside: avoid;
            }
        }
        
        /* **Estilos para mejor visualización en pantalla** */
        .tabla-dia-section {
            margin-bottom: 2rem;
            border-radius: 8px;
            overflow: hidden;
        }
        
        .tabla-dia-header {
            background: linear-gradient(135deg, #007bff, #0056b3);
            color: white;
            padding: 1rem;
            font-weight: bold;
            font-size: 1.1rem;
        }
        
        .tabla-individual-card {
            border: 1px solid #e0e0e0;
            border-radius: 6px;
            margin: 10px;
            background: white;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .tabla-header {
            background: #f8f9fa;
            padding: 12px;
            border-bottom: 1px solid #e0e0e0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .tabla-numero {
            font-weight: bold;
            color: #495057;
        }
        
        .tabla-total {
            background: #28a745;
            color: white;
            padding: 4px 12px;
            border-radius: 20px;
            font-weight: bold;
        }
        
        .producto-item {
            padding: 8px 12px;
            border-bottom: 1px solid #f1f1f1;
            display: grid;
            grid-template-columns: 2fr 1fr 1fr;
            gap: 10px;
            align-items: center;
        }
        
        .producto-item:last-child {
            border-bottom: none;
        }
        
        .producto-descripcion {
            font-weight: 500;
            color: #495057;
        }
        
        .producto-proveedor {
            font-size: 0.85rem;
            color: #6c757d;
        }
        
        .producto-cantidad {
            text-align: center;
            font-weight: bold;
            color: #007bff;
        }
        
        .producto-precio {
            text-align: right;
            font-weight: 500;
            color: #28a745;
        }
        
        /* Responsividad */
        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
                transition: transform 0.3s ease;
            }
            
            .sidebar.show {
                transform: translateX(0);
            }
            
            .main-content {
                margin-left: 0;
                width: 100%;
            }
        }
        
        /* Estados de las distribuciones */
        .estado-activo {
            background-color: #d1e7dd;
            color: #0f5132;
        }
        
        .estado-eliminado {
            background-color: #f8d7da;
            color: #721c24;
        }
        
        /* Animaciones suaves */
        .card-hover {
            transition: all 0.3s ease;
        }
        
        .card-hover:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 20px rgba(0,0,0,0.15);
        }
        
        /* Validación visual */
        .is-invalid {
            border-color: #dc3545;
        }
        
        .is-valid {
            border-color: #28a745;
        }
        
        /* Spinner de carga */
        .loading-spinner {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 3px solid #f3f3f3;
            border-top: 3px solid #007bff;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        /* Indicadores de progreso */
        .progress-bar {
            transition: width 0.6s ease;
        }
        
        /* Tooltips personalizados */
        .tooltip-inner {
            background-color: #212529;
            color: #fff;
        }
        
        /* Badges mejorados */
        .badge-custom {
            font-size: 0.75rem;
            padding: 0.35em 0.65em;
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
                            <a class="nav-link" href="ingresos.php">
                                <i class="bi bi-plus-circle"></i> Ingresos
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link active" href="distribuciones.php">
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
                    <h1 class="h2"><i class="bi bi-truck"></i> Gestión de Distribuciones</h1>
                    <div class="btn-toolbar mb-2 mb-md-0 no-print">
                        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalNuevaDistribucion">
                            <i class="bi bi-plus-circle"></i> Nueva Distribución
                        </button>
                    </div>
                </div>

                <!-- Mensajes de alerta -->
                <?php if (!empty($mensaje)): ?>
                    <div class="alert alert-<?php echo $tipo_mensaje; ?> alert-dismissible fade show no-print" role="alert">
                        <div style="white-space: pre-line; font-family: 'Courier New', monospace;">
                            <?php echo htmlspecialchars($mensaje); ?>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <!-- Filtros -->
                <div class="card mb-4 no-print">
                    <div class="card-body">
                        <form method="GET" class="row g-3">
                            <div class="col-md-6">
                                <label for="estado" class="form-label">Estado:</label>
                                <select name="estado" id="estado" class="form-select">
                                    <option value="activo" <?php echo $estado_filter === 'activo' ? 'selected' : ''; ?>>Activas</option>
                                    <option value="eliminado" <?php echo $estado_filter === 'eliminado' ? 'selected' : ''; ?>>Eliminadas</option>
                                </select>
                            </div>
                            <div class="col-md-6 d-flex align-items-end">
                                <button type="submit" class="btn btn-outline-primary w-100">
                                    <i class="bi bi-funnel"></i> Filtrar
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Lista de distribuciones -->
                <div class="row">
                    <?php if (count($distribuciones) > 0): ?>
                        <?php foreach ($distribuciones as $distribucion): ?>
                            <div class="col-lg-6 col-xl-4 mb-4">
                                <div class="card distribution-card card-hover h-100">
                                    <div class="card-header d-flex justify-content-between align-items-center">
                                        <h6 class="card-title mb-0">
                                            <i class="bi bi-calendar-range"></i> 
                                            Distribución #<?php echo $distribucion['id']; ?>
                                        </h6>
                                        <span class="badge <?php echo $distribucion['estado'] === 'activo' ? 'bg-success estado-activo' : 'bg-danger estado-eliminado'; ?> badge-custom">
                                            <?php echo ucfirst($distribucion['estado']); ?>
                                        </span>
                                    </div>
                                    <div class="card-body">
                                        <div class="row mb-3">
                                            <div class="col-6">
                                                <small class="text-muted">Inicio:</small><br>
                                                <strong><?php echo date('d/m/Y', strtotime($distribucion['fecha_inicio'])); ?></strong>
                                            </div>
                                            <div class="col-6">
                                                <small class="text-muted">Fin:</small><br>
                                                <strong><?php echo date('d/m/Y', strtotime($distribucion['fecha_fin'])); ?></strong>
                                            </div>
                                        </div>
                                        
                                        <div class="row mb-3">
                                            <div class="col-6">
                                                <small class="text-muted">Tipo:</small><br>
                                                <span class="badge <?php echo $distribucion['tipo_distribucion'] === 'completo' ? 'bg-info' : 'bg-warning'; ?> badge-custom">
                                                    <?php echo ucfirst($distribucion['tipo_distribucion']); ?>
                                                </span>
                                            </div>
                                            <div class="col-6">
                                                <small class="text-muted">Tablas:</small><br>
                                                <strong class="text-primary"><?php echo $distribucion['total_tablas'] ?: 0; ?></strong>
                                            </div>
                                        </div>

                                        <?php if ($distribucion['total_distribucion']): ?>
                                            <div class="alert alert-success py-2 mb-3">
                                                <small><i class="bi bi-cash-coin"></i> <strong>Total: $<?php echo number_format($distribucion['total_distribucion'], 2); ?></strong></small>
                                            </div>
                                        <?php endif; ?>

                                        <?php if (!empty($distribucion['dias_exclusion']) && $distribucion['dias_exclusion'] !== '[]'): ?>
                                            <div class="mb-2">
                                                <small class="text-muted">Días excluidos:</small><br>
                                                <?php
                                                $dias_nombres = ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];
                                                $dias_excluidos = json_decode($distribucion['dias_exclusion'], true) ?: [];
                                                $excluidos = [];
                                                foreach ($dias_excluidos as $dia) {
                                                    $excluidos[] = $dias_nombres[(int)$dia];
                                                }
                                                echo '<span class="badge bg-secondary badge-custom">' . implode(', ', $excluidos) . '</span>';
                                                ?>
                                            </div>
                                        <?php endif; ?>

                                        <small class="text-muted">
                                            <i class="bi bi-clock"></i> Creada: <?php echo date('d/m/Y H:i', strtotime($distribucion['fecha_creacion'])); ?>
                                        </small>
                                    </div>
                                    <div class="card-footer">
                                        <div class="btn-group w-100" role="group">
                                            <?php if ($distribucion['estado'] == 'activo'): ?>
                                                <button type="button" class="btn btn-outline-info btn-sm" 
                                                        onclick="verTablas(<?php echo $distribucion['id']; ?>)" 
                                                        title="Ver tablas de distribución">
                                                    <i class="bi bi-table"></i> Ver Tablas
                                                </button>
                                                <button type="button" class="btn btn-outline-danger btn-sm" 
                                                        onclick="eliminarDistribucion(<?php echo $distribucion['id']; ?>)" 
                                                        title="Eliminar distribución">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            <?php else: ?>
                                                <button type="button" class="btn btn-outline-secondary btn-sm" disabled>
                                                    <i class="bi bi-archive"></i> Eliminada
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="col-12">
                            <div class="alert alert-info text-center">
                                <i class="bi bi-info-circle"></i> 
                                No hay distribuciones registradas para mostrar.
                                <br><br>
                                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalNuevaDistribucion">
                                    <i class="bi bi-plus-circle"></i> Crear Primera Distribución
                                </button>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Paginación -->
                <?php if ($total_pages > 1): ?>
                    <nav aria-label="Navegación de distribuciones" class="no-print">
                        <ul class="pagination justify-content-center">
                            <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                <a class="page-link" href="?page=<?php echo max(1, $page - 1); ?>&estado=<?php echo urlencode($estado_filter); ?>">Anterior</a>
                            </li>
                            
                            <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                                <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                    <a class="page-link" href="?page=<?php echo $i; ?>&estado=<?php echo urlencode($estado_filter); ?>"><?php echo $i; ?></a>
                                </li>
                            <?php endfor; ?>
                            
                            <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                                <a class="page-link" href="?page=<?php echo min($total_pages, $page + 1); ?>&estado=<?php echo urlencode($estado_filter); ?>">Siguiente</a>
                            </li>
                        </ul>
                    </nav>
                <?php endif; ?>

            </main>
        </div>
    </div>
    <!-- Modal para nueva distribución -->
    <div class="modal fade" id="modalNuevaDistribucion" tabindex="-1" aria-labelledby="modalNuevaDistribucionLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalNuevaDistribucionLabel">
                        <i class="bi bi-plus-circle"></i> Crear Nueva Distribución
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" id="formDistribucion">
                    <input type="hidden" name="accion" value="generar_distribucion">
                    <div class="modal-body">
                        <!-- Configuración de fechas -->
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label for="fecha_inicio" class="form-label">Fecha de Inicio:</label>
                                <input type="date" class="form-control" id="fecha_inicio" name="fecha_inicio" required>
                            </div>
                            <div class="col-md-6">
                                <label for="fecha_fin" class="form-label">Fecha de Fin:</label>
                                <input type="date" class="form-control" id="fecha_fin" name="fecha_fin" required>
                            </div>
                        </div>

                        <!-- Días a excluir -->
                        <div class="mb-3">
                            <label class="form-label">Días de la semana a excluir:</label>
                            <div class="row">
                                <?php 
                                $dias_semana = [
                                    ['valor' => '0', 'nombre' => 'Domingo'],
                                    ['valor' => '1', 'nombre' => 'Lunes'],
                                    ['valor' => '2', 'nombre' => 'Martes'],
                                    ['valor' => '3', 'nombre' => 'Miércoles'],
                                    ['valor' => '4', 'nombre' => 'Jueves'],
                                    ['valor' => '5', 'nombre' => 'Viernes'],
                                    ['valor' => '6', 'nombre' => 'Sábado']
                                ];
                                foreach ($dias_semana as $dia): 
                                ?>
                                    <div class="col-md-4 col-6 mb-2">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" name="dias_exclusion[]" 
                                                   value="<?php echo $dia['valor']; ?>" id="dia_<?php echo $dia['valor']; ?>">
                                            <label class="form-check-label" for="dia_<?php echo $dia['valor']; ?>">
                                                <?php echo $dia['nombre']; ?>
                                            </label>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- Tipo de distribución -->
                        <div class="mb-3">
                            <label class="form-label">Tipo de Distribución:</label>
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="tipo_distribucion" 
                                               id="tipo_completo" value="completo" checked onchange="toggleProductosSeleccion()">
                                        <label class="form-check-label" for="tipo_completo">
                                            <strong>Distribución Completa</strong><br>
                                            <small class="text-muted">Incluir todos los productos con existencia</small>
                                        </label>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="tipo_distribucion" 
                                               id="tipo_parcial" value="parcial" onchange="toggleProductosSeleccion()">
                                        <label class="form-check-label" for="tipo_parcial">
                                            <strong>Distribución Parcial</strong><br>
                                            <small class="text-muted">Seleccionar productos específicos</small>
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Selección de productos (solo para distribución parcial) -->
                        <div id="productos_seleccion" style="display: none;">
                            <label class="form-label">Productos a incluir:</label>
                            <div class="row mb-3">
                                <div class="col-12">
                                    <div style="max-height: 300px; overflow-y: auto; border: 1px solid #dee2e6; border-radius: 0.375rem; padding: 0.5rem;">
                                        <?php 
                                        $proveedor_actual = '';
                                        foreach ($productos_con_existencia as $producto): 
                                            if ($proveedor_actual !== $producto['proveedor']): 
                                                if ($proveedor_actual !== '') echo '</div>';
                                                $proveedor_actual = $producto['proveedor'];
                                        ?>
                                            <div class="mb-2">
                                                <h6 class="text-primary mb-2">
                                                    <i class="bi bi-building"></i> <?php echo htmlspecialchars($proveedor_actual); ?>
                                                </h6>
                                        <?php endif; ?>
                                                <div class="row producto-row mb-2 p-2 border-bottom">
                                                    <div class="col-1">
                                                        <input class="form-check-input producto-checkbox" type="checkbox" 
                                                               name="productos_seleccionados[]" value="<?php echo $producto['id']; ?>" 
                                                               id="producto_<?php echo $producto['id']; ?>">
                                                    </div>
                                                    <div class="col-7">
                                                        <label class="form-check-label" for="producto_<?php echo $producto['id']; ?>">
                                                            <strong><?php echo htmlspecialchars($producto['descripcion']); ?></strong><br>
                                                            <small class="text-muted">
                                                                Stock: <?php echo $producto['existencia']; ?> | 
                                                                Precio: $<?php echo number_format($producto['precio_venta'], 2); ?>
                                                            </small>
                                                        </label>
                                                    </div>
                                                    <div class="col-4">
                                                        <input type="number" class="form-control cantidad-parcial" 
                                                               name="cantidades[<?php echo $producto['id']; ?>]" 
                                                               min="1" max="<?php echo $producto['existencia']; ?>" 
                                                               placeholder="Cantidad" disabled>
                                                    </div>
                                                </div>
                                        <?php endforeach; ?>
                                        <?php if ($proveedor_actual !== '') echo '</div>'; ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Predicción y validación -->
                        <div id="prediccion_distribucion" class="alert alert-info" style="display: none;">
                            <h6><i class="bi bi-info-circle"></i> Análisis de Factibilidad:</h6>
                            <div id="contenido_prediccion">
                                <div class="loading-spinner"></div> Calculando...
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            <i class="bi bi-x-circle"></i> Cancelar
                        </button>
                        <button type="submit" class="btn btn-primary" id="btnGenerarDistribucion">
                            <i class="bi bi-rocket"></i> Generar Distribución de Unidades
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal para ver tablas de distribución -->
    <div class="modal fade" id="modalVerTablas" tabindex="-1" aria-labelledby="modalVerTablasLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalVerTablasLabel">
                        <i class="bi bi-table"></i> Tablas de Distribución
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="tablasContent" style="max-height: 70vh; overflow-y: auto;">
                    <div class="text-center">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Cargando tablas...</span>
                        </div>
                        <p class="mt-2">Cargando tablas de distribución...</p>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-x-circle"></i> Cerrar
                    </button>
                    <button type="button" class="btn btn-primary" onclick="imprimirTablas()">
                        <i class="bi bi-printer"></i> Imprimir Todas las Tablas
                    </button>
                </div>
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
                        <h6><i class="bi bi-exclamation-triangle"></i> ¿Está seguro que desea eliminar esta distribución?</h6>
                        <p class="mb-0">Esta acción realizará las siguientes operaciones:</p>
                        <ul class="mt-2 mb-0">
                            <li><strong>Revertirá todas las salidas</strong> del inventario</li>
                            <li><strong>Eliminará todas las tablas</strong> generadas</li>
                            <li><strong>Restaurará las existencias</strong> originales</li>
                            <li><strong>No se puede deshacer</strong> esta operación</li>
                        </ul>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-x-circle"></i> Cancelar
                    </button>
                    <form method="POST" style="display: inline;">
                        <input type="hidden" name="accion" value="eliminar_distribucion">
                        <input type="hidden" name="distribucion_id" id="distribucion_id_eliminar">
                        <button type="submit" class="btn btn-danger">
                            <i class="bi bi-trash"></i> Sí, Eliminar Distribución
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Variables globales para validación
        let validacionTimeout = null;

        // **Función corregida para cargar tablas con formato optimizado para impresión**
        async function verTablas(distribucionId) {
            const modal = new bootstrap.Modal(document.getElementById('modalVerTablas'));
            modal.show();
            
            try {
                const response = await fetch(`obtener_tablas_distribucion.php?distribucion_id=${distribucionId}`);
                const data = await response.json();
                
                if (data.success) {
                    mostrarTablasConFormatoImpresion(data.tablas_por_dia);
                } else {
                    document.getElementById('tablasContent').innerHTML = `
                        <div class="alert alert-warning">
                            <i class="bi bi-exclamation-triangle"></i> ${data.message || 'No se pudieron cargar las tablas'}
                        </div>
                    `;
                }
            } catch (error) {
                console.error('Error:', error);
                document.getElementById('tablasContent').innerHTML = `
                    <div class="alert alert-danger">
                        <i class="bi bi-exclamation-circle"></i> Error al cargar las tablas: ${error.message}
                    </div>
                `;
            }
        }

        // **Nueva función que prepara el contenido para impresión correcta**
        function mostrarTablasConFormatoImpresion(tablasPorDia) {
            let html = '';
            
            if (!tablasPorDia || Object.keys(tablasPorDia).length === 0) {
                html = `
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle"></i> No hay tablas generadas para esta distribución.
                    </div>
                `;
            } else {
                // **Crear encabezado de distribución para impresión**
                html += `
                    <div class="print-distribution-header mb-4" style="text-align: center; border-bottom: 2px solid #000; padding-bottom: 10px;">
                        <h2>TABLAS DE DISTRIBUCIÓN</h2>
                        <p><strong>Generado:</strong> ${new Date().toLocaleString('es-ES')}</p>
                        <p><strong>Total de días:</strong> ${Object.keys(tablasPorDia).length}</p>
                    </div>
                `;
                
                Object.entries(tablasPorDia).forEach(([fecha, datosDia], indexDia) => {
                    const fechaFormateada = new Date(fecha + 'T00:00:00').toLocaleDateString('es-ES', {
                        weekday: 'long',
                        year: 'numeric',
                        month: 'long',
                        day: 'numeric'
                    });
                    
                    // **Encabezado del día (optimizado para impresión)**
                    html += `
                        <div class="tabla-dia-section ${indexDia > 0 ? 'print-page-break' : ''}">
                            <div class="tabla-dia-header print-dia-header">
                                📅 ${fechaFormateada.toUpperCase()}
                                <div style="float: right;">
                                    Total del día: ${datosDia.tablasDelDia.length} tabla${datosDia.tablasDelDia.length !== 1 ? 's' : ''}
                                </div>
                            </div>
                            <div class="tablas-del-dia-container">
                    `;
                    
                    if (datosDia.tablasDelDia && datosDia.tablasDelDia.length > 0) {
                        datosDia.tablasDelDia.forEach((tabla, indexTabla) => {
                            html += `
                                <div class="tabla-individual-card print-tabla-individual">
                                    <div class="tabla-header print-tabla-header">
                                        <div class="tabla-numero">
                                            📋 Tabla #${tabla.numero_tabla}
                                        </div>
                                        <div class="tabla-total">
                                            Total: $${parseFloat(tabla.total_tabla).toFixed(2)}
                                        </div>
                                    </div>
                                    <div class="tabla-productos">
                            `;
                            
                            if (tabla.detalles && tabla.detalles.length > 0) {
                                tabla.detalles.forEach(detalle => {
                                    html += `
                                        <div class="producto-item print-producto-row">
                                            <div>
                                                <div class="producto-descripcion">${detalle.descripcion}</div>
                                                <div class="producto-proveedor">${detalle.proveedor}</div>
                                            </div>
                                            <div class="producto-cantidad">${detalle.cantidad}</div>
                                            <div class="producto-precio">$${parseFloat(detalle.precio_venta).toFixed(2)}</div>
                                        </div>
                                    `;
                                });
                            } else {
                                html += `
                                    <div class="producto-item print-producto-row">
                                        <div class="text-muted">Sin productos asignados</div>
                                        <div>-</div>
                                        <div>-</div>
                                    </div>
                                `;
                            }
                            
                            html += `
                                    </div>
                                    <div class="print-total-tabla" style="background-color: #f8f9fa; padding: 8px; border-top: 1px solid #dee2e6; text-align: right; font-weight: bold;">
                                        TOTAL: $${parseFloat(tabla.total_tabla).toFixed(2)}
                                    </div>
                                </div>
                            `;
                        });
                    } else {
                        html += `
                            <div class="alert alert-info m-3">
                                <i class="bi bi-info-circle"></i> No hay tablas para este día.
                            </div>
                        `;
                    }
                    
                    html += `
                            </div>
                        </div>
                    `;
                });
            }
            
            document.getElementById('tablasContent').innerHTML = html;
        }

        // **Función corregida para impresión - Ahora imprime todo el contenido**
        function imprimirTablas() {
            // Crear una nueva ventana para la impresión
            const printWindow = window.open('', '_blank', 'width=800,height=600');
            
            // Obtener todo el contenido del modal
            const contenidoTablas = document.getElementById('tablasContent').innerHTML;
            
            // Crear el HTML completo para impresión
            const htmlParaImpresion = `
                <!DOCTYPE html>
                <html lang="es">
                <head>
                    <meta charset="UTF-8">
                    <meta name="viewport" content="width=device-width, initial-scale=1.0">
                    <title>Tablas de Distribución - Impresión</title>
                    <style>
                        * {
                            margin: 0;
                            padding: 0;
                            box-sizing: border-box;
                        }
                        
                        body {
                            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
                            font-size: 12px;
                            line-height: 1.4;
                            color: #333;
                            background: white;
                        }
                        
                        .print-distribution-header {
                            text-align: center;
                            margin-bottom: 30px;
                            padding-bottom: 15px;
                            border-bottom: 3px solid #000;
                        }
                        
                        .print-distribution-header h2 {
                            font-size: 24px;
                            margin-bottom: 10px;
                            text-transform: uppercase;
                            letter-spacing: 2px;
                        }
                        
                        .tabla-dia-section {
                            margin-bottom: 25px;
                            page-break-inside: avoid;
                        }
                        
                        .tabla-dia-header,
                        .print-dia-header {
                            background-color: #007bff !important;
                            color: white !important;
                            padding: 12px;
                            font-size: 16px;
                            font-weight: bold;
                            margin-bottom: 15px;
                            -webkit-print-color-adjust: exact;
                            print-color-adjust: exact;
                        }
                        
                        .tabla-individual-card,
                        .print-tabla-individual {
                            border: 2px solid #333;
                            margin-bottom: 20px;
                            page-break-inside: avoid;
                        }
                        
                        .tabla-header,
                        .print-tabla-header {
                            background-color: #f8f9fa !important;
                            padding: 10px;
                            border-bottom: 1px solid #333;
                            display: flex;
                            justify-content: space-between;
                            align-items: center;
                            font-weight: bold;
                            -webkit-print-color-adjust: exact;
                            print-color-adjust: exact;
                        }
                        
                        .tabla-total {
                            background-color: #28a745 !important;
                            color: white !important;
                            padding: 6px 12px;
                            border-radius: 4px;
                            font-weight: bold;
                            -webkit-print-color-adjust: exact;
                            print-color-adjust: exact;
                        }
                        
                        .producto-item,
                        .print-producto-row {
                            padding: 8px 10px;
                            border-bottom: 1px solid #ddd;
                            display: grid;
                            grid-template-columns: 3fr 1fr 1fr;
                            gap: 15px;
                            align-items: center;
                        }
                        
                        .producto-item:last-child,
                        .print-producto-row:last-child {
                            border-bottom: none;
                        }
                        
                        .producto-descripcion {
                            font-weight: bold;
                            margin-bottom: 2px;
                        }
                        
                        .producto-proveedor {
                            font-size: 10px;
                            color: #666;
                            font-style: italic;
                        }
                        
                        .producto-cantidad {
                            text-align: center;
                            font-weight: bold;
                            font-size: 14px;
                        }
                        
                        .producto-precio {
                            text-align: right;
                            font-weight: bold;
                            color: #28a745;
                        }
                        
                        .print-total-tabla {
                            background-color: #e9ecef !important;
                            padding: 10px;
                            border-top: 2px solid #333;
                            text-align: right;
                            font-weight: bold;
                            font-size: 14px;
                            -webkit-print-color-adjust: exact;
                            print-color-adjust: exact;
                        }
                        
                        .alert {
                            padding: 12px;
                            margin: 15px 0;
                            border: 1px solid #ddd;
                            border-radius: 4px;
                            background-color: #f8f9fa !important;
                            -webkit-print-color-adjust: exact;
                            print-color-adjust: exact;
                        }
                        
                        .text-muted {
                            color: #666 !important;
                        }
                        
                        /* Configuración de página */
                        @page {
                            margin: 20mm;
                            size: A4;
                        }
                        
                        /* Saltos de página */
                        .print-page-break {
                            page-break-before: always;
                        }
                        
                        /* Evitar cortes inapropiados */
                        .tabla-dia-section {
                            page-break-inside: avoid;
                        }
                        
                        .tabla-individual-card {
                            page-break-inside: avoid;
                        }
                    </style>
                </head>
                <body>
                    ${contenidoTablas}
                </body>
                </html>
            `;
            
            // Escribir el contenido en la nueva ventana
            printWindow.document.write(htmlParaImpresion);
            printWindow.document.close();
            
            // Esperar a que se cargue el contenido y luego imprimir
            printWindow.onload = function() {
                setTimeout(() => {
                    printWindow.print();
                    // Cerrar la ventana después de imprimir
                    printWindow.onafterprint = function() {
                        printWindow.close();
                    };
                }, 250);
            };
        }

        // **Función auxiliar para eliminar distribución (mantener existente)**
        function eliminarDistribucion(id) {
            document.getElementById('distribucion_id_eliminar').value = id;
            const modal = new bootstrap.Modal(document.getElementById('modalEliminar'));
            modal.show();
        }

        // Mostrar/ocultar selección de productos
        function toggleProductosSeleccion() {
            const tipoCompleto = document.getElementById('tipo_completo').checked;
            const productosDiv = document.getElementById('productos_seleccion');
            
            if (tipoCompleto) {
                productosDiv.style.display = 'none';
                // Desmarcar todos los checkboxes
                document.querySelectorAll('.producto-checkbox').forEach(cb => {
                    cb.checked = false;
                    cb.closest('.producto-row').querySelector('.cantidad-parcial').disabled = true;
                });
            } else {
                productosDiv.style.display = 'block';
            }
            
            // Actualizar predicción
            validarFactibilidadEnTiempoReal();
        }

        // Validación en tiempo real
        function validarFactibilidadEnTiempoReal() {
            clearTimeout(validacionTimeout);
            validacionTimeout = setTimeout(() => {
                realizarValidacionFactibilidad();
            }, 500);
        }

        function realizarValidacionFactibilidad() {
            const fechaInicio = document.getElementById('fecha_inicio').value;
            const fechaFin = document.getElementById('fecha_fin').value;
            const diasExcluidos = Array.from(document.querySelectorAll('input[name="dias_exclusion[]"]:checked')).map(cb => parseInt(cb.value));
            const tipoDistribucion = document.querySelector('input[name="tipo_distribucion"]:checked').value;
            
            if (!fechaInicio || !fechaFin) {
                document.getElementById('prediccion_distribucion').style.display = 'none';
                return;
            }

            // Calcular días válidos
            const fechas = [];
            const inicio = new Date(fechaInicio);
            const fin = new Date(fechaFin);
            
            for (let d = new Date(inicio); d <= fin; d.setDate(d.getDate() + 1)) {
                if (!diasExcluidos.includes(d.getDay())) {
                    fechas.push(new Date(d));
                }
            }

            // Calcular productos y unidades
            let totalUnidades = 0;
            let totalProductos = 0;

            if (tipoDistribucion === 'completo') {
                <?php foreach ($productos_con_existencia as $producto): ?>
                    totalUnidades += <?php echo $producto['existencia']; ?>;
                    totalProductos++;
                <?php endforeach; ?>
            } else {
                document.querySelectorAll('.producto-checkbox:checked').forEach(cb => {
                    const cantidadInput = cb.closest('.producto-row').querySelector('.cantidad-parcial');
                    const cantidad = parseInt(cantidadInput.value) || 0;
                    totalUnidades += cantidad;
                    totalProductos++;
                });
            }

            // Generar predicción
            const totalDias = fechas.length;
            const unidadesPorDia = Math.floor(totalUnidades / totalDias);
            const tablasPorDiaEstimadas = Math.max(1, Math.min(40, unidadesPorDia));

            let html = `
                <strong>📊 Análisis Predictivo:</strong><br>
                • <strong>Días de distribución:</strong> ${totalDias}<br>
                • <strong>Productos únicos:</strong> ${totalProductos}<br>
                • <strong>Total unidades:</strong> ${totalUnidades.toLocaleString()}<br>
                • <strong>Unidades por día:</strong> ~${unidadesPorDia}<br>
                • <strong>Tablas estimadas por día:</strong> ${tablasPorDiaEstimadas}<br>
                • <strong>Total tablas estimadas:</strong> ~${(tablasPorDiaEstimadas * totalDias).toLocaleString()}
            `;

            if (totalUnidades > 0 && totalDias > 0) {
                html += '<br><br><span class="badge bg-success">✅ Distribución factible</span>';
                document.getElementById('btnGenerarDistribucion').disabled = false;
            } else {
                html += '<br><br><span class="badge bg-danger">❌ Configuración inválida</span>';
                document.getElementById('btnGenerarDistribucion').disabled = true;
            }

            document.getElementById('contenido_prediccion').innerHTML = html;
            document.getElementById('prediccion_distribucion').style.display = 'block';
        }

        // Configuración inicial al cargar la página
        document.addEventListener('DOMContentLoaded', function() {
            const hoy = new Date();
            const fechaHoy = hoy.toISOString().split('T')[0];
            
            document.getElementById('fecha_inicio').value = fechaHoy;
            document.getElementById('fecha_fin').value = fechaHoy;
            document.getElementById('fecha_inicio').min = fechaHoy;
            document.getElementById('fecha_fin').min = fechaHoy;

            // Realizar validación inicial
            setTimeout(() => {
                validarFactibilidadEnTiempoReal();
            }, 1000);
        });

        // Actualizar fecha mínima de fin cuando cambia fecha de inicio
        document.getElementById('fecha_inicio').addEventListener('change', function() {
            const fechaInicio = this.value;
            const fechaFinInput = document.getElementById('fecha_fin');
            
            fechaFinInput.min = fechaInicio;
            if (fechaFinInput.value < fechaInicio) {
                fechaFinInput.value = fechaInicio;
            }
            validarFactibilidadEnTiempoReal();
        });

        // Actualizar validación cuando cambia fecha fin
        document.getElementById('fecha_fin').addEventListener('change', function() {
            validarFactibilidadEnTiempoReal();
        });

        // Actualizar validación cuando cambian días excluidos
        document.querySelectorAll('input[name="dias_exclusion[]"]').forEach(checkbox => {
            checkbox.addEventListener('change', validarFactibilidadEnTiempoReal);
        });

        // Mejorar experiencia del usuario con feedback visual en productos parciales
        document.querySelectorAll('.cantidad-parcial').forEach(input => {
            input.addEventListener('input', function() {
                const max = parseInt(this.max);
                const value = parseInt(this.value) || 0;
                
                if (value > max) {
                    this.value = max;
                    this.classList.add('is-invalid');
                    setTimeout(() => this.classList.remove('is-invalid'), 2000);
                } else if (value > 0) {
                    this.classList.add('is-valid');
                    setTimeout(() => this.classList.remove('is-valid'), 1000);
                }
                
                validarFactibilidadEnTiempoReal();
            });
        });

        // Habilitar/deshabilitar campos de cantidad cuando se selecciona producto
        document.querySelectorAll('.producto-checkbox').forEach(checkbox => {
            checkbox.addEventListener('change', function() {
                const cantidadInput = this.closest('.producto-row').querySelector('.cantidad-parcial');
                
                if (this.checked) {
                    cantidadInput.disabled = false;
                    cantidadInput.required = true;
                    cantidadInput.focus();
                    
                    // Asignar valor por defecto (la mitad del stock disponible, mínimo 1)
                    const max = parseInt(cantidadInput.max);
                    cantidadInput.value = Math.max(1, Math.floor(max / 2));
                } else {
                    cantidadInput.disabled = true;
                    cantidadInput.required = false;
                    cantidadInput.value = '';
                }
                
                validarFactibilidadEnTiempoReal();
            });
        });

        // Validación del formulario antes de envío
        document.getElementById('formDistribucion').addEventListener('submit', function(e) {
            const tipoDistribucion = document.querySelector('input[name="tipo_distribucion"]:checked').value;
            
            if (tipoDistribucion === 'parcial') {
                const productosSeleccionados = document.querySelectorAll('.producto-checkbox:checked');
                
                if (productosSeleccionados.length === 0) {
                    e.preventDefault();
                    alert('⚠️ Debe seleccionar al menos un producto para distribución parcial.');
                    return false;
                }
                
                // Validar que todos los productos seleccionados tengan cantidad válida
                let hayErrores = false;
                productosSeleccionados.forEach(checkbox => {
                    const cantidadInput = checkbox.closest('.producto-row').querySelector('.cantidad-parcial');
                    const cantidad = parseInt(cantidadInput.value) || 0;
                    const max = parseInt(cantidadInput.max);
                    
                    if (cantidad <= 0 || cantidad > max) {
                        cantidadInput.classList.add('is-invalid');
                        hayErrores = true;
                    }
                });
                
                if (hayErrores) {
                    e.preventDefault();
                    alert('⚠️ Verifique que todas las cantidades sean válidas (entre 1 y el stock disponible).');
                    return false;
                }
            }
            
            // Confirmar antes de generar
            const fechaInicio = document.getElementById('fecha_inicio').value;
            const fechaFin = document.getElementById('fecha_fin').value;
            
            if (!confirm(`¿Está seguro que desea generar la distribución desde ${fechaInicio} hasta ${fechaFin}?\n\nEsta acción modificará las existencias de los productos.`)) {
                e.preventDefault();
                return false;
            }
            
            // Mostrar indicador de carga
            const btnSubmit = document.getElementById('btnGenerarDistribucion');
            const textOriginal = btnSubmit.innerHTML;
            btnSubmit.innerHTML = '<span class="loading-spinner"></span> Generando...';
            btnSubmit.disabled = true;
            
            // Restaurar botón después de 30 segundos (timeout de seguridad)
            setTimeout(() => {
                btnSubmit.innerHTML = textOriginal;
                btnSubmit.disabled = false;
            }, 30000);
        });

        // Función para limpiar formulario
        function limpiarFormulario() {
            document.getElementById('formDistribucion').reset();
            document.getElementById('productos_seleccion').style.display = 'none';
            document.getElementById('prediccion_distribucion').style.display = 'none';
            
            // Deshabilitar todos los campos de cantidad
            document.querySelectorAll('.cantidad-parcial').forEach(input => {
                input.disabled = true;
                input.value = '';
                input.classList.remove('is-valid', 'is-invalid');
            });
            
            // Configurar fechas por defecto
            const hoy = new Date().toISOString().split('T')[0];
            document.getElementById('fecha_inicio').value = hoy;
            document.getElementById('fecha_fin').value = hoy;
        }

        // Limpiar formulario al abrir modal
        document.getElementById('modalNuevaDistribucion').addEventListener('show.bs.modal', function() {
            limpiarFormulario();
            setTimeout(validarFactibilidadEnTiempoReal, 500);
        });

        // Función para buscar productos en la lista (funcionalidad adicional)
        function agregarBuscadorProductos() {
            const productosDiv = document.getElementById('productos_seleccion');
            const buscadorHTML = `
                <div class="mb-3">
                    <input type="text" class="form-control" id="buscador_productos" 
                           placeholder="🔍 Buscar productos..." onkeyup="filtrarProductos()">
                </div>
            `;
            
            if (productosDiv && !document.getElementById('buscador_productos')) {
                productosDiv.insertAdjacentHTML('afterbegin', buscadorHTML);
            }
        }

        function filtrarProductos() {
            const termino = document.getElementById('buscador_productos').value.toLowerCase();
            const filas = document.querySelectorAll('.producto-row');
            
            filas.forEach(fila => {
                const descripcion = fila.querySelector('label').textContent.toLowerCase();
                if (descripcion.includes(termino)) {
                    fila.style.display = '';
                } else {
                    fila.style.display = 'none';
                }
            });
        }

        // Agregar buscador cuando se muestra la sección de productos
        document.getElementById('tipo_parcial').addEventListener('change', function() {
            if (this.checked) {
                setTimeout(agregarBuscadorProductos, 100);
            }
        });

        // Funciones auxiliares para mejorar UX
        function mostrarTooltip(elemento, mensaje) {
            elemento.setAttribute('title', mensaje);
            elemento.setAttribute('data-bs-toggle', 'tooltip');
            elemento.setAttribute('data-bs-placement', 'top');
            
            // Inicializar tooltip de Bootstrap si está disponible
            if (typeof bootstrap !== 'undefined') {
                new bootstrap.Tooltip(elemento);
            }
        }

        // Añadir tooltips informativos
        document.addEventListener('DOMContentLoaded', function() {
            // Tooltip para tipo de distribución
            mostrarTooltip(document.getElementById('tipo_completo'), 
                'Incluye todos los productos con existencia disponible');
            mostrarTooltip(document.getElementById('tipo_parcial'), 
                'Permite seleccionar productos específicos y cantidades');
            
            // Tooltip para días de exclusión
            document.querySelectorAll('input[name="dias_exclusion[]"]').forEach(checkbox => {
                mostrarTooltip(checkbox, 'Marcar para excluir este día de la distribución');
            });
        });

        // Función para exportar configuración (funcionalidad adicional)
        function exportarConfiguracion() {
            const config = {
                fecha_inicio: document.getElementById('fecha_inicio').value,
                fecha_fin: document.getElementById('fecha_fin').value,
                dias_exclusion: Array.from(document.querySelectorAll('input[name="dias_exclusion[]"]:checked')).map(cb => cb.value),
                tipo_distribucion: document.querySelector('input[name="tipo_distribucion"]:checked').value,
                timestamp: new Date().toISOString()
            };
            
            if (config.tipo_distribucion === 'parcial') {
                config.productos_seleccionados = [];
                document.querySelectorAll('.producto-checkbox:checked').forEach(cb => {
                    const cantidadInput = cb.closest('.producto-row').querySelector('.cantidad-parcial');
                    config.productos_seleccionados.push({
                        producto_id: cb.value,
                        cantidad: cantidadInput.value
                    });
                });
            }
            
            const dataStr = "data:text/json;charset=utf-8," + encodeURIComponent(JSON.stringify(config, null, 2));
            const downloadAnchorNode = document.createElement('a');
            downloadAnchorNode.setAttribute("href", dataStr);
            downloadAnchorNode.setAttribute("download", `configuracion_distribucion_${new Date().toISOString().split('T')[0]}.json`);
            document.body.appendChild(downloadAnchorNode);
            downloadAnchorNode.click();
            downloadAnchorNode.remove();
        }

        // Estadísticas en tiempo real
        function actualizarEstadisticas() {
            const totalProductos = <?php echo count($productos_con_existencia); ?>;
            const totalExistencias = <?php echo array_sum(array_column($productos_con_existencia, 'existencia')); ?>;
            const valorTotal = <?php 
                $valor_total = 0;
                foreach ($productos_con_existencia as $p) {
                    $valor_total += $p['existencia'] * $p['precio_venta'];
                }
                echo $valor_total;
            ?>;
            
            console.log(`📊 Estadísticas del Inventario:
            • Total productos: ${totalProductos.toLocaleString()}
            • Total unidades: ${totalExistencias.toLocaleString()}
            • Valor estimado: ${valorTotal.toLocaleString()}`);
        }

        // Ejecutar estadísticas al cargar
        actualizarEstadisticas();

        // Función para debugging (modo desarrollo)
        function debugDistribucion() {
            console.log('🔧 Modo Debug Activado');
            console.log('Productos disponibles:', <?php echo count($productos_con_existencia); ?>);
            console.log('Formulario:', document.getElementById('formDistribucion'));
            console.log('Validación activa:', validacionTimeout !== null);
        }

        // Shortcut para debug (Ctrl+Shift+D)
        document.addEventListener('keydown', function(e) {
            if (e.ctrlKey && e.shiftKey && e.key === 'D') {
                debugDistribucion();
            }
        });

        console.log('✅ Sistema de Distribuciones cargado correctamente');
    </script>
</body>
</html>