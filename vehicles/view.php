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
    header('Location: ../auth/login.php');
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

// Paramètres de filtrage et pagination
$search = isset($_GET['search']) ? $_GET['search'] : '';
$status = isset($_GET['status']) ? $_GET['status'] : '';
$marque = isset($_GET['marque']) ? $_GET['marque'] : '';
$vehiculesParPage = 10; // Nombre de véhicules par page
$pageCourante = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
$debut = ($pageCourante - 1) * $vehiculesParPage;

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

// Récupérer le nombre total de véhicules
$queryTotal = "SELECT COUNT(*) FROM vehicules v 
              LEFT JOIN clients c ON v.client_id = c.id 
              $whereString";
$stmtTotal = $db->prepare($queryTotal);
foreach ($params as $key => $value) {
    $stmtTotal->bindValue($key, $value);
}
$stmtTotal->execute();
$totalVehicules = $stmtTotal->fetchColumn();
$totalPages = ceil($totalVehicules / $vehiculesParPage);

// Requête paginée pour les véhicules
// Requête paginée pour les véhicules
$query = "SELECT v.id, v.immatriculation, v.marque, v.modele, v.annee, 
          c.id AS client_id, c.nom, c.prenom, c.raison_sociale, c.telephone, c.email,
          tc.type AS type_client,
          v.kilometrage, v.statut, v.couleur, v.carburant, v.puissance,
          v.date_mise_circulation, v.date_derniere_revision, v.date_prochain_ct, v.notes
          FROM vehicules v 
          LEFT JOIN clients c ON v.client_id = c.id 
          LEFT JOIN type_client tc ON c.type_client_id = tc.id
          $whereString
          ORDER BY v.date_creation DESC
          LIMIT :debut, :vehiculesParPage";


$stmt = $db->prepare($query);
$stmt->bindValue(':debut', $debut, PDO::PARAM_INT);
$stmt->bindValue(':vehiculesParPage', $vehiculesParPage, PDO::PARAM_INT);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$vehicles = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Récupérer les statistiques
// Véhicules en maintenance
$queryMaintenance = "SELECT COUNT(*) FROM vehicules WHERE statut = 'maintenance'";
$stmtMaintenance = $db->query($queryMaintenance);
$maintenanceCount = $stmtMaintenance->fetchColumn();

// Contrôles techniques à prévoir (dans les 30 jours)
$queryControles = "SELECT COUNT(*) FROM vehicules 
                  WHERE date_prochain_ct IS NOT NULL 
                  AND date_prochain_ct BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)";
$stmtControles = $db->query($queryControles);
$controlesTotalCount = $stmtControles->fetchColumn();

// Kilométrage moyen
$queryKilometrage = "SELECT AVG(kilometrage) FROM vehicules WHERE kilometrage > 0";
$stmtKilometrage = $db->query($queryKilometrage);
$kilometrageMoyen = round($stmtKilometrage->fetchColumn());

// Récupérer la liste des marques pour le filtre
$queryMarques = "SELECT DISTINCT marque FROM vehicules ORDER BY marque";
$stmtMarques = $db->query($queryMarques);
$marques = $stmtMarques->fetchAll(PDO::FETCH_COLUMN);

// Récupérer la liste des clients pour le formulaire d'ajout
$queryClients = "SELECT id, nom, prenom FROM clients ORDER BY nom, prenom";
$stmtClients = $db->query($queryClients);
$clients = $stmtClients->fetchAll(PDO::FETCH_ASSOC);

// Récupérer les alertes
// Contrôles techniques à prévoir
$queryAlertesCT = "SELECT v.id, v.immatriculation, v.marque, v.modele, v.date_prochain_ct, 
                  CONCAT(c.nom, ' ', c.prenom) AS client
                  FROM vehicules v
                  LEFT JOIN clients c ON v.client_id = c.id
                  WHERE v.date_prochain_ct IS NOT NULL 
                  AND v.date_prochain_ct BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
                  ORDER BY v.date_prochain_ct
                  LIMIT 5";
$stmtAlertesCT = $db->query($queryAlertesCT);
$alertesCT = $stmtAlertesCT->fetchAll(PDO::FETCH_ASSOC);

// Vidanges à effectuer (basé sur le kilométrage - exemple: +10000km depuis la dernière révision)
$queryAlertesVidange = "SELECT v.id, v.immatriculation, v.marque, v.modele, v.kilometrage, 
                       CONCAT(c.nom, ' ', c.prenom) AS client
                       FROM vehicules v
                       LEFT JOIN clients c ON v.client_id = c.id
                       WHERE v.date_derniere_revision IS NOT NULL 
                       AND DATEDIFF(CURDATE(), v.date_derniere_revision) > 365
                       ORDER BY v.date_derniere_revision
                       LIMIT 5";
$stmtAlertesVidange = $db->query($queryAlertesVidange);
$alertesVidange = $stmtAlertesVidange->fetchAll(PDO::FETCH_ASSOC);


// Inclure l'en-tête
include '../includes/header.php';
?>

<div class="flex h-screen bg-gray-100">
    <!-- Sidebar -->
    <?php include '../includes/sidebar.php'; ?>

    <!-- Main Content -->
    <div class="flex-1 overflow-y-auto">
        <!-- Top header -->
        <div class="bg-white shadow-sm">
            <div class="container mx-auto px-6 py-4 flex justify-between items-center">
                <h1 class="text-2xl font-semibold text-gray-800">Gestion des Véhicules</h1>
                <div class="flex items-center space-x-4">
                    <div class="relative">
                        <span class="text-gray-700"><?php echo htmlspecialchars($currentUser['prenom'] . ' ' . $currentUser['nom']); ?></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Main Content -->
        <div class="container mx-auto px-6 py-8">
            <!-- Statistics Cards -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                <!-- Total Vehicles Card -->
                <div class="bg-white p-6 rounded-lg shadow-md transition duration-300 hover:shadow-lg">
                    <div class="flex items-center justify-between">
                        <div>
                            <h3 class="text-lg font-semibold text-gray-700">Total Véhicules</h3>
                            <p class="text-3xl font-bold text-gray-800 mt-2">
                                <?php echo $totalVehicules; ?>
                            </p>
                        </div>
                        <div class="bg-blue-100 p-3 rounded-full">
                            <svg class="w-8 h-8 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path>
                            </svg>
                        </div>
                    </div>
                    <div class="mt-4">
                        <a href="view.php" class="text-blue-500 hover:text-blue-700 text-sm font-semibold">Voir tous les véhicules →</a>
                    </div>
                </div>

                <!-- Maintenance Vehicles Card -->
                <div class="bg-white p-6 rounded-lg shadow-md transition duration-300 hover:shadow-lg">
                    <div class="flex items-center justify-between">
                        <div>
                            <h3 class="text-lg font-semibold text-gray-700">En maintenance</h3>
                            <p class="text-3xl font-bold text-gray-800 mt-2">
                                <?php echo $maintenanceCount; ?>
                            </p>
                        </div>
                        <div class="bg-yellow-100 p-3 rounded-full">
                            <svg class="w-8 h-8 text-yellow-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5.882V19.24a1.76 1.76 0 01-3.417.592l-2.147-6.15M18 13a3 3 0 100-6M5.436 13.683A4.001 4.001 0 017 6h1.832c4.1 0 7.625-1.234 9.168-3v14c-1.543-1.766-5.067-3-9.168-3H7a3.988 3.988 0 01-1.564-.317z"></path>
                            </svg>
                        </div>
                    </div>
                    <div class="mt-4">
                        <a href="view.php?status=maintenance" class="text-yellow-500 hover:text-yellow-700 text-sm font-semibold">Voir les détails →</a>
                    </div>
                </div>

                <!-- Technical Control Card -->
                <div class="bg-white p-6 rounded-lg shadow-md transition duration-300 hover:shadow-lg">
                    <div class="flex items-center justify-between">
                        <div>
                            <h3 class="text-lg font-semibold text-gray-700">Contrôles techniques</h3>
                            <p class="text-3xl font-bold text-gray-800 mt-2"><?php echo $controlesTotalCount; ?></p>
                        </div>
                        <div class="bg-red-100 p-3 rounded-full">
                            <svg class="w-8 h-8 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                            </svg>
                        </div>
                    </div>
                    <div class="mt-4">
                        <a href="#" class="text-red-500 hover:text-red-700 text-sm font-semibold">Voir les échéances →</a>
                    </div>
                </div>

                <!-- Average Mileage Card -->
                <div class="bg-white p-6 rounded-lg shadow-md transition duration-300 hover:shadow-lg">
                    <div class="flex items-center justify-between">
                        <div>
                            <h3 class="text-lg font-semibold text-gray-700">Kilométrage moyen</h3>
                            <p class="text-3xl font-bold text-gray-800 mt-2"><?php echo number_format($kilometrageMoyen, 0, ',', ' '); ?> km</p>
                        </div>
                        <div class="bg-green-100 p-3 rounded-full">
                            <svg class="w-8 h-8 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                            </svg>
                        </div>
                    </div>
                    <div class="mt-4">
                        <a href="#" class="text-green-500 hover:text-green-700 text-sm font-semibold">Voir les statistiques →</a>
                    </div>
                </div>
            </div>

            <!-- Content Grid -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-8 mb-8">
                <!-- Quick Actions Card -->
                <div class="bg-white rounded-lg shadow-md p-6">
                    <h3 class="text-xl font-semibold text-gray-800 mb-4">Actions rapides</h3>
                    <div class="space-y-4">
                        <a href="create.php" class="flex items-center p-3 bg-gray-50 rounded-lg hover:bg-gray-100 transition duration-200">
                            <div class="bg-blue-100 p-2 rounded-full mr-4">
                                <svg class="w-6 h-6 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                                </svg>
                            </div>
                            <div>
                                <h4 class="font-medium text-gray-800">Ajouter un véhicule</h4>
                                <p class="text-sm text-gray-600">Enregistrer un nouveau véhicule</p>
                            </div>
                        </a>
                        <a href="export.php" class="flex items-center p-3 bg-gray-50 rounded-lg hover:bg-gray-100 transition duration-200">
                            <div class="bg-green-100 p-2 rounded-full mr-4">
                                <svg class="w-6 h-6 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"></path>
                                </svg>
                            </div>
                            <div>
                                <h4 class="font-medium text-gray-800">Exporter les données</h4>
                                <p class="text-sm text-gray-600">Exporter en CSV ou Excel</p>
                            </div>
                        </a>
                        <a href="print.php" class="flex items-center p-3 bg-gray-50 rounded-lg hover:bg-gray-100 transition duration-200">
                            <div class="bg-purple-100 p-2 rounded-full mr-4">
                                <svg class="w-6 h-6 text-purple-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"></path>
                                </svg>
                            </div>
                            <div>
                                <h4 class="font-medium text-gray-800">Imprimer la liste</h4>
                                <p class="text-sm text-gray-600">Générer un rapport imprimable</p>
                            </div>
                        </a>
                    </div>
                </div>

                <!-- Maintenance Alerts Card -->
                <div class="bg-white rounded-lg shadow-md p-6 col-span-2">
                    <h3 class="text-xl font-semibold text-gray-800 mb-4">Alertes maintenance</h3>
                    <div class="space-y-4">
                        <div class="flex items-center p-3 bg-red-50 rounded-lg">
                            <div class="bg-red-100 p-2 rounded-full mr-4">
                                <svg class="w-6 h-6 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                </svg>
                            </div>
                            <div class="flex-1">
                                <h4 class="font-medium text-gray-800">Contrôle technique à prévoir</h4>
                                <p class="text-sm text-gray-600"><?php echo $controlesTotalCount; ?> véhicules ont un contrôle technique à prévoir dans les 30 jours</p>
                            </div>
                            <a href="#" class="px-3 py-1 bg-red-100 text-red-700 rounded-md text-sm font-medium hover:bg-red-200 transition duration-200">Voir</a>
                        </div>
                        <div class="flex items-center p-3 bg-yellow-50 rounded-lg">
                            <div class="bg-yellow-100 p-2 rounded-full mr-4">
                                <svg class="w-6 h-6 text-yellow-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4"></path>
                                </svg>
                            </div>
                            <div class="flex-1">
                                <h4 class="font-medium text-gray-800">Vidanges à effectuer</h4>
                                <p class="text-sm text-gray-600"><?php echo count($alertesVidange); ?> véhicules ont dépassé le délai recommandé pour la vidange</p>
                            </div>
                            <a href="#" class="px-3 py-1 bg-yellow-100 text-yellow-700 rounded-md text-sm font-medium hover:bg-yellow-200 transition duration-200">Voir</a>
                        </div>
                        <div class="flex items-center p-3 bg-blue-50 rounded-lg">
                            <div class="bg-blue-100 p-2 rounded-full mr-4">
                                <svg class="w-6 h-6 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path>
                                </svg>
                            </div>
                            <div class="flex-1">
                                <h4 class="font-medium text-gray-800">Rappels d'entretien</h4>
                                <p class="text-sm text-gray-600">Consultez les rappels d'entretien programmés</p>
                            </div>
                            <a href="#" class="px-3 py-1 bg-blue-100 text-blue-700 rounded-md text-sm font-medium hover:bg-blue-200 transition duration-200">Voir</a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Vehicles List -->
            <div class="bg-white rounded-lg shadow-md p-6 mb-8">
                <div class="flex justify-between items-center mb-6">
                    <h3 class="text-xl font-semibold text-gray-800">Liste des véhicules</h3>
                    <div class="flex space-x-2">
                        <a href="export.php" class="px-4 py-2 bg-blue-600 text-white rounded-md flex items-center hover:bg-blue-700 transition duration-200">
                            <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"></path>
                            </svg>
                            Exporter
                        </a>
                        <a href="create.php"  class="px-4 py-2 bg-green-600 text-white rounded-md flex items-center hover:bg-green-700 transition duration-200">
                            <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                            </svg>
                            Ajouter un véhicule
                            </a>
                    </div>
                </div>

                <!-- Search and Filter -->
                <form action="view.php" method="GET" class="flex flex-col md:flex-row gap-4 mb-6">
                    <div class="relative flex-1">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                            </svg>
                        </div>
                        <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" class="pl-10 pr-4 py-2 w-full border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500" placeholder="Rechercher un véhicule...">
                    </div>
                    <div class="flex gap-4">
                        <select name="status" class="px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                            <option value="">Tous les statuts</option>
                            <option value="actif" <?php echo $status === 'actif' ? 'selected' : ''; ?>>Actif</option>
                            <option value="maintenance" <?php echo $status === 'maintenance' ? 'selected' : ''; ?>>En maintenance</option>
                            <option value="inactif" <?php echo $status === 'inactif' ? 'selected' : ''; ?>>Inactif</option>
                        </select>
                        <select name="marque" class="px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                            <option value="">Toutes les marques</option>
                            <?php foreach ($marques as $m): ?>
                                <option value="<?php echo htmlspecialchars($m); ?>" <?php echo $marque === $m ? 'selected' : ''; ?>><?php echo htmlspecialchars($m); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="px-4 py-2 bg-blue-500 text-white rounded-md hover:bg-blue-600 transition duration-200">
                            Filtrer
                        </button>
                    </div>
                </form>

                <!-- Table -->
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        
                    <thead class="bg-gray-50">
                            <tr>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Immatriculation</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Marque</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Modèle</th>
                                <th scope="col" class="hidden md:table-cell px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Année</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Client</th>
                                <th scope="col" class="hidden md:table-cell px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Kilométrage</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Statut</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php if (count($vehicles) > 0): ?>
                                <?php foreach ($vehicles as $vehicle): ?>
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900"><?php echo htmlspecialchars($vehicle['immatriculation']); ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo htmlspecialchars($vehicle['marque']); ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo htmlspecialchars($vehicle['modele']); ?></td>
                                        <td class="hidden md:table-cell px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo htmlspecialchars($vehicle['annee']); ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            <?php 
                                            if ($vehicle['type_client'] == 'societe' && !empty($vehicle['raison_sociale'])) {
                                                echo htmlspecialchars($vehicle['raison_sociale']);
                                            } else {
                                                echo htmlspecialchars($vehicle['nom'] . ' ' . $vehicle['prenom']);
                                            }
                                            ?>
                                        </td>

                                        <td class="hidden md:table-cell px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo number_format($vehicle['kilometrage'], 0, ',', ' '); ?> km</td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <?php
                                            $statusClass = '';
                                            $statusText = '';
                                            
                                            switch ($vehicle['statut']) {
                                                case 'actif':
                                                    $statusClass = 'bg-green-100 text-green-800';
                                                    $statusText = 'Actif';
                                                    break;
                                                case 'maintenance':
                                                    $statusClass = 'bg-yellow-100 text-yellow-800';
                                                    $statusText = 'En maintenance';
                                                    break;
                                                case 'inactif':
                                                    $statusClass = 'bg-red-100 text-red-800';
                                                    $statusText = 'Inactif';
                                                    break;
                                                default:
                                                    $statusClass = 'bg-gray-100 text-gray-800';
                                                    $statusText = $vehicle['statut'];
                                            }
                                            ?>
                                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full <?php echo $statusClass; ?>">
                                                <?php echo $statusText; ?>
                                            </span>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                            <div class="flex space-x-2">
                                                <button onclick="viewVehicle(<?php echo $vehicle['id']; ?>)" class="text-blue-600 hover:text-blue-900">
                                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                                                    </svg>
                                                </button>
                                                <a href="edit.php?id=<?php echo $vehicle['id']; ?>" class="text-indigo-600 hover:text-indigo-900">
    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
    </svg>
</a>

                                                <button onclick="deleteVehicle(<?php echo $vehicle['id']; ?>)" class="text-red-600 hover:text-red-900">
                                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                                                    </svg>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="8" class="px-6 py-4 text-center text-sm text-gray-500">
                                        Aucun véhicule trouvé
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <?php if ($totalPages > 1): ?>
                    <div class="flex justify-between items-center mt-6">
                        <div class="text-sm text-gray-500">
                            Affichage de <span class="font-medium"><?= $debut + 1 ?></span> à 
                            <span class="font-medium"><?= min($debut + $vehiculesParPage, $totalVehicules) ?></span> 
                            sur <span class="font-medium"><?= $totalVehicules ?></span> résultats
                        </div>
                        <div class="flex space-x-1">
                            <!-- Bouton précédent -->
                            <?php if ($pageCourante > 1): ?>
                                <a href="?page=<?= $pageCourante - 1 ?>&search=<?= urlencode($search) ?>&status=<?= urlencode($status) ?>&marque=<?= urlencode($marque) ?>" class="px-3 py-1 border border-gray-300 rounded-md text-sm font-medium text-gray-700 bg-white hover:bg-gray-50">Précédent</a>
                            <?php else: ?>
                                <span class="px-3 py-1 border border-gray-200 rounded-md text-sm font-medium text-gray-400 bg-gray-100 cursor-not-allowed">Précédent</span>
                            <?php endif; ?>

                            <!-- Affichage des numéros de pages -->
                            <?php 
                            $startPage = max(1, $pageCourante - 2);
                            $endPage = min($startPage + 4, $totalPages);
                            
                            if ($startPage > 1) {
                                echo '<a href="?page=1&search=' . urlencode($search) . '&status=' . urlencode($status) . '&marque=' . urlencode($marque) . '" class="px-3 py-1 border border-gray-300 rounded-md text-sm font-medium text-gray-700 bg-white hover:bg-gray-50">1</a>';
                                if ($startPage > 2) {
                                    echo '<span class="px-3 py-1 text-gray-500">...</span>';
                                }
                            }
                            
                            for ($i = $startPage; $i <= $endPage; $i++): ?>
                                <?php if ($pageCourante == $i): ?>
                                    <span class="px-3 py-1 border border-blue-500 rounded-md text-sm font-medium text-white bg-blue-500"><?= $i ?></span>
                                <?php else: ?>
                                    <a href="?page=<?= $i ?>&search=<?= urlencode($search) ?>&status=<?= urlencode($status) ?>&marque=<?= urlencode($marque) ?>" class="px-3 py-1 border border-gray-300 rounded-md text-sm font-medium text-gray-700 bg-white hover:bg-gray-50"><?= $i ?></a>
                                <?php endif; ?>
                            <?php endfor; 
                            
                            if ($endPage < $totalPages) {
                                if ($endPage < $totalPages - 1) {
                                    echo '<span class="px-3 py-1 text-gray-500">...</span>';
                                }
                                echo '<a href="?page=' . $totalPages . '&search=' . urlencode($search) . '&status=' . urlencode($status) . '&marque=' . urlencode($marque) . '" class="px-3 py-1 border border-gray-300 rounded-md text-sm font-medium text-gray-700 bg-white hover:bg-gray-50">' . $totalPages . '</a>';
                            }
                            ?>

                            <!-- Bouton suivant -->
                            <?php if ($pageCourante < $totalPages): ?>
                                <a href="?page=<?= $pageCourante + 1 ?>&search=<?= urlencode($search) ?>&status=<?= urlencode($status) ?>&marque=<?= urlencode($marque) ?>" class="px-3 py-1 border border-gray-300 rounded-md text-sm font-medium text-gray-700 bg-white hover:bg-gray-50">Suivant</a>
                            <?php else: ?>
                                <span class="px-3 py-1 border border-gray-200 rounded-md text-sm font-medium text-gray-400 bg-gray-100 cursor-not-allowed">Suivant</span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Add Vehicle Modal -->
<div id="addVehicleModal" class="fixed inset-0 z-50 overflow-auto bg-black bg-opacity-50 flex items-center justify-center hidden">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-3xl mx-4">
        <div class="flex justify-between items-center p-4 border-b bg-green-600 rounded-t-lg">
            <h3 class="text-xl font-semibold text-white">Ajouter un véhicule</h3>
            <button onclick="closeModal('addVehicleModal')" class="text-white hover:text-gray-200">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                </svg>
            </button>
        </div>

        <form action="create.php" method="POST" class="p-5">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                <div>
                    <label for="immatriculation" class="block text-sm font-medium text-gray-700 mb-1">Immatriculation</label>
                    <input type="text" id="immatriculation" name="immatriculation" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-green-500 focus:border-green-500" placeholder="AB-123-CD" required>
                </div>
                <div>
                    <label for="client" class="block text-sm font-medium text-gray-700 mb-1">Client</label>
                    <select id="client" name="client_id" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-green-500 focus:border-green-500" required>
                        <option value="">Sélectionner un client</option>
                        <?php foreach ($clients as $client): ?>
                            <option value="<?php echo $client['id']; ?>"><?php echo htmlspecialchars($client['nom'] . ' ' . $client['prenom']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="marque" class="block text-sm font-medium text-gray-700 mb-1">Marque</label>
                    <select id="marque" name="marque" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-green-500 focus:border-green-500" required>
                        <option value="0">:: MARQUE ::</option>
                        <option value="ABARTH">ABARTH</option>
                        <option value="ALFA ROMEO">ALFA ROMEO</option>
                        <option value="AUDI">AUDI</option>
                        <option value="BMW">BMW</option>
                        <option value="BYD">BYD</option>
                        <option value="CHANGAN">CHANGAN</option>
                        <option value="CHERY">CHERY</option>
                        <option value="CITROEN">CITROEN</option>
                        <option value="CUPRA">CUPRA</option>
                        <option value="DACIA">DACIA</option>
                        <option value="DFSK">DFSK</option>
                        <option value="DS">DS</option>
                        <option value="FIAT">FIAT</option>
                        <option value="FORD">FORD</option>
                        <option value="GEELY">GEELY</option>
                        <option value="GWM">GWM</option>
                        <option value="HONDA">HONDA</option>
                        <option value="HYUNDAI">HYUNDAI</option>
                        <option value="JAECOO">JAECOO</option>
                        <option value="JAGUAR">JAGUAR</option>
                        <option value="JEEP">JEEP</option>
                        <option value="KIA">KIA</option>
                        <option value="LAND ROVER">LAND ROVER</option>
                        <option value="LEXUS">LEXUS</option>
                        <option value="MAHINDRA">MAHINDRA</option>
                        <option value="MASERATI">MASERATI</option>
                        <option value="MAZDA">MAZDA</option>
                        <option value="MERCEDES">MERCEDES</option>
                        <option value="MG">MG</option>
                        <option value="MINI">MINI</option>
                        <option value="MITSUBISHI">MITSUBISHI</option>
                        <option value="NISSAN">NISSAN</option>
                        <option value="OMODA">OMODA</option>
                        <option value="OPEL">OPEL</option>
                        <option value="PEUGEOT">PEUGEOT</option>
                        <option value="PORSCHE">PORSCHE</option>
                        <option value="RENAULT">RENAULT</option>
                        <option value="SEAT">SEAT</option>
                        <option value="SERES">SERES</option>
                        <option value="SKODA">SKODA</option>
                        <option value="SUZUKI">SUZUKI</option>
                        <option value="TOYOTA">TOYOTA</option>
                        <option value="VOLKSWAGEN">VOLKSWAGEN</option>
                        <option value="VOLVO">VOLVO</option>
                    </select>
                </div>
                <div>
                    <label for="modele" class="block text-sm font-medium text-gray-700 mb-1">Modèle</label>
                    <input type="text" id="modele" name="modele" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-green-500 focus:border-green-500" placeholder="Clio, 308, etc." required>
                </div>
                <div>
                    <label for="annee" class="block text-sm font-medium text-gray-700 mb-1">Année</label>
                    <input type="number" id="annee" name="annee" min="1900" max="<?php echo date('Y') + 1; ?>" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-green-500 focus:border-green-500" placeholder="<?php echo date('Y'); ?>" required>
                </div>
                <div>
                    <label for="kilometrage" class="block text-sm font-medium text-gray-700 mb-1">Kilométrage</label>
                    <input type="number" id="kilometrage" name="kilometrage" min="0" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-green-500 focus:border-green-500" placeholder="0" required>
                </div>
                <div>
                    <label for="couleur" class="block text-sm font-medium text-gray-700 mb-1">Couleur</label>
                    <input type="text" id="couleur" name="couleur" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-green-500 focus:border-green-500" placeholder="Noir, Blanc, etc.">
                </div>
                <div>
                    <label for="carburant" class="block text-sm font-medium text-gray-700 mb-1">Type de carburant</label>
                    <select id="carburant" name="carburant" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-green-500 focus:border-green-500">
                        <option value="">Sélectionner un type</option>
                        <option value="essence">Essence</option>
                        <option value="diesel">Diesel</option>
                        <option value="hybride">Hybride</option>
                        <option value="electrique">Électrique</option>
                        <option value="gpl">GPL</option>
                    </select>
                </div>
                <div>
                    <label for="puissance" class="block text-sm font-medium text-gray-700 mb-1">Puissance fiscale (CV)</label>
                    <input type="number" id="puissance" name="puissance" min="1" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-green-500 focus:border-green-500" placeholder="5">
                </div>
                <div>
                    <label for="date_mise_circulation" class="block text-sm font-medium text-gray-700 mb-1">Date de mise en circulation</label>
                    <input type="date" id="date_mise_circulation" name="date_mise_circulation" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-green-500 focus:border-green-500">
                </div>
                <div>
                    <label for="date_derniere_revision" class="block text-sm font-medium text-gray-700 mb-1">Date dernière révision</label>
                    <input type="date" id="date_derniere_revision" name="date_derniere_revision" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-green-500 focus:border-green-500">
                </div>
                <div>
                    <label for="date_prochain_ct" class="block text-sm font-medium text-gray-700 mb-1">Date prochain contrôle technique</label>
                    <input type="date" id="date_prochain_ct" name="date_prochain_ct" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-green-500 focus:border-green-500">
                </div>
                <div>
                    <label for="statut" class="block text-sm font-medium text-gray-700 mb-1">Statut</label>
                    <select id="statut" name="statut" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-green-500 focus:border-green-500">
                        <option value="actif">Actif</option>
                        <option value="maintenance">En maintenance</option>
                        <option value="inactif">Inactif</option>
                    </select>
                </div>
            </div>
            
            <div class="mt-5">
                <label for="notes" class="block text-sm font-medium text-gray-700 mb-1">Notes</label>
                <textarea id="notes" name="notes" rows="3" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-green-500 focus:border-green-500" placeholder="Informations complémentaires sur le véhicule..."></textarea>
            </div>

            <div class="mt-5 pt-5 border-t border-gray-200 flex justify-end space-x-3">
                <button type="button" onclick="closeModal('addVehicleModal')" class="px-4 py-2 bg-gray-200 text-gray-700 rounded-md hover:bg-gray-300 focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2">
                    Annuler
                </button>
                <button type="submit" class="px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-offset-2">
                    Enregistrer
                </button>
            </div>
        </form>
    </div>
</div>

<!-- View Vehicle Modal -->
<div id="viewVehicleModal" class="fixed inset-0 z-50 overflow-auto bg-black bg-opacity-50 flex items-center justify-center hidden">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-4xl mx-4">
        <div class="flex justify-between items-center p-6 border-b">
            <h3 class="text-xl font-semibold text-gray-900">Détails du véhicule</h3>
            <button onclick="closeModal('viewVehicleModal')" class="text-gray-400 hover:text-gray-500">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                </svg>
            </button>
        </div>

        <div class="p-6">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <h4 class="text-lg font-semibold text-blue-600 mb-4">Informations générales</h4>
                    <div class="space-y-3">
                        <div class="flex justify-between">
                            <span class="text-sm font-medium text-gray-500">Immatriculation:</span>
                            <span id="view_immatriculation" class="text-sm text-gray-900"></span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-sm font-medium text-gray-500">Marque:</span>
                            <span id="view_marque" class="text-sm text-gray-900"></span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-sm font-medium text-gray-500">Modèle:</span>
                            <span id="view_modele" class="text-sm text-gray-900"></span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-sm font-medium text-gray-500">Année:</span>
                            <span id="view_annee" class="text-sm text-gray-900"></span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-sm font-medium text-gray-500">Couleur:</span>
                            <span id="view_couleur" class="text-sm text-gray-900"></span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-sm font-medium text-gray-500">Type de carburant:</span>
                            <span id="view_carburant" class="text-sm text-gray-900"></span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-sm font-medium text-gray-500">Puissance:</span>
                            <span id="view_puissance" class="text-sm text-gray-900"></span>
                        </div>
                    </div>

                    <h4 class="text-lg font-semibold text-blue-600 mt-6 mb-4">Propriétaire</h4>
                    <div class="space-y-3">
                        <div class="flex justify-between">
                            <span class="text-sm font-medium text-gray-500">Client:</span>
                            <span id="view_client" class="text-sm text-gray-900"></span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-sm font-medium text-gray-500">Téléphone:</span>
                            <span id="view_telephone" class="text-sm text-gray-900"></span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-sm font-medium text-gray-500">Email:</span>
                            <span id="view_email" class="text-sm text-gray-900"></span>
                        </div>
                    </div>
                </div>
                
                <div>
                    <h4 class="text-lg font-semibold text-blue-600 mb-4">Suivi technique</h4>
                    <div class="space-y-3">
                        <div class="flex justify-between">
                            <span class="text-sm font-medium text-gray-500">Kilométrage actuel:</span>
                            <span id="view_kilometrage" class="text-sm text-gray-900"></span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-sm font-medium text-gray-500">Date de mise en circulation:</span>
                            <span id="view_date_mise_circulation" class="text-sm text-gray-900"></span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-sm font-medium text-gray-500">Dernière révision:</span>
                            <span id="view_date_derniere_revision" class="text-sm text-gray-900"></span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-sm font-medium text-gray-500">Prochain contrôle technique:</span>
                            
                            <span id="view_date_prochain_ct" class="text-sm text-gray-900"></span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-sm font-medium text-gray-500">Statut:</span>
                            <span id="view_statut" class="text-sm"></span>
                        </div>
                    </div>

                    <h4 class="text-lg font-semibold text-blue-600 mt-6 mb-4">Notes</h4>
                    <div class="bg-gray-50 p-4 rounded-md">
                        <p id="view_notes" class="text-sm text-gray-700"></p>
                    </div>
                </div>
            </div>

            <div class="mt-8">
                <h4 class="text-lg font-semibold text-blue-600 mb-4">Historique des interventions</h4>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Description</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Statut</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Kilométrage</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Technicien</th>
                            </tr>
                        </thead>
                        <tbody id="view_interventions" class="bg-white divide-y divide-gray-200">
                            <!-- Les interventions seront chargées dynamiquement -->
                        </tbody>
                    </table>
                </div>
            </div>

      
            <div class="mt-6 pt-6 border-t border-gray-200 flex justify-end space-x-3">
                <button type="button" onclick="closeModal('viewVehicleModal')" class="px-4 py-2 bg-gray-200 text-gray-700 rounded-md hover:bg-gray-300 focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2">
                    Fermer
                </button>
                <a id="edit_vehicle_link" href="#" class="px-4 py-2 bg-indigo-500 text-white rounded-md hover:bg-indigo-600 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2">
                    Modifier
                </a>
            </div>
        </div>
    </div>
</div>


<!-- Edit Vehicle Modal -->
<div id="editVehicleModal" class="fixed inset-0 z-50 overflow-auto bg-black bg-opacity-50 flex items-center justify-center hidden">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-4xl mx-4">
        <div class="flex justify-between items-center p-6 border-b">
            <h3 class="text-xl font-semibold text-gray-900">Modifier le véhicule</h3>
            <button onclick="closeModal('editVehicleModal')" class="text-gray-400 hover:text-gray-500">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                </svg>
            </button>
        </div>

        <form action="edit.php" method="POST" class="p-6">
            <input type="hidden" id="edit_id" name="id" value="">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <label for="edit_immatriculation" class="block text-sm font-medium text-gray-700 mb-1">Immatriculation</label>
                    <input type="text" id="edit_immatriculation" name="immatriculation" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500" required>
                </div>
                <div>
                    <label for="edit_client" class="block text-sm font-medium text-gray-700 mb-1">Client</label>
                    <select id="edit_client" name="client_id" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500" required>
                        <option value="">Sélectionner un client</option>
                        <?php foreach ($clients as $client): ?>
                            <option value="<?php echo $client['id']; ?>"><?php echo htmlspecialchars($client['nom'] . ' ' . $client['prenom']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="edit_marque" class="block text-sm font-medium text-gray-700 mb-1">Marque</label>
                    <select id="edit_marque" name="marque" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500" required>
                        <option value="0">:: MARQUE ::</option>
                        <option value="ABARTH">ABARTH</option>
                        <option value="ALFA ROMEO">ALFA ROMEO</option>
                        <option value="AUDI">AUDI</option>
                        <option value="BMW">BMW</option>
                        <option value="BYD">BYD</option>
                        <option value="CHANGAN">CHANGAN</option>
                        <option value="CHERY">CHERY</option>
                        <option value="CITROEN">CITROEN</option>
                        <option value="CUPRA">CUPRA</option>
                        <option value="DACIA">DACIA</option>
                        <option value="DFSK">DFSK</option>
                        <option value="DS">DS</option>
                        <option value="FIAT">FIAT</option>
                        <option value="FORD">FORD</option>
                        <option value="GEELY">GEELY</option>
                        <option value="GWM">GWM</option>
                        <option value="HONDA">HONDA</option>
                        <option value="HYUNDAI">HYUNDAI</option>
                        <option value="JAECOO">JAECOO</option>
                        <option value="JAGUAR">JAGUAR</option>
                        <option value="JEEP">JEEP</option>
                        <option value="KIA">KIA</option>
                        <option value="LAND ROVER">LAND ROVER</option>
                        <option value="LEXUS">LEXUS</option>
                        <option value="MAHINDRA">MAHINDRA</option>
                        <option value="MASERATI">MASERATI</option>
                        <option value="MAZDA">MAZDA</option>
                        <option value="MERCEDES">MERCEDES</option>
                        <option value="MG">MG</option>
                        <option value="MINI">MINI</option>
                        <option value="MITSUBISHI">MITSUBISHI</option>
                        <option value="NISSAN">NISSAN</option>
                        <option value="OMODA">OMODA</option>
                        <option value="OPEL">OPEL</option>
                        <option value="PEUGEOT">PEUGEOT</option>
                        <option value="PORSCHE">PORSCHE</option>
                        <option value="RENAULT">RENAULT</option>
                        <option value="SEAT">SEAT</option>
                        <option value="SERES">SERES</option>
                        <option value="SKODA">SKODA</option>
                        <option value="SUZUKI">SUZUKI</option>
                        <option value="TOYOTA">TOYOTA</option>
                        <option value="VOLKSWAGEN">VOLKSWAGEN</option>
                        <option value="VOLVO">VOLVO</option>
                    </select>
                </div>
                <div>
                    <label for="edit_modele" class="block text-sm font-medium text-gray-700 mb-1">Modèle</label>
                    <input type="text" id="edit_modele" name="modele" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500" required>
                </div>
                <div>
                    <label for="edit_annee" class="block text-sm font-medium text-gray-700 mb-1">Année</label>
                    <input type="number" id="edit_annee" name="annee" min="1900" max="<?php echo date('Y') + 1; ?>" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500" required>
                </div>
                <div>
                    <label for="edit_kilometrage" class="block text-sm font-medium text-gray-700 mb-1">Kilométrage</label>
                    <input type="number" id="edit_kilometrage" name="kilometrage" min="0" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500" required>
                </div>
                <div>
                    <label for="edit_couleur" class="block text-sm font-medium text-gray-700 mb-1">Couleur</label>
                    <input type="text" id="edit_couleur" name="couleur" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                </div>
                <div>
                    <label for="edit_carburant" class="block text-sm font-medium text-gray-700 mb-1">Type de carburant</label>
                    <select id="edit_carburant" name="carburant" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                        <option value="">Sélectionner un type</option>
                        <option value="essence">Essence</option>
                        <option value="diesel">Diesel</option>
                        <option value="hybride">Hybride</option>
                        <option value="electrique">Électrique</option>
                        <option value="gpl">GPL</option>
                    </select>
                </div>
                <div>
                    <label for="edit_puissance" class="block text-sm font-medium text-gray-700 mb-1">Puissance fiscale (CV)</label>
                    <input type="number" id="edit_puissance" name="puissance" min="1" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                </div>
                <div>
                    <label for="edit_date_mise_circulation" class="block text-sm font-medium text-gray-700 mb-1">Date de mise en circulation</label>
                    <input type="date" id="edit_date_mise_circulation" name="date_mise_circulation" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                </div>
                <div>
                    <label for="edit_date_derniere_revision" class="block text-sm font-medium text-gray-700 mb-1">Date dernière révision</label>
                    <input type="date" id="edit_date_derniere_revision" name="date_derniere_revision" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                </div>
                <div>
                    <label for="edit_date_prochain_ct" class="block text-sm font-medium text-gray-700 mb-1">Date prochain contrôle technique</label>
                    <input type="date" id="edit_date_prochain_ct" name="date_prochain_ct" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                </div>
                <div>
                    <label for="edit_statut" class="block text-sm font-medium text-gray-700 mb-1">Statut</label>
                    <select id="edit_statut" name="statut" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                        <option value="actif">Actif</option>
                        <option value="maintenance">En maintenance</option>
                        <option value="inactif">Inactif</option>
                    </select>
                </div>
            </div>
            
            <div class="mt-6">
                <label for="edit_notes" class="block text-sm font-medium text-gray-700 mb-1">Notes</label>
                <textarea id="edit_notes" name="notes" rows="3" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500"></textarea>
            </div>

            <div class="mt-6 pt-6 border-t border-gray-200 flex justify-end space-x-3">
                <button type="button" onclick="closeModal('editVehicleModal')" class="px-4 py-2 bg-gray-200 text-gray-700 rounded-md hover:bg-gray-300 focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2">
                    Annuler
                </button>
                <button type="submit" class="px-4 py-2 bg-blue-500 text-white rounded-md hover:bg-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
                    Enregistrer les modifications
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Delete Vehicle Confirmation Modal -->
<div id="deleteVehicleModal" class="fixed inset-0 z-50 overflow-auto bg-black bg-opacity-50 flex items-center justify-center hidden">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-md mx-4">
        <div class="p-6">
            <div class="flex items-center justify-center mb-4">
                <div class="bg-red-100 rounded-full p-3">
                    <svg class="w-8 h-8 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                    </svg>
                </div>
            </div>
            <h3 class="text-xl font-semibold text-center text-gray-900 mb-4">Confirmer la suppression</h3>
            <p class="text-gray-700 text-center mb-6">Êtes-vous sûr de vouloir supprimer ce véhicule ? Cette action est irréversible.</p>
            <div class="flex justify-center space-x-4">
                <button type="button" onclick="closeModal('deleteVehicleModal')" class="px-4 py-2 bg-gray-200 text-gray-700 rounded-md hover:bg-gray-300 focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2">
                    Annuler
                </button>
                <form id="deleteVehicleForm" action="delete.php" method="POST">
                    <input type="hidden" id="delete_id" name="id" value="">
                    <button type="submit" class="px-4 py-2 bg-red-500 text-white rounded-md hover:bg-red-600 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2">
                        Supprimer
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
    // Variables pour stocker les données du véhicule actuel
    let currentVehicleId = null;
    let currentVehicleData = null;

    // Fonction pour ouvrir une modal
    function openModal(modalId) {
        document.getElementById(modalId).classList.remove('hidden');
    }

    // Fonction pour fermer une modal
    function closeModal(modalId) {
        document.getElementById(modalId).classList.add('hidden');
    }

    // Fonction pour afficher les détails d'un véhicule
    function viewVehicle(id) {
    currentVehicleId = id;
    
    // Faire une requête AJAX pour récupérer les détails du véhicule
    fetch(`get_vehicle.php?id=${id}`)
        .then(response => response.json())
        .then(data => {
            currentVehicleData = data;
            
            // Remplir les champs du modal avec les données reçues
            document.getElementById('view_immatriculation').textContent = data.immatriculation || '-';
            document.getElementById('view_marque').textContent = data.marque || '-';
            document.getElementById('view_modele').textContent = data.modele || '-';
            document.getElementById('view_annee').textContent = data.annee || '-';
            document.getElementById('view_couleur').textContent = data.couleur || '-';
            document.getElementById('view_carburant').textContent = data.carburant || '-';
            document.getElementById('view_puissance').textContent = data.puissance ? `${data.puissance} CV` : '-';
            
            document.getElementById('view_client').textContent = data.client || '-';
            document.getElementById('view_telephone').textContent = data.telephone || '-';
            document.getElementById('view_email').textContent = data.email || '-';
            
            document.getElementById('view_kilometrage').textContent = data.kilometrage ? `${data.kilometrage} km` : '-';
            document.getElementById('view_date_mise_circulation').textContent = data.date_mise_circulation || '-';
            document.getElementById('view_date_derniere_revision').textContent = data.date_derniere_revision || '-';
            document.getElementById('view_date_prochain_ct').textContent = data.date_prochain_ct || '-';
            
            // Afficher le statut avec la bonne classe CSS
            let statusClass = '';
            let statusText = '';
            
            switch (data.statut) {
                case 'actif':
                    statusClass = 'bg-green-100 text-green-800';
                    statusText = 'Actif';
                    break;
                case 'maintenance':
                    statusClass = 'bg-yellow-100 text-yellow-800';
                    statusText = 'En maintenance';
                    break;
                case 'inactif':
                    statusClass = 'bg-red-100 text-red-800';
                    statusText = 'Inactif';
                    break;
                default:
                    statusClass = 'bg-gray-100 text-gray-800';
                    statusText = data.statut || '-';
            }
            
            document.getElementById('view_statut').innerHTML = `
                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full ${statusClass}">
                    ${statusText}
                </span>
            `;
            
            document.getElementById('view_notes').textContent = data.notes || 'Aucune note disponible';
            
            // Mettre à jour le lien pour modifier le véhicule
            document.getElementById('edit_vehicle_link').href = `edit.php?id=${id}`;
            
            // Afficher les interventions
            const interventionsTable = document.getElementById('view_interventions');
            interventionsTable.innerHTML = '';
            
            if (data.interventions && data.interventions.length > 0) {
                data.interventions.forEach(intervention => {
                    let statusClass = '';
                    let statusText = '';
                    
                    switch (intervention.statut) {
                        case 'En attente':
                            statusClass = 'bg-blue-100 text-blue-800';
                            break;
                        case 'En cours':
                            statusClass = 'bg-yellow-100 text-yellow-800';
                            break;
                        case 'Terminée':
                            statusClass = 'bg-green-100 text-green-800';
                            break;
                        case 'Facturée':
                            statusClass = 'bg-purple-100 text-purple-800';
                            break;
                        case 'Annulée':
                            statusClass = 'bg-red-100 text-red-800';
                            break;
                        default:
                            statusClass = 'bg-gray-100 text-gray-800';
                    }
                    
                    const row = document.createElement('tr');
                    row.innerHTML = `
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">${intervention.date_creation || '-'}</td>
                        <td class="px-6 py-4 text-sm text-gray-500">${intervention.description || '-'}</td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full ${statusClass}">
                                ${intervention.statut || '-'}
                            </span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">${intervention.kilometrage ? `${intervention.kilometrage} km` : '-'}</td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">${intervention.technicien || '-'}</td>
                    `;
                    interventionsTable.appendChild(row);
                });
            } else {
                const row = document.createElement('tr');
                row.innerHTML = `
                    <td colspan="5" class="px-6 py-4 text-center text-sm text-gray-500">
                        Aucune intervention enregistrée pour ce véhicule
                    </td>
                `;
                interventionsTable.appendChild(row);
            }
            
            // Ouvrir la modal
            openModal('viewVehicleModal');
        })
        .catch(error => {
            console.error('Erreur lors de la récupération des détails du véhicule:', error);
            alert('Une erreur est survenue lors de la récupération des détails du véhicule.');
        });
}

    // Fonction pour éditer un véhicule
    function editVehicle(id) {
        // Faire une requête AJAX pour récupérer les détails du véhicule
        fetch(`get_vehicle.php?id=${id}`)
            .then(response => response.json())
            .then(data => {
                // Remplir les champs du formulaire avec les données reçues
                document.getElementById('edit_id').value = data.id;
                document.getElementById('edit_immatriculation').value = data.immatriculation || '';
                document.getElementById('edit_client').value = data.client_id || '';
                document.getElementById('edit_marque').value = data.marque || '';
                document.getElementById('edit_modele').value = data.modele || '';
                document.getElementById('edit_annee').value = data.annee || '';
                document.getElementById('edit_kilometrage').value = data.kilometrage || '';
                document.getElementById('edit_couleur').value = data.couleur || '';
                document.getElementById('edit_carburant').value = data.carburant || '';
                document.getElementById('edit_puissance').value = data.puissance || '';
                document.getElementById('edit_date_mise_circulation').value = data.date_mise_circulation || '';
                document.getElementById('edit_date_derniere_revision').value = data.date_derniere_revision || '';
                document.getElementById('edit_date_prochain_ct').value = data.date_prochain_ct || '';
                document.getElementById('edit_statut').value = data.statut || 'actif';
                document.getElementById('edit_notes').value = data.notes || '';
                
                // Ouvrir la modal
                openModal('editVehicleModal');
            })
            .catch(error => {
                console.error('Erreur lors de la récupération des détails du véhicule:', error);
                alert('Une erreur est survenue lors de la récupération des détails du véhicule.');
            });
    }

    // Fonction pour éditer un véhicule depuis la vue détaillée
    function editVehicleFromView() {
        if (currentVehicleId) {
            closeModal('viewVehicleModal');
            editVehicle(currentVehicleId);
        }
    }

    // Fonction pour supprimer un véhicule
    function deleteVehicle(id) {
        document.getElementById('delete_id').value = id;
        openModal('deleteVehicleModal');
    }

    // Fermer les modals en cliquant à l'extérieur
    window.onclick = function(event) {
        const modals = document.querySelectorAll('.fixed.inset-0');
        modals.forEach(modal => {
            if (event.target === modal) {
                modal.classList.add('hidden');
            }
        });
    }
</script>

<?php include '../includes/footer.php'; ?>
