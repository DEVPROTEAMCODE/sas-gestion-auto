<?php
// Démarrer la session
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

// Chemin racine de l'application
$root_path = dirname(__DIR__);

// Inclure les fichiers de configuration et de fonctions
if (file_exists($root_path . '/config/database.php')) {
    require_once $root_path . '/config/database.php';
}

// Vérifier si l'ID de l'offre est fourni
if (!isset($_GET['offre_id']) || empty($_GET['offre_id'])) {
    echo json_encode(['success' => false, 'message' => 'ID de l\'offre non fourni']);
    exit;
}

$offre_id = intval($_GET['offre_id']);

try {
    $database = new Database();
    $db = $database->getConnection();
    
    // Récupérer les articles associés à l'offre
    $query = "SELECT a.*, o.valeur_remise AS remise_specifique 
              FROM articles a
              JOIN offres_articles oa ON a.id = oa.article_id
              join offres o on oa.offre_id = o.id
              WHERE oa.offre_id = :offre_id";
    
    $stmt = $db->prepare($query);
    $stmt->bindParam(':offre_id', $offre_id, PDO::PARAM_INT);
    $stmt->execute();
    
    $articles = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(['success' => true, 'articles' => $articles]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Erreur lors de la récupération des articles: ' . $e->getMessage()]);
}
?>
