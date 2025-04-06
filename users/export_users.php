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

// Vérifier le type d'exportation demandé
$type = isset($_GET['type']) ? $_GET['type'] : '';
$format = isset($_GET['format']) ? $_GET['format'] : 'csv';

if ($type !== 'users') {
    $_SESSION['error'] = "Type d'exportation non valide.";
    header('Location: index.php');
    exit;
}

try {
    // Connexion à la base de données
    $database = new Database();
    $db = $database->getConnection();
    
    // Récupérer les données selon le type
    if ($type === 'users') {
        $query = "SELECT id, username, nom, prenom, email, role, date_creation, derniere_connexion, actif
                  FROM users
                  ORDER BY id";
        
        $stmt = $db->prepare($query);
        $stmt->execute();
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $filename = 'utilisateurs_' . date('Y-m-d_H-i-s');
        $headers = ['ID', 'Nom d\'utilisateur', 'Nom', 'Prénom', 'Email', 'Rôle', 'Date de création', 'Dernière connexion', 'Actif'];
        $fields = ['id', 'username', 'nom', 'prenom', 'email', 'role', 'date_creation', 'derniere_connexion', 'actif'];
    }
    
    // Exporter les données au format demandé
    if ($format === 'csv') {
        // Définir les en-têtes HTTP pour le téléchargement
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
        
        // Ouvrir le flux de sortie
        $output = fopen('php://output', 'w');
        
        // Ajouter le BOM UTF-8 pour Excel
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
        
        // Écrire l'en-tête
        fputcsv($output, $headers, ';');
        
        // Écrire les données
        foreach ($data as $row) {
            $line = [];
            foreach ($fields as $field) {
                if ($field === 'date_creation' || $field === 'derniere_connexion') {
                    $line[] = $row[$field] ? date('d/m/Y H:i', strtotime($row[$field])) : '';
                } elseif ($field === 'actif') {
                    $line[] = $row[$field] ? 'Oui' : 'Non';
                } else {
                    $line[] = $row[$field];
                }
            }
            fputcsv($output, $line, ';');
        }
        
        fclose($output);
        exit;
    } elseif ($format === 'excel') {
        // Pour une exportation Excel plus avancée, vous pourriez utiliser une bibliothèque comme PhpSpreadsheet
        // Pour cet exemple, nous redirigeons vers l'exportation CSV
        header('Location: export.php?type=' . $type . '&format=csv');
        exit;
    } else {
        $_SESSION['error'] = "Format d'exportation non valide.";
        header('Location: index.php?type=' . $type);
        exit;
    }
    
} catch (PDOException $e) {
    $_SESSION['error'] = "Erreur de base de données: " . $e->getMessage();
    header('Location: index.php?type=' . $type);
    exit;
}
?>
