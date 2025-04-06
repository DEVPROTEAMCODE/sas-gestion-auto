<?php

session_start();

$root_path = dirname(__DIR__);

// Inclure les fichiers de configuration et de fonctions

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

if (file_exists($root_path . '/config/database.php')) {
    require_once $root_path . '/config/database.php';
}

if (file_exists($root_path . '/includes/functions.php')) {
    require_once $root_path . '/includes/functions.php';
}

if (isset($_GET['id']) && is_numeric($_GET['id'])) {
    $vehicle_id = intval($_GET['id']);

    try {
        $database = new Database();
        $db = $database->getConnection();

        $query = "SELECT id, immatriculation, client_id, marque, modele, annee, kilometrage, couleur, carburant, puissance, 
                         date_achat, date_derniere_revision, date_prochain_ct, statut, notes 
                  FROM vehicules WHERE id = :id";

        $stmt = $db->prepare($query);
        $stmt->bindParam(':id', $vehicle_id, PDO::PARAM_INT);
        $stmt->execute();

        if ($stmt->rowCount() > 0) {
            echo json_encode($stmt->fetch(PDO::FETCH_ASSOC));
        } else {
            echo json_encode(["error" => "Véhicule non trouvé"]);
        }
    } catch (PDOException $e) {
        echo json_encode(["error" => "Erreur lors de la récupération: " . $e->getMessage()]);
    }
} else {
    echo json_encode(["error" => "ID de véhicule invalide"]);
}
?>
