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

// Récupérer les filtres éventuels
$search = isset($_GET['search']) ? $_GET['search'] : '';
$status = isset($_GET['status']) ? $_GET['status'] : '';
$marque = isset($_GET['marque']) ? $_GET['marque'] : '';

try {
    $database = new Database();
    $db = $database->getConnection();
    
    // Récupérer les informations de l'utilisateur
    $queryUser = "SELECT nom, prenom FROM users WHERE id = :user_id";
    $stmtUser = $db->prepare($queryUser);
    $stmtUser->bindParam(':user_id', $_SESSION['user_id']);
    $stmtUser->execute();
    $user = $stmtUser->fetch(PDO::FETCH_ASSOC);
    
    // Construction de la requête avec filtres
    $whereClause = [];
    $params = [];

    if (!empty($search)) {
        $whereClause[] = "(v.immatriculation LIKE :search OR v.marque LIKE :search OR v.modele LIKE :search OR CONCAT(c.nom, ' ', c.prenom) LIKE :search)";
        $params[':search'] = "%$search%";
    }

    if ($status !== '') {
        $whereClause[] = "v.statut = :status";
        $params[':status'] = $status;
    }

    if ($marque !== '') {
        $whereClause[] = "v.marque = :marque";
        $params[':marque'] = $marque;
    }

    $whereString = !empty($whereClause) ? 'WHERE ' . implode(' AND ', $whereClause) : '';
    
    // Requête pour récupérer les véhicules
    $query = "SELECT v.id, v.immatriculation, v.marque, v.modele, v.annee, 
              CONCAT(c.nom, ' ', c.prenom) AS client,
              v.kilometrage, v.statut, v.couleur, v.carburant,
              v.date_derniere_revision, v.date_prochain_ct
              FROM vehicules v 
              LEFT JOIN clients c ON v.client_id = c.id 
              $whereString
              ORDER BY v.marque, v.modele";
    
    $stmt = $db->prepare($query);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    
    $vehicles = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Enregistrer l'action dans les logs
    $logQuery = "INSERT INTO logs (user_id, action, entite, entite_id, details, date_action, adresse_ip) 
                VALUES (:user_id, 'Impression', 'vehicules', NULL, :details, NOW(), :adresse_ip)";
    
    $logStmt = $db->prepare($logQuery);
    $logStmt->bindParam(':user_id', $_SESSION['user_id']);
    
    $logDetails = "Impression de la liste des véhicules";
    $logStmt->bindParam(':details', $logDetails);
    
    $ipAddress = $_SERVER['REMOTE_ADDR'];
    $logStmt->bindParam(':adresse_ip', $ipAddress);
    
    $logStmt->execute();
    
} catch (PDOException $e) {
    echo "Erreur: " . $e->getMessage();
    exit;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Liste des Véhicules - Impression</title>
    <style>
       
       body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 20px;
            font-size: 12px;
        }
        .header {
            text-align: center;
            margin-bottom: 20px;
            border-bottom: 1px solid #ddd;
            padding-bottom: 10px;
        }
        .header h1 {
            margin: 0;
            font-size: 24px;
            color: #333;
        }
        .header p {
            margin: 5px 0 0;
            color: #666;
        }
        .info {
            margin-bottom: 20px;
            font-size: 11px;
        }
        .info p {
            margin: 2px 0;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        th, td {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: left;
        }
        th {
            background-color: #f2f2f2;
            font-weight: bold;
        }
        tr:nth-child(even) {
            background-color: #f9f9f9;
        }
        .footer {
            margin-top: 30px;
            font-size: 10px;
            text-align: center;
            color: #999;
            border-top: 1px solid #ddd;
            padding-top: 10px;
        }
        .status {
            padding: 2px 5px;
            border-radius: 3px;
            font-size: 10px;
            font-weight: bold;
            text-transform: uppercase;
        }
        .status-actif {
            background-color: #e6f7e6;
            color: #2e7d32;
        }
        .status-maintenance {
            background-color: #fff8e1;
            color: #f57f17;
        }
        .status-inactif {
            background-color: #ffebee;
            color: #c62828;
        }
        @media print {
            body {
                padding: 0;
            }
            button.no-print {
                display: none;
            }
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>Liste des Véhicules</h1>
        <p>Système de Gestion Automobile</p>
    </div>
    
    <div class="info">
        <p><strong>Date d'impression:</strong> <?php echo date('d/m/Y H:i'); ?></p>
        <p><strong>Généré par:</strong> <?php echo htmlspecialchars($user['prenom'] . ' ' . $user['nom']); ?></p>
        <?php if (!empty($search) || !empty($status) || !empty($marque)): ?>
            <p><strong>Filtres appliqués:</strong> 
                <?php 
                $appliedFilters = [];
                if (!empty($search)) $appliedFilters[] = "Recherche: " . htmlspecialchars($search);
                if (!empty($status)) $appliedFilters[] = "Statut: " . htmlspecialchars($status);
                if (!empty($marque)) $appliedFilters[] = "Marque: " . htmlspecialchars($marque);
                echo implode(', ', $appliedFilters);
                ?>
            </p>
        <?php endif; ?>
        <p><strong>Total véhicules:</strong> <?php echo count($vehicles); ?></p>
    </div>
    
    <button class="no-print" onclick="window.print()" style="padding: 8px 16px; background-color: #4CAF50; color: white; border: none; border-radius: 4px; cursor: pointer; margin-bottom: 20px;">
        Imprimer
    </button>
    
    <table>
        <thead>
            <tr>
                <th>Immatriculation</th>
                <th>Marque</th>
                <th>Modèle</th>
                <th>Année</th>
                <th>Client</th>
                <th>Kilométrage</th>
                <th>Statut</th>
                <th>Dernière révision</th>
                <th>Prochain CT</th>
            </tr>
        </thead>
        <tbody>
            <?php if (count($vehicles) > 0): ?>
                <?php foreach ($vehicles as $vehicle): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($vehicle['immatriculation']); ?></td>
                        <td><?php echo htmlspecialchars($vehicle['marque']); ?></td>
                        <td><?php echo htmlspecialchars($vehicle['modele']); ?></td>
                        <td><?php echo htmlspecialchars($vehicle['annee']); ?></td>
                        <td><?php echo htmlspecialchars($vehicle['client']); ?></td>
                        <td><?php echo number_format($vehicle['kilometrage'], 0, ',', ' '); ?> km</td>
                        <td>
                            <?php
                            $statusClass = '';
                            $statusText = '';
                            
                            switch ($vehicle['statut']) {
                                case 'actif':
                                    $statusClass = 'status-actif';
                                    $statusText = 'Actif';
                                    break;
                                case 'maintenance':
                                    $statusClass = 'status-maintenance';
                                    $statusText = 'En maintenance';
                                    break;
                                case 'inactif':
                                    $statusClass = 'status-inactif';
                                    $statusText = 'Inactif';
                                    break;
                                default:
                                    $statusText = $vehicle['statut'];
                            }
                            ?>
                            <span class="status <?php echo $statusClass; ?>"><?php echo $statusText; ?></span>
                        </td>
                        <td><?php echo !empty($vehicle['date_derniere_revision']) ? date('d/m/Y', strtotime($vehicle['date_derniere_revision'])) : '-'; ?></td>
                        <td><?php echo !empty($vehicle['date_prochain_ct']) ? date('d/m/Y', strtotime($vehicle['date_prochain_ct'])) : '-'; ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="9" style="text-align: center;">Aucun véhicule trouvé</td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
    
    <div class="footer">
        <p>Document généré automatiquement par le système de gestion automobile - <?php echo date('d/m/Y H:i:s'); ?></p>
        <p>Ce document est confidentiel et à usage interne uniquement.</p>
    </div>
    
    <script>
        // Imprimer automatiquement la page après chargement complet
        window.onload = function() {
            // Attendre un peu pour que tout soit bien chargé
            setTimeout(function() {
                window.print();
            }, 500);
        };
    </script>
</body>
</html>
