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

// Récupérer les filtres
$search = isset($_GET['search']) ? $_GET['search'] : '';
$categorie = isset($_GET['categorie']) ? $_GET['categorie'] : '';
$statut = isset($_GET['statut']) ? $_GET['statut'] : '';
$date_debut = isset($_GET['date_debut']) ? $_GET['date_debut'] : '';
$date_fin = isset($_GET['date_fin']) ? $_GET['date_fin'] : '';

// Récupérer les informations de l'utilisateur actuel
$database = new Database();
$db = $database->getConnection();

try {
    $query = "SELECT username, nom, prenom FROM users WHERE id = :user_id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':user_id', $_SESSION['user_id']);
    $stmt->execute();
    
    if ($stmt->rowCount() > 0) {
        $currentUser = $stmt->fetch(PDO::FETCH_ASSOC);
    } else {
        $currentUser = ['username' => 'Inconnu', 'nom' => '', 'prenom' => ''];
    }
} catch (PDOException $e) {
    $currentUser = ['username' => 'Inconnu', 'nom' => '', 'prenom' => ''];
}

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

// Récupérer les offres
$offres = [];
try {
    $query = "SELECT o.*, c.nom as categorie_nom 
              FROM offres o 
              LEFT JOIN categorie c ON o.categorie_id = c.id 
              $whereString 
              ORDER BY o.date_creation DESC";
    
    $stmt = $db->prepare($query);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    
    $offres = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = 'Erreur lors de la récupération des offres: ' . $e->getMessage();
}

// Récupérer les catégories pour le filtre
$categories = [];
try {
    $queryCategories = "SELECT id, nom FROM categorie ORDER BY nom";
    $stmtCategories = $db->query($queryCategories);
    $categories = $stmtCategories->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('Erreur lors de la récupération des catégories: ' . $e->getMessage());
}

// Statistiques des offres
try {
    // Offres actives
    $queryActives = "SELECT COUNT(*) FROM offres WHERE actif = 1";
    $stmtActives = $db->query($queryActives);
    $offresActives = $stmtActives->fetchColumn();
    
    // Offres en cours (date actuelle entre date_debut et date_fin)
    $queryEnCours = "SELECT COUNT(*) FROM offres WHERE actif = 1 AND date_debut <= CURDATE() AND (date_fin IS NULL OR date_fin >= CURDATE())";
    $stmtEnCours = $db->query($queryEnCours);
    $offresEnCours = $stmtEnCours->fetchColumn();
    
    // Offres à venir
    $queryAVenir = "SELECT COUNT(*) FROM offres WHERE actif = 1 AND date_debut > CURDATE()";
    $stmtAVenir = $db->query($queryAVenir);
    $offresAVenir = $stmtAVenir->fetchColumn();
    
    // Offres expirées
    $queryExpirees = "SELECT COUNT(*) FROM offres WHERE date_fin < CURDATE()";
    $stmtExpirees = $db->query($queryExpirees);
    $offresExpirees = $stmtExpirees->fetchColumn();
} catch (PDOException $e) {
    $offresActives = 0;
    $offresEnCours = 0;
    $offresAVenir = 0;
    $offresExpirees = 0;
}

// Enregistrer l'action dans les logs
logAction($db, $_SESSION['user_id'], 'Impression', 'offres', null, "Impression du rapport des offres");

// Contenu HTML pour l'impression
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rapport des offres</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 20px;
            color: #333;
        }
        .header {
            text-align: center;
            margin-bottom: 30px;
        }
        .header h1 {
            margin: 0;
            color: #2563eb;
        }
        .header p {
            margin: 5px 0;
            color: #666;
        }
        .stats {
            display: flex;
            justify-content: space-between;
            margin-bottom: 30px;
            flex-wrap: wrap;
        }
        .stat-card {
            background: #f9fafb;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 15px;
            width: calc(25% - 20px);
            box-sizing: border-box;
            margin-bottom: 15px;
        }
        .stat-card h3 {
            margin: 0 0 10px 0;
            font-size: 16px;
            color: #4b5563;
        }
        .stat-card p {
            margin: 0;
            font-size: 24px;
            font-weight: bold;
            color: #1f2937;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 30px;
        }
        th, td {
            border: 1px solid #e5e7eb;
            padding: 10px;
            text-align: left;
        }
        th {
            background-color: #f9fafb;
            font-weight: bold;
        }
        tr:nth-child(even) {
            background-color: #f9fafb;
        }
        .footer {
            margin-top: 50px;
            text-align: center;
            font-size: 12px;
            color: #6b7280;
        }
        .status {
            padding: 5px 10px;
            border-radius: 15px;
            font-size: 12px;
            font-weight: bold;
            display: inline-block;
        }
        .status-active {
            background-color: #d1fae5;
            color: #065f46;
        }
        .status-inactive {
            background-color: #f3f4f6;
            color: #4b5563;
        }
        .status-coming {
            background-color: #e0e7ff;
            color: #3730a3;
        }
        .status-expired {
            background-color: #fee2e2;
            color: #991b1b;
        }
        @media print {
            body {
                padding: 0;
            }
            .no-print {
                display: none;
            }
            .stats {
                display: flex;
                justify-content: space-between;
                margin-bottom: 30px;
            }
            .stat-card {
                width: 22%;
            }
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>Rapport des offres</h1>
        <p>Généré le <?= date('d/m/Y à H:i') ?> par <?= htmlspecialchars($currentUser['prenom'] . ' ' . $currentUser['nom']) ?></p>
        <?php if (!empty($search) || !empty($categorie) || $statut !== '' || !empty($date_debut) || !empty($date_fin)): ?>
            <p>
                Filtres: 
                <?php
                $filterTexts = [];
                if (!empty($search)) $filterTexts[] = "Recherche: " . htmlspecialchars($search);
                if (!empty($categorie)) {
                    foreach ($categories as $cat) {
                        if ($cat['id'] == $categorie) {
                            $filterTexts[] = "Catégorie: " . htmlspecialchars($cat['nom']);
                            break;
                        }
                    }
                }
                if ($statut !== '') $filterTexts[] = "Statut: " . ($statut ? "Actif" : "Inactif");
                if (!empty($date_debut)) $filterTexts[] = "Date début: " . htmlspecialchars($date_debut);
                if (!empty($date_fin)) $filterTexts[] = "Date fin: " . htmlspecialchars($date_fin);
                echo implode(" | ", $filterTexts);
                ?>
            </p>
        <?php endif; ?>
    </div>
    
    <div class="stats">
        <div class="stat-card">
            <h3>Total des offres</h3>
            <p><?= count($offres) ?></p>
        </div>
        <div class="stat-card">
            <h3>Offres actives</h3>
            <p><?= $offresActives ?></p>
        </div>
        <div class="stat-card">
            <h3>Offres en cours</h3>
            <p><?= $offresEnCours ?></p>
        </div>
        <div class="stat-card">
            <h3>Offres à venir</h3>
            <p><?= $offresAVenir ?></p>
        </div>
    </div>
    
    <table>
        <thead>
            <tr>
                <th>Code</th>
                <th>Nom</th>
                <th>Catégorie</th>
                <th>Période</th>
                <th>Remise</th>
                <th>Statut</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($offres as $offre): ?>
                <tr>
                    <td><?= htmlspecialchars($offre['code']) ?></td>
                    <td><?= htmlspecialchars($offre['nom']) ?></td>
                    <td><?= htmlspecialchars($offre['categorie_nom'] ?? 'Toutes') ?></td>
                    <td>
                        <?php 
                        echo date('d/m/Y', strtotime($offre['date_debut'])); 
                        echo $offre['date_fin'] ? ' au ' . date('d/m/Y', strtotime($offre['date_fin'])) : ' (sans fin)';
                        ?>
                    </td>
                    <td>
                        <?= htmlspecialchars($offre['valeur_remise']) ?>
                        <?= $offre['type_remise'] === 'pourcentage' ? ' %' : ' DH' ?>
                    </td>
                    <td>
                        <?php
                        $today = date('Y-m-d');
                        $isActive = $offre['actif'] == 1;
                        $isStarted = $offre['date_debut'] <= $today;
                        $isEnded = $offre['date_fin'] && $offre['date_fin'] < $today;
                        
                        if (!$isActive) {
                            $statusClass = 'status-inactive';
                            $statusText = 'Inactif';
                        } elseif (!$isStarted) {
                            $statusClass = 'status-coming';
                            $statusText = 'À venir';
                        } elseif ($isEnded) {
                            $statusClass = 'status-expired';
                            $statusText = 'Expiré';
                        } else {
                            $statusClass = 'status-active';
                            $statusText = 'En cours';
                        }
                        ?>
                        <span class="status <?= $statusClass ?>"><?= $statusText ?></span>
                    </td>
                </tr>
            <?php endforeach; ?>
            
            <?php if (empty($offres)): ?>
                <tr>
                    <td colspan="6" style="text-align: center;">Aucune offre trouvée</td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
    
    <div class="footer">
        <p>© <?= date('Y') ?> - Système de gestion des offres</p>
    </div>
    
    <div class="no-print" style="text-align: center; margin-top: 30px;">
        <button onclick="window.print();" style="padding: 10px 20px; background-color: #2563eb; color: white; border: none; border-radius: 5px; cursor: pointer;">Imprimer</button>
        <button onclick="window.location.href='index.php';" style="padding: 10px 20px; background-color: #6b7280; color: white; border: none; border-radius: 5px; cursor: pointer; margin-left: 10px;">Retour</button>
    </div>
    
    <script>
        // Lancer l'impression automatiquement
        window.onload = function() {
            // Attendre un peu pour que la page se charge complètement
            setTimeout(function() {
                window.print();
            }, 500);
        };
    </script>
</body>
</html>
<?php
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
