<?php
// Démarrer la session d'abord
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

// Récupérer les informations de l'utilisateur actuel
try {
    $database = new Database();
    $db = $database->getConnection();
    
    $query = "SELECT id, username, nom, prenom, role FROM users WHERE id = :user_id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':user_id', $_SESSION['user_id']);
    $stmt->execute();
    
    if ($stmt->rowCount() > 0) {
        $currentUser = $stmt->fetch(PDO::FETCH_ASSOC);
    } else {
        // Si l'utilisateur n'existe pas, déconnecter
        session_destroy();
        header('Location: ../auth/login.php');
        exit;
    }
} catch (PDOException $e) {
    // Log l'erreur et rediriger vers une page d'erreur
    error_log('Erreur de récupération des données utilisateur: ' . $e->getMessage());
    header('Location: ../error.php');
    exit;
}

// Assurer que $currentUser['name'] est défini pour éviter les erreurs
if (!isset($currentUser['name'])) {
    $currentUser['name'] = $currentUser['prenom'] . ' ' . $currentUser['nom'];
}
if (!isset($currentUser['role'])) {
    $currentUser['role'] = 'Utilisateur';
}

// Filtres
$search = isset($_GET['search']) ? $_GET['search'] : '';
$status = isset($_GET['status']) ? $_GET['status'] : '';
$delai = isset($_GET['delai']) ? $_GET['delai'] : '';
$format = isset($_GET['format']) ? $_GET['format'] : 'print';

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

// Récupération des fournisseurs
$fournisseurs = [];
$totalFournisseurs = 0;

try {
    // Compter le nombre total de fournisseurs
    $countQuery = "SELECT COUNT(*) FROM fournisseurs $whereString";
    $countStmt = $db->prepare($countQuery);
    foreach ($params as $key => $value) {
        $countStmt->bindValue($key, $value);
    }
    $countStmt->execute();
    $totalFournisseurs = $countStmt->fetchColumn();
    
    // Récupérer les fournisseurs
    $query = "SELECT * FROM fournisseurs $whereString ORDER BY Raison_Sociale";
    $stmt = $db->prepare($query);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    
    if ($stmt->rowCount() > 0) {
        $fournisseurs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Statistiques des fournisseurs
    // Fournisseurs actifs
    $queryActifs = "SELECT COUNT(*) FROM fournisseurs WHERE Actif = 1";
    if (!empty($whereString)) {
        $queryActifs .= " AND " . substr($whereString, 6); // Enlever le "WHERE" du début
    }
    $stmtActifs = $db->prepare($queryActifs);
    foreach ($params as $key => $value) {
        if ($key !== ':status') { // Ne pas lier le statut car on filtre spécifiquement par actif
            $stmtActifs->bindValue($key, $value);
        }
    }
    $stmtActifs->execute();
    $fournisseursActifs = $stmtActifs->fetchColumn();
    
    // Délai moyen de livraison
    $queryDelai = "SELECT AVG(Delai_Livraison_Moyen) FROM fournisseurs WHERE Delai_Livraison_Moyen IS NOT NULL AND Delai_Livraison_Moyen > 0";
    if (!empty($whereString)) {
        $queryDelai .= " AND " . substr($whereString, 6); // Enlever le "WHERE" du début
    }
    $stmtDelai = $db->prepare($queryDelai);
    foreach ($params as $key => $value) {
        if ($key !== ':delai') { // Ne pas lier le délai car on calcule spécifiquement la moyenne
            $stmtDelai->bindValue($key, $value);
        }
    }
    $stmtDelai->execute();
    $delaiLivraisonMoyen = round($stmtDelai->fetchColumn());
    
} catch (PDOException $e) {
    $error = 'Erreur lors de la récupération des fournisseurs: ' . $e->getMessage();
    error_log($error);
}

// Si format PDF est demandé, générer un PDF
if ($format === 'pdf') {
    // Dans une application réelle, vous utiliseriez une bibliothèque comme TCPDF, FPDF ou mPDF
    // Pour cet exemple, nous allons simplement afficher une version imprimable qui peut être sauvegardée en PDF via le navigateur
    
    // Ne pas inclure l'en-tête standard pour le format PDF
    $isPDF = true;
} else {
    // Inclure l'en-tête standard pour l'affichage normal
    include '../includes/header.php';
    $isPDF = false;
}
?>

<?php if ($isPDF): ?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Liste des Fournisseurs - Rapport</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            margin: 0;
            padding: 20px;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
        }
        .header {
            text-align: center;
            margin-bottom: 30px;
            border-bottom: 2px solid #4a5568;
            padding-bottom: 20px;
        }
        .header h1 {
            font-size: 24px;
            margin-bottom: 5px;
        }
        .header p {
            font-size: 14px;
            color: #666;
        }
        .stats {
            display: flex;
            justify-content: space-between;
            margin-bottom: 30px;
            flex-wrap: wrap;
        }
        .stat-card {
            background-color: #f9f9f9;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 15px;
            width: 30%;
            box-sizing: border-box;
            text-align: center;
        }
        .stat-card h3 {
            font-size: 16px;
            margin-top: 0;
            margin-bottom: 10px;
            color: #4a5568;
        }
        .stat-card p {
            font-size: 20px;
            font-weight: bold;
            margin: 0;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 30px;
        }
        th, td {
            border: 1px solid #e2e8f0;
            padding: 12px;
            text-align: left;
        }
        th {
            background-color: #f8fafc;
            font-weight: bold;
            color: #4a5568;
        }
        tr:nth-child(even) {
            background-color: #f9f9f9;
        }
        .footer {
            text-align: center;
            margin-top: 30px;
            font-size: 12px;
            color: #666;
            border-top: 1px solid #e2e8f0;
            padding-top: 20px;
        }
        .status {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 9999px;
            font-size: 12px;
            font-weight: 600;
        }
        .status-active {
            background-color: #c6f6d5;
            color: #22543d;
        }
        .status-inactive {
            background-color: #fed7d7;
            color: #822727;
        }
        @media print {
            body {
                padding: 0;
            }
            .no-print {
                display: none;
            }
            table {
                page-break-inside: auto;
            }
            tr {
                page-break-inside: avoid;
                page-break-after: auto;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Liste des Fournisseurs</h1>
            <p>Rapport généré le <?php echo date('d/m/Y à H:i'); ?> par <?php echo htmlspecialchars($currentUser['prenom'] . ' ' . $currentUser['nom']); ?></p>
            <?php if (!empty($search) || $status !== '' || !empty($delai)): ?>
                <p>
                    Filtres appliqués:
                    <?php
                    $filterTexts = [];
                    if (!empty($search)) $filterTexts[] = "Recherche: \"" . htmlspecialchars($search) . "\"";
                    if ($status !== '') $filterTexts[] = "Statut: " . ($status === '1' ? 'Actif' : 'Inactif');
                    if (!empty($delai)) {
                        switch ($delai) {
                            case 'less5': $filterTexts[] = "Délai: < 5 jours"; break;
                            case '5to10': $filterTexts[] = "Délai: 5-10 jours"; break;
                            case 'more10': $filterTexts[] = "Délai: > 10 jours"; break;
                        }
                    }
                    echo implode(', ', $filterTexts);
                    ?>
                </p>
            <?php endif; ?>
        </div>
        
        <div class="stats">
            <div class="stat-card">
                <h3>Total Fournisseurs</h3>
                <p><?php echo $totalFournisseurs; ?></p>
            </div>
            <div class="stat-card">
                <h3>Fournisseurs Actifs</h3>
                <p><?php echo $fournisseursActifs; ?></p>
            </div>
            <div class="stat-card">
                <h3>Délai Moyen</h3>
                <p><?php echo $delaiLivraisonMoyen; ?> jours</p>
            </div>
        </div>
        
        <table>
            <thead>
                <tr>
                    <th>Code</th>
                    <th>Raison Sociale</th>
                    <th>Ville</th>
                    <th>Contact</th>
                    <th>Téléphone</th>
                    <th>Email</th>
                    <th>Délai (jours)</th>
                    <th>Statut</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($fournisseurs)): ?>
                    <?php foreach ($fournisseurs as $fournisseur): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($fournisseur['Code_Fournisseur'] ?? '-'); ?></td>
                            <td><?php echo htmlspecialchars($fournisseur['Raison_Sociale'] ?? '-'); ?></td>
                            <td><?php echo htmlspecialchars($fournisseur['Ville'] ?? '-'); ?></td>
                            <td><?php echo htmlspecialchars($fournisseur['Contact_Principal'] ?? '-'); ?></td>
                            <td><?php echo htmlspecialchars($fournisseur['Telephone'] ?? '-'); ?></td>
                            <td><?php echo htmlspecialchars($fournisseur['Email'] ?? '-'); ?></td>
                            <td><?php echo $fournisseur['Delai_Livraison_Moyen'] ?? '-'; ?></td>
                            <td>
                                <span class="status <?php echo $fournisseur['Actif'] == 1 ? 'status-active' : 'status-inactive'; ?>">
                                    <?php echo $fournisseur['Actif'] == 1 ? 'Actif' : 'Inactif'; ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="8" style="text-align: center;">Aucun fournisseur trouvé</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
        
        <div class="footer">
            <p>© <?php echo date('Y'); ?> - Surprise-autoservice</p>
        </div>
        
        <div class="no-print" style="margin-top: 30px; text-align: center;">
            <button onclick="window.print();" style="padding: 10px 20px; background-color: #4a5568; color: white; border: none; border-radius: 5px; cursor: pointer; margin-right: 10px;">Imprimer / Sauvegarder en PDF</button>
            <button onclick="window.location.href='index.php';" style="padding: 10px 20px; background-color: #e2e8f0; color: #4a5568; border: none; border-radius: 5px; cursor: pointer;">Retour à la liste</button>
        </div>
    </div>
    
    <script>
        // Imprimer automatiquement si demandé
        document.addEventListener('DOMContentLoaded', function() {
            // Attendre que la page soit complètement chargée
            setTimeout(function() {
                <?php if (isset($_GET['autoprint']) && $_GET['autoprint'] === 'true'): ?>
                window.print();
                <?php endif; ?>
            }, 1000);
        });
    </script>
</body>
</html>
<?php else: ?>

<div class="flex h-screen bg-gray-100">
    <!-- Sidebar -->
    <?php include '../includes/sidebar.php'; ?>

    <!-- Main Content -->
    <div class="flex-1 overflow-y-auto">
        <!-- Top header -->
        <div class="bg-white shadow-sm">
            <div class="container mx-auto px-6 py-4 flex justify-between items-center">
                <h1 class="text-2xl font-semibold text-gray-800">Impression de la liste des fournisseurs</h1>
                <div class="flex items-center space-x-4">
                    <div class="relative">
                        <span class="text-gray-700"><?php echo htmlspecialchars($currentUser['prenom'] . ' ' . $currentUser['nom']); ?></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Main Content -->
        <div class="container mx-auto px-6 py-8">
            <?php if (isset($error)): ?>
                <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6" role="alert">
                    <p><?php echo $error; ?></p>
                </div>
            <?php endif; ?>
            
            <!-- Print Options Card -->
            <div class="bg-white rounded-lg shadow-md p-6 mb-8">
                <h3 class="text-xl font-semibold text-gray-800 mb-6">Options d'impression</h3>
                
                <form action="print.php" method="GET" class="space-y-6">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <!-- Format d'impression -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Format d'impression</label>
                            <div class="space-y-2">
                                <div class="flex items-center">
                                    <input type="radio" id="format_standard" name="format" value="print" class="h-4 w-4 text-blue-600 border-gray-300 focus:ring-blue-500" checked>
                                    <label for="format_standard" class="ml-2 block text-sm text-gray-700">Standard (pour imprimante)</label>
                                </div>
                                <div class="flex items-center">
                                    <input type="radio" id="format_pdf" name="format" value="pdf" class="h-4 w-4 text-blue-600 border-gray-300 focus:ring-blue-500">
                                    <label for="format_pdf" class="ml-2 block text-sm text-gray-700">PDF optimisé</label>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Filtres -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Filtres</label>
                            
                            <div class="space-y-4">
                                <div class="flex flex-col space-y-2">
                                    <label for="search" class="text-sm text-gray-600">Recherche</label>
                                    <input type="text" id="search" name="search" value="<?= htmlspecialchars($search) ?>" class="px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500" placeholder="Nom, code, ville...">
                                </div>
                                
                                <div class="flex flex-col space-y-2">
                                    <label for="status" class="text-sm text-gray-600">Statut</label>
                                    <select id="status" name="status" class="px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                                        <option value="">Tous les statuts</option>
                                        <option value="1" <?= $status === '1' ? 'selected' : '' ?>>Actif</option>
                                        <option value="0" <?= $status === '0' ? 'selected' : '' ?>>Inactif</option>
                                    </select>
                                </div>
                                
                                <div class="flex flex-col space-y-2">
                                    <label for="delai" class="text-sm text-gray-600">Délai de livraison</label>
                                    <select id="delai" name="delai" class="px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                                        <option value="">Tous les délais</option>
                                        <option value="less5" <?= $delai === 'less5' ? 'selected' : '' ?>>< 5 jours</option>
                                        <option value="5to10" <?= $delai === '5to10' ? 'selected' : '' ?>>5-10 jours</option>
                                        <option value="more10" <?= $delai === 'more10' ? 'selected' : '' ?>>> 10 jours</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="border-t border-gray-200 pt-4">
                        <h4 class="text-lg font-medium text-gray-800 mb-3">Options d'affichage</h4>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label for="page_size" class="block text-sm font-medium text-gray-700 mb-2">Taille de page</label>
                                <select id="page_size" name="page_size" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                                    <option value="a4">A4 (210 × 297 mm)</option>
                                    <option value="letter">Lettre (216 × 279 mm)</option>
                                    <option value="legal">Legal (216 × 356 mm)</option>
                                </select>
                            </div>
                            <div>
                                <label for="orientation" class="block text-sm font-medium text-gray-700 mb-2">Orientation</label>
                                <select id="orientation" name="orientation" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                                    <option value="portrait">Portrait</option>
                                    <option value="landscape">Paysage</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="mt-4">
                            <div class="flex items-center">
                                <input type="checkbox" id="include_header" name="include_header" value="1" class="h-4 w-4 text-blue-600 border-gray-300 focus:ring-blue-500" checked>
                                <label for="include_header" class="ml-2 block text-sm text-gray-700">Inclure l'en-tête avec logo et informations</label>
                            </div>
                        </div>
                        <div class="mt-2">
                            <div class="flex items-center">
                                <input type="checkbox" id="include_stats" name="include_stats" value="1" class="h-4 w-4 text-blue-600 border-gray-300 focus:ring-blue-500" checked>
                                <label for="include_stats" class="ml-2 block text-sm text-gray-700">Inclure les statistiques</label>
                            </div>
                        </div>
                        <div class="mt-2">
                            <div class="flex items-center">
                                <input type="checkbox" id="autoprint" name="autoprint" value="true" class="h-4 w-4 text-blue-600 border-gray-300 focus:ring-blue-500">
                                <label for="autoprint" class="ml-2 block text-sm text-gray-700">Lancer l'impression automatiquement</label>
                            </div>
                        </div>
                    </div>
                    
                    <div class="flex justify-between items-center pt-4 border-t border-gray-200">
                        <a href="index.php" class="px-4 py-2 bg-gray-200 text-gray-700 rounded-md hover:bg-gray-300 focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2">
                            Retour à la liste
                        </a>
                        <div class="flex space-x-3">
                            <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
                                Générer le document
                            </button>
                        </div>
                    </div>
                </form>
            </div>
            
            <!-- Preview Card -->
            <div class="bg-white rounded-lg shadow-md p-6">
                <h3 class="text-xl font-semibold text-gray-800 mb-4">Aperçu</h3>
                
                <div class="border border-gray-300 rounded-lg p-6 bg-gray-50">
                    <div class="text-center border-b border-gray-300 pb-4 mb-4">
                        <h2 class="text-xl font-bold text-gray-800">Liste des Fournisseurs</h2>
                        <p class="text-sm text-gray-600">Rapport généré le <?php echo date('d/m/Y'); ?></p>
                    </div>
                    
                    <div class="grid grid-cols-3 gap-4 mb-6">
                        <div class="bg-white p-3 rounded-lg border border-gray-200 text-center">
                            <h4 class="text-sm font-medium text-gray-700">Total Fournisseurs</h4>
                            <p class="text-xl font-bold text-gray-900"><?php echo $totalFournisseurs; ?></p>
                        </div>
                        <div class="bg-white p-3 rounded-lg border border-gray-200 text-center">
                            <h4 class="text-sm font-medium text-gray-700">Fournisseurs Actifs</h4>
                            <p class="text-xl font-bold text-gray-900"><?php echo $fournisseursActifs; ?></p>
                        </div>
                        <div class="bg-white p-3 rounded-lg border border-gray-200 text-center">
                            <h4 class="text-sm font-medium text-gray-700">Délai Moyen</h4>
                            <p class="text-xl font-bold text-gray-900"><?php echo $delaiLivraisonMoyen; ?> jours</p>
                        </div>
                    </div>
                    
                    <div class="overflow-x-auto">
                        <table class="min-w-full bg-white border border-gray-300">
                            <thead>
                                <tr class="bg-gray-100">
                                    <th class="py-2 px-4 border-b text-left text-xs font-medium text-gray-600 uppercase">Code</th>
                                    <th class="py-2 px-4 border-b text-left text-xs font-medium text-gray-600 uppercase">Raison Sociale</th>
                                    <th class="py-2 px-4 border-b text-left text-xs font-medium text-gray-600 uppercase">Ville</th>
                                    <th class="py-2 px-4 border-b text-left text-xs font-medium text-gray-600 uppercase">Contact</th>
                                    <th class="py-2 px-4 border-b text-left text-xs font-medium text-gray-600 uppercase">Délai</th>
                                    <th class="py-2 px-4 border-b text-left text-xs font-medium text-gray-600 uppercase">Statut</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                // Afficher seulement les 5 premiers fournisseurs dans l'aperçu
                                $previewCount = min(5, count($fournisseurs));
                                for ($i = 0; $i < $previewCount; $i++): 
                                    $fournisseur = $fournisseurs[$i];
                                ?>
                                    <tr class="border-b">
                                        <td class="py-2 px-4 text-sm"><?php echo htmlspecialchars($fournisseur['Code_Fournisseur'] ?? '-'); ?></td>
                                        <td class="py-2 px-4 text-sm"><?php echo htmlspecialchars($fournisseur['Raison_Sociale'] ?? '-'); ?></td>
                                        <td class="py-2 px-4 text-sm"><?php echo htmlspecialchars($fournisseur['Ville'] ?? '-'); ?></td>
                                        <td class="py-2 px-4 text-sm"><?php echo htmlspecialchars($fournisseur['Contact_Principal'] ?? '-'); ?></td>
                                        <td class="py-2 px-4 text-sm"><?php echo $fournisseur['Delai_Livraison_Moyen'] ?? '-'; ?></td>
                                        <td class="py-2 px-4 text-sm">
                                            <span class="px-2 py-1 text-xs rounded-full <?php echo $fournisseur['Actif'] == 1 ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>">
                                                <?php echo $fournisseur['Actif'] == 1 ? 'Actif' : 'Inactif'; ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endfor; ?>
                                
                                <?php if (count($fournisseurs) > 5): ?>
                                    <tr>
                                        <td colspan="6" class="py-2 px-4 text-sm text-center text-gray-500 italic">
                                            ... et <?php echo count($fournisseurs) - 5; ?> autres fournisseurs
                                        </td>
                                    </tr>
                                <?php elseif (empty($fournisseurs)): ?>
                                    <tr>
                                        <td colspan="6" class="py-2 px-4 text-sm text-center text-gray-500">
                                            Aucun fournisseur trouvé
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <div class="text-center text-gray-500 text-xs mt-6">
                        © <?php echo date('Y'); ?> - Système de Gestion des Fournisseurs
                    </div>
                </div>
                
                <div class="mt-4 text-sm text-gray-500">
                    <p>* Ceci est un aperçu simplifié. Le document généré contiendra tous les fournisseurs correspondant aux filtres sélectionnés.</p>
                </div>
            </div>
            
            <!-- Help Card -->
            <div class="bg-white rounded-lg shadow-md p-6 mt-8">
                <h3 class="text-xl font-semibold text-gray-800 mb-4">Aide à l'impression</h3>
                
                <div class="space-y-4">
                    <div>
                        <h4 class="text-lg font-medium text-gray-700">Formats disponibles</h4>
                        <ul class="mt-2 list-disc list-inside text-gray-600 space-y-1">
                            <li><strong>Standard</strong> - Format optimisé pour l'impression sur papier</li>
                            <li><strong>PDF optimisé</strong> - Format PDF avec mise en page améliorée</li>
                        </ul>
                    </div>
                    
                    <div>
                        <h4 class="text-lg font-medium text-gray-700">Conseils d'impression</h4>
                        <ul class="mt-2 list-disc list-inside text-gray-600 space-y-1">
                            <li>Pour un grand nombre de fournisseurs, utilisez l'orientation paysage</li>
                            <li>Pour enregistrer en PDF, utilisez la fonction "Imprimer" de votre navigateur et sélectionnez "Enregistrer en PDF" comme imprimante</li>
                            <li>Activez l'option "Inclure les statistiques" pour obtenir une vue d'ensemble des données</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Gestion des formats d'impression
        const formatRadios = document.querySelectorAll('input[name="format"]');
        const pageOptions = document.querySelector('.border-t.border-gray-200.pt-4');
        
        formatRadios.forEach(radio => {
            radio.addEventListener('change', function() {
                if (this.value === 'pdf') {
                    pageOptions.classList.remove('opacity-50');
                } else {
                    pageOptions.classList.remove('opacity-50');
                }
            });
        });
        
        // Gestion de l'orientation
        const orientationSelect = document.getElementById('orientation');
        const previewContainer = document.querySelector('.border.border-gray-300.rounded-lg');
        
        orientationSelect.addEventListener('change', function() {
            if (this.value === 'landscape') {
                previewContainer.style.maxWidth = '800px';
                previewContainer.style.margin = '0 auto';
            } else {
                previewContainer.style.maxWidth = '100%';
                previewContainer.style.margin = '0';
            }
        });
    });
</script>

<?php endif; ?>

<?php if (!$isPDF): include '../includes/footer.php'; endif; ?>
