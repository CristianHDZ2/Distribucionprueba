<?php
header('Content-Type: application/json');
require_once 'config/database.php';

if (!isset($_GET['proveedor']) || empty($_GET['proveedor'])) {
    echo json_encode(['success' => false, 'message' => 'Proveedor no especificado']);
    exit;
}

try {
    $database = new Database();
    $db = $database->getConnection();
    
    $proveedor = $_GET['proveedor'];
    
    $stmt = $db->prepare("SELECT id, descripcion, existencia, precio_venta FROM productos WHERE proveedor = ? ORDER BY descripcion");
    $stmt->execute([$proveedor]);
    $productos = $stmt->fetchAll();
    
    echo json_encode([
        'success' => true,
        'productos' => $productos
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error al obtener productos: ' . $e->getMessage()
    ]);
}
?>