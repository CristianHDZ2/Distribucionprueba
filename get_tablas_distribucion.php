<?php
header('Content-Type: application/json');
require_once 'config/database.php';

if (!isset($_GET['id']) || empty($_GET['id'])) {
    echo json_encode(['success' => false, 'message' => 'ID de distribución no especificado']);
    exit;
}

try {
    $database = new Database();
    $db = $database->getConnection();
    
    $distribucion_id = $_GET['id'];
    
    // Obtener datos de la distribución
    $stmt_distribucion = $db->prepare("SELECT 
        d.*,
        DATE_FORMAT(d.fecha_inicio, '%d/%m/%Y') as fecha_inicio,
        DATE_FORMAT(d.fecha_fin, '%d/%m/%Y') as fecha_fin
    FROM distribuciones d WHERE d.id = ?");
    $stmt_distribucion->execute([$distribucion_id]);
    $distribucion = $stmt_distribucion->fetch();
    
    if (!$distribucion) {
        echo json_encode(['success' => false, 'message' => 'Distribución no encontrada']);
        exit;
    }
    
    // Obtener tablas de la distribución ordenadas por fecha y número de tabla
    $stmt_tablas = $db->prepare("SELECT 
        td.*,
        DATE_FORMAT(td.fecha_tabla, '%Y-%m-%d') as fecha_tabla,
        DATE_FORMAT(td.fecha_tabla, '%d/%m/%Y') as fecha_tabla_formato,
        DAYNAME(td.fecha_tabla) as dia_semana_ingles
    FROM tablas_distribucion td 
    WHERE td.distribucion_id = ? AND td.estado = 'activo'
    ORDER BY td.fecha_tabla, td.numero_tabla");
    $stmt_tablas->execute([$distribucion_id]);
    $tablas_raw = $stmt_tablas->fetchAll();
    
    // Traducir días de la semana
    $dias_traduccion = [
        'Sunday' => 'Domingo',
        'Monday' => 'Lunes',
        'Tuesday' => 'Martes',
        'Wednesday' => 'Miércoles',
        'Thursday' => 'Jueves',
        'Friday' => 'Viernes',
        'Saturday' => 'Sábado'
    ];
    
    // Obtener detalles de cada tabla y agregar información del día
    $tablas_con_detalles = [];
    $total_general = 0;
    
    foreach ($tablas_raw as $tabla) {
        $stmt_detalles = $db->prepare("SELECT 
            dtd.*,
            p.descripcion,
            p.proveedor
        FROM detalle_tablas_distribucion dtd 
        INNER JOIN productos p ON dtd.producto_id = p.id 
        WHERE dtd.tabla_id = ?
        ORDER BY p.descripcion");
        $stmt_detalles->execute([$tabla['id']]);
        $detalles = $stmt_detalles->fetchAll();
        
        // Agregar día de la semana en español
        $tabla['dia_semana'] = isset($dias_traduccion[$tabla['dia_semana_ingles']]) 
                              ? $dias_traduccion[$tabla['dia_semana_ingles']] 
                              : $tabla['dia_semana_ingles'];
        
        $tabla['detalles'] = $detalles;
        $tablas_con_detalles[] = $tabla;
        $total_general += $tabla['total_tabla'];
    }
    
    echo json_encode([
        'success' => true,
        'distribucion' => $distribucion,
        'tablas' => $tablas_con_detalles,
        'total_general' => $total_general
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error al obtener tablas de distribución: ' . $e->getMessage()
    ]);
}
?>