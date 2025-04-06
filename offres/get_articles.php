<?php
// Démarrer la session
session_start();

// Vérifier si l'utilisateur est connecté
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

// Chemin racine de l'application
$root_path = dirname(__DIR__);

// Inclure les fichiers de configuration
require_once $root_path . '/config/database.php';

// Récupérer l'ID de catégorie s'il est fourni
$categorie_id = isset($_GET['categorie_id']) && is_numeric($_GET['categorie_id']) ? (int)$_GET['categorie_id'] : null;

try {
    $database = new Database();
    $db = $database->getConnection();
    
    // Construire la requête en fonction de la catégorie
    // Correction: utiliser prix_vente_ht au lieu de prix_vente
    $query = "SELECT id, reference, designation, prix_vente_ht as prix_vente FROM articles WHERE 1=1";
    $params = [];
    
    if ($categorie_id) {
        // Correction: utiliser categorie au lieu de categorie_id (selon votre structure de table)
        $query .= " AND categorie_id = :categorie_id";
        $params[':categorie_id'] = $categorie_id;
    }
    
    $query .= " ORDER BY reference";
    
    $stmt = $db->prepare($query);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    
    $articles = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Retourner les données au format JSON
    header('Content-Type: application/json');
    echo json_encode(['articles' => $articles]);
    
} catch (PDOException $e) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Erreur de base de données: ' . $e->getMessage()]);
    exit;
}
?>