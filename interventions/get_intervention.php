<?php
// Démarrer la session
session_start();

// Chemin racine de l'application
$root_path = dirname(__DIR__);

// Inclure les fichiers de configuration et de fonctions
if (file_exists($root_path . '/config/database.php')) {
    require_once $root_path . '/config/database.php';
}

// Vérifier si l'utilisateur est connecté
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}
// Vérifier si l'ID de l'intervention est fourni
if (!isset($_GET['id']) || empty($_GET['id'])) {
    echo json_encode(['success' => false, 'message' => 'ID d\'intervention non spécifié.']);
    exit;
}

$intervention_id = intval($_GET['id']);

try {
    // Connexion à la base de données
    $database = new Database();
    $db = $database->getConnection();
    
    // Récupérer les détails de l'intervention
    $query = "SELECT i.*, DATE(i.date_debut) AS date_debut,
                 DATE(i.date_fin) AS date_fin,
              v.marque, v.modele, v.immatriculation, 
              CONCAT(c.nom, ' ', c.prenom) AS client, c.id AS client_id,
              CONCAT(t.nom, ' ', t.prenom) AS technicien, t.specialite AS technicien_specialite,
              co.ID_Commande AS commande_id, co.date_creation AS commande_date
              FROM interventions i
              LEFT JOIN vehicules v ON i.vehicule_id = v.id
              LEFT JOIN clients c ON v.client_id = c.id
              LEFT JOIN technicien t ON i.technicien_id = t.id
              LEFT JOIN commandes co ON i.commande_id = co.ID_Commande
              WHERE i.id = :id";
    
    $stmt = $db->prepare($query);
    $stmt->bindParam(':id', $intervention_id);
    $stmt->execute();
    
    if ($stmt->rowCount() > 0) {
        $intervention = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Formater les informations du véhicule
        $intervention['vehicule_info'] = $intervention['marque'] . ' ' . $intervention['modele'];
        
        // Récupérer les articles associés à l'intervention
        $query = "SELECT ia.*, a.reference, a.designation
                  FROM interventions_articles ia
                  JOIN articles a ON ia.article_id = a.id
                  WHERE ia.intervention_id = :intervention_id";
        
        $stmt = $db->prepare($query);
        $stmt->bindParam(':intervention_id', $intervention_id);
        $stmt->execute();
        
        $articles = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $articles[] = [
                'id' => $row['article_id'],
                'reference' => $row['reference'],
                'designation' => $row['designation'],
                'quantite' => $row['quantite'],
                'prix_unitaire' => $row['prix_unitaire'],
                'remise' => $row['remise']
            ];
        }
        
        $intervention['articles'] = $articles;
        
        // Récupérer les offres associées à l'intervention
        $query = "SELECT io.*, o.code, o.nom
                  FROM interventions_offres io
                  JOIN offres o ON io.offre_id = o.id
                  WHERE io.intervention_id = :intervention_id";
        
        $stmt = $db->prepare($query);
        $stmt->bindParam(':intervention_id', $intervention_id);
        $stmt->execute();
        
        $offres = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $offres[] = [
                'id' => $row['offre_id'],
                'code' => $row['code'],
                'nom' => $row['nom'],
                'quantite' => $row['quantite'],
                'prix_unitaire' => $row['prix_unitaire'],
                'remise' => $row['remise']
            ];
        }
        
        $intervention['offres'] = $offres;
        
        echo json_encode(['success' => true, 'intervention' => $intervention]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Intervention non trouvée.']);
    }
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Erreur de base de données: ' . $e->getMessage()]);
}
?>