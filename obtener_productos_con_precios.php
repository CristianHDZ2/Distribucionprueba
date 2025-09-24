<?php
require_once 'config/database.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $database = new Database();
    $db = $database->getConnection();
    
    $proveedor = isset($_POST['proveedor']) ? trim($_POST['proveedor']) : '';
    
    if (empty($proveedor)) {
        echo json_encode([
            'success' => false,
            'message' => 'Proveedor no especificado'
        ]);
        exit;
    }
    
    // **CONSULTA PRINCIPAL: Obtener productos con información de precios**
    $stmt = $db->prepare("
        SELECT 
            p.id,
            p.descripcion,
            p.proveedor,
            p.existencia,
            p.precio_venta,
            p.ultimo_precio_compra,
            p.fecha_ultimo_precio_compra,
            -- Calcular estadísticas de precios de compras anteriores
            (SELECT AVG(di.precio_compra) 
             FROM detalle_ingresos di 
             INNER JOIN ingresos i ON di.ingreso_id = i.id 
             WHERE di.producto_id = p.id 
             AND i.proveedor = p.proveedor
            ) as precio_promedio,
            -- Contar total de compras históricas
            (SELECT COUNT(*) 
             FROM detalle_ingresos di 
             INNER JOIN ingresos i ON di.ingreso_id = i.id 
             WHERE di.producto_id = p.id 
             AND i.proveedor = p.proveedor
            ) as total_compras,
            -- Obtener el precio más reciente (por fecha)
            (SELECT di.precio_compra 
             FROM detalle_ingresos di 
             INNER JOIN ingresos i ON di.ingreso_id = i.id 
             WHERE di.producto_id = p.id 
             AND i.proveedor = p.proveedor
             ORDER BY i.fecha_ingreso DESC, i.fecha_creacion DESC 
             LIMIT 1
            ) as precio_mas_reciente,
            -- Obtener fecha del precio más reciente
            (SELECT i.fecha_ingreso 
             FROM detalle_ingresos di 
             INNER JOIN ingresos i ON di.ingreso_id = i.id 
             WHERE di.producto_id = p.id 
             AND i.proveedor = p.proveedor
             ORDER BY i.fecha_ingreso DESC, i.fecha_creacion DESC 
             LIMIT 1
            ) as fecha_precio_mas_reciente,
            -- Precio mínimo y máximo histórico
            (SELECT MIN(di.precio_compra) 
             FROM detalle_ingresos di 
             INNER JOIN ingresos i ON di.ingreso_id = i.id 
             WHERE di.producto_id = p.id 
             AND i.proveedor = p.proveedor
            ) as precio_minimo,
            (SELECT MAX(di.precio_compra) 
             FROM detalle_ingresos di 
             INNER JOIN ingresos i ON di.ingreso_id = i.id 
             WHERE di.producto_id = p.id 
             AND i.proveedor = p.proveedor
            ) as precio_maximo
        FROM productos p
        WHERE p.proveedor = ?
        ORDER BY p.descripcion ASC
    ");
    
    $stmt->execute([$proveedor]);
    $productos_raw = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // **PROCESAR Y ENRIQUECER DATOS**
    $productos_procesados = [];
    
    foreach ($productos_raw as $producto) {
        // Determinar el precio sugerido (prioridad: campo último_precio_compra, luego precio más reciente)
        $precio_sugerido = null;
        $fecha_precio_sugerido = null;
        
        if (!empty($producto['ultimo_precio_compra']) && $producto['ultimo_precio_compra'] > 0) {
            $precio_sugerido = $producto['ultimo_precio_compra'];
            $fecha_precio_sugerido = $producto['fecha_ultimo_precio_compra'];
        } elseif (!empty($producto['precio_mas_reciente']) && $producto['precio_mas_reciente'] > 0) {
            $precio_sugerido = $producto['precio_mas_reciente'];
            $fecha_precio_sugerido = $producto['fecha_precio_mas_reciente'];
        }
        
        // Calcular variación de precios
        $variacion_precios = null;
        if ($producto['precio_minimo'] && $producto['precio_maximo'] && $producto['precio_minimo'] > 0) {
            $variacion_precios = (($producto['precio_maximo'] - $producto['precio_minimo']) / $producto['precio_minimo']) * 100;
        }
        
        // Determinar estado del precio
        $estado_precio = 'sin_historial';
        if ($precio_sugerido && $precio_sugerido > 0) {
            if ($producto['total_compras'] >= 5) {
                $estado_precio = 'confiable'; // 5 o más compras
            } elseif ($producto['total_compras'] >= 2) {
                $estado_precio = 'intermedio'; // 2-4 compras
            } else {
                $estado_precio = 'limitado'; // 1 compra
            }
        }
        
        $productos_procesados[] = [
            'id' => $producto['id'],
            'descripcion' => $producto['descripcion'],
            'proveedor' => $producto['proveedor'],
            'existencia' => (int)$producto['existencia'],
            'precio_venta' => (float)$producto['precio_venta'],
            
            // **DATOS DE PRECIOS RECORDADOS**
            'ultimo_precio_compra' => $precio_sugerido ? (float)$precio_sugerido : null,
            'fecha_ultimo_precio_compra' => $fecha_precio_sugerido,
            'precio_promedio' => $producto['precio_promedio'] ? (float)$producto['precio_promedio'] : null,
            'total_compras' => (int)$producto['total_compras'],
            'precio_minimo' => $producto['precio_minimo'] ? (float)$producto['precio_minimo'] : null,
            'precio_maximo' => $producto['precio_maximo'] ? (float)$producto['precio_maximo'] : null,
            'variacion_precios' => $variacion_precios ? round($variacion_precios, 2) : null,
            'estado_precio' => $estado_precio,
            
            // **METADATOS ADICIONALES**
            'dias_desde_ultimo_precio' => $fecha_precio_sugerido ? 
                (new DateTime())->diff(new DateTime($fecha_precio_sugerido))->days : null,
            'recomendacion' => generarRecomendacionPrecio($producto, $precio_sugerido),
        ];
    }
    
    // **ESTADÍSTICAS GENERALES**
    $estadisticas = [
        'total_productos' => count($productos_procesados),
        'productos_con_historial' => count(array_filter($productos_procesados, function($p) {
            return $p['ultimo_precio_compra'] !== null;
        })),
        'productos_sin_historial' => count(array_filter($productos_procesados, function($p) {
            return $p['ultimo_precio_compra'] === null;
        })),
        'promedio_compras_por_producto' => count($productos_procesados) > 0 ? 
            round(array_sum(array_column($productos_procesados, 'total_compras')) / count($productos_procesados), 2) : 0
    ];
    
    echo json_encode([
        'success' => true,
        'message' => 'Productos cargados correctamente',
        'productos' => $productos_procesados,
        'estadisticas' => $estadisticas,
        'proveedor' => $proveedor
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
    
    error_log("Error en obtener_productos_con_precios.php: " . $e->getMessage());
}

// **FUNCIÓN AUXILIAR: Generar recomendación de precio**
function generarRecomendacionPrecio($producto, $precio_sugerido) {
    if (!$precio_sugerido || $precio_sugerido <= 0) {
        return [
            'tipo' => 'sin_historial',
            'mensaje' => 'Sin historial de precios - revisar precios de mercado',
            'confianza' => 0
        ];
    }
    
    $total_compras = (int)$producto['total_compras'];
    $dias_desde_ultimo = null;
    
    if (!empty($producto['fecha_precio_mas_reciente'])) {
        $fecha_ultimo = new DateTime($producto['fecha_precio_mas_reciente']);
        $dias_desde_ultimo = (new DateTime())->diff($fecha_ultimo)->days;
    }
    
    // Determinar confianza y mensaje basado en historial
    if ($total_compras >= 5) {
        $confianza = 90;
        $tipo = 'alta_confianza';
        $mensaje = 'Precio altamente confiable basado en ' . $total_compras . ' compras anteriores';
    } elseif ($total_compras >= 2) {
        $confianza = 70;
        $tipo = 'confianza_media';
        $mensaje = 'Precio confiable basado en ' . $total_compras . ' compras anteriores';
    } else {
        $confianza = 40;
        $tipo = 'confianza_baja';
        $mensaje = 'Precio basado en pocas compras - verificar con proveedor';
    }
    
    // Ajustar confianza según antigüedad
    if ($dias_desde_ultimo !== null) {
        if ($dias_desde_ultimo > 180) { // Más de 6 meses
            $confianza -= 20;
            $mensaje .= ' (Precio antiguo: ' . $dias_desde_ultimo . ' días)';
        } elseif ($dias_desde_ultimo > 90) { // Más de 3 meses
            $confianza -= 10;
            $mensaje .= ' (Precio de hace ' . $dias_desde_ultimo . ' días)';
        }
    }
    
    return [
        'tipo' => $tipo,
        'mensaje' => $mensaje,
        'confianza' => max(0, min(100, $confianza)), // Entre 0 y 100
        'dias_antiguedad' => $dias_desde_ultimo
    ];
}
?>