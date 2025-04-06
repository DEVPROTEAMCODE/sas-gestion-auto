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
require_once $root_path . '/includes/functions.php';

// Récupérer le format d'export
$format = isset($_GET['format']) ? $_GET['format'] : 'csv';

// Récupérer les filtres
$search = isset($_GET['search']) ? $_GET['search'] : '';
$categorie = isset($_GET['categorie']) ? $_GET['categorie'] : '';
$statut = isset($_GET['statut']) ? $_GET['statut'] : '';
$date_debut = isset($_GET['date_debut']) ? $_GET['date_debut'] : '';
$date_fin = isset($_GET['date_fin']) ? $_GET['date_fin'] : '';

try {
    $database = new Database();
    $db = $database->getConnection();
    
    // Construction de la requête avec filtres
    $whereClause = [];
    $params = [];

    if (!empty($search)) {
        $whereClause[] = "(o.code LIKE :search OR o.nom LIKE :search OR o.description LIKE :search)";
        $params[':search'] = "%$search%";
    }

    if (!empty($categorie)) {
        $whereClause[] = "o.categorie_id = :categorie";
        $params[':categorie'] = $categorie;
    }

    if ($statut !== '') {
        $whereClause[] = "o.actif = :statut";
        $params[':statut'] = $statut;
    }

    if (!empty($date_debut)) {
        $whereClause[] = "o.date_debut >= :date_debut";
        $params[':date_debut'] = $date_debut;
    }

    if (!empty($date_fin)) {
        $whereClause[] = "o.date_fin <= :date_fin";
        $params[':date_fin'] = $date_fin;
    }

    $whereString = !empty($whereClause) ? 'WHERE ' . implode(' AND ', $whereClause) : '';
    
    // Requête pour récupérer les données
    $query = "SELECT o.id, o.code, o.nom, c.nom as categorie_nom, o.date_debut, o.date_fin, 
                     o.type_remise, o.valeur_remise, o.actif, o.priorite, o.description, o.conditions,
                     o.date_creation, u.username as createur
              FROM offres o 
              LEFT JOIN categorie c ON o.categorie_id = c.id 
              LEFT JOIN users u ON o.createur_id = u.id 
              $whereString 
              ORDER BY o.date_creation DESC";
    
    $stmt = $db->prepare($query);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    
    $offres = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Préparer les données pour l'export
    $exportData = [];
    
    // En-têtes des colonnes
    $headers = [
        'ID', 'Code', 'Nom', 'Catégorie', 'Date début', 'Date fin', 
        'Type remise', 'Valeur remise', 'Statut', 'Priorité', 
        'Description', 'Conditions', 'Date création', 'Créateur'
    ];
    
    $exportData[] = $headers;
    
    // Données des offres
    foreach ($offres as $offre) {
        $row = [
            $offre['id'],
            $offre['code'],
            $offre['nom'],
            $offre['categorie_nom'] ?: 'Toutes',
            $offre['date_debut'],
            $offre['date_fin'] ?: 'Sans fin',
            $offre['type_remise'] === 'pourcentage' ? 'Pourcentage' : 'Montant fixe',
            $offre['valeur_remise'] . ($offre['type_remise'] === 'pourcentage' ? ' %' : ' DH'),
            $offre['actif'] ? 'Actif' : 'Inactif',
            $offre['priorite'],
            $offre['description'],
            $offre['conditions'],
            $offre['date_creation'],
            $offre['createur']
        ];
        
        $exportData[] = $row;
    }
    
    // Générer le fichier selon le format demandé
    $filename = 'export_offres_' . date('Y-m-d_H-i-s');
    
    if ($format === 'excel') {
        // Export Excel
        header('Content-Type: application/vnd.ms-excel');
        header('Content-Disposition: attachment; filename="' . $filename . '.xls"');
        header('Cache-Control: max-age=0');
        
        echo '<table border="1">';
        foreach ($exportData as $row) {
            echo '<tr>';
            foreach ($row as $cell) {
                echo '<td>' . htmlspecialchars($cell) . '</td>';
            }
            echo '</tr>';
        }
        echo '</table>';
    } else {
        // Export CSV par défaut
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
        
        $output = fopen('php://output', 'w');
        
        // Ajouter BOM pour UTF-8
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
        
        foreach ($exportData as $row) {
            fputcsv($output, $row);
        }
        
        fclose($output);
    }
    
    // Enregistrer l'action dans les logs
    logAction($db, $_SESSION['user_id'], 'Export', 'offres', null, 
              "Export des offres au format " . strtoupper($format));
    
} catch (PDOException $e) {
    // En cas d'erreur, rediriger avec un message d'erreur
    setFlashMessage('error', 'Erreur lors de l\'export: ' . $e->getMessage());
    header('Location: index.php');
    exit;
}

// Fonction pour enregistrer une action dans les logs
function logAction($db, $userId, $action, $entite, $entiteId, $details) {
    try {
        $query = "INSERT INTO logs (user_id, action, entite, entite_id, details, date_action, adresse_ip) 
                  VALUES (:userId, :action, :entite, :entiteId, :details, NOW(), :ip)";
        $stmt = $db->prepare($query);
        
        $ip = $_SERVER['REMOTE_ADDR'];
        
        $stmt->bindParam(':userId', $userId, PDO::PARAM_INT);
        $stmt->bindParam(':action', $action);
        $stmt->bindParam(':entite', $entite);
        $stmt->bindParam(':entiteId', $entiteId, PDO::PARAM_INT);
        $stmt->bindParam(':details', $details);
        $stmt->bindParam(':ip', $ip);
        
        $stmt->execute();
    } catch (PDOException $e) {
        // Log l'erreur mais continue l'exécution
        error_log('Erreur lors de l\'enregistrement du log: ' . $e->getMessage());
    }
}
?>