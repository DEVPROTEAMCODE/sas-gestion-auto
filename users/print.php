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
    $_SESSION['error'] = "Type d'impression non spécifié";
    header('Location: index.php');
    exit;
}

$type = $_GET['type'];

// Valider le type
if (!in_array($type, ['technicien', 'client', 'admin', 'users'])) {
    $_SESSION['error'] = "Type d'impression invalide";
    header('Location: index.php');
    exit;
}

try {
    // Connexion à la base de données
    $database = new Database();
    $db = $database->getConnection();
    
    $items = [];
    $title = '';
    
    if ($type === 'technicien') {
        // Récupérer les données des techniciens
        $query = "SELECT id, nom, prenom, date_naissance, specialite, email 
                  FROM technicien 
                  ORDER BY nom, prenom";
        
        $stmt = $db->prepare($query);
        $stmt->execute();
        
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $title = 'Liste des techniciens';
    } elseif ($type === 'client') {
        // Récupérer les données des clients
        $query = "SELECT id, nom, prenom, email, telephone 
                  FROM clients 
                  ORDER BY nom, prenom";
        
        $stmt = $db->prepare($query);
        $stmt->execute();
        
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $title = 'Liste des clients';
    } elseif ($type === 'admin') {
        // Récupérer les données des administrateurs
        $query = "SELECT id, nom, prenom, email, role 
                  FROM administrateurs 
                  ORDER BY nom, prenom";
        
        $stmt = $db->prepare($query);
        $stmt->execute();
        
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $title = 'Liste des administrateurs';
    } elseif ($type === 'users') {
        $query = "SELECT id, username, nom, prenom, email, role, date_creation, derniere_connexion, actif
                  FROM users
                  ORDER BY id";
        
        $stmt = $db->prepare($query);
        $stmt->execute();
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $title = 'Liste des utilisateurs';
    }
} catch (PDOException $e) {
    $_SESSION['error'] = "Erreur de base de données: " . $e->getMessage();
    header('Location: index.php?type=' . $type);
    exit;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $title; ?> - SAS Réparation Auto</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 20px;
            color: #333;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
        }
        .header {
            text-align: center;
            margin-bottom: 30px;
            border-bottom: 1px solid #ddd;
            padding-bottom: 20px;
        }
        .header h1 {
            margin: 0;
            color: #2563eb;
        }
        .header p {
            margin: 5px 0;
            color: #666;
        }
        .print-info {
            margin-bottom: 20px;
            font-size: 0.9em;
            color: #666;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 30px;
        }
        th, td {
            padding: 10px;
            border: 1px solid #ddd;
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
            margin-top: 30px;
            text-align: center;
            font-size: 0.9em;
            color: #666;
            border-top: 1px solid #ddd;
            padding-top: 20px;
        }
        .no-print {
            margin-top: 30px;
            text-align: center;
        }
        .no-print button {
            padding: 8px 16px;
            background-color: #2563eb;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            margin: 0 5px;
        }
        .no-print button:hover {
            background-color: #1d4ed8;
        }
        @media print {
            .no-print {
                display: none;
            }
            body {
                padding: 0;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>SAS Réparation Auto</h1>
            <p>Gestion des ressources humaines</p>
        </div>
        
        <div class="print-info">
            <p><strong>Date d'impression:</strong> <?php echo date('d/m/Y H:i'); ?></p>
            <p><strong>Imprimé par:</strong> <?php echo isset($_SESSION['username']) ? htmlspecialchars($_SESSION['username']) : 'Utilisateur système'; ?></p>
        </div>
        
        <h2><?php echo $title; ?></h2>
        
        <?php if ($type === 'users'): ?>
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Nom d'utilisateur</th>
                        <th>Nom</th>
                        <th>Prénom</th>
                        <th>Email</th>
                        <th>Rôle</th>
                        <th>Date de création</th>
                        <th>Dernière connexion</th>
                        <th>Statut</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($items)): ?>
                        <tr>
                            <td colspan="9" style="text-align: center;">Aucun utilisateur trouvé</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($items as $item): ?>
                            <tr>
                                <td><?php echo $item['id']; ?></td>
                                <td><?php echo htmlspecialchars($item['username']); ?></td>
                                <td><?php echo htmlspecialchars($item['nom']); ?></td>
                                <td><?php echo htmlspecialchars($item['prenom']); ?></td>
                                <td><?php echo htmlspecialchars($item['email']); ?></td>
                                <td><?php echo ucfirst(htmlspecialchars($item['role'])); ?></td>
                                <td><?php echo $item['date_creation'] ? date('d/m/Y H:i', strtotime($item['date_creation'])) : ''; ?></td>
                                <td><?php echo $item['derniere_connexion'] ? date('d/m/Y H:i', strtotime($item['derniere_connexion'])) : 'Jamais'; ?></td>
                                <td><?php echo $item['actif'] ? 'Actif' : 'Inactif'; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        <?php elseif ($type === 'technicien'): ?>
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Nom</th>
                        <th>Prénom</th>
                        <th>Date de naissance</th>
                        <th>Âge</th>
                        <th>Spécialité</th>
                        <th>Email</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($items)): ?>
                        <tr>
                            <td colspan="7" style="text-align: center;">Aucun technicien trouvé</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($items as $item): 
                            // Calculer l'âge
                            $dateNaissance = new DateTime($item['date_naissance']);
                            $aujourdhui = new DateTime();
                            $age = $dateNaissance->diff($aujourdhui)->y;
                        ?>
                            <tr>
                                <td><?php echo $item['id']; ?></td>
                                <td><?php echo htmlspecialchars($item['nom']); ?></td>
                                <td><?php echo htmlspecialchars($item['prenom']); ?></td>
                                <td><?php echo date('d/m/Y', strtotime($item['date_naissance'])); ?></td>
                                <td><?php echo $age; ?> ans</td>
                                <td><?php echo htmlspecialchars($item['specialite']); ?></td>
                                <td><?php echo htmlspecialchars($item['email']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        <?php elseif ($type === 'client'): ?>
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Nom</th>
                        <th>Prénom</th>
                        <th>Email</th>
                        <th>Téléphone</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($items)): ?>
                        <tr>
                            <td colspan="5" style="text-align: center;">Aucun client trouvé</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($items as $item): ?>
                            <tr>
                                <td><?php echo $item['id']; ?></td>
                                <td><?php echo htmlspecialchars($item['nom']); ?></td>
                                <td><?php echo htmlspecialchars($item['prenom']); ?></td>
                                <td><?php echo htmlspecialchars($item['email']); ?></td>
                                <td><?php echo htmlspecialchars($item['telephone']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        <?php elseif ($type === 'admin'): ?>
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Nom</th>
                        <th>Prénom</th>
                        <th>Email</th>
                        <th>Rôle</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($items)): ?>
                        <tr>
                            <td colspan="5" style="text-align: center;">Aucun administrateur trouvé</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($items as $item): ?>
                            <tr>
                                <td><?php echo $item['id']; ?></td>
                                <td><?php echo htmlspecialchars($item['nom']); ?></td>
                                <td><?php echo htmlspecialchars($item['prenom']); ?></td>
                                <td><?php echo htmlspecialchars($item['email']); ?></td>
                                <td><?php echo htmlspecialchars($item['role']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        <?php endif; ?>
        
        <div class="footer">
            <p>SAS Réparation Auto - Document confidentiel</p>
            <p>© <?php echo date('Y'); ?> - Tous droits réservés</p>
            <p>Page 1</p>
        </div>
        
        <div class="no-print">
            <button onclick="window.print()">Imprimer</button>
            <button onclick="window.close()">Fermer</button>
        </div>
    </div>
    
    <script>
        // Déclencher automatiquement l'impression
        window.onload = function() {
            setTimeout(function() {
                window.print();
            }, 500);
        };
    </script>
</body>
</html>