<?php
require_once 'config/database.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $database = new Database();
    $db = $database->getConnection();
    
    $ingreso_id = isset($_POST['ingreso_id']) ? (int)$_POST['ingreso_id'] : 0;
    
    if (!$ingreso_id) {
        echo json_encode([
            'success' => false,
            'message' => 'ID de ingreso no válido'
        ]);
        exit;
    }
    
    // **OBTENER INFORMACIÓN DEL INGRESO**
    $stmt_ingreso = $db->prepare("SELECT * FROM ingresos WHERE id = ?");
    $stmt_ingreso->execute([$ingreso_id]);
    $ingreso = $stmt_ingreso->fetch(PDO::FETCH_ASSOC);
    
    if (!$ingreso) {
        echo json_encode([
            'success' => false,
            'message' => 'Ingreso no encontrado'
        ]);
        exit;
    }
    
    // **OBTENER DETALLES DEL INGRESO CON INFORMACIÓN DE PRODUCTOS**
    $stmt_detalles = $db->prepare("
        SELECT 
            di.id,
            di.cantidad,
            di.precio_compra,
            di.subtotal,
            p.descripcion,
            p.proveedor,
            p.existencia as existencia_actual,
            p.precio_venta,
            -- Calcular margen de ganancia
            CASE 
                WHEN di.precio_compra > 0 AND p.precio_venta > 0 
                THEN ROUND((((p.precio_venta - di.precio_compra) / di.precio_compra) * 100), 2)
                ELSE NULL 
            END as margen_ganancia_porcentaje,
            -- Valor del inventario de este producto
            (di.cantidad * p.precio_venta) as valor_inventario_producto
        FROM detalle_ingresos di
        INNER JOIN productos p ON di.producto_id = p.id
        WHERE di.ingreso_id = ?
        ORDER BY p.descripcion ASC
    ");
    $stmt_detalles->execute([$ingreso_id]);
    $detalles = $stmt_detalles->fetchAll(PDO::FETCH_ASSOC);
    
    // **CALCULAR ESTADÍSTICAS DEL INGRESO**
    $total_productos_distintos = count($detalles);
    $total_unidades_ingresadas = array_sum(array_column($detalles, 'cantidad'));
    $costo_total = array_sum(array_column($detalles, 'subtotal'));
    $valor_inventario_total = array_sum(array_column($detalles, 'valor_inventario_producto'));
    $ganancia_potencial_total = $valor_inventario_total - $costo_total;
    $margen_promedio = $costo_total > 0 ? (($ganancia_potencial_total / $costo_total) * 100) : 0;
    
    // **OBTENER HISTORIAL DE PRECIOS PARA COMPARACIÓN**
    $productos_con_historial = [];
    foreach ($detalles as $detalle) {
        $stmt_historial = $db->prepare("
            SELECT 
                di.precio_compra,
                i.fecha_ingreso,
                i.numero_factura
            FROM detalle_ingresos di
            INNER JOIN ingresos i ON di.ingreso_id = i.id
            INNER JOIN productos p ON di.producto_id = p.id
            WHERE p.descripcion = ? 
            AND p.proveedor = ?
            AND i.id != ?
            ORDER BY i.fecha_ingreso DESC
            LIMIT 5
        ");
        $stmt_historial->execute([
            $detalle['descripcion'], 
            $detalle['proveedor'], 
            $ingreso_id
        ]);
        $historial_precios = $stmt_historial->fetchAll(PDO::FETCH_ASSOC);
        
        $productos_con_historial[] = array_merge($detalle, [
            'historial_precios' => $historial_precios,
            'tiene_historial' => count($historial_precios) > 0
        ]);
    }
    
    // **ANÁLISIS DE VARIACIÓN DE PRECIOS**
    $alertas_precios = [];
    foreach ($productos_con_historial as $producto) {
        if ($producto['tiene_historial']) {
            $precio_actual = (float)$producto['precio_compra'];
            $precio_anterior = (float)$producto['historial_precios'][0]['precio_compra'];
            
            if ($precio_anterior > 0) {
                $variacion = (($precio_actual - $precio_anterior) / $precio_anterior) * 100;
                
                if (abs($variacion) > 20) { // Cambio mayor al 20%
                    $alertas_precios[] = [
                        'producto' => $producto['descripcion'],
                        'precio_actual' => $precio_actual,
                        'precio_anterior' => $precio_anterior,
                        'variacion' => round($variacion, 2),
                        'tipo' => $variacion > 0 ? 'aumento' : 'disminucion'
                    ];
                }
            }
        }
    }
    
    // **COMPARACIÓN CON INGRESOS SIMILARES**
    $stmt_comparacion = $db->prepare("
        SELECT 
            AVG(i.total_factura) as promedio_factura,
            COUNT(*) as total_ingresos_similares
        FROM ingresos i
        WHERE i.proveedor = ? 
        AND i.id != ?
        AND i.fecha_ingreso >= DATE_SUB(?, INTERVAL 6 MONTH)
    ");
    $stmt_comparacion->execute([
        $ingreso['proveedor'], 
        $ingreso_id, 
        $ingreso['fecha_ingreso']
    ]);
    $comparacion = $stmt_comparacion->fetch(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'message' => 'Detalle cargado correctamente',
        'ingreso' => $ingreso,
        'detalles' => $productos_con_historial,
        'estadisticas' => [
            'total_productos_distintos' => $total_productos_distintos,
            'total_unidades_ingresadas' => $total_unidades_ingresadas,
            'costo_total' => round($costo_total, 2),
            'valor_inventario_total' => round($valor_inventario_total, 2),
            'ganancia_potencial_total' => round($ganancia_potencial_total, 2),
            'margen_promedio' => round($margen_promedio, 2)
        ],
        'alertas_precios' => $alertas_precios,
        'comparacion' => [
            'promedio_factura_6_meses' => $comparacion['promedio_factura'] ? round($comparacion['promedio_factura'], 2) : null,
            'total_ingresos_similares' => (int)$comparacion['total_ingresos_similares'],
            'variacion_vs_promedio' => $comparacion['promedio_factura'] && $comparacion['promedio_factura'] > 0 ? 
                round((($costo_total - $comparacion['promedio_factura']) / $comparacion['promedio_factura']) * 100, 2) : null
        ]
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error interno del servidor: ' . $e->getMessage(),
        'error_details' => [
            'file' => $e->getFile(),
            'line' => $e->getLine()
        ]
    ], JSON_UNESCAPED_UNICODE);
    
    error_log("Error en obtener_detalle_ingreso.php: " . $e->getMessage());
}
?>