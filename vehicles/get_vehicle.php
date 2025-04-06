<?php
// Démarrer la session d'abord
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

// Vérifier si l'ID du véhicule est fourni
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'ID de véhicule non valide']);
    exit;
}

$vehicleId = (int)$_GET['id'];

try {
    $database = new Database();
    $db = $database->getConnection();
    
    // Récupérer les détails du véhicule
    $query = "SELECT v.*, c.nom, c.prenom, c.telephone, c.email, 
              CONCAT(c.nom, ' ', c.prenom) AS client 
              FROM vehicules v 
              LEFT JOIN clients c ON v.client_id = c.id 
              WHERE v.id = :id";
    
    $stmt = $db->prepare($query);
    $stmt->bindParam(':id', $vehicleId, PDO::PARAM_INT);
    $stmt->execute();
    
    if ($stmt->rowCount() > 0) {
        $vehicle = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Formater les dates pour l'affichage
        if (!empty($vehicle['date_achat'])) {
            $vehicle['date_achat'] = date('d/m/Y', strtotime($vehicle['date_achat']));
        }
        if (!empty($vehicle['date_derniere_revision'])) {
            $vehicle['date_derniere_revision'] = date('d/m/Y', strtotime($vehicle['date_derniere_revision']));
        }
        if (!empty($vehicle['date_prochain_ct'])) {
            $vehicle['date_prochain_ct'] = date('d/m/Y', strtotime($vehicle['date_prochain_ct']));
        }
        
        // Récupérer les interventions liées au véhicule
        $queryInterventions = "SELECT i.id, i.date_creation, i.date_debut, i.date_fin, i.description,
                              i.diagnostique, i.kilometrage, i.statut, i.commentaire,
                              CONCAT(t.prenom, ' ', t.nom) AS technicien
                              FROM interventions i
                              LEFT JOIN technicien t ON i.technicien_id = t.id
                              WHERE i.vehicule_id = :vehicule_id
                              ORDER BY i.date_creation DESC";
        
        $stmtInterventions = $db->prepare($queryInterventions);
        $stmtInterventions->bindParam(':vehicule_id', $vehicleId, PDO::PARAM_INT);
        $stmtInterventions->execute();
        
        $interventions = $stmtInterventions->fetchAll(PDO::FETCH_ASSOC);
        
        // Formater les dates pour les interventions
        foreach ($interventions as &$intervention) {
            if (!empty($intervention['date_creation'])) {
                $intervention['date_creation'] = date('d/m/Y', strtotime($intervention['date_creation']));
            }
            if (!empty($intervention['date_debut'])) {
                $intervention['date_debut'] = date('d/m/Y', strtotime($intervention['date_debut']));
            }
            if (!empty($intervention['date_fin'])) {
                $intervention['date_fin'] = date('d/m/Y', strtotime($intervention['date_fin']));
            }
        }
        
        $vehicle['interventions'] = $interventions;
        
        header('Content-Type: application/json');
        echo json_encode($vehicle);
    } else {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Véhicule non trouvé']);
    }
} catch (PDOException $e) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Erreur lors de la récupération des données: ' . $e->getMessage()]);
}
