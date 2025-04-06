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

// Vérifier si l'ID est fourni
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'ID d\'offre non valide']);
    exit;
}

$id = (int)$_GET['id'];

try {
    $database = new Database();
    $db = $database->getConnection();
    
    // Récupérer les informations de l'offre
    $query = "SELECT o.*, c.nom as categorie_nom 
              FROM offres o 
              LEFT JOIN categorie c ON o.categorie_id = c.id 
              WHERE o.id = :id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':id', $id, PDO::PARAM_INT);
    $stmt->execute();
    
    if ($stmt->rowCount() === 0) {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Offre non trouvée']);
        exit;
    }
    
    $offre = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Récupérer les articles liés à cette offre
    // Correction: utiliser prix_vente_ht au lieu de prix_vente
    $queryArticles = "SELECT a.id, a.reference, a.designation, a.prix_vente_ht as prix_vente, oa.remise_specifique 
                     FROM offres_articles oa
                     JOIN articles a ON oa.article_id = a.id
                     WHERE oa.offre_id = :id
                     ORDER BY a.reference";
    $stmtArticles = $db->prepare($queryArticles);
    $stmtArticles->bindParam(':id', $id, PDO::PARAM_INT);
    $stmtArticles->execute();
    
    $articles = $stmtArticles->fetchAll(PDO::FETCH_ASSOC);
    
    // Retourner les données au format JSON
    header('Content-Type: application/json');
    echo json_encode([
        'offre' => $offre,
        'articles' => $articles
    ]);
    
} catch (PDOException $e) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Erreur de base de données: ' . $e->getMessage()]);
    exit;
}
?>