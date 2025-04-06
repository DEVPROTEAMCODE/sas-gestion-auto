<?php
// Démarrer la session
session_start();

// Chemin racine de l'application
$root_path = dirname(__DIR__);

// Inclure les fichiers de configuration et de fonctions
if (file_exists($root_path . '/config/database.php')) {
    require_once $root_path . '/config/database.php';
}

if (file_exists($root_path . '/includes/functions.php')) {
    require_once $root_path . '/includes/functions.php';
}

// Vérifier si l'utilisateur est connecté
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

// Vérifier si l'ID de la commande est fourni
if (!isset($_POST['id']) || empty($_POST['id']) || !is_numeric($_POST['id'])) {
    $_SESSION['error'] = "ID de commande invalide.";
    header('Location: index.php');
    exit;
}

$id_commande = intval($_POST['id']);

// Initialiser la connexion à la base de données
$database = new Database();
$db = $database->getConnection();

try {
    // Commencer une transaction pour garantir l'intégrité des données
    $db->beginTransaction();
    
    // 1. D'abord, supprimer les détails de la commande (lignes de commande)
    $query = "DELETE FROM commande_details WHERE ID_Commande = :id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':id', $id_commande, PDO::PARAM_INT);
    $stmt->execute();
    
    // 2. Ensuite, supprimer la commande elle-même
    $query = "DELETE FROM commandes WHERE ID_Commande = :id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':id', $id_commande, PDO::PARAM_INT);
    $stmt->execute();
    
    // Valider la transaction
    $db->commit();
    
    // Rediriger avec un message de succès
    $_SESSION['success'] = "La commande a été supprimée avec succès.";
    header('Location: index.php');
    exit;
    
} catch (PDOException $e) {
    // En cas d'erreur, annuler la transaction
    $db->rollBack();
    
    // Rediriger avec un message d'erreur
    $_SESSION['error'] = "Erreur lors de la suppression de la commande : " . $e->getMessage();
    header('Location: index.php');
    exit;
}
?>
