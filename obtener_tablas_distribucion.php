<?php
require_once 'config/database.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $database = new Database();
    $db = $database->getConnection();
    
    $distribucion_id = isset($_GET['distribucion_id']) ? (int)$_GET['distribucion_id'] : 0;
    
    if (!$distribucion_id) {
        echo json_encode([
            'success' => false,
            'message' => 'ID de distribución no válido'
        ]);
        exit;
    }
    
    // Verificar que la distribución existe
    $stmt_distribucion = $db->prepare("SELECT * FROM distribuciones WHERE id = ? AND estado = 'activo'");
    $stmt_distribucion->execute([$distribucion_id]);
    $distribucion = $stmt_distribucion->fetch();
    
    if (!$distribucion) {
        echo json_encode([
            'success' => false,
            'message' => 'Distribución no encontrada o eliminada'
        ]);
        exit;
    }
    
    // **OBTENER TODAS LAS TABLAS DE LA DISTRIBUCIÓN ORGANIZADAS POR FECHA**
    $stmt_tablas = $db->prepare("
        SELECT 
            td.id,
            td.fecha_tabla,
            td.numero_tabla,
            td.total_tabla,
            DATE(td.fecha_tabla) as fecha_solo
        FROM tablas_distribucion td 
        WHERE td.distribucion_id = ? AND td.estado = 'activo'
        ORDER BY td.fecha_tabla ASC, td.numero_tabla ASC
    ");
    $stmt_tablas->execute([$distribucion_id]);
    $tablas = $stmt_tablas->fetchAll();
    
    if (empty($tablas)) {
        echo json_encode([
            'success' => false,
            'message' => 'No se encontraron tablas para esta distribución'
        ]);
        exit;
    }
    
    // **ORGANIZAR TABLAS POR FECHA Y OBTENER DETALLES**
    $tablas_por_dia = [];
    
    foreach ($tablas as $tabla) {
        $fecha_solo = $tabla['fecha_solo'];
        
        // Inicializar el día si no existe
        if (!isset($tablas_por_dia[$fecha_solo])) {
            $tablas_por_dia[$fecha_solo] = [
                'fecha' => $fecha_solo,
                'fecha_formateada' => date('d/m/Y', strtotime($fecha_solo)),
                'dia_nombre' => date('l', strtotime($fecha_solo)),
                'tablasDelDia' => []
            ];
        }
        
        // **OBTENER DETALLES DE CADA TABLA**
        $stmt_detalles = $db->prepare("
            SELECT 
                dtd.cantidad,
                dtd.precio_venta,
                dtd.subtotal,
                p.descripcion,
                p.proveedor
            FROM detalle_tablas_distribucion dtd
            INNER JOIN productos p ON dtd.producto_id = p.id
            WHERE dtd.tabla_id = ?
            ORDER BY p.proveedor, p.descripcion
        ");
        $stmt_detalles->execute([$tabla['id']]);
        $detalles = $stmt_detalles->fetchAll();
        
        // Agregar la tabla con sus detalles al día correspondiente
        $tablas_por_dia[$fecha_solo]['tablasDelDia'][] = [
            'id' => $tabla['id'],
            'numero_tabla' => $tabla['numero_tabla'],
            'total_tabla' => $tabla['total_tabla'],
            'fecha_tabla' => $tabla['fecha_tabla'],
            'detalles' => $detalles
        ];
    }
    
    // **CALCULAR ESTADÍSTICAS ADICIONALES**
    $total_tablas = count($tablas);
    $total_dias = count($tablas_por_dia);
    $total_monto_general = array_sum(array_column($tablas, 'total_tabla'));
    
    // Contar total de productos distribuidos
    $stmt_total_productos = $db->prepare("
        SELECT SUM(dtd.cantidad) as total_productos
        FROM detalle_tablas_distribucion dtd
        INNER JOIN tablas_distribucion td ON dtd.tabla_id = td.id
        WHERE td.distribucion_id = ? AND td.estado = 'activo'
    ");
    $stmt_total_productos->execute([$distribucion_id]);
    $resultado_productos = $stmt_total_productos->fetch();
    $total_productos_distribuidos = $resultado_productos['total_productos'] ?: 0;
    
    // **RESPUESTA EXITOSA CON TODOS LOS DATOS**
    echo json_encode([
        'success' => true,
        'message' => 'Tablas cargadas correctamente',
        'tablas_por_dia' => $tablas_por_dia,
        'estadisticas' => [
            'total_tablas' => $total_tablas,
            'total_dias' => $total_dias,
            'total_monto_general' => $total_monto_general,
            'total_productos_distribuidos' => $total_productos_distribuidos,
            'promedio_tablas_por_dia' => round($total_tablas / max(1, $total_dias), 2),
            'promedio_monto_por_tabla' => round($total_monto_general / max(1, $total_tablas), 2)
        ],
        'distribucion_info' => [
            'id' => $distribucion['id'],
            'fecha_inicio' => $distribucion['fecha_inicio'],
            'fecha_fin' => $distribucion['fecha_fin'],
            'tipo_distribucion' => $distribucion['tipo_distribucion'],
            'fecha_creacion' => $distribucion['fecha_creacion']
        ]
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Exception $e) {
    // **MANEJO DE ERRORES**
    echo json_encode([
        'success' => false,
        'message' => 'Error interno del servidor: ' . $e->getMessage(),
        'error_details' => [
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString()
        ]
    ], JSON_UNESCAPED_UNICODE);
    
    // Log del error para debugging
    error_log("Error en obtener_tablas_distribucion.php: " . $e->getMessage());
}
?>