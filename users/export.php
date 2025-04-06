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

// Vérifier si l'utilisateur est connecté, sinon rediriger vers la page de connexion
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

// Vérifier si le type est fourni
if (!isset($_GET['type']) || empty($_GET['type'])) {
    $_SESSION['error'] = "Type d'exportation non spécifié";
    header('Location: index.php');
    exit;
}

$type = $_GET['type'];
$format = isset($_GET['format']) ? $_GET['format'] : 'csv';

// Valider le type
if (!in_array($type, ['technicien', 'client', 'admin', 'users'])) {
    $_SESSION['error'] = "Type d'exportation invalide";
    header('Location: index.php');
    exit;
}

// Valider le format
if (!in_array($format, ['csv', 'excel'])) {
    $format = 'csv';
}

try {
    // Connexion à la base de données
    $database = new Database();
    $db = $database->getConnection();
    
    $data = [];
    $filename = '';
    $headers = [];
    $fields = [];
    
    if ($type === 'technicien') {
        // Récupérer les données des techniciens
        $query = "SELECT id, nom, prenom, date_naissance, specialite, email, user_name 
                  FROM technicien 
                  ORDER BY nom, prenom";
        
        $stmt = $db->prepare($query);
        $stmt->execute();
        
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $filename = 'techniciens_' . date('Y-m-d_H-i-s');
        
        // Définir les en-têtes des colonnes
        $headers = [
            'ID', 'Nom', 'Prénom', 'Date de naissance', 'Spécialité', 'Email', 'Nom d\'utilisateur'
        ];
        $fields = ['id', 'nom', 'prenom', 'date_naissance', 'specialite', 'email', 'user_name'];
        
    } elseif ($type === 'client') {
        // Récupérer les données des clients
        $query = "SELECT id, nom, prenom, email, telephone, adresse 
                  FROM clients 
                  ORDER BY nom, prenom";
        
        $stmt = $db->prepare($query);
        $stmt->execute();
        
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $filename = 'clients_' . date('Y-m-d_H-i-s');
        
        // Définir les en-têtes des colonnes
        $headers = [
            'ID', 'Nom', 'Prénom', 'Email', 'Téléphone', 'Adresse'
        ];
        $fields = ['id', 'nom', 'prenom', 'email', 'telephone', 'adresse'];
        
    } elseif ($type === 'admin') {
        // Récupérer les données des administrateurs
        $query = "SELECT id, nom, prenom, email, role 
                  FROM administrateurs 
                  ORDER BY nom, prenom";
        
        $stmt = $db->prepare($query);
        $stmt->execute();
        
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $filename = 'administrateurs_' . date('Y-m-d_H-i-s');
        
        // Définir les en-têtes des colonnes
        $headers = [
            'ID', 'Nom', 'Prénom', 'Email', 'Rôle'
        ];
        $fields = ['id', 'nom', 'prenom', 'email', 'role'];
        
    } elseif ($type === 'users') {
        // Récupérer les données des utilisateurs génériques
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
    
    // Exporter les données au format CSV
    if ($format === 'csv') {
        // Définir les en-têtes HTTP pour le téléchargement
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
        
        // Créer un pointeur de fichier pour la sortie
        $output = fopen('php://output', 'w');
        
        // Ajouter le BOM UTF-8 pour Excel
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
        
        // Écrire les en-têtes des colonnes
        fputcsv($output, $headers, ';');
        
        // Écrire les données
        foreach ($data as $row) {
            $line = [];
            foreach ($fields as $field) {
                if (in_array($field, ['date_creation', 'derniere_connexion', 'date_naissance']) && !empty($row[$field])) {
                    $line[] = date('d/m/Y H:i', strtotime($row[$field]));
                } elseif ($field === 'actif') {
                    $line[] = isset($row[$field]) && $row[$field] ? 'Oui' : 'Non';
                } else {
                    $line[] = isset($row[$field]) ? $row[$field] : '';
                }
            }
            fputcsv($output, $line, ';');
        }
        
        fclose($output);
        exit;
    } elseif ($format === 'excel') {
        // Pour l'exportation Excel, vous auriez besoin d'une bibliothèque comme PhpSpreadsheet
        // Cet exemple redirige vers l'exportation CSV en attendant
        header('Location: export.php?type=' . $type . '&format=csv');
        exit;
    }
} catch (PDOException $e) {
    $_SESSION['error'] = "Erreur de base de données: " . $e->getMessage();
    header('Location: index.php');
    exit;
}
?>