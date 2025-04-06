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
    echo json_encode(['error' => 'ID de fournisseur non valide']);
    exit;
}

$id = (int)$_GET['id'];

try {
    $database = new Database();
    $db = $database->getConnection();
    
    // Récupérer les informations du fournisseur
    $query = "SELECT * FROM fournisseurs WHERE ID_Fournisseur = :id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':id', $id, PDO::PARAM_INT);
    $stmt->execute();
    
    if ($stmt->rowCount() === 0) {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Fournisseur non trouvé']);
        exit;
    }
    
    $fournisseur = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Récupérer les commandes récentes de ce fournisseur
    $queryCommandes = "SELECT Numero_Commande, Date_Commande, Montant_Total_TTC, Statut_Commande 
                      FROM commandes 
                      WHERE ID_Fournisseur = :id 
                      ORDER BY Date_Commande DESC 
                      LIMIT 5";
    $stmtCommandes = $db->prepare($queryCommandes);
    $stmtCommandes->bindParam(':id', $id, PDO::PARAM_INT);
    $stmtCommandes->execute();
    
    $commandes = $stmtCommandes->fetchAll(PDO::FETCH_ASSOC);
    
    // Retourner les données au format JSON
    header('Content-Type: application/json');
    echo json_encode([
        'fournisseur' => $fournisseur,
        'commandes' => $commandes
    ]);
    
} catch (PDOException $e) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Erreur de base de données: ' . $e->getMessage()]);
    exit;
}
