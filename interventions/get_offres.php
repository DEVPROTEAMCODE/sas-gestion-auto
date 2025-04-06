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

try {
    $database = new Database();
    $db = $database->getConnection();
    
    // Récupérer l'ID de la catégorie depuis la requête
    $categorie_id = isset($_GET['categorie_id']) ? (int)$_GET['categorie_id'] : 0;
    
    // Construire la requête SQL pour récupérer les offres actives pour cette catégorie
    $query = "SELECT o.id, o.code, o.nom, o.description, o.date_debut, o.date_fin, 
              o.type_remise, o.valeur_remise, o.actif, o.priorite, 
              COUNT(oa.article_id) as nombre_articles,
              CASE 
                  WHEN o.type_remise = 'pourcentage' THEN CONCAT(o.valeur_remise, '%')
                  ELSE CONCAT(o.valeur_remise, ' DH')
              END as remise_formatee
              FROM offres o
              LEFT JOIN offres_articles oa ON o.id = oa.offre_id
              WHERE (o.categorie_id = :categorie_id OR :categorie_id = 0) 
              AND o.actif = 1
              AND (o.date_fin IS NULL OR o.date_fin >= CURDATE())
              GROUP BY o.id
              ORDER BY o.priorite DESC, o.date_debut DESC";
    
    $stmt = $db->prepare($query);
    $stmt->bindParam(':categorie_id', $categorie_id, PDO::PARAM_INT);
    $stmt->execute();
    
    $offres = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Pour chaque offre, calculer le prix moyen des articles
    foreach ($offres as &$offre) {
        // Récupérer le prix moyen des articles de l'offre
        $queryPrixMoyen = "SELECT AVG(a.prix_vente_ht) as prix_moyen
                        FROM articles a
                        JOIN offres_articles oa ON a.id = oa.article_id
                        WHERE oa.offre_id = :offre_id";
        $stmtPrixMoyen = $db->prepare($queryPrixMoyen);
        $stmtPrixMoyen->bindParam(':offre_id', $offre['id'], PDO::PARAM_INT);
        $stmtPrixMoyen->execute();
        
        $resultat = $stmtPrixMoyen->fetch(PDO::FETCH_ASSOC);
        $offre['prix_moyen'] = $resultat['prix_moyen'] ? round($resultat['prix_moyen'], 2) : 0;
        
        // Calculer le prix moyen après remise
        if ($offre['type_remise'] === 'pourcentage') {
            $offre['prix_moyen_apres_remise'] = round($offre['prix_moyen'] * (1 - $offre['valeur_remise'] / 100), 2);
        } else {
            $offre['prix_moyen_apres_remise'] = max(0, $offre['prix_moyen'] - $offre['valeur_remise']);
        }
    }
    
    // Retourner les données au format JSON
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'offres' => $offres
    ]);
    
} catch (PDOException $e) {
    // En cas d'erreur, retourner un message d'erreur
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => 'Erreur de base de données: ' . $e->getMessage()
    ]);
    exit;
}
?>
