<?php
header('Content-Type: application/json');
require_once 'config/database.php';

if (!isset($_GET['id']) || empty($_GET['id'])) {
    echo json_encode(['success' => false, 'message' => 'ID de ingreso no especificado']);
    exit;
}

try {
    $database = new Database();
    $db = $database->getConnection();
    
    $ingreso_id = $_GET['id'];
    
    // Obtener datos del ingreso
    $stmt_ingreso = $db->prepare("SELECT 
        i.*,
        DATE_FORMAT(i.fecha_ingreso, '%d/%m/%Y') as fecha_ingreso,
        DATE_FORMAT(i.fecha_creacion, '%d/%m/%Y %H:%i') as fecha_creacion
    FROM ingresos i WHERE i.id = ?");
    $stmt_ingreso->execute([$ingreso_id]);
    $ingreso = $stmt_ingreso->fetch();
    
    if (!$ingreso) {
        echo json_encode(['success' => false, 'message' => 'Ingreso no encontrado']);
        exit;
    }
    
    // Obtener detalles del ingreso
    $stmt_detalles = $db->prepare("SELECT 
        di.*,
        p.descripcion,
        p.proveedor
    FROM detalle_ingresos di 
    INNER JOIN productos p ON di.producto_id = p.id 
    WHERE di.ingreso_id = ?
    ORDER BY p.descripcion");
    $stmt_detalles->execute([$ingreso_id]);
    $detalles = $stmt_detalles->fetchAll();
    
    echo json_encode([
        'success' => true,
        'ingreso' => $ingreso,
        'detalles' => $detalles
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error al obtener detalle del ingreso: ' . $e->getMessage()
    ]);
}
?>