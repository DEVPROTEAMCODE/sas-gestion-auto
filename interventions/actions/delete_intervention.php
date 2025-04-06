<?php
// Démarrer la session
session_start();

// Chemin racine de l'application
$root_path = dirname(dirname(__DIR__));

// Inclure les fichiers de configuration et de fonctions
if (file_exists($root_path . '/config/database.php')) {
    require_once $root_path . '/config/database.php';
}

// Vérifier si l'utilisateur est connecté
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

// Vérifier si le formulaire a été soumis
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Récupérer l'ID de l'intervention
    $intervention_id = isset($_POST['id']) ? intval($_POST['id']) : 0;
    
    if (empty($intervention_id)) {
        $_SESSION['error'] = 'ID d\'intervention invalide.';
        header('Location: ../index.php');
        exit;
    }
    
    try {
        // Connexion à la base de données
        $database = new Database();
        $db = $database->getConnection();
        
        // Démarrer une transaction
        $db->beginTransaction();
        
        // Vérifier si l'intervention est liée à une commande
        $query = "SELECT commande_id FROM interventions WHERE id = :id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':id', $intervention_id);
        $stmt->execute();
        $commande_id = $stmt->fetchColumn();
        
        // Si l'intervention est liée à une commande, vérifier si la commande peut être supprimée
        if ($commande_id) {
            $query = "SELECT Statut_Commande FROM commandes WHERE id = :id";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':id', $commande_id);
            $stmt->execute();
            $statut_commande = $stmt->fetchColumn();
            
            // Si la commande n'est pas déjà facturée ou livrée, on peut la supprimer
            if ($statut_commande !== 'Facturée' && $statut_commande !== 'Livrée') {
                // Supprimer les articles et offres de la commande
                $query = "DELETE FROM commande_articles WHERE commande_id = :commande_id";
                $stmt = $db->prepare($query);
                $stmt->bindParam(':commande_id', $commande_id);
                $stmt->execute();
                
                $query = "DELETE FROM commande_offres WHERE commande_id = :commande_id";
                $stmt = $db->prepare($query);
                $stmt->bindParam(':commande_id', $commande_id);
                $stmt->execute();
                
                // Supprimer la commande
                $query = "DELETE FROM commandes WHERE id = :id";
                $stmt = $db->prepare($query);
                $stmt->bindParam(':id', $commande_id);
                $stmt->execute();
            } else {
                // Si la commande est facturée ou livrée, on ne peut pas la supprimer
                // Mais on peut dissocier l'intervention de la commande
                $query = "UPDATE interventions SET commande_id = NULL WHERE id = :id";
                $stmt = $db->prepare($query);
                $stmt->bindParam(':id', $intervention_id);
                $stmt->execute();
            }
        }
        
        // Supprimer les articles et offres de l'intervention
        $query = "DELETE FROM interventions_articles WHERE intervention_id = :intervention_id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':intervention_id', $intervention_id);
        $stmt->execute();
        
        $query = "DELETE FROM interventions_offres WHERE intervention_id = :intervention_id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':intervention_id', $intervention_id);
        $stmt->execute();
        
        // Supprimer l'intervention
        $query = "DELETE FROM interventions WHERE id = :id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':id', $intervention_id);
        
        if ($stmt->execute()) {
            // Valider la transaction
            $db->commit();
            $_SESSION['success'] = 'L\'intervention a été supprimée avec succès.';
        } else {
            // Annuler la transaction
            $db->rollBack();
            $_SESSION['error'] = 'Une erreur est survenue lors de la suppression de l\'intervention.';
        }
    } catch (PDOException $e) {
        // Annuler la transaction en cas d'erreur
        if (isset($db)) {
            $db->rollBack();
        }
        $_SESSION['error'] = 'Erreur de base de données: ' . $e->getMessage();
    }
    
    header('Location: ../index.php');
    exit;
} else {
    // Redirection si accès direct
    header('Location: ../index.php');
    exit;
}
?>