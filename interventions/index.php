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

$database = new Database();
$db = $database->getConnection();
// Vérifier si l'utilisateur est connecté, sinon rediriger vers la page de connexion
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

// Récupérer les informations de l'utilisateur actuel
$currentUser = [];
if (isset($_SESSION['user_id'])) {
    $user_id = $_SESSION['user_id'];
    $stmt = $db->prepare("SELECT nom, prenom, role FROM users WHERE id = ?");
    if ($stmt) {
        $stmt->bindParam(1, $user_id, PDO::PARAM_INT);
        $stmt->execute();
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($user) {
            $currentUser = [
                'name' => $user['prenom'] . ' ' . $user['nom'],
                'role' => $user['role']
            ];
        }
    }
}

// Inclure l'en-tête
include '../includes/header.php';


// Récupération des catégories pour le formulaire d'articles
function getCategories($db) {
    try {
        $query = "SELECT id, nom, with_article FROM categorie ORDER BY nom ASC";
        $stmt = $db->prepare($query);
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('Erreur lors de la récupération des catégories: ' . $e->getMessage());
        return [];
    }
}

$categories = getCategories($db);

// Récupération des techniciens
function getTechniciens($db) {
    try {
        $query = "SELECT t.id, CONCAT(t.prenom, ' ', t.nom) AS nom_complet, t.specialite 
                  FROM technicien t 
                  ORDER BY t.nom ASC";
        $stmt = $db->prepare($query);
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('Erreur lors de la récupération des techniciens: ' . $e->getMessage());
        return [];
    }
}

$techniciens = getTechniciens($db);

// Récupération des véhicules
$vehicules = [];
try {
    $query = "SELECT v.*, CONCAT(c.nom, ' ', c.prenom) AS client_nom, c.id AS client_id
              FROM vehicules v
              INNER JOIN clients c ON v.client_id = c.id
              ORDER BY v.marque, v.modele";
    $stmt = $db->prepare($query);
    $stmt->execute();
    
    if ($stmt->rowCount() > 0) {
        $vehicules = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } 
} catch (PDOException $e) {
    $errors['database'] = 'Erreur lors de la récupération des véhicules: ' . $e->getMessage();
}

// Données pour les interventions
$interventions = [];
try {
    // Paramètres de filtrage et pagination
    $statut_filter = isset($_GET['statut']) ? $_GET['statut'] : '';
    $search = isset($_GET['search']) ? $_GET['search'] : '';
    $view_mode = isset($_GET['view']) ? $_GET['view'] : 'list'; // 'list' ou 'calendar'
    
    $interventionsParPage = 10; // Nombre d'interventions par page
    $pageCourante = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
    $debut = ($pageCourante - 1) * $interventionsParPage;

    // Construction de la requête avec filtres
    $where_clauses = [];
    $params = [];
    
    if (!empty($statut_filter)) {
        $where_clauses[] = "i.statut = :statut";
        $params[':statut'] = $statut_filter;
    }
    
    if (!empty($search)) {
        $where_clauses[] = "(v.immatriculation LIKE :search OR c.nom LIKE :search OR c.prenom LIKE :search OR i.description LIKE :search)";
        $params[':search'] = "%$search%";
    }
    
    $where_sql = !empty($where_clauses) ? "WHERE " . implode(" AND ", $where_clauses) : "";

    // Récupérer le nombre total d'interventions avec filtres
    $query_count = "SELECT COUNT(*) 
                    FROM interventions i
                    INNER JOIN vehicules v ON i.vehicule_id = v.id
                    INNER JOIN clients c ON v.client_id = c.id
                    LEFT JOIN technicien t ON i.technicien_id = t.id
                    $where_sql";
    
    $stmt_count = $db->prepare($query_count);
    foreach ($params as $key => $value) {
        $stmt_count->bindValue($key, $value);
    }
    $stmt_count->execute();
    $totalInterventions = $stmt_count->fetchColumn();
    $totalPages = ceil($totalInterventions / $interventionsParPage);

    // Requête paginée avec filtres
    $query = "SELECT i.id, i.date_creation, i.date_prevue, DATE(i.date_debut) AS date_debut,
                 DATE(i.date_fin) AS date_fin, 
                 i.description, i.diagnostique, i.kilometrage, i.statut, i.commentaire, i.commande_id,
                 v.immatriculation, CONCAT(v.marque, ' ', v.modele) AS vehicule_info,
                 i.date_prochain_ct, -- Ajout du champ date_prochain_ct
                 CONCAT(c.nom, ' ', c.prenom) AS client, c.id AS client_id,
                 CONCAT(t.prenom, ' ', t.nom) AS technicien,
                 t.specialite AS technicien_specialite
          FROM interventions i
          INNER JOIN vehicules v ON i.vehicule_id = v.id
          INNER JOIN clients c ON v.client_id = c.id
          LEFT JOIN technicien t ON i.technicien_id = t.id
          $where_sql
          ORDER BY i.date_creation DESC
          LIMIT :debut, :interventionsParPage";


    $stmt = $db->prepare($query);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':debut', $debut, PDO::PARAM_INT);
    $stmt->bindValue(':interventionsParPage', $interventionsParPage, PDO::PARAM_INT);
    $stmt->execute();
    $interventions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Comptage par statut pour les statistiques
    $query_stats = "SELECT statut, COUNT(*) as count FROM interventions GROUP BY statut";
    $stmt_stats = $db->query($query_stats);
    $stats = [];
    while ($row = $stmt_stats->fetch(PDO::FETCH_ASSOC)) {
        $stats[$row['statut']] = $row['count'];
    }
    
    // Valeurs par défaut si certains statuts n'existent pas encore
    $statuts = ['En attente', 'En cours', 'Terminée', 'Facturée', 'Annulée'];
    foreach ($statuts as $statut) {
        if (!isset($stats[$statut])) {
            $stats[$statut] = 0;
        }
    }
    
} catch (PDOException $e) {
    $errors['database'] = 'Erreur lors de la récupération des interventions : ' . $e->getMessage();
}
?>

<div class="flex h-screen bg-gray-100">
    <!-- Sidebar -->
    <?php include '../includes/sidebar.php'; ?>

    <!-- Main Content -->
    <div class="flex-1 overflow-y-auto">
        <!-- Top header -->
        <div class="bg-white shadow-sm">
            <div class="container mx-auto px-6 py-4 flex justify-between items-center">
                <h1 class="text-2xl font-semibold text-gray-800">Gestion des Interventions</h1>
                <div class="flex items-center space-x-4">
                    <div class="relative">
                        <span class="text-gray-700"><?php echo isset($currentUser['name']) ? htmlspecialchars($currentUser['name']) : 'Utilisateur'; ?></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Main Content -->
        <div class="container mx-auto px-6 py-8">
            <!-- Notifications -->
            <?php if (isset($_SESSION['success'])): ?>
                <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-6 rounded" role="alert">
                    <p><?php echo $_SESSION['success']; ?></p>
                </div>
                <?php unset($_SESSION['success']); ?>
            <?php endif; ?>
            
            <?php if (isset($_SESSION['error'])): ?>
                <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6 rounded" role="alert">
                    <p><?php echo $_SESSION['error']; ?></p>
                </div>
                <?php unset($_SESSION['error']); ?>
            <?php endif; ?>

            <!-- Statistics Cards -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-6 mb-8">
                <!-- Total Interventions Card -->
                <div class="bg-white p-6 rounded-lg shadow-md transition duration-300 hover:shadow-lg">
                    <div class="flex items-center justify-between">
                        <div>
                            <h3 class="text-lg font-semibold text-gray-700">Total</h3>
                            <p class="text-3xl font-bold text-gray-800 mt-2"><?php echo $totalInterventions; ?></p>
                        </div>
                        <div class="bg-blue-100 p-3 rounded-full">
                            <svg class="w-8 h-8 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path>
                            </svg>
                        </div>
                    </div>
                    <div class="mt-4">
                        <a href="?view=<?php echo $view_mode; ?>" class="text-blue-500 hover:text-blue-700 text-sm font-semibold">Voir toutes →</a>
                    </div>
                </div>

                <!-- En attente Card -->
                <div class="bg-white p-6 rounded-lg shadow-md transition duration-300 hover:shadow-lg">
                    <div class="flex items-center justify-between">
                        <div>
                            <h3 class="text-lg font-semibold text-gray-700">En attente</h3>
                            <p class="text-3xl font-bold text-gray-800 mt-2"><?php echo $stats['En attente']; ?></p>
                        </div>
                        <div class="bg-yellow-100 p-3 rounded-full">
                            <svg class="w-8 h-8 text-yellow-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                            </svg>
                        </div>
                    </div>
                    <div class="mt-4">
                        <a href="?view=<?php echo $view_mode; ?>&statut=En+attente" class="text-yellow-500 hover:text-yellow-700 text-sm font-semibold">Voir les détails →</a>
                    </div>
                </div>

                <!-- En cours Card -->
                <div class="bg-white p-6 rounded-lg shadow-md transition duration-300 hover:shadow-lg">
                    <div class="flex items-center justify-between">
                        <div>
                            <h3 class="text-lg font-semibold text-gray-700">En cours</h3>
                            <p class="text-3xl font-bold text-gray-800 mt-2"><?php echo $stats['En cours']; ?></p>
                        </div>
                        <div class="bg-blue-100 p-3 rounded-full">
                            <svg class="w-8 h-8 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5.882V19.24a1.76 1.76 0 01-3.417.592l-2.147-6.15M18 13a3 3 0 100-6M5.436 13.683A4.001 4.001 0 017 6h1.832c4.1 0 7.625-1.234 9.168-3v14c-1.543-1.766-5.067-3-9.168-3H7a3.988 3.988 0 01-1.564-.317z"></path>
                            </svg>
                        </div>
                    </div>
                    <div class="mt-4">
                        <a href="?view=<?php echo $view_mode; ?>&statut=En+cours" class="text-blue-500 hover:text-blue-700 text-sm font-semibold">Voir les détails →</a>
                    </div>
                </div>

                <!-- Terminée Card -->
                <div class="bg-white p-6 rounded-lg shadow-md transition duration-300 hover:shadow-lg">
                    <div class="flex items-center justify-between">
                        <div>
                            <h3 class="text-lg font-semibold text-gray-700">Terminée</h3>
                            <p class="text-3xl font-bold text-gray-800 mt-2"><?php echo $stats['Terminée']; ?></p>
                        </div>
                        <div class="bg-green-100 p-3 rounded-full">
                            <svg class="w-8 h-8 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                            </svg>
                        </div>
                    </div>
                    <div class="mt-4">
                        <a href="?view=<?php echo $view_mode; ?>&statut=Terminée" class="text-green-500 hover:text-green-700 text-sm font-semibold">Voir les détails →</a>
                    </div>
                </div>

                <!-- Facturée Card -->
                <div class="bg-white p-6 rounded-lg shadow-md transition duration-300 hover:shadow-lg">
                    <div class="flex items-center justify-between">
                        <div>
                            <h3 class="text-lg font-semibold text-gray-700">Facturée</h3>
                            <p class="text-3xl font-bold text-gray-800 mt-2"><?php echo $stats['Facturée']; ?></p>
                        </div>
                        <div class="bg-purple-100 p-3 rounded-full">
                            <svg class="w-8 h-8 text-purple-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                            </svg>
                        </div>
                    </div>
                    <div class="mt-4">
                        <a href="?view=<?php echo $view_mode; ?>&statut=Facturée" class="text-purple-500 hover:text-purple-700 text-sm font-semibold">Voir les détails →</a>
                    </div>
                </div>
            </div>

            <!-- View Toggle -->
            <div class="bg-white rounded-lg shadow-md p-4 mb-6">
                <div class="flex justify-between items-center">
                    <h3 class="text-xl font-semibold text-gray-800">Interventions</h3>
                    <div class="flex items-center space-x-2">
                        <button onclick="addIntervention()" class="px-4 py-2 bg-green-600 text-white rounded-md flex items-center hover:bg-green-700 transition duration-200">
                            <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                            </svg>
                            Ajouter une intervention
                        </button>
                        <div class="bg-gray-200 rounded-lg p-1 flex">
                            <a href="?view=list<?php echo !empty($statut_filter) ? '&statut='.urlencode($statut_filter) : ''; ?><?php echo !empty($search) ? '&search='.urlencode($search) : ''; ?>" class="px-3 py-1.5 rounded-md <?php echo $view_mode === 'list' ? 'bg-white shadow-sm' : 'text-gray-700 hover:bg-gray-100'; ?>">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16"></path>
                                </svg>
                            </a>
                            <a href="?view=calendar<?php echo !empty($statut_filter) ? '&statut='.urlencode($statut_filter) : ''; ?><?php echo !empty($search) ? '&search='.urlencode($search) : ''; ?>" class="px-3 py-1.5 rounded-md <?php echo $view_mode === 'calendar' ? 'bg-white shadow-sm' : 'text-gray-700 hover:bg-gray-100'; ?>">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                                </svg>
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Search and Filter -->
            <div class="bg-white rounded-lg shadow-md p-6 mb-6">
                <form action="" method="GET" class="flex flex-col md:flex-row gap-4 w-full">
                    <input type="hidden" name="view" value="<?php echo $view_mode; ?>">
                    <div class="relative flex-1">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                            </svg>
                        </div>
                        <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" class="pl-10 pr-4 py-2 w-full border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500" placeholder="Rechercher une intervention...">
                    </div>
                    <div class="flex gap-4">
                        <select name="statut" class="px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                            <option value="">Tous les statuts</option>
                            <option value="En attente" <?php echo $statut_filter === 'En attente' ? 'selected' : ''; ?>>En attente</option>
                            <option value="En cours" <?php echo $statut_filter === 'En cours' ? 'selected' : ''; ?>>En cours</option>
                            <option value="Terminée" <?php echo $statut_filter === 'Terminée' ? 'selected' : ''; ?>>Terminée</option>
                            <option value="Facturée" <?php echo $statut_filter === 'Facturée' ? 'selected' : ''; ?>>Facturée</option>
                            <option value="Annulée" <?php echo $statut_filter === 'Annulée' ? 'selected' : ''; ?>>Annulée</option>
                        </select>
                        <button type="submit" class="px-4 py-2 bg-blue-500 text-white rounded-md hover:bg-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
                            Filtrer
                        </button>
                        <?php if (!empty($search) || !empty($statut_filter)): ?>
                            <a href="?view=<?php echo $view_mode; ?>" class="px-4 py-2 bg-gray-200 text-gray-700 rounded-md hover:bg-gray-300 focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2">
                                Réinitialiser
                            </a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <?php if ($view_mode === 'calendar'): ?>
            <!-- Calendar View -->
            <div class="bg-white rounded-lg shadow-md p-6 mb-8">
                <div class="flex justify-between items-center mb-6">
                    <h3 class="text-xl font-semibold text-gray-800">Calendrier des interventions</h3>
                    <div class="flex gap-2">
                        <button id="prev-month-btn" class="px-3 py-1 bg-gray-200 text-gray-700 rounded-md hover:bg-gray-300">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>
                            </svg>
                        </button>
                        <div id="month-year-display" class="px-3 py-1 font-medium"></div>
                        <button id="next-month-btn" class="px-3 py-1 bg-gray-200 text-gray-700 rounded-md hover:bg-gray-300">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                            </svg>
                        </button>
                    </div>
                </div>
                
                <div class="calendar-container">
                    <div class="weekdays grid grid-cols-7 text-center font-medium bg-gray-100 py-2">
                        <div>Dim</div>
                        <div>Lun</div>
                        <div>Mar</div>
                        <div>Mer</div>
                        <div>Jeu</div>
                        <div>Ven</div>
                        <div>Sam</div>
                    </div>
                    
                    <div id="calendar-grid" class="grid grid-cols-7 grid-rows-6 gap-1 bg-gray-200 p-1">
                        <!-- Les jours du calendrier seront générés par JavaScript -->
                    </div>
                </div>
            </div>
            <?php else: ?>
            <!-- Interventions List -->
            <div class="bg-white rounded-lg shadow-md p-6 mb-8">
                <!-- Table -->
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr><!-- 
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">ID</th> -->
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date création</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date prévue</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Prochain CT</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Description</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Client/Véhicule</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Technicien</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Statut</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Commande</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php if (empty($interventions)): ?>
                                <tr>
                                    <td colspan="9" class="px-6 py-4 text-center text-gray-500">Aucune intervention trouvée</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($interventions as $intervention): ?>
                                    <tr class="hover:bg-gray-50">
                                        <!-- <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo $intervention['id']; ?></td>
                                        --> <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            <?php echo date('d/m/Y', strtotime($intervention['date_creation'])); ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            <?php echo $intervention['date_prevue'] ? date('d/m/Y', strtotime($intervention['date_prevue'])) : '-'; ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            <?php echo $intervention['date_prochain_ct'] ? date('d/m/Y', strtotime($intervention['date_prochain_ct'])) : '-'; ?>
                                        </td>
                                        <td class="px-6 py-4 text-sm text-gray-500 max-w-xs truncate">
                                            <?php echo htmlspecialchars(substr($intervention['description'], 0, 50)) . (strlen($intervention['description']) > 50 ? '...' : ''); ?>
                                        </td>
                                        <td class="px-6 py-4 text-sm text-gray-500">
                                            <div><?php echo htmlspecialchars($intervention['client']); ?></div>
                                            <div class="text-xs text-gray-400"><?php echo htmlspecialchars($intervention['immatriculation']); ?></div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm">
                                            <?php if ($intervention['technicien']): ?>
                                                <div class="text-gray-700"><?php echo htmlspecialchars($intervention['technicien']); ?></div>
                                                <div class="text-xs text-gray-500"><?php echo htmlspecialchars($intervention['technicien_specialite']); ?></div>
                                            <?php else: ?>
                                                <span class="text-gray-400 italic">Non assigné</span>
                                                <button onclick="assignTechnicien(<?php echo $intervention['id']; ?>)" class="ml-2 text-xs text-blue-500 hover:text-blue-700">
                                                    Assigner
                                                </button>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <?php                                             $statusClass = '';
                                            switch ($intervention['statut']) {
                                                case 'En attente':
                                                    $statusClass = 'bg-yellow-100 text-yellow-800';
                                                    break;
                                                case 'En cours':
                                                    $statusClass = 'bg-blue-100 text-blue-800';
                                                    break;
                                                case 'Terminée':
                                                    $statusClass = 'bg-green-100 text-green-800';
                                                    break;
                                                case 'Facturée':
                                                    $statusClass = 'bg-purple-100 text-purple-800';
                                                    break;
                                                case 'Annulée':
                                                    $statusClass = 'bg-red-100 text-red-800';
                                                    break;
                                            }
                                            ?>
                                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full <?php echo $statusClass; ?>">
                                                <?php echo $intervention['statut']; ?>
                                            </span>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm">
                                            <?php if ($intervention['commande_id']): ?>
                                                <a href="../orders/view.php?id=<?php echo $intervention['commande_id']; ?>" class="text-blue-600 hover:text-blue-900">
                                                    Commande #<?php echo $intervention['commande_id']; ?>
                                                </a>
                                            <?php else: ?>
                                                <button onclick="createCommandeFromIntervention(<?php echo $intervention['id']; ?>)" class="text-xs bg-blue-100 text-blue-700 px-2 py-1 rounded hover:bg-blue-200">
                                                    Créer commande
                                                </button>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                            <div class="flex space-x-2">
                                                <button onclick="viewIntervention(<?php echo $intervention['id']; ?>)" class="text-blue-600 hover:text-blue-900">Voir</button>
                                                <button onclick="editIntervention(<?php echo $intervention['id']; ?>)" class="text-indigo-600 hover:text-indigo-900">Modifier</button>
                                                <button onclick="deleteIntervention(<?php echo $intervention['id']; ?>)" class="text-red-600 hover:text-red-900">Supprimer</button>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <?php if ($totalPages > 1): ?>
                    <div class="flex justify-between items-center mt-6">
                        <div class="text-sm text-gray-500">
                            Affichage de <span class="font-medium"><?= $debut + 1 ?></span> à 
                            <span class="font-medium"><?= min($debut + $interventionsParPage, $totalInterventions) ?></span> 
                            sur <span class="font-medium"><?= $totalInterventions ?></span> résultats
                        </div>
                        <div class="flex space-x-1">
                        <?php 
                            // Construire l'URL de base pour la pagination en conservant les filtres
                            $url_params = [];
                            if (!empty($search)) $url_params[] = "search=" . urlencode($search);
                            if (!empty($statut_filter)) $url_params[] = "statut=" . urlencode($statut_filter);
                            if (!empty($view_mode)) $url_params[] = "view=" . urlencode($view_mode);
                            $url_base = "?" . implode("&", $url_params);
                            $url_base = !empty($url_base) ? $url_base . "&" : "?";
                        ?>
                        
                        <?php if ($pageCourante > 1): ?>
                            <a href="<?= $url_base ?>page=<?= $pageCourante - 1 ?>" class="px-3 py-1 border border-gray-300 rounded-md text-sm font-medium text-gray-700 bg-white hover:bg-gray-50">Précédent</a>
                        <?php else: ?>
                            <span class="px-3 py-1 border border-gray-200 rounded-md text-sm font-medium text-gray-400 bg-gray-100 cursor-not-allowed">Précédent</span>
                        <?php endif; ?>
                        
                        <?php 
                            // Afficher un nombre limité de pages avec ellipses
                            $range = 2; // Nombre de pages à afficher de chaque côté de la page courante
                            
                            for ($i = 1; $i <= $totalPages; $i++): 
                                // Afficher la première page, la dernière page, et les pages autour de la page courante
                                if ($i == 1 || $i == $totalPages || ($i >= $pageCourante - $range && $i <= $pageCourante + $range)):
                        ?>
                            <?php if ($pageCourante == $i): ?>
                                <span class="px-3 py-1 border border-blue-500 rounded-md text-sm font-medium text-white bg-blue-500"><?= $i ?></span>
                            <?php else: ?>
                                <a href="<?= $url_base ?>page=<?= $i ?>" class="px-3 py-1 border border-gray-300 rounded-md text-sm font-medium text-gray-700 bg-white hover:bg-gray-50"><?= $i ?></a>
                            <?php endif; ?>
                        <?php 
                            // Ajouter des ellipses
                            elseif (($i == 2 && $pageCourante - $range > 2) || ($i == $totalPages - 1 && $pageCourante + $range < $totalPages - 1)): 
                        ?>
                            <span class="px-2 py-1 text-gray-500">...</span>
                        <?php endif; ?>
                        <?php endfor; ?>
                        
                        <?php if ($pageCourante < $totalPages): ?>
                            <a href="<?= $url_base ?>page=<?= $pageCourante + 1 ?>" class="px-3 py-1 border border-gray-300 rounded-md text-sm font-medium text-gray-700 bg-white hover:bg-gray-50">Suivant</a>
                        <?php else: ?>
                            <span class="px-3 py-1 border border-gray-200 rounded-md text-sm font-medium text-gray-400 bg-gray-100 cursor-not-allowed">Suivant</span>
                        <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Add Intervention Modal -->
<div id="addInterventionModal" class="fixed inset-0 bg-black bg-opacity-50 z-50 hidden flex items-center justify-center p-4">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-4xl max-h-[90vh] overflow-y-auto">
        <div class="flex justify-between items-center p-6 border-b">
            <h3 class="text-lg font-semibold text-gray-800">Ajouter une intervention</h3>
            <button onclick="closeModal('addInterventionModal')" class="text-gray-400 hover:text-gray-500">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                </svg>
            </button>
        </div>
        <form id="addInterventionForm" action="create.php" method="POST" class="p-6">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <label for="vehicule_id" class="block text-sm font-medium text-gray-700 mb-1">Véhicule <span class="text-red-500">*</span></label>
                    <select id="vehicule_id" name="vehicule_id" required class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-green-500 focus:border-green-500">
                        <option value="">Sélectionner un véhicule</option>
                        <?php foreach ($vehicules as $vehicule): ?>
                            <option value="<?php echo $vehicule['id']; ?>" data-client-id="<?php echo $vehicule['client_id']; ?>">
                                <?php echo htmlspecialchars($vehicule['marque'] . ' ' . $vehicule['modele'] . ' - ' . $vehicule['immatriculation'] . ' (' . $vehicule['client_nom'] . ')'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div id="client_info" class="hidden"></div>
                    <input type="hidden" id="client_id" name="client_id" value="">
                </div>
                <div>
                    <label for="technicien_id" class="block text-sm font-medium text-gray-700 mb-1">Technicien</label>
                    <select id="technicien_id" name="technicien_id" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-green-500 focus:border-green-500">
                        <option value="">Sélectionner un technicien</option>
                        <?php foreach ($techniciens as $technicien): ?>
                            <option value="<?php echo $technicien['id']; ?>" data-specialite="<?php echo htmlspecialchars($technicien['specialite']); ?>">
                                <?php echo htmlspecialchars($technicien['nom_complet']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="mt-1 text-xs text-gray-500 technicien-specialite hidden">Spécialité: <span id="specialite_technicien"></span></p>
                </div>
                <div>
                    <label for="date_prevue" class="block text-sm font-medium text-gray-700 mb-1">Date prévue</label>
                    <input type="date" id="date_prevue" name="date_prevue" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-green-500 focus:border-green-500">
                </div>
                <div>
                    <label for="date_debut" class="block text-sm font-medium text-gray-700 mb-1">Date de début</label>
                    <input type="date" id="date_debut" name="date_debut" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-green-500 focus:border-green-500">
                </div>
                <div>
                    <label for="date_fin" class="block text-sm font-medium text-gray-700 mb-1">Date de fin</label>
                    <input type="date" id="date_fin" name="date_fin" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-green-500 focus:border-green-500">
                </div>
                <div>
                    <label for="date_prochain_ct" class="block text-sm font-medium text-gray-700 mb-1">Date prochain contrôle technique</label>
                    <input type="date" id="date_prochain_ct" name="date_prochain_ct" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-green-500 focus:border-green-500">
                </div>
                <div>
                    <label for="kilometrage" class="block text-sm font-medium text-gray-700 mb-1">Kilométrage</label>
                    <input type="number" id="kilometrage" name="kilometrage" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-green-500 focus:border-green-500" placeholder="Kilométrage actuel">
                </div>
                <div class="col-span-1 md:col-span-2">
                    <label for="description" class="block text-sm font-medium text-gray-700 mb-1">Description <span class="text-red-500">*</span></label>
                    <textarea id="description" name="description" rows="3" required class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-green-500 focus:border-green-500" placeholder="Description du problème ou de l'intervention à réaliser"></textarea>
                </div>
                <div class="col-span-1 md:col-span-2">
                    <label for="diagnostique" class="block text-sm font-medium text-gray-700 mb-1">Diagnostique</label>
                    <textarea id="diagnostique" name="diagnostique" rows="3" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-green-500 focus:border-green-500" placeholder="Diagnostique technique"></textarea>
                </div>
                <div class="col-span-1 md:col-span-2">
                    <label for="commentaire" class="block text-sm font-medium text-gray-700 mb-1">Commentaire</label>
                    <textarea id="commentaire" name="commentaire" rows="2" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-green-500 focus:border-green-500" placeholder="Commentaires additionnels"></textarea>
                </div>
                <div>
                    <label for="statut" class="block text-sm font-medium text-gray-700 mb-1">Statut <span class="text-red-500">*</span></label>
                    <select id="statut" name="statut" required class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-green-500 focus:border-green-500">
                        <option value="En attente">En attente</option>
                        <option value="En cours">En cours</option>
                        <option value="Terminée">Terminée</option>
                        <option value="Facturée">Facturée</option>
                        <option value="Annulée">Annulée</option>
                    </select>
                </div>
                <div>
                    <label for="create_commande" class="block text-sm font-medium text-gray-700 mb-1">Créer une commande</label>
                    <div class="flex items-center">
                        <input type="checkbox" id="create_commande" name="create_commande" class="h-4 w-4 text-green-600 focus:ring-green-500 border-gray-300 rounded">
                        <label for="create_commande" class="ml-2 block text-sm text-gray-900">
                            Créer automatiquement une commande liée à cette intervention
                        </label>
                    </div>
                </div>
            </div>
            
            <!-- Section des articles et offres -->
            <div class="mt-8 border-t border-gray-200 pt-6">
                <h4 class="text-lg font-medium text-gray-900 mb-4">Articles et offres</h4>
                
                <!-- Sélection de catégorie -->
                <div class="mb-4">
                    <label for="categorie_filter" class="block text-sm font-medium text-gray-700 mb-1">Sélectionner une catégorie</label>
                    <select id="categorie_filter" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-green-500 focus:border-green-500">
                        <option value="">Toutes les catégories</option>
                        <?php foreach ($categories as $categorie): ?>
                            <option value="<?php echo $categorie['id']; ?>" data-with-article="<?php echo $categorie['with_article']; ?>">
                                <?php echo htmlspecialchars($categorie['nom']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <!-- Onglets Articles/Offres -->
                <div class="mb-6">
                    <div class="flex border-b border-gray-200">
                        <button type="button" id="tab-articles" class="py-2 px-4 border-b-2 border-green-500 text-green-600 font-medium text-sm focus:outline-none">
                            Articles
                        </button>
                        <button type="button" id="tab-offres" class="py-2 px-4 border-b-2 border-transparent text-gray-500 hover:text-gray-700 font-medium text-sm focus:outline-none">
                            Offres
                        </button>
                    </div>
                </div>
                
                <!-- Section Articles -->
                <div id="section-articles" class="mb-6">
                    <!-- Recherche d'articles -->
                    <div class="mb-4">
                        <label for="article_search" class="block text-sm font-medium text-gray-700 mb-1">Rechercher un article</label>
                        <div class="relative">
                            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                                </svg>
                            </div>
                            <input type="text" id="article_search" class="pl-10 pr-4 py-2 w-full border border-gray-300 rounded-md focus:outline-none focus:ring-green-500 focus:border-green-500" placeholder="Rechercher par référence, désignation...">
                        </div>
                    </div>
                    
                    <!-- Liste des articles -->
                    <div class="border border-gray-300 rounded-md overflow-hidden">
                        <div class="max-h-64 overflow-y-auto" id="articles_container">
                            <div class="p-4 text-center text-gray-500">
                                Sélectionnez une catégorie ou recherchez un article
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Section Offres -->
                <div id="section-offres" class="mb-6 hidden">
                    <!-- Liste des offres disponibles pour la catégorie sélectionnée -->
                    <div class="border border-gray-300 rounded-md overflow-hidden">
                        <div class="max-h-64 overflow-y-auto" id="offres_container">
                            <div class="p-4 text-center text-gray-500">
                                Sélectionnez une catégorie pour voir les offres disponibles
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Articles et offres sélectionnés -->
                <div class="mt-6">
                    <h5 class="text-md font-medium text-gray-900 mb-2">Éléments sélectionnés</h5>
                    <div class="border border-gray-300 rounded-md overflow-hidden">
                        <table class="min-w-full divide-y divide-gray-200" id="selected_items_table">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Type</th>
                                    <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Référence</th>
                                    <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Désignation</th>
                                    <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Quantité</th>
                                    <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Prix unitaire</th>
                                    <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Remise</th>
                                    <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Total</th>
                                    <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200" id="selected_items_body">
                                <tr>
                                    <td colspan="8" class="px-4 py-3 text-center text-gray-500">Aucun élément sélectionné</td>
                                </tr>
                            </tbody>
                            <tfoot class="bg-gray-50">
                                <tr>
                                    <td colspan="6" class="px-4 py-3 text-right font-medium text-gray-700">Total HT:</td>
                                    <td class="px-4 py-3 font-medium text-gray-900" id="total_ht">0.00 DH</td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
            
            <div class="mt-6 flex justify-end space-x-3">
                <button type="button" onclick="closeModal('addInterventionModal')" class="px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500">
                    Annuler
                </button>
                <button type="submit" class="px-4 py-2 bg-green-600 border border-transparent rounded-md text-sm font-medium text-white hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500">
                    Enregistrer
                </button>
            </div>
            <p class="text-xs text-gray-500 mt-4">Les champs marqués d'un <span class="text-red-500">*</span> sont obligatoires.</p>
            
            <!-- Champs cachés pour stocker les articles et offres sélectionnés -->
            <input type="hidden" id="selected_articles_json" name="selected_articles" value="[]">
            <input type="hidden" id="selected_offres_json" name="selected_offres" value="[]">
        </form>
    </div>
</div>

<!-- Edit Intervention Modal -->
<div id="editInterventionModal" class="fixed inset-0 bg-black bg-opacity-50 z-50 hidden flex items-center justify-center p-4">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-4xl max-h-[90vh] overflow-y-auto">
        <div class="flex justify-between items-center p-6 border-b">
            <h3 class="text-lg font-semibold text-gray-800">Modifier l'intervention</h3>
            <button onclick="closeModal('editInterventionModal')" class="text-gray-400 hover:text-gray-500">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                </svg>
            </button>
        </div>
        <form id="editInterventionForm" action="edit.php" method="POST" class="p-6">
            <input type="hidden" id="edit_id" name="id" value="">
            <input type="hidden" id="edit_client_id" name="client_id" value="">
            <input type="hidden" id="edit_commande_id" name="commande_id" value="">
            
            <!-- Same fields as add intervention form but with edit_ prefix -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <!-- Vehicle selection -->
                <div>
                    <label for="edit_vehicule_id" class="block text-sm font-medium text-gray-700 mb-1">Véhicule <span class="text-red-500">*</span></label>
                    <select id="edit_vehicule_id" name="vehicule_id" required class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                        <option value="">Sélectionner un véhicule</option>
                        <?php foreach ($vehicules as $vehicule): ?>
                            <option value="<?php echo $vehicule['id']; ?>" data-client-id="<?php echo $vehicule['client_id']; ?>">
                                <?php echo htmlspecialchars($vehicule['marque'] . ' ' . $vehicule['modele'] . ' - ' . $vehicule['immatriculation'] . ' (' . $vehicule['client_nom'] . ')'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div id="edit_client_info" class="hidden"></div>
                </div>
                
                <!-- Technician selection -->
                <div>
                    <label for="edit_technicien_id" class="block text-sm font-medium text-gray-700 mb-1">Technicien</label>
                    <select id="edit_technicien_id" name="technicien_id" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                        <option value="">Sélectionner un technicien</option>
                        <?php foreach ($techniciens as $technicien): ?>
                            <option value="<?php echo $technicien['id']; ?>" data-specialite="<?php echo htmlspecialchars($technicien['specialite']); ?>">
                                <?php echo htmlspecialchars($technicien['nom_complet']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="mt-1 text-xs text-gray-500 edit-technicien-specialite hidden">Spécialité: <span id="edit_specialite_technicien"></span></p>
                </div>
                
                <!-- Other fields -->
                <div>
                    <label for="edit_date_prevue" class="block text-sm font-medium text-gray-700 mb-1">Date prévue</label>
                    <input type="date" id="edit_date_prevue" name="date_prevue" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                </div>
                <div>
                    <label for="edit_date_debut" class="block text-sm font-medium text-gray-700 mb-1">Date de début</label>
                    <input type="date" id="edit_date_debut" name="date_debut" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                </div>
                <div>
                    <label for="edit_date_fin" class="block text-sm font-medium text-gray-700 mb-1">Date de fin</label>
                    <input type="date" id="edit_date_fin" name="date_fin" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                </div>
                <div>
                    <label for="edit_date_prochain_ct" class="block text-sm font-medium text-gray-700 mb-1">Date prochain contrôle technique</label>
                    <input type="date" id="edit_date_prochain_ct" name="date_prochain_ct" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                </div>
                <div>
                    <label for="edit_kilometrage" class="block text-sm font-medium text-gray-700 mb-1">Kilométrage</label>
                    <input type="number" id="edit_kilometrage" name="kilometrage" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500" placeholder="Kilométrage actuel">
                </div>
                <div class="col-span-1 md:col-span-2">
                    <label for="edit_description" class="block text-sm font-medium text-gray-700 mb-1">Description <span class="text-red-500">*</span></label>
                    <textarea id="edit_description" name="description" rows="3" required class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500" placeholder="Description du problème ou de l'intervention à réaliser"></textarea>
                </div>
                <div class="col-span-1 md:col-span-2">
                    <label for="edit_diagnostique" class="block text-sm font-medium text-gray-700 mb-1">Diagnostique</label>
                    <textarea id="edit_diagnostique" name="diagnostique" rows="3" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500" placeholder="Diagnostique technique"></textarea>
                </div>
                <div class="col-span-1 md:col-span-2">
                    <label for="edit_commentaire" class="block text-sm font-medium text-gray-700 mb-1">Commentaire</label>
                    <textarea id="edit_commentaire" name="commentaire" rows="2" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500" placeholder="Commentaires additionnels"></textarea>
                </div>
                <div>
                    <label for="edit_statut" class="block text-sm font-medium text-gray-700 mb-1">Statut <span class="text-red-500">*</span></label>
                    <select id="edit_statut" name="statut" required class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                        <option value="En attente">En attente</option>
                        <option value="En cours">En cours</option>
                        <option value="Terminée">Terminée</option>
                        <option value="Facturée">Facturée</option>
                        <option value="Annulée">Annulée</option>
                    </select>
                </div>
                <div>
                    <div id="edit_commande_info"></div>
                </div>
            </div>
            
            <!-- Articles and offers section -->
            <div class="mt-8 border-t border-gray-200 pt-6">
                <h4 class="text-lg font-medium text-gray-900 mb-4">Articles et offres</h4>
                
                <!-- Category selection -->
                <div class="mb-4">
                    <label for="edit_categorie_filter" class="block text-sm font-medium text-gray-700 mb-1">Sélectionner une catégorie</label>
                    <select id="edit_categorie_filter" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                        <option value="">Toutes les catégories</option>
                        <?php foreach ($categories as $categorie): ?>
                            <option value="<?php echo $categorie['id']; ?>" data-with-article="<?php echo $categorie['with_article']; ?>">
                                <?php echo htmlspecialchars($categorie['nom']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <!-- Tabs for Articles/Offers -->
                <div class="mb-6">
                    <div class="flex border-b border-gray-200">
                        <button type="button" id="edit-tab-articles" class="py-2 px-4 border-b-2 border-blue-500 text-blue-600 font-medium text-sm focus:outline-none">
                            Articles
                        </button>
                        <button type="button" id="edit-tab-offres" class="py-2 px-4 border-b-2 border-transparent text-gray-500 hover:text-gray-700 font-medium text-sm focus:outline-none">
                            Offres
                        </button>
                    </div>
                </div>
                
                <!-- Articles section -->
                <div id="edit-section-articles" class="mb-6">
                    <div class="mb-4">
                        <label for="edit_article_search" class="block text-sm font-medium text-gray-700 mb-1">Rechercher un article</label>
                        <div class="relative">
                            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                                </svg>
                            </div>
                            <input type="text" id="edit_article_search" class="pl-10 pr-4 py-2 w-full border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500" placeholder="Rechercher par référence, désignation...">
                        </div>
                    </div>
                    
                    <div class="border border-gray-300 rounded-md overflow-hidden">
                        <div class="max-h-64 overflow-y-auto" id="edit_articles_container">
                            <div class="p-4 text-center text-gray-500">
                                Sélectionnez une catégorie ou recherchez un article
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Offers section -->
                <div id="edit-section-offres" class="mb-6 hidden">
                    <div class="border border-gray-300 rounded-md overflow-hidden">
                        <div class="max-h-64 overflow-y-auto" id="edit_offres_container">
                            <div class="p-4 text-center text-gray-500">
                                Sélectionnez une catégorie pour voir les offres disponibles
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Selected items -->
                <div class="mt-6">
                    <h5 class="text-md font-medium text-gray-900 mb-2">Éléments sélectionnés</h5>
                    <div class="border border-gray-300 rounded-md overflow-hidden">
                        <table class="min-w-full divide-y divide-gray-200" id="edit_selected_items_table">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Type</th>
                                    <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Référence</th>
                                    <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Désignation</th>
                                    <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Quantité</th>
                                    <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Prix unitaire</th>
                                    <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Remise</th>
                                    <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Total</th>
                                    <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200" id="edit_selected_items_body">
                                <tr>
                                    <td colspan="8" class="px-4 py-3 text-center text-gray-500">Aucun élément sélectionné</td>
                                </tr>
                            </tbody>
                            <tfoot class="bg-gray-50">
                                <tr>
                                    <td colspan="6" class="px-4 py-3 text-right font-medium text-gray-700">Total HT:</td>
                                    <td class="px-4 py-3 font-medium text-gray-900" id="edit_total_ht">0.00 DH</td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
            
            <div class="mt-6 flex justify-end space-x-3">
                <button type="button" onclick="closeModal('editInterventionModal')" class="px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                    Annuler
                </button>
                <button type="submit" class="px-4 py-2 bg-blue-600 border border-transparent rounded-md text-sm font-medium text-white hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                    Enregistrer les modifications
                </button>
            </div>
            
            <!-- Hidden fields for selected items -->
            <input type="hidden" id="edit_selected_articles_json" name="selected_articles" value="[]">
            <input type="hidden" id="edit_selected_offres_json" name="selected_offres" value="[]">
        </form>
    </div>
</div>


<!-- View Intervention Modal -->
<div id="viewInterventionModal" class="fixed inset-0 bg-black bg-opacity-50 z-50 hidden flex items-center justify-center p-4">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-3xl max-h-[90vh] overflow-y-auto">
        <div class="flex justify-between items-center p-6 border-b">
            <h3 class="text-lg font-semibold text-gray-800">Détails de l'intervention #<span id="view_intervention_id"></span></h3>
            <button onclick="closeModal('viewInterventionModal')" class="text-gray-400 hover:text-gray-500">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                </svg>
            </button>
        </div>
        <div class="p-6">
            <div class="mb-4">
                <span id="view_statut" class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full"></span>
            </div>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                <div>
                    <h4 class="font-medium text-gray-700 mb-2">Informations générales</h4>
                    <div class="bg-gray-50 p-4 rounded-md">
                        <div class="mb-2">
                            <p class="text-sm text-gray-500">Date de création</p>
                            <p id="view_date_creation" class="font-medium">Aucun</p>
                        </div>
                        <div class="mb-2">
                            <p class="text-sm text-gray-500">Date prévue</p>
                            <p id="view_date_prevue" class="font-medium">Aucun</p>
                        </div>
                        <div class="mb-2">
                            <p class="text-sm text-gray-500">Date de début</p>
                            <p id="view_date_debut" class="font-medium">Aucun</p>
                        </div>
                        <div>
                            <p class="text-sm text-gray-500">Date de fin</p>
                            <p id="view_date_fin" class="font-medium">Aucun</p>
                        </div>
                        <div>
                            <p class="text-sm text-gray-500">Date prochain CT</p>
                            <p id="view_date_prochain_ct" class="font-medium">Aucun</p>
                        </div>
                    </div>
                </div>
                
                <div>
                    <h4 class="font-medium text-gray-700 mb-2">Client et véhicule</h4>
                    <div class="bg-gray-50 p-4 rounded-md">
                        <div class="mb-2">
                            <p class="text-sm text-gray-500">Client</p>
                            <p id="view_client" class="font-medium"></p>
                        </div>
                        <div class="mb-2">
                            <p class="text-sm text-gray-500">Véhicule</p>
                            <p id="view_vehicule" class="font-medium"></p>
                        </div>
                        <div>
                            <p class="text-sm text-gray-500">Kilométrage</p>
                            <p id="view_kilometrage" class="font-medium"></p>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="mb-6">
                <h4 class="font-medium text-gray-700 mb-2">Détails de l'intervention</h4>
                <div class="bg-gray-50 p-4 rounded-md">
                    <div class="mb-4">
                        <p class="text-sm text-gray-500 mb-1">Description</p>
                        <p id="view_description" class="whitespace-pre-line"></p>
                    </div>
                    <div class="mb-4">
                        <p class="text-sm text-gray-500 mb-1">Diagnostique</p>
                        <p id="view_diagnostique" class="whitespace-pre-line"></p>
                    </div>
                    <div>
                    <p class="text-sm text-gray-500 mb-1">Commentaire</p>
                        <p id="view_commentaire" class="whitespace-pre-line"></p>
                    </div>
                </div>
            </div>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                <div>
                    <h4 class="font-medium text-gray-700 mb-2">Technicien</h4>
                    <div class="bg-gray-50 p-4 rounded-md">
                        <p id="view_technicien" class="font-medium"></p>
                        <p id="view_technicien_specialite" class="text-sm text-gray-500"></p>
                    </div>
                </div>
                
                <div>
                    <h4 class="font-medium text-gray-700 mb-2">Commande</h4>
                    <div class="bg-gray-50 p-4 rounded-md" id="view_commande_details">
                        <!-- Contenu dynamique pour la commande -->
                    </div>
                </div>
            </div>
            
            <div id="view_articles_section" class="mb-6">
                <h4 class="font-medium text-gray-700 mb-2">Articles utilisés</h4>
                <div class="bg-gray-50 rounded-md overflow-hidden">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-100">
                            <tr>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Référence</th>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Désignation</th>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Quantité</th>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Prix unitaire</th>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Remise</th>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Total</th>
                            </tr>
                        </thead>
                        <tbody id="view_articles_container">
                            <!-- Contenu dynamique pour les articles -->
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="5" class="px-4 py-2 text-right font-medium">Total HT:</td>
                                <td id="view_total_ht" class="px-4 py-2 font-medium"></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
            
            <div class="flex justify-end space-x-3 mt-6">
                <button type="button" onclick="closeModal('viewInterventionModal')" class="px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 hover:bg-gray-50">
                    Fermer
                </button>
                <button type="button" id="editFromViewBtn" class="px-4 py-2 bg-blue-600 text-white rounded-md text-sm font-medium hover:bg-blue-700">
                    Modifier
                </button>
                <button type="button" id="createCommandeBtn" class="px-4 py-2 bg-green-600 text-white rounded-md text-sm font-medium hover:bg-green-700 hidden">
                    Créer une commande
                </button>
            </div>
        </div>
    </div>
</div>


       
    </div>
</div>


<!-- Delete Confirmation Modal -->
<div id="deleteConfirmationModal" class="fixed inset-0 bg-black bg-opacity-50 z-50 hidden flex items-center justify-center p-4">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-md">
        <div class="p-6">
            <div class="flex items-center justify-center mb-4 text-red-600">
            <svg class="w-12 h-12" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                </svg>
            </div>
            <h3 class="text-lg font-semibold text-gray-900 mb-2 text-center">Confirmer la suppression</h3>
            <p class="text-gray-700 mb-6 text-center">Êtes-vous sûr de vouloir supprimer cette intervention ? Cette action ne peut pas être annulée.</p>
            <div class="flex justify-center space-x-4">
                <button type="button" onclick="closeModal('deleteConfirmationModal')" class="px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                    Annuler
                </button>
                <form id="deleteInterventionForm" action="actions/delete_intervention.php" method="POST">
                    <input type="hidden" id="delete_intervention_id" name="id">
                    <button type="submit" class="px-4 py-2 bg-red-600 border border-transparent rounded-md text-sm font-medium text-white hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500">
                        Supprimer
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<div id="createCommandeModal" class="fixed inset-0 bg-black bg-opacity-50 z-50 hidden flex items-center justify-center p-4">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-md">
        <div class="flex justify-between items-center p-6 border-b">
            <h3 class="text-lg font-semibold text-gray-800">Créer une commande</h3>
            <button onclick="closeModal('createCommandeModal')" class="text-gray-400 hover:text-gray-500">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                </svg>
            </button>
        </div>
        <form id="createCommandeForm" action="actions/create_commande_from_intervention.php" method="POST" class="p-6">
            <input type="hidden" id="commande_intervention_id" name="intervention_id">
            <div class="mb-4">
                <p class="text-gray-700 mb-4">Vous êtes sur le point de créer une commande pour cette intervention. Les articles et offres associés à l'intervention seront automatiquement ajoutés à la commande.</p>
                <div class="bg-yellow-50 border-l-4 border-yellow-400 p-4 mb-4">
                    <div class="flex">
                        <div class="flex-shrink-0">
                            <svg class="h-5 w-5 text-yellow-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                            </svg>
                        </div>
                        <div class="ml-3">
                            <p class="text-sm text-yellow-700">
                                Cette action créera une nouvelle commande qui sera liée à cette intervention.
                            </p>
                        </div>
                    </div>
                </div>
            </div>
            <div class="mt-6 flex justify-end space-x-3">
                <button type="button" onclick="closeModal('createCommandeModal')" class="px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                    Annuler
                </button>
                <button type="submit" class="px-4 py-2 bg-green-600 border border-transparent rounded-md text-sm font-medium text-white hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500">
                    Créer la commande
                </button>
            </div>
        </form>
    </div>
</div>


<script>
// Récupérer les interventions pour le calendrier
let calendarInterventions = <?php 
    try {
        $query = "SELECT i.id, i.date_prevue, i.description, i.statut, 
                  CONCAT(c.nom, ' ', c.prenom) AS client_nom,
                  v.immatriculation, CONCAT(v.marque, ' ', v.modele) AS vehicule_info
                  FROM interventions i
                  INNER JOIN vehicules v ON i.vehicule_id = v.id
                  INNER JOIN clients c ON v.client_id = c.id
                  WHERE i.date_prevue IS NOT NULL";
        
        // Ajouter les filtres si nécessaire
        if (!empty($statut_filter)) {
            $query .= " AND i.statut = :statut";
        }
        if (!empty($search)) {
            $query .= " AND (v.immatriculation LIKE :search OR c.nom LIKE :search OR c.prenom LIKE :search OR i.description LIKE :search)";
        }
        
        $query .= " ORDER BY i.date_prevue ASC";
        
        $stmt = $db->prepare($query);
        
        // Lier les paramètres si nécessaire
        if (!empty($statut_filter)) {
            $stmt->bindParam(':statut', $statut_filter);
        }
        if (!empty($search)) {
            $search_param = "%$search%";
            $stmt->bindParam(':search', $search_param);
        }
        
        $stmt->execute();
        $calendarData = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Transformer les données pour le format du calendrier
        $formattedData = [];
        foreach ($calendarData as $intervention) {
            $date = substr($intervention['date_prevue'], 0, 10); // Format YYYY-MM-DD
            if (!isset($formattedData[$date])) {
                $formattedData[$date] = [];
            }
            $formattedData[$date][] = [
                'id' => $intervention['id'],
                'client' => $intervention['client_nom'],
                'description' => $intervention['description'],
                'statut' => $intervention['statut'],
                'vehicule' => $intervention['immatriculation']
            ];
        }
        echo json_encode($formattedData);
    } catch (PDOException $e) {
        echo "{}";
        error_log('Erreur lors de la récupération des interventions pour le calendrier: ' . $e->getMessage());
    }
?>;

// Fonction pour générer le calendrier
function generateCalendar(year, month) {
    const calendarGrid = document.getElementById('calendar-grid');
    if (!calendarGrid) return; // Sortir si on n'est pas sur la vue calendrier
    
    const monthYearDisplay = document.getElementById('month-year-display');
    
    // Vider le calendrier
    calendarGrid.innerHTML = '';
    
    // Mettre à jour l'affichage du mois et de l'année
    const monthNames = ['Janvier', 'Février', 'Mars', 'Avril', 'Mai', 'Juin', 
                        'Juillet', 'Août', 'Septembre', 'Octobre', 'Novembre', 'Décembre'];
    monthYearDisplay.textContent = `${monthNames[month]} ${year}`;
    
    // Obtenir le premier jour du mois (0 = Dimanche, 1 = Lundi, etc.)
    const firstDay = new Date(year, month, 1).getDay();
    
    // Obtenir le nombre de jours dans le mois
    const daysInMonth = new Date(year, month + 1, 0).getDate();
    
    // Obtenir le dernier jour du mois précédent
    const daysInPrevMonth = new Date(year, month, 0).getDate();
    
    // Date actuelle pour surligner le jour courant
    const today = new Date();
    const currentDate = today.getDate();
    const currentMonth = today.getMonth();
    const currentYear = today.getFullYear();
    
    // Générer les jours du mois précédent (grisés)
    for (let i = 0; i < firstDay; i++) {
        const day = daysInPrevMonth - firstDay + i + 1;
        const prevMonth = month - 1 < 0 ? 11 : month - 1;
        const prevYear = prevMonth === 11 ? year - 1 : year;
        const dateString = `${prevYear}-${String(prevMonth + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
        
        const dayCell = document.createElement('div');
        dayCell.className = 'day-cell bg-gray-100 p-1 h-24 overflow-y-auto';
        dayCell.innerHTML = `
            <div class="text-gray-400 text-sm font-medium">${day}</div>
            <div class="interventions-container"></div>
        `;
        
        // Ajouter les interventions pour ce jour (s'il y en a)
        addInterventionsToCell(dayCell, dateString);
        
        calendarGrid.appendChild(dayCell);
    }
    
    // Générer les jours du mois courant
    for (let day = 1; day <= daysInMonth; day++) {
        const dateString = `${year}-${String(month + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
        
        const dayCell = document.createElement('div');
        dayCell.className = 'day-cell bg-white p-1 h-24 overflow-y-auto';
        
        // Surligner le jour courant
        if (day === currentDate && month === currentMonth && year === currentYear) {
            dayCell.classList.add('bg-blue-50', 'border', 'border-blue-300');
        }
        
        dayCell.innerHTML = `
            <div class="text-gray-800 text-sm font-medium">${day}</div>
            <div class="interventions-container"></div>
        `;
        
        // Ajouter les interventions pour ce jour (s'il y en a)
        addInterventionsToCell(dayCell, dateString);
        
        // Ajouter un événement pour ajouter une intervention à cette date
        dayCell.addEventListener('dblclick', () => {
            addInterventionForDate(dateString);
        });
        
        calendarGrid.appendChild(dayCell);
    }
    
    // Calculer le nombre de cellules nécessaires pour compléter la grille (6 lignes de 7 jours)
    const remainingCells = 42 - (firstDay + daysInMonth);
    
    // Générer les jours du mois suivant (grisés)
    for (let day = 1; day <= remainingCells; day++) {
        const nextMonth = month + 1 > 11 ? 0 : month + 1;
        const nextYear = nextMonth === 0 ? year + 1 : year;
        const dateString = `${nextYear}-${String(nextMonth + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
        
        const dayCell = document.createElement('div');
        dayCell.className = 'day-cell bg-gray-100 p-1 h-24 overflow-y-auto';
        dayCell.innerHTML = `
            <div class="text-gray-400 text-sm font-medium">${day}</div>
            <div class="interventions-container"></div>
        `;
        
        // Ajouter les interventions pour ce jour (s'il y en a)
        addInterventionsToCell(dayCell, dateString);
        
        calendarGrid.appendChild(dayCell);
    }
}

// Fonction pour ajouter les interventions à une cellule du calendrier
function addInterventionsToCell(cell, dateString) {
    const interventionsContainer = cell.querySelector('.interventions-container');
    
    if (calendarInterventions[dateString]) {
        const interventions = calendarInterventions[dateString];
        
        interventions.forEach(intervention => {
            const statusClass = getStatusClass(intervention.statut);
            
            const interventionElement = document.createElement('div');
            interventionElement.className = `text-xs p-1 mb-1 rounded ${statusClass} cursor-pointer hover:opacity-80`;
            interventionElement.innerHTML = `
                <div class="font-medium">#${intervention.id} - ${intervention.client}</div>
                <div class="text-xs truncate">${intervention.vehicule}</div>
            `;
            
            // Ajouter un événement pour voir les détails de l'intervention
            interventionElement.addEventListener('click', () => {
                viewIntervention(intervention.id);
            });
            
            interventionsContainer.appendChild(interventionElement);
        });
    }
}

// Fonction pour obtenir la classe CSS en fonction du statut
function getStatusClass(statut) {
    switch (statut) {
        case 'En attente':
            return 'bg-yellow-100 text-yellow-800 border-l-2 border-yellow-500';
        case 'En cours':
            return 'bg-blue-100 text-blue-800 border-l-2 border-blue-500';
        case 'Terminée':
            return 'bg-green-100 text-green-800 border-l-2 border-green-500';
        case 'Facturée':
            return 'bg-purple-100 text-purple-800 border-l-2 border-purple-500';
        case 'Annulée':
            return 'bg-red-100 text-red-800 border-l-2 border-red-500';
        default:
            return 'bg-gray-100 text-gray-800 border-l-2 border-gray-500';
    }
}

// Fonction pour ajouter une intervention à une date spécifique
function addInterventionForDate(dateString) {
    // Ouvrir le modal d'ajout d'intervention avec la date pré-remplie
    document.getElementById('date_prevue').value = dateString;
    addIntervention();
}

// Initialisation du calendrier si on est sur la vue calendrier
let currentCalendarDate = new Date();
if (document.getElementById('calendar-grid')) {
    // Générer le calendrier initial
    generateCalendar(currentCalendarDate.getFullYear(), currentCalendarDate.getMonth());

    // Gérer la navigation entre les mois
    document.getElementById('prev-month-btn').addEventListener('click', () => {
        currentCalendarDate.setMonth(currentCalendarDate.getMonth() - 1);
        generateCalendar(currentCalendarDate.getFullYear(), currentCalendarDate.getMonth());
    });

    document.getElementById('next-month-btn').addEventListener('click', () => {
        currentCalendarDate.setMonth(currentCalendarDate.getMonth() + 1);
        generateCalendar(currentCalendarDate.getFullYear(), currentCalendarDate.getMonth());
    });
}

// Ajouter des styles CSS pour le calendrier
document.head.insertAdjacentHTML('beforeend', `
    <style>
        .calendar-container {
            border: 1px solid #e5e7eb;
            border-radius: 0.5rem;
            overflow: hidden;
        }
        
        .day-cell {
            border: 1px solid #e5e7eb;
            min-height: 100px;
            position: relative;
        }
        
        .day-cell:hover {
            background-color: #f9fafb;
        }
        
        .interventions-container {
            margin-top: 4px;
        }
    </style>
`);

function loadCategoryData(categorieId, mode = 'add') {
    const prefix = mode === 'add' ? '' : 'edit_';
    const withArticle = document.getElementById(`${prefix}categorie_filter`).options[document.getElementById(`${prefix}categorie_filter`).selectedIndex].getAttribute('data-with-article');
console.log("categorieId : ",categorieId);

    if (withArticle === '1') {
        document.getElementById(`${prefix}tab-articles`).click(); // Activer l'onglet Articles
        loadArticlesByCategory(categorieId, mode);
    } else {
        document.getElementById(`${prefix}tab-offres`).click(); // Activer l'onglet Offres
        loadOffresByCategory(categorieId, mode);
    }
}

// Écouteur d'événement pour le changement de catégorie
document.getElementById('categorie_filter').addEventListener('change', function() {
    const categorieId = this.value;
    loadCategoryData(categorieId, 'add');
});

document.getElementById('edit_categorie_filter').addEventListener('change', function() {
    const categorieId = this.value;
    loadCategoryData(categorieId, 'edit');
});


// Gestion des modals
function openModal(modalId) {
    document.getElementById(modalId).classList.remove('hidden');
    document.getElementById(modalId).classList.add('flex');
    document.body.classList.add('overflow-hidden');
}

function closeModal(modalId) {
    document.getElementById(modalId).classList.add('hidden');
    document.getElementById(modalId).classList.remove('flex');
    document.body.classList.remove('overflow-hidden');
}

// Fonction pour ajouter une intervention
function addIntervention() {
    // Réinitialiser le formulaire
    document.getElementById('addInterventionForm').reset();
    
    // Réinitialiser les informations du client
    document.getElementById('client_info').classList.add('hidden');
    document.getElementById('client_id').value = '';
    
    // Réinitialiser les articles et offres sélectionnés
    document.getElementById('selected_articles_json').value = '[]';
    document.getElementById('selected_offres_json').value = '[]';
    updateSelectedItemsTable('add');
    
    // Ouvrir le modal
    openModal('addInterventionModal');
}

// Fonction pour voir les détails d'une intervention
// Fonction pour voir les détails d'une intervention
function viewIntervention(id) {
    // Requête AJAX pour récupérer les détails de l'intervention
    fetch(`get_intervention.php?id=${id}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const intervention = data.intervention;
                
                // Afficher l'ID de l'intervention
                document.getElementById('view_intervention_id').textContent = intervention.id;
                
                // Remplir les champs du modal de visualisation
                document.getElementById('view_date_creation').textContent = formatDate(intervention.date_creation);
                document.getElementById('view_client').textContent = intervention.client;
                document.getElementById('view_vehicule').textContent = `${intervention.vehicule_info} (${intervention.immatriculation})`;
                document.getElementById('view_kilometrage').textContent = intervention.kilometrage ? `${intervention.kilometrage} km` : 'Non spécifié';
                document.getElementById('view_description').textContent = intervention.description || 'Aucune description';
                document.getElementById('view_diagnostique').textContent = intervention.diagnostique || 'Aucun diagnostique';
                document.getElementById('view_commentaire').textContent = intervention.commentaire || 'Aucun commentaire';
                document.getElementById('view_date_prevue').textContent = intervention.date_prevue ? formatDate(intervention.date_prevue) : 'Non spécifiée';
                document.getElementById('view_date_debut').textContent = intervention.date_debut ? formatDate(intervention.date_debut) : 'Non spécifiée';
                document.getElementById('view_date_fin').textContent = intervention.date_fin ? formatDate(intervention.date_fin) : 'Non spécifiée';
                
                // Afficher le statut avec la bonne couleur
                const viewStatut = document.getElementById('view_statut');
                viewStatut.textContent = intervention.statut;
                viewStatut.className = 'px-3 py-1 rounded-full text-sm font-medium';
                
                switch (intervention.statut) {
                    case 'En attente':
                        viewStatut.classList.add('bg-yellow-100', 'text-yellow-800');
                        break;
                    case 'En cours':
                        viewStatut.classList.add('bg-blue-100', 'text-blue-800');
                        break;
                    case 'Terminée':
                        viewStatut.classList.add('bg-green-100', 'text-green-800');
                        break;
                    case 'Facturée':
                        viewStatut.classList.add('bg-purple-100', 'text-purple-800');
                        break;
                    case 'Annulée':
                        viewStatut.classList.add('bg-red-100', 'text-red-800');
                        break;
                }
                
                // Afficher les informations du technicien
                const technicienName = document.getElementById('view_technicien');
                const technicienSpecialite = document.getElementById('view_technicien_specialite');
                
                if (intervention.technicien) {
                    technicienName.textContent = intervention.technicien;
                    technicienSpecialite.textContent = intervention.technicien_specialite || 'Spécialité non spécifiée';
                } else {
                    technicienName.textContent = 'Non assigné';
                    technicienSpecialite.textContent = '';
                    
                    // Ajouter un bouton pour assigner un technicien
                    technicienSpecialite.innerHTML = `
                        <button onclick="assignTechnicien(${id}); closeModal('viewInterventionModal');" class="mt-2 text-sm text-blue-600 hover:text-blue-800">
                            Assigner un technicien
                        </button>
                    `;
                }
                
                // Afficher les informations de la commande
                const commandeDetails = document.getElementById('view_commande_details');
                const createCommandeBtn = document.getElementById('createCommandeBtn');
                
                if (intervention.commande_id) {
                    commandeDetails.innerHTML = `
                        <div class="flex justify-between items-center">
                            <div>
                                <p class="font-medium">Commande #${intervention.commande_id}</p>
                                <p class="text-sm text-gray-500">Date: ${intervention.commande_date ? formatDate(intervention.commande_date) : 'Non spécifiée'}</p>
                            </div>
                            <a href="../orders/view.php?id=${intervention.commande_id}" class="px-3 py-1 bg-blue-100 text-blue-700 rounded-md text-sm hover:bg-blue-200 transition-colors">
                                Voir la commande
                            </a>
                        </div>
                    `;
                    createCommandeBtn.classList.add('hidden');
                } else {
                    commandeDetails.innerHTML = `
                        <div class="text-center py-3">
                            <p class="text-gray-500 italic">Aucune commande associée</p>
                        </div>
                    `;
                    createCommandeBtn.classList.remove('hidden');
                    createCommandeBtn.onclick = function() {
                        createCommandeFromIntervention(id);
                        closeModal('viewInterventionModal');
                    };
                }
                
                // Configurer le bouton d'édition
                document.getElementById('editFromViewBtn').onclick = function() {
                    closeModal('viewInterventionModal');
                    editIntervention(id);
                };
                
                // Afficher les articles et offres
                const viewArticlesContainer = document.getElementById('view_articles_container');
                let itemsHtml = '';
                let totalHT = 0;
                
                if (intervention.articles && intervention.articles.length > 0) {
                    intervention.articles.forEach(article => {
                        const prixTotal = article.quantite * article.prix_unitaire * (1 - article.remise / 100);
                        totalHT += prixTotal;
                        
                        itemsHtml += `
                            <tr>
                                <td class="px-4 py-3 text-sm">${article.reference || '-'}</td>
                                <td class="px-4 py-3 text-sm">${article.designation}</td>
                                <td class="px-4 py-3 text-sm">${article.quantite}</td>
                                <td class="px-4 py-3 text-sm">${formatPrice(article.prix_unitaire)} DH</td>
                                <td class="px-4 py-3 text-sm">${article.remise > 0 ? article.remise + '%' : '-'}</td>
                                <td class="px-4 py-3 text-sm font-medium">${formatPrice(prixTotal)} DH</td>
                            </tr>
                        `;
                    });
                }
                
                if (intervention.offres && intervention.offres.length > 0) {
                    intervention.offres.forEach(offre => {
                        const prixTotal = offre.quantite * offre.prix_unitaire * (1 - offre.remise / 100);
                        totalHT += prixTotal;
                        
                        itemsHtml += `
                            <tr>
                                <td class="px-4 py-3 text-sm">${offre.code || '-'}</td>
                                <td class="px-4 py-3 text-sm">${offre.nom}</td>
                                <td class="px-4 py-3 text-sm">${offre.quantite}</td>
                                <td class="px-4 py-3 text-sm">${formatPrice(offre.prix_unitaire)} DH</td>
                                <td class="px-4 py-3 text-sm">${offre.remise > 0 ? offre.remise + '%' : '-'}</td>
                                <td class="px-4 py-3 text-sm font-medium">${formatPrice(prixTotal)} DH</td>
                            </tr>
                        `;
                    });
                }
                
                if (itemsHtml === '') {
                    viewArticlesContainer.innerHTML = `
                        <tr>
                            <td colspan="6" class="px-4 py-3 text-center text-gray-500">Aucun élément utilisé</td>
                        </tr>
                    `;
                } else {
                    viewArticlesContainer.innerHTML = itemsHtml;
                }
                
                document.getElementById('view_total_ht').textContent = `${formatPrice(totalHT)} DH`;
                
                // Ouvrir le modal
                openModal('viewInterventionModal');
            } else {
                alert('Erreur lors de la récupération des détails de l\'intervention');
            }
        })
        .catch(error => {
            console.error('Erreur:', error);
            alert('Une erreur est survenue lors de la récupération des détails');
        });
}

// Fonction pour supprimer une intervention
function deleteIntervention(id) {
    if (confirm('Êtes-vous sûr de vouloir supprimer cette intervention ?')) {
        // Créer un formulaire pour soumettre la suppression
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = 'actions/delete_intervention.php';
        
        const idInput = document.createElement('input');
        idInput.type = 'hidden';
        idInput.name = 'id';
        idInput.value = id;
        
        form.appendChild(idInput);
        document.body.appendChild(form);
        form.submit();
    }
}

// Fonction pour créer une commande à partir d'une intervention
function createCommandeFromIntervention(id) {
    // Rediriger vers la page de création de commande avec l'ID de l'intervention
    window.location.href = '../orders/create.php?intervention_id=' + id;
}


// Fonction pour éditer une intervention
function editIntervention(id) {
    // Requête AJAX pour récupérer les détails de l'intervention
    fetch(`get_intervention.php?id=${id}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const intervention = data.intervention;
                
                // Remplir les champs du formulaire d'édition
                document.getElementById('edit_id').value = intervention.id;
                document.getElementById('edit_vehicule_id').value = intervention.vehicule_id;
                document.getElementById('edit_client_id').value = intervention.client_id;
                document.getElementById('edit_technicien_id').value = intervention.technicien_id || '';
                document.getElementById('edit_date_prevue').value = intervention.date_prevue || '';
                document.getElementById('edit_date_debut').value = intervention.date_debut || '';
                document.getElementById('edit_date_fin').value = intervention.date_fin || '';
                document.getElementById('edit_kilometrage').value = intervention.kilometrage || '';
                document.getElementById('edit_description').value = intervention.description || '';
                document.getElementById('edit_diagnostique').value = intervention.diagnostique || '';
                document.getElementById('edit_commentaire').value = intervention.commentaire || '';
                document.getElementById('edit_statut').value = intervention.statut;
                document.getElementById('edit_commande_id').value = intervention.commande_id || '';
                
                // Mettre à jour l'affichage des informations du client
                updateClientFromVehicle(intervention.vehicule_id, 'edit');
                
                // Afficher la spécialité du technicien si sélectionné
                if (intervention.technicien_id) {
                    const technicienSelect = document.getElementById('edit_technicien_id');
                    const selectedOption = technicienSelect.options[technicienSelect.selectedIndex];
                    if (selectedOption) {
                        const specialite = selectedOption.getAttribute('data-specialite');
                        document.getElementById('edit_specialite_technicien').textContent = specialite || 'Non spécifiée';
                        document.querySelector('.edit-technicien-specialite').classList.remove('hidden');
                    }
                } else {
                    document.querySelector('.edit-technicien-specialite').classList.add('hidden');
                }
                
                // Afficher les informations de la commande
                const commandeInfo = document.getElementById('edit_commande_info');
                if (intervention.commande_id) {
                    commandeInfo.innerHTML = `
                        <div class="flex items-center">
                            <span class="text-sm">Commande #${intervention.commande_id} associée</span>
                            <a href="../orders/view.php?id=${intervention.commande_id}" class="ml-2 text-xs text-blue-600 hover:text-blue-800">Voir</a>
                        </div>
                    `;
                } else {
                    commandeInfo.innerHTML = `
                        <div class="flex items-center">
                            <input type="checkbox" id="edit_create_commande" name="create_commande" class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded">
                            <label for="edit_create_commande" class="ml-2 block text-sm text-gray-900">
                                Créer une commande pour cette intervention
                            </label>
                        </div>
                    `;
                }
                
                // Charger les articles et offres sélectionnés
                let selectedArticles = [];
                let selectedOffres = [];
                
                if (intervention.articles && intervention.articles.length > 0) {
                    selectedArticles = intervention.articles;
                }
                
                if (intervention.offres && intervention.offres.length > 0) {
                    selectedOffres = intervention.offres;
                }
                
                document.getElementById('edit_selected_articles_json').value = JSON.stringify(selectedArticles);
                document.getElementById('edit_selected_offres_json').value = JSON.stringify(selectedOffres);
                
                // Mettre à jour le tableau des éléments sélectionnés
                updateSelectedItemsTable('edit');
                
                // Ouvrir le modal
                openModal('editInterventionModal');
            } else {
                alert('Erreur lors de la récupération des détails de l\'intervention');
            }
        })
        .catch(error => {
            console.error('Erreur:', error);
            alert('Une erreur est survenue lors de la récupération des détails');
        });
}

// Fonction pour supprimer une intervention
function deleteIntervention(id) {
    document.getElementById('delete_intervention_id').value = id;
    openModal('deleteConfirmationModal');
}

// Fonction pour assigner un technicien
function assignTechnicien(id) {
    document.getElementById('assign_intervention_id').value = id;
    document.getElementById('assign_technicien_id').value = '';
    document.querySelector('.assign-technicien-specialite').classList.add('hidden');
    openModal('assignTechnicienModal');
}

// Fonction pour créer une commande à partir d'une intervention
function createCommandeFromIntervention(id) {
    document.getElementById('commande_intervention_id').value = id;
    openModal('createCommandeModal');
}

// Fonction pour formater les dates
function formatDate(dateString) {
    if (!dateString) return '-';
    const date = new Date(dateString);
    return date.toLocaleDateString('fr-FR');
}

// Fonction pour formater les prix
function formatPrice(price) {
    return parseFloat(price).toFixed(2).replace('.', ',');
}

// Fonction pour filtrer les véhicules par client
function filterVehiclesByClient(clientId, mode = 'add') {
    const vehiculeSelect = mode === 'add' ? document.getElementById('vehicule_id') : document.getElementById('edit_vehicule_id');
    const options = vehiculeSelect.options;
    
    // Réinitialiser d'abord toutes les options
    for (let i = 0; i < options.length; i++) {
        options[i].style.display = '';
    }
    
    // Si aucun client n'est sélectionné, on affiche tous les véhicules
    if (!clientId) {
        return;
    }
    
    // Sinon, on filtre les véhicules pour n'afficher que ceux du client sélectionné
    for (let i = 0; i < options.length; i++) {
        if (i === 0) continue; // Sauter l'option "Sélectionner un véhicule"
        
        const vehicleClientId = options[i].getAttribute('data-client-id');
        if (vehicleClientId !== clientId) {
            options[i].style.display = 'none';
        }
    }
    
    // Réinitialiser la sélection si le véhicule actuel n'appartient pas au client sélectionné
    const currentVehicleOption = vehiculeSelect.options[vehiculeSelect.selectedIndex];
    if (currentVehicleOption && currentVehicleOption.getAttribute('data-client-id') !== clientId) {
        vehiculeSelect.value = '';
    }
}

// Fonction pour mettre à jour le client sélectionné en fonction du véhicule
function updateClientFromVehicle(vehicleId, mode = 'add') {
    if (!vehicleId) return;
    
    const vehiculeSelect = mode === 'add' ? document.getElementById('vehicule_id') : document.getElementById('edit_vehicule_id');
    const clientIdInput = mode === 'add' ? document.getElementById('client_id') : document.getElementById('edit_client_id');
    
    const selectedOption = vehiculeSelect.options[vehiculeSelect.selectedIndex];
    if (selectedOption) {
        const clientId = selectedOption.getAttribute('data-client-id');
        clientIdInput.value = clientId || '';
        
        // Afficher les informations du client sous le sélecteur de véhicule
        const clientInfoDiv = mode === 'add' ? document.getElementById('client_info') : document.getElementById('edit_client_info');
        
        if (clientId) {
            const clientName = selectedOption.textContent.split('(').pop().split(')')[0];
            clientInfoDiv.innerHTML = `<p class="text-sm text-gray-600 mt-1">Client: <span class="font-medium">${clientName}</span></p>`;
            clientInfoDiv.classList.remove('hidden');
        } else {
            clientInfoDiv.classList.add('hidden');
        }
    }
}

// Fonction pour mettre à jour le tableau des éléments sélectionnés
function updateSelectedItemsTable(mode = 'add') {
    const prefix = mode === 'add' ? '' : 'edit_';
    const articlesJson = document.getElementById(`${prefix}selected_articles_json`).value;
    const offresJson = document.getElementById(`${prefix}selected_offres_json`).value;
    
    const selectedArticles = JSON.parse(articlesJson);
    const selectedOffres = JSON.parse(offresJson);
    
    const tableBody = document.getElementById(`${prefix}selected_items_body`);
    let html = '';
    let totalHT = 0;
    
    // Ajouter les articles
    if (selectedArticles.length > 0) {
        selectedArticles.forEach((article, index) => {
            const prixTotal = article.quantite * article.prix_unitaire * (1 - article.remise / 100);
            totalHT += prixTotal;
            
            html += `
                <tr>
                    <td class="px-4 py-3 text-sm">Article</td>
                    <td class="px-4 py-3 text-sm">${article.reference || '-'}</td>
                    <td class="px-4 py-3 text-sm">${article.designation}</td>
                    <td class="px-4 py-3 text-sm">
                        <input type="number" min="1" value="${article.quantite}" 
                               onchange="updateItemQuantity('${mode}', 'article', ${index}, this.value)"
                               class="w-16 px-2 py-1 border border-gray-300 rounded-md text-sm">
                    </td>
                    <td class="px-4 py-3 text-sm">${formatPrice(article.prix_unitaire)} DH</td>
                    <td class="px-4 py-3 text-sm">
                        <input type="number" min="0" max="100" value="${article.remise || 0}" 
                               onchange="updateItemRemise('${mode}', 'article', ${index}, this.value)"
                               class="w-16 px-2 py-1 border border-gray-300 rounded-md text-sm">%
                    </td>
                    <td class="px-4 py-3 text-sm font-medium">${formatPrice(prixTotal)} DH</td>
                    <td class="px-4 py-3 text-sm">
                        <button type="button" onclick="removeItem('${mode}', 'article', ${index})" class="text-red-600 hover:text-red-800">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                            </svg>
                        </button>
                    </td>
                </tr>
            `;
        });
    }
    
    // Ajouter les offres
    if (selectedOffres.length > 0) {
        selectedOffres.forEach((offre, index) => {
            const prixTotal = offre.quantite * offre.prix_unitaire * (1 - offre.remise / 100);
            totalHT += prixTotal;
            
            html += `
                <tr>
                    <td class="px-4 py-3 text-sm">Offre</td>
                    <td class="px-4 py-3 text-sm">${offre.code || '-'}</td>
                    <td class="px-4 py-3 text-sm">${offre.nom}</td>
                    <td class="px-4 py-3 text-sm">
                        <input type="number" min="1" value="${offre.quantite}" 
                               onchange="updateItemQuantity('${mode}', 'offre', ${index}, this.value)"
                               class="w-16 px-2 py-1 border border-gray-300 rounded-md text-sm">
                    </td>
                    <td class="px-4 py-3 text-sm">${formatPrice(offre.prix_unitaire)} DH</td>
                    <td class="px-4 py-3 text-sm">
                        <input type="number" min="0" max="100" value="${offre.remise || 0}" 
                               onchange="updateItemRemise('${mode}', 'offre', ${index}, this.value)"
                               class="w-16 px-2 py-1 border border-gray-300 rounded-md text-sm">%
                    </td>
                    <td class="px-4 py-3 text-sm font-medium">${formatPrice(prixTotal)} DH</td>
                    <td class="px-4 py-3 text-sm">
                        <button type="button" onclick="removeItem('${mode}', 'offre', ${index})" class="text-red-600 hover:text-red-800">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                                               <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                            </svg>
                        </button>
                    </td>
                </tr>
            `;
        });
    }
    
    if (html === '') {
        tableBody.innerHTML = `
            <tr>
                <td colspan="8" class="px-4 py-3 text-center text-gray-500">Aucun élément sélectionné</td>
            </tr>
        `;
    } else {
        tableBody.innerHTML = html;
    }
    
    // Mettre à jour le total HT
    document.getElementById(`${prefix}total_ht`).textContent = `${formatPrice(totalHT)} DH`;
}

// Fonction pour mettre à jour la quantité d'un élément
function updateItemQuantity(mode, type, index, quantity) {
    const prefix = mode === 'add' ? '' : 'edit_';
    const jsonField = type === 'article' ? `${prefix}selected_articles_json` : `${prefix}selected_offres_json`;
    
    let items = JSON.parse(document.getElementById(jsonField).value);
    items[index].quantite = parseInt(quantity);
    document.getElementById(jsonField).value = JSON.stringify(items);
    
    // Mettre à jour le tableau
    updateSelectedItemsTable(mode);
}

// Fonction pour mettre à jour la remise d'un élément
function updateItemRemise(mode, type, index, remise) {
    const prefix = mode === 'add' ? '' : 'edit_';
    const jsonField = type === 'article' ? `${prefix}selected_articles_json` : `${prefix}selected_offres_json`;
    
    let items = JSON.parse(document.getElementById(jsonField).value);
    items[index].remise = parseFloat(remise);
    document.getElementById(jsonField).value = JSON.stringify(items);
    
    // Mettre à jour le tableau
    updateSelectedItemsTable(mode);
}

// Fonction pour supprimer un élément
function removeItem(mode, type, index) {
    const prefix = mode === 'add' ? '' : 'edit_';
    const jsonField = type === 'article' ? `${prefix}selected_articles_json` : `${prefix}selected_offres_json`;
    
    let items = JSON.parse(document.getElementById(jsonField).value);
    items.splice(index, 1);
    document.getElementById(jsonField).value = JSON.stringify(items);
    
    // Mettre à jour le tableau
    updateSelectedItemsTable(mode);
}

// Fonction pour ajouter un article à la liste des éléments sélectionnés
function addArticleToSelection(mode, articleId, reference, designation, prix_unitaire) {
    const prefix = mode === 'add' ? '' : 'edit_';
    const jsonField = `${prefix}selected_articles_json`;
    
    let selectedArticles = JSON.parse(document.getElementById(jsonField).value);
    
    // Vérifier si l'article est déjà dans la liste
    const existingIndex = selectedArticles.findIndex(item => item.id === articleId);
    
    if (existingIndex !== -1) {
        // Si l'article existe déjà, augmenter la quantité
        selectedArticles[existingIndex].quantite += 1;
    } else {
        // Sinon, ajouter le nouvel article
        selectedArticles.push({
            id: articleId,
            reference: reference,
            designation: designation,
            prix_unitaire: prix_unitaire,
            quantite: 1,
            remise: 0
        });
    }
    
    document.getElementById(jsonField).value = JSON.stringify(selectedArticles);
    
    // Mettre à jour le tableau
    updateSelectedItemsTable(mode);
}

// Fonction pour ajouter une offre à la liste des éléments sélectionnés
function addOffreToSelection(mode, offreId, code, nom, prix_unitaire) {
    const prefix = mode === 'add' ? '' : 'edit_';
    const jsonField = `${prefix}selected_offres_json`;
    
    let selectedOffres = JSON.parse(document.getElementById(jsonField).value);
    
    // Vérifier si l'offre est déjà dans la liste
    const existingIndex = selectedOffres.findIndex(item => item.id === offreId);
    
    if (existingIndex !== -1) {
        // Si l'offre existe déjà, augmenter la quantité
        selectedOffres[existingIndex].quantite += 1;
    } else {
        // Sinon, ajouter la nouvelle offre
       /*  selectedOffres.push({
            id: offreId,
            code: code,
            nom: nom,
            prix_unitaire: prix_unitaire,
            quantite: 1,
            remise: 0
        }); */
        
        // Récupérer les articles associés à cette offre
        fetch(`get_offre_articles.php?offre_id=${offreId}`)
            .then(response => response.json())
            .then(data => {
                if (data.success && data.articles.length > 0) {
                    // Récupérer les articles déjà sélectionnés
                    const articlesJsonField = `${prefix}selected_articles_json`;
                    let selectedArticles = JSON.parse(document.getElementById(articlesJsonField).value);
                    
                    // Ajouter chaque article associé à l'offre
                    data.articles.forEach(article => {
                        // Vérifier si l'article est déjà dans la liste
                        const existingArticleIndex = selectedArticles.findIndex(item => item.id === article.id);
                        
                        if (existingArticleIndex !== -1) {
                            // Si l'article existe déjà, augmenter la quantité
                            selectedArticles[existingArticleIndex].quantite += 1;
                        } else {
                            // Sinon, ajouter le nouvel article avec la remise de l'offre
                            selectedArticles.push({
                                id: article.id,
                                reference: article.reference || '',
                                designation: article.designation,
                                prix_unitaire: article.prix_vente_ht,
                                quantite: 1,
                                remise: article.remise_specifique || selectedOffres[selectedOffres.length - 1].remise,
                                from_offre: offreId
                            });
                        }
                    });
                    
                    // Mettre à jour la liste des articles sélectionnés
                    document.getElementById(articlesJsonField).value = JSON.stringify(selectedArticles);
                    
                    // Mettre à jour le tableau
                    updateSelectedItemsTable(mode);
                }
            })
            .catch(error => {
                console.error('Erreur lors de la récupération des articles associés à l\'offre:', error);
            });
    }
    
    document.getElementById(jsonField).value = JSON.stringify(selectedOffres);
    
    // Mettre à jour le tableau
    updateSelectedItemsTable(mode);
}


// Fonction pour charger les articles d'une catégorie
function loadArticlesByCategory(categorieId, mode = 'add') {
    const prefix = mode === 'add' ? '' : 'edit_';
    const container = document.getElementById(`${prefix}articles_container`);
    
    container.innerHTML = '<div class="p-4 text-center"><div class="inline-block animate-spin rounded-full h-8 w-8 border-b-2 border-gray-900"></div><p class="mt-2 text-gray-600">Chargement des articles...</p></div>';
    
    fetch(`get_articles.php?categorie_id=${categorieId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success && data.articles.length > 0) {
                let html = '<div class="divide-y divide-gray-200">';
                
                data.articles.forEach(article => {
                    html += `
                        <div class="p-3 hover:bg-gray-50 flex justify-between items-center">
                            <div>
                                <div class="font-medium">${article.designation}</div>
                                <div class="text-sm text-gray-500">Réf: ${article.reference || 'N/A'} | Prix: ${formatPrice(article.prix_vente_ht)} DH</div>
                            </div>
                            <button type="button" onclick="addArticleToSelection('${mode}', ${article.id}, '${article.reference || ''}', '${article.designation.replace(/'/g, "\\'")}', ${article.prix_vente_ht})" class="px-3 py-1 bg-blue-100 text-blue-700 rounded hover:bg-blue-200 text-sm">
                                Ajouter
                            </button>
                        </div>
                    `;
                });
                
                html += '</div>';
                container.innerHTML = html;
            } else {
                container.innerHTML = '<div class="p-4 text-center text-gray-500">Aucun article trouvé pour cette catégorie</div>';
            }
        })
        .catch(error => {
            console.error('Erreur:', error);
            container.innerHTML = '<div class="p-4 text-center text-red-500">Erreur lors du chargement des articles</div>';
        });
}

// Fonction pour charger les offres d'une catégorie
function loadOffresByCategory(categorieId, mode = 'add') {
    const prefix = mode === 'add' ? '' : 'edit_';
    const container = document.getElementById(`${prefix}offres_container`);
    
    container.innerHTML = '<div class="p-4 text-center"><div class="inline-block animate-spin rounded-full h-8 w-8 border-b-2 border-gray-900"></div><p class="mt-2 text-gray-600">Chargement des offres...</p></div>';
    
    fetch(`get_offres.php?categorie_id=${categorieId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success && data.offres.length > 0) {
                let html = '<div class="divide-y divide-gray-200">';
                
                data.offres.forEach(offre => {
                    html += `
                        <div class="p-3 hover:bg-gray-50 flex justify-between items-center">
                            <div>
                                <div class="font-medium">${offre.nom}</div>
                                <div class="text-sm text-gray-500">Code: ${offre.code || 'N/A'} | Prix: ${formatPrice(offre.prix_moyen_apres_remise)} DH</div>
                            </div>
                            <button type="button" onclick="addOffreToSelection('${mode}', ${offre.id}, '${offre.code || ''}', '${offre.nom.replace(/'/g, "\\'")}', ${offre.prix_moyen_apres_remise})" class="px-3 py-1 bg-blue-100 text-blue-700 rounded hover:bg-blue-200 text-sm">
                                Ajouter
                            </button>
                        </div>
                    `;
                });
                
                html += '</div>';
                container.innerHTML = html;
            } else {
                container.innerHTML = '<div class="p-4 text-center text-gray-500">Aucune offre trouvée pour cette catégorie</div>';
            }
        })
        .catch(error => {
            console.error('Erreur:', error);
            container.innerHTML = '<div class="p-4 text-center text-red-500">Erreur lors du chargement des offres</div>';
        });
}

// Fonction pour rechercher des articles
function searchArticles(searchTerm, mode = 'add') {
    if (searchTerm.length < 2) return;
    
    const prefix = mode === 'add' ? '' : 'edit_';
    const container = document.getElementById(`${prefix}articles_container`);
    
    container.innerHTML = '<div class="p-4 text-center"><div class="inline-block animate-spin rounded-full h-8 w-8 border-b-2 border-gray-900"></div><p class="mt-2 text-gray-600">Recherche en cours...</p></div>';
    
    fetch(`search_articles.php?search=${encodeURIComponent(searchTerm)}`)
        .then(response => response.json())
        .then(data => {
            if (data.success && data.articles.length > 0) {
                let html = '<div class="divide-y divide-gray-200">';
                
                data.articles.forEach(article => {
                    html += `
                        <div class="p-3 hover:bg-gray-50 flex justify-between items-center">
                            <div>
                                <div class="font-medium">${article.designation}</div>
                                <div class="text-sm text-gray-500">Réf: ${article.reference || 'N/A'} | Prix: ${formatPrice(article.prix_vente_ht)} DH</div>
                            </div>
                            <button type="button" onclick="addArticleToSelection('${mode}', ${article.id}, '${article.reference || ''}', '${article.designation.replace(/'/g, "\\'")}', ${article.prix_vente_ht})" class="px-3 py-1 bg-blue-100 text-blue-700 rounded hover:bg-blue-200 text-sm">
                                Ajouter
                            </button>
                        </div>
                    `;
                });
                
                html += '</div>';
                container.innerHTML = html;
            } else {
                container.innerHTML = '<div class="p-4 text-center text-gray-500">Aucun article trouvé pour cette recherche</div>';
            }
        })
        .catch(error => {
            console.error('Erreur:', error);
            container.innerHTML = '<div class="p-4 text-center text-red-500">Erreur lors de la recherche</div>';
        });
}

// Initialisation des événements
document.addEventListener('DOMContentLoaded', function() {
    // Gestion des onglets Articles/Offres dans le modal d'ajout
    document.getElementById('tab-articles').addEventListener('click', function() {
        this.classList.add('border-green-500', 'text-green-600');
        this.classList.remove('border-transparent', 'text-gray-500');
        document.getElementById('tab-offres').classList.add('border-transparent', 'text-gray-500');
        document.getElementById('tab-offres').classList.remove('border-green-500', 'text-green-600');
        document.getElementById('section-articles').classList.remove('hidden');
        document.getElementById('section-offres').classList.add('hidden');
    });
    
    document.getElementById('tab-offres').addEventListener('click', function() {
        this.classList.add('border-green-500', 'text-green-600');
        this.classList.remove('border-transparent', 'text-gray-500');
        document.getElementById('tab-articles').classList.add('border-transparent', 'text-gray-500');
        document.getElementById('tab-articles').classList.remove('border-green-500', 'text-green-600');
        document.getElementById('section-offres').classList.remove('hidden');
        document.getElementById('section-articles').classList.add('hidden');
    });
    
    // Gestion des onglets Articles/Offres dans le modal d'édition
    document.getElementById('edit-tab-articles').addEventListener('click', function() {
        this.classList.add('border-blue-500', 'text-blue-600');
        this.classList.remove('border-transparent', 'text-gray-500');
        document.getElementById('edit-tab-offres').classList.add('border-transparent', 'text-gray-500');
        document.getElementById('edit-tab-offres').classList.remove('border-blue-500', 'text-blue-600');
        document.getElementById('edit-section-articles').classList.remove('hidden');
        document.getElementById('edit-section-offres').classList.add('hidden');
    });
    
    document.getElementById('edit-tab-offres').addEventListener('click', function() {
        this.classList.add('border-blue-500', 'text-blue-600');
        this.classList.remove('border-transparent', 'text-gray-500');
        document.getElementById('edit-tab-articles').classList.add('border-transparent', 'text-gray-500');
        document.getElementById('edit-tab-articles').classList.remove('border-blue-500', 'text-blue-600');
        document.getElementById('edit-section-offres').classList.remove('hidden');
        document.getElementById('edit-section-articles').classList.add('hidden');
    });
    
    // Filtrage des articles par catégorie
    document.getElementById('categorie_filter').addEventListener('change', function() {
    const categorieId = this.value;
    const withArticle = this.options[this.selectedIndex].getAttribute('data-with-article');
    
    // Charger les deux contenus en même temps
    loadArticlesByCategory(categorieId, 'add');
    loadOffresByCategory(categorieId, 'add');
    
    // Activer l'onglet approprié selon le type de catégorie
    if (withArticle === '1') {
        document.getElementById('tab-articles').click(); // Activer l'onglet Articles
    } else {
        document.getElementById('tab-offres').click(); // Activer l'onglet Offres
    }
});
    
document.getElementById('edit_categorie_filter').addEventListener('change', function() {
    const categorieId = this.value;
    const withArticle = this.options[this.selectedIndex].getAttribute('data-with-article');
    
    // Charger les deux contenus en même temps
    loadArticlesByCategory(categorieId, 'edit');
    loadOffresByCategory(categorieId, 'edit');
    
    // Activer l'onglet approprié selon le type de catégorie
    if (withArticle === '1') {
        document.getElementById('edit-tab-articles').click(); // Activer l'onglet Articles
    } else {
        document.getElementById('edit-tab-offres').click(); // Activer l'onglet Offres
    }
});
    
    // Recherche d'articles
    let searchTimeout;
    
    document.getElementById('article_search').addEventListener('input', function() {
        clearTimeout(searchTimeout);
        const searchTerm = this.value.trim();
        
        if (searchTerm.length < 2) {
            document.getElementById('articles_container').innerHTML = '<div class="p-4 text-center text-gray-500">Saisissez au moins 2 caractères pour rechercher</div>';
            return;
        }
        
        searchTimeout = setTimeout(() => {
            searchArticles(searchTerm, 'add');
        }, 500);
    });
    
    document.getElementById('edit_article_search').addEventListener('input', function() {
        clearTimeout(searchTimeout);
        const searchTerm = this.value.trim();
        
        if (searchTerm.length < 2) {
            document.getElementById('edit_articles_container').innerHTML = '<div class="p-4 text-center text-gray-500">Saisissez au moins 2 caractères pour rechercher</div>';
            return;
        }
        
        searchTimeout = setTimeout(() => {
            searchArticles(searchTerm, 'edit');
        }, 500);
    });
    
    // Mise à jour du client en fonction du véhicule sélectionné
    document.getElementById('vehicule_id').addEventListener('change', function() {
        updateClientFromVehicle(this.value, 'add');
    });
    
    document.getElementById('edit_vehicule_id').addEventListener('change', function() {
        updateClientFromVehicle(this.value, 'edit');
    });
    
    // Affichage de la spécialité du technicien
    document.getElementById('technicien_id').addEventListener('change', function() {
        const specialite = this.options[this.selectedIndex].getAttribute('data-specialite');
        if (specialite) {
            document.getElementById('specialite_technicien').textContent = specialite;
            document.querySelector('.technicien-specialite').classList.remove('hidden');
        } else {
            document.querySelector('.technicien-specialite').classList.add('hidden');
        }
    });
    
    document.getElementById('edit_technicien_id').addEventListener('change', function() {
        const specialite = this.options[this.selectedIndex].getAttribute('data-specialite');
        if (specialite) {
            document.getElementById('edit_specialite_technicien').textContent = specialite;
            document.querySelector('.edit-technicien-specialite').classList.remove('hidden');
        } else {
            document.querySelector('.edit-technicien-specialite').classList.add('hidden');
        }
    });
    
    document.getElementById('assign_technicien_id').addEventListener('change', function() {
        const specialite = this.options[this.selectedIndex].getAttribute('data-specialite');
        if (specialite) {
            document.getElementById('assign_specialite_technicien').textContent = specialite;
            document.querySelector('.assign-technicien-specialite').classList.remove('hidden');
        } else {
            document.querySelector('.assign-technicien-specialite').classList.add('hidden');
        }
    });
    
    // Initialisation des tableaux d'éléments sélectionnés
    updateSelectedItemsTable('add');
    updateSelectedItemsTable('edit');
});
</script>

<?php include '../includes/footer.php'; ?>
