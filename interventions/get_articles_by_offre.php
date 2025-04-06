// get_articles_by_offre.php
<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

$offre_id = isset($_GET['offre_id']) ? (int)$_GET['offre_id'] : 0;

$database = new Database();
$db = $database->getConnection();

try {
    $query = "SELECT a.id, a.reference, a.designation, a.prix_vente_ht 
              FROM articles a
              INNER JOIN offre_article oa ON a.id = oa.article_id
              WHERE oa.offre_id = :offre_id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':offre_id', $offre_id, PDO::PARAM_INT);
    $stmt->execute();
    
    $articles = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(['success' => true, 'articles' => $articles]);
} catch (PDOException $e) {
    error_log('Erreur lors de la récupération des articles: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Erreur lors de la récupération des articles']);
}
?>
