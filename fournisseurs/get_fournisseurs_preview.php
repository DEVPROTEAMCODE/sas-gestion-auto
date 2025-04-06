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
// Filtres
$search = isset($_GET['search']) ? $_GET['search'] : '';
$status = isset($_GET['status']) ? $_GET['status'] : '';
$delai = isset($_GET['delai']) ? $_GET['delai'] : '';

// Construction de la requête avec filtres
$whereClause = [];
$params = [];

if (!empty($search)) {
    $whereClause[] = "(Code_Fournisseur LIKE :search OR Raison_Sociale LIKE :search OR Ville LIKE :search)";
    $params[':search'] = "%$search%";
}

if ($status !== '') {
    $whereClause[] = "Actif = :status";
    $params[':status'] = $status;
}

if (!empty($delai)) {
    switch ($delai) {
        case 'less5':
            $whereClause[] = "Delai_Livraison_Moyen < 5";
            break;
        case '5to10':
            $whereClause[] = "Delai_Livraison_Moyen BETWEEN 5 AND 10";
            break;
        case 'more10':
            $whereClause[] = "Delai_Livraison_Moyen > 10";
            break;
    }
}

$whereString = !empty($whereClause) ? 'WHERE ' . implode(' AND ', $whereClause) : '';

// Récupération des fournisseurs pour l'aperçu (limité à 10)
$fournisseurs = [];

try {
    $database = new Database();
    $db = $database->getConnection();
    
    $query = "SELECT * FROM fournisseurs $whereString ORDER BY Raison_Sociale LIMIT 10";
    $stmt = $db->prepare($query);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    
    if ($stmt->rowCount() > 0) {
        $fournisseurs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Retourner les résultats en JSON
    header('Content-Type: application/json');
    echo json_encode(['fournisseurs' => $fournisseurs]);
    
} catch (PDOException $e) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Erreur lors de la récupération des données: ' . $e->getMessage()]);
}
